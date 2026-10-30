<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Cctv\Models\HikvisionDiscoveryRequest;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Read-only manager projection. Commands remain owned by discovery, mapping,
 * source actions and the canonical live session service. */
final class HikvisionSetup
{
    public function page(int $actorId, int $cameraId, array $query = []): array
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actorId, $cameraId, $query): array {
            $actor = app(CctvAuthority::class)->actor($actorId, true);
            $kind = $reads->query(Device::query())->whereKey($cameraId)->value('subcategory');
            [$camera, $site] = app(CctvAuthority::class)->device($actor, $cameraId, $kind === 'nvr');
            abort_unless($camera->provider === 'hikvision', 404, 'unavailable');
            if ($camera->subcategory === 'nvr') {
                [$choice, $search, $collectorId, $choices] = $this->choices($actor, $cameraId, $site, 0, $query, $reads, HikvisionRecorderDiscoveryContract::CAPABILITY);
                $recent = $reads->query(HikvisionDiscoveryRequest::query())->where('actor_id', $actorId)->where('device_id', $cameraId)->where('site_id', $site)->orderByDesc('id')->limit(10)->get()->map(fn ($row) => ['request_uuid' => $row->uuid, 'status' => $row->status, 'created_at' => $row->created_at->utc()->format('Y-m-d\TH:i:s\Z'), 'reader_href' => '/security-devices/cctv/hikvision-discoveries/'.$row->uuid])->all();

                return ['provider' => 'hikvision_recorder', 'actor_id' => $actorId, 'recorder_id' => $cameraId, 'recorder' => ['id' => $cameraId, 'name' => $camera->name, 'site' => ['id' => $site, 'name' => Site::query()->findOrFail($site)->name], 'model' => $camera->model, 'serial' => $camera->serial_number, 'ip_address' => $camera->ip_address], 'revision' => 0, 'readiness' => ['can_discover' => config('cctv.hikvision_discovery_enabled') === true], 'choices' => $choices, 'choice' => $choice, 'q' => $search, 'collector_id' => $collectorId, 'recent_requests' => $recent];
            }
            $latest = $reads->query(CctvSource::query())->where('camera_id', $cameraId)->orderByDesc('revision')->first();
            $revision = $latest ? (int) $latest->revision : 0;
            $source = $latest && $latest->provider === 'hikvision' && (int) $latest->site_id === $site ? $latest : null;
            $enabled = $reads->query(CctvSource::query())->where('camera_id', $cameraId)->where('enabled', true)->orderByDesc('revision')->limit(2)->get();
            $active = $enabled->first(fn ($row) => $row->provider === 'hikvision' && (int) $row->site_id === $site);
            $conflict = $enabled->contains(fn ($row) => ! $source || (int) $row->id !== (int) $source->id);
            $canEnable = false;
            $canView = false;
            $reason = $source ? 'connection_changed' : 'not_mapped';
            if ($source && ! $conflict) {
                try {
                    [, , $collector] = app(HikvisionLiveAuthority::class)->source($actor, $source, false, true);
                    app(CctvSessions::class)->readiness((int) $collector->id, $source);
                    $canEnable = ! $source->enabled;
                    $reason = null;
                } catch (HttpExceptionInterface) {
                    $reason = 'connection_unavailable';
                }
            }
            if ($conflict) {
                $reason = 'active_connection_exists';
            }
            if ($active && $enabled->count() === 1) {
                try {
                    app(CctvAuthority::class)->actor($actorId);
                    [, , $collector] = app(HikvisionLiveAuthority::class)->source($actor, $active);
                    app(CctvSessions::class)->readiness((int) $collector->id, $active);
                    $canView = true;
                } catch (HttpExceptionInterface) {
                }
            }
            [$choice, $search, $collectorId, $choices] = $this->choices($actor, $cameraId, $site, $revision, $query, $reads);

            return ['provider' => 'hikvision', 'actor_id' => $actorId, 'camera_id' => $cameraId,
                'camera' => ['id' => $cameraId, 'name' => $camera->name, 'site' => ['id' => $site, 'name' => Site::query()->findOrFail($site)->name], 'model' => $camera->model, 'serial' => $camera->serial_number, 'ip_address' => $camera->ip_address],
                'revision' => $revision, 'source' => $source ? $this->summary($source) : null, 'active_source' => $active ? $this->summary($active) : null,
                'readiness' => ['can_discover' => config('cctv.hikvision_discovery_enabled') === true, 'can_map' => config('cctv.hikvision_mapping_enabled') === true, 'can_enable' => $canEnable, 'can_disable' => $active !== null, 'can_view' => $canView, 'reason_code' => $reason],
                'choices' => $choices, 'choice' => $choice, 'q' => $search, 'collector_id' => $collectorId];
        }));
    }

    private function summary(CctvSource $source): array
    {
        $configuration = $source->configuration;
        $trusted = is_array($configuration) && hash_equals($source->fingerprint, CctvContract::hash($configuration));

        return ['id' => (int) $source->id, 'revision' => (int) $source->revision, 'enabled' => (bool) $source->enabled, 'stream_state' => $source->stream_state,
            'stream_verified_at' => $source->stream_verified_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'channel_name' => $trusted && is_string($configuration['channel']['name'] ?? null) ? $configuration['channel']['name'] : null,
            'observed_at' => $trusted && is_string($configuration['observed_at'] ?? null) ? $configuration['observed_at'] : null];
    }

    private function choices(User $actor, int $cameraId, int $site, int $revision, array $query, CurrentAuthorizationReads $reads, string $capability = HikvisionDiscoveryAuthority::CAPABILITY): array
    {
        abort_unless(array_diff(array_keys($query), ['choice', 'cursor', 'q', 'collector_id']) === [], 400, 'invalid_request');
        $choice = $query['choice'] ?? null;
        $search = $query['q'] ?? '';
        $cursor = $query['cursor'] ?? null;
        $collectorId = $query['collector_id'] ?? null;
        abort_unless($choice === null || in_array($choice, ['collectors', 'scopes', 'credentials'], true), 400, 'invalid_request');
        abort_unless(is_string($search) && mb_check_encoding($search, 'UTF-8'), 400, 'invalid_request');
        $search = trim($search);
        CctvContract::text($search, 0, 200);
        abort_if($choice === null && ($search !== '' || $cursor !== null), 400, 'invalid_request');
        if ($collectorId !== null) {
            abort_unless($choice === 'scopes' && is_string($collectorId) && preg_match('/\A[1-9][0-9]{0,18}\z/', $collectorId) && (string) (int) $collectorId === $collectorId, 400, 'invalid_request');
            $collectorId = (int) $collectorId;
            abort_unless($this->collectorVisible($actor, $site, MonitoringCollector::query()->whereKey($collectorId)->lock('for share nowait')->first()), 404, 'unavailable');
        }
        $collectors = fn () => MonitoringCollector::query()->where('site_id', $site)->whereNull('revoked_at')->where('status', '!=', 'revoked')->whereNotNull('enrolled_at')->whereIn('collector_device_id', app(SecurityDevicesAccessService::class)->visibleDevicesForSite($actor, $site)->select('devices.id'));
        $choices = [];
        foreach (['collectors', 'scopes', 'credentials'] as $kind) {
            $base = match ($kind) {
                'collectors' => $collectors(),
                'scopes' => DiscoveryScope::query()->where('site_id', $site)->where('status', 'active')->whereJsonContains('protocols', 'tls')->whereIn('collector_id', $collectors()->select('id')),
                'credentials' => CredentialReference::query()->where('site_id', $site)->where('provider', 'hikvision')->where('purpose', 'cctv_read_only')->where('status', 'active')->where('test_status', 'passed')->whereNotIn('rotation_status', ['overdue', 'failed'])->whereJsonContains('capabilities', $capability),
            };
            if ($kind === 'scopes' && $collectorId !== null) {
                $base->where('collector_id', $collectorId);
            }
            $q = $kind === $choice ? $search : '';
            if ($q !== '') {
                $base->whereRaw(($kind === 'credentials' ? 'reference_key' : 'name')." LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%']);
            }
            $context = CctvContract::hash(['actor' => CctvAuthority::fingerprint($actor), 'camera' => $cameraId, 'site' => $site, 'revision' => $revision, 'kind' => $kind, 'q' => $q, 'collector' => $kind === 'scopes' ? $collectorId : null] + ($capability === HikvisionDiscoveryAuthority::CAPABILITY ? [] : ['capability' => $capability]));
            $page = $reads->query($base)->orderBy('id')->cursorPaginate(20, ['*'], 'cursor', $kind === $choice ? $this->cursor($cursor, $context) : '');
            $href = '/security-devices/devices/'.$cameraId.'/live/hikvision/setup?'.http_build_query(['choice' => $kind] + ($q !== '' ? ['q' => $q] : []) + ($kind === 'scopes' && $collectorId !== null ? ['collector_id' => $collectorId] : []), '', '&', PHP_QUERY_RFC3986);
            $link = fn ($next) => $next ? $href.'&cursor='.(new Cursor(['id' => (int) $next->parameter('id'), 'context' => $context], $next->pointsToNextItems()))->encode() : null;
            $choices[$kind] = ['items' => $page->getCollection()->filter(fn ($row) => $kind === 'credentials' || $this->collectorVisible($actor, $site, $kind === 'collectors' ? $row : MonitoringCollector::query()->whereKey($row->collector_id)->lock('for share nowait')->first()))->map(fn ($row) => ['id' => (int) $row->id, 'name' => $kind === 'credentials' ? $row->reference_key : $row->name] + ($kind === 'scopes' ? ['collector_id' => (int) $row->collector_id] : []))->values()->all(), 'limit' => 20, 'next_href' => $link($page->nextCursor()), 'previous_href' => $link($page->previousCursor())];
        }

        return [$choice, $search, $collectorId, $choices];
    }

    private function collectorVisible(User $actor, int $site, ?MonitoringCollector $collector): bool
    {
        try {
            if (! $collector || (int) $collector->site_id !== $site || ! $collector->enrolled_at || $collector->revoked_at || $collector->status === 'revoked') {
                return false;
            }
            [$device, $currentSite] = app(CctvAuthority::class)->collectorDevice($actor, (int) $collector->collector_device_id);

            return $currentSite === $site && ! $device->status->isRetired();
        } catch (HttpExceptionInterface) {
            return false;
        }
    }

    private function cursor(mixed $value, string $context): string|Cursor
    {
        if ($value === null) {
            return '';
        }
        abort_unless(is_string($value) && strlen($value) <= 256 && preg_match('/\A[A-Za-z0-9_-]+\z/', $value), 400, 'invalid_request');
        $cursor = Cursor::fromEncoded($value);
        abort_unless($cursor && $cursor->encode() === $value, 400, 'invalid_request');
        $data = $cursor->toArray();
        CctvContract::exact($data, ['id', 'context', '_pointsToNextItems']);
        abort_unless(is_int($data['id']) && $data['id'] > 0 && is_bool($data['_pointsToNextItems']) && is_string($data['context']) && hash_equals($context, $data['context']), 400, 'invalid_request');

        return new Cursor(['id' => $data['id']], $data['_pointsToNextItems']);
    }
}
