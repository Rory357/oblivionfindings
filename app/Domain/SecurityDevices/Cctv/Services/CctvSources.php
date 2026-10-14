<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CctvLiveSession;
use App\Domain\SecurityDevices\Cctv\Models\CctvRequestReceipt;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CctvSources
{
    public function save(int $actorId, int $cameraId, array $input): array
    {
        CctvContract::sourceInput($input);
        abort_unless($input['expected_actor_id'] === $actorId, 409, 'context_changed');
        $context = DB::transaction(fn () => $this->context($actorId, $cameraId, $input));
        if (isset($context['replay'])) {
            return $context['replay'];
        }
        $version = app(CctvProvider::class)->verify($context['connection'], $input['console_id'], $input['provider_camera_id'], $context['camera']->mac_address, $context['recorder']->mac_address);

        $streamVerified = false;
        try {
            app(CctvProvider::class)->existing($context['connection'], $input);
            $streamVerified = true;
        } catch (HttpExceptionInterface $failure) {
            if ($failure->getStatusCode() !== 503) {
                throw $failure;
            }
        }

        return DB::transaction(function () use ($actorId, $cameraId, $input, $context, $version, $streamVerified): array {
            $current = $this->context($actorId, $cameraId, $input);
            if (isset($current['replay'])) {
                return $current['replay'];
            }
            abort_unless(hash_equals($context['fingerprint'], $current['fingerprint']), 409, 'context_changed');
            $configuration = array_intersect_key($input, array_flip(['console_id', 'provider_camera_id', 'stream_address', 'stream_port', 'tls_server_name', 'tls_pin']));
            $configuration += ['application_version' => $version['application_version'], 'recorder_provider_id' => $version['recorder_provider_id'], 'camera_mac' => $current['camera']->mac_address, 'recorder_mac' => $current['recorder']->mac_address, 'collector_key' => $current['collector']->public_key, 'collector_certificate' => $current['collector']->client_certificate_fingerprint];
            CctvSource::query()->where('camera_id', $cameraId)->where('enabled', true)->update(['enabled' => false]);
            $source = CctvSource::query()->create([
                'provider' => 'unifi', 'camera_id' => $cameraId, 'recorder_device_id' => $input['recorder_device_id'], 'site_id' => $current['site'],
                'collector_id' => $input['collector_id'], 'discovery_scope_id' => $input['discovery_scope_id'], 'actor_id' => $actorId,
                'revision' => $input['expected_revision'] + 1, 'enabled' => true, 'stream_state' => $streamVerified ? 'verified' : 'unavailable', 'stream_verified_at' => $streamVerified ? now() : null, 'fingerprint' => CctvContract::hash($configuration), 'provider_fingerprint' => $current['provider_fingerprint'], 'configuration' => $configuration, 'verified_at' => now(),
            ]);
            $this->invalidate($cameraId);
            AuditLogger::logOrFail('cctv.source.verified', $current['camera'], ['actor_id' => $actorId, 'site_id' => $current['site'], 'recorder_device_id' => $input['recorder_device_id'], 'revision' => $source->revision, 'purpose' => $input['purpose']]);
            $response = ['actor_id' => $actorId, 'camera_id' => $cameraId, 'source' => $this->projection($source)];
            CctvRequestReceipt::query()->create(['owner' => 'source:'.$actorId.':'.$cameraId, 'request_key' => $input['request_key'], 'input_hash' => CctvContract::hash($input), 'response' => $response]);

            return $response;
        });
    }

    private function context(int $actorId, int $cameraId, array $input): array
    {
        $authority = app(CctvAuthority::class);
        $actor = $authority->actor($actorId, true);
        // The camera serializes revision writers and their private request receipts.
        Device::query()->whereKey($cameraId)->lockForUpdate()->firstOrFail();
        [$camera, $site] = $authority->device($actor, $cameraId);
        $receipt = CctvRequestReceipt::query()->where('owner', 'source:'.$actorId.':'.$cameraId)->where('request_key', $input['request_key'])->lockForUpdate()->first();
        if ($receipt) {
            abort_unless(hash_equals($receipt->input_hash, CctvContract::hash($input)), 409, 'context_changed');

            return ['replay' => $this->currentReceipt($actor, $cameraId, $receipt)];
        }
        [$recorder, $recorderSite] = $authority->device($actor, $input['recorder_device_id'], true);
        abort_unless($site === $recorderSite && $camera->provider === 'unifi' && $recorder->provider === 'unifi' && data_get($camera->external_ref, 'host_id') === $input['console_id'] && data_get($recorder->external_ref, 'host_id') === $input['console_id'], 404, 'unavailable');
        $latest = CctvSource::query()->where('camera_id', $cameraId)->orderByDesc('revision')->lockForUpdate()->first();
        abort_unless(($latest ? (int) $latest->revision : 0) === $input['expected_revision'], 409, 'context_changed');
        $collector = MonitoringCollector::query()->whereKey($input['collector_id'])->lock('for share nowait')->first();
        $scope = DiscoveryScope::query()->whereKey($input['discovery_scope_id'])->lock('for share nowait')->first();
        abort_unless($collector && $collector->enrolled_at && ! $collector->revoked_at && $collector->status !== 'revoked' && (int) $collector->site_id === $site && $scope && $scope->status === 'active' && (int) $scope->site_id === $site && (int) $scope->collector_id === (int) $collector->id, 404, 'unavailable');
        [, $collectorSite] = $authority->collectorDevice($actor, (int) $collector->collector_device_id);
        abort_unless($collectorSite === $site, 404, 'unavailable');
        abort_unless(CctvContract::network($scope->cidrs ?? [], $scope->exclusions ?? [], $scope->port_bounds ?? [], $input['stream_address'], $input['stream_port']), 404, 'unavailable');
        [$connection, $providerFingerprint] = $authority->provider($site, $input['console_id']);
        $fingerprint = CctvContract::hash([CctvAuthority::fingerprint($actor), $camera->getRawOriginal(), $recorder->getRawOriginal(), $collector->getRawOriginal(), $scope->getRawOriginal(), $site, $providerFingerprint]);

        return ['camera' => $camera, 'recorder' => $recorder, 'site' => $site, 'collector' => $collector, 'connection' => $connection, 'provider_fingerprint' => $providerFingerprint, 'fingerprint' => $fingerprint];
    }

    public function action(int $actorId, int $cameraId, array $input, bool $enable): array
    {
        CctvContract::exact($input, ['expected_actor_id', 'expected_revision', 'request_key', 'purpose']);
        foreach (['expected_actor_id', 'expected_revision'] as $key) {
            CctvContract::positive($input[$key]);
        }
        CctvContract::uuid($input['request_key']);
        CctvContract::text($input['purpose'], 10, 500);
        abort_unless($input['expected_actor_id'] === $actorId, 409, 'context_changed');
        $owner = 'source-action:'.$actorId.':'.$cameraId.':'.($enable ? 'enable' : 'disable');
        $context = DB::transaction(function () use ($actorId, $cameraId, $input, $enable, $owner): array {
            $actor = app(CctvAuthority::class)->actor($actorId, true);
            Device::query()->whereKey($cameraId)->lockForUpdate()->firstOrFail();
            [$camera] = app(CctvAuthority::class)->device($actor, $cameraId);
            $receipt = CctvRequestReceipt::query()->where('owner', $owner)->where('request_key', $input['request_key'])->lockForUpdate()->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->input_hash, CctvContract::hash($input)), 409, 'context_changed');
                if ($receipt->response !== null) {
                    return ['replay' => $this->currentReceipt($actor, $cameraId, $receipt)];
                }
                abort_unless($enable, 409, 'context_changed');
            }
            $source = CctvSource::query()->where('camera_id', $cameraId)->where('revision', $input['expected_revision'])->lockForUpdate()->first();
            abort_unless($source && $source->enabled, 409, 'context_changed');
            [, , , $connection] = app(CctvAuthority::class)->source($actor, $source);
            $fingerprint = CctvContract::hash([CctvAuthority::fingerprint($actor), $source->fingerprint, $source->provider_fingerprint]);
            $recovering = $receipt !== null;
            if ($recovering) {
                abort_unless(is_string($receipt->context_fingerprint) && hash_equals($receipt->context_fingerprint, $fingerprint), 409, 'context_changed');
            } else {
                $receipt = CctvRequestReceipt::query()->create(['owner' => $owner, 'request_key' => $input['request_key'], 'input_hash' => CctvContract::hash($input), 'context_fingerprint' => $fingerprint, 'response' => null]);
                AuditLogger::logOrFail($enable ? 'cctv.source.stream_enable_requested' : 'cctv.source.withdrawal_requested', $camera, ['actor_id' => $actorId, 'revision' => $source->revision, 'purpose' => $input['purpose']]);
            }
            if (! $enable) {
                $source->enabled = false;
                $source->save();
                $this->invalidate($cameraId);
                $response = ['actor_id' => $actorId, 'camera_id' => $cameraId, 'source' => $this->projection($source)];
                $receipt->response = $response;
                $receipt->save();
                AuditLogger::logOrFail('cctv.source.withdrawn', $camera, ['actor_id' => $actorId, 'revision' => $source->revision, 'purpose' => $input['purpose']]);

                return ['replay' => $response];
            }

            return ['source' => $source, 'connection' => $connection, 'actor_fingerprint' => CctvAuthority::fingerprint($actor), 'receipt_id' => $receipt->id, 'recovering' => $recovering];
        });
        if (isset($context['replay'])) {
            return $context['replay'];
        }
        if (! $context['recovering']) {
            app(CctvProvider::class)->enable($context['connection'], $context['source']->configuration);
        }
        // Recovery only reads the exact existing stream; a pending receipt never repeats POST.
        app(CctvProvider::class)->existing($context['connection'], $context['source']->configuration);

        return DB::transaction(function () use ($actorId, $cameraId, $input, $context): array {
            $actor = app(CctvAuthority::class)->actor($actorId, true);
            Device::query()->whereKey($cameraId)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($context['actor_fingerprint'], CctvAuthority::fingerprint($actor)), 409, 'context_changed');
            $source = CctvSource::query()->whereKey($context['source']->id)->lockForUpdate()->firstOrFail();
            app(CctvAuthority::class)->source($actor, $source);
            abort_unless(hash_equals($context['source']->fingerprint, $source->fingerprint) && (int) $source->revision === $input['expected_revision'], 409, 'context_changed');
            $source->stream_state = 'verified';
            $source->stream_verified_at = now();
            $source->save();
            $response = ['actor_id' => $actorId, 'camera_id' => $cameraId, 'source' => $this->projection($source)];
            $receipt = CctvRequestReceipt::query()->whereKey($context['receipt_id'])->lockForUpdate()->firstOrFail();
            $receipt->response = $response;
            $receipt->save();
            AuditLogger::logOrFail($context['recovering'] ? 'cctv.source.stream_enable_recovered' : 'cctv.source.stream_enabled', Device::query()->findOrFail($cameraId), ['actor_id' => $actorId, 'revision' => $source->revision, 'purpose' => $input['purpose']]);

            return $response;
        });
    }

    public function setup(int $actorId, int $cameraId, array $query): array
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actorId, $cameraId, $query): array {
            $actor = app(CctvAuthority::class)->actor($actorId, true);
            [$camera, $site] = app(CctvAuthority::class)->device($actor, $cameraId);
            $source = CctvSource::query()->where('camera_id', $cameraId)->orderByDesc('revision')->lock('for share nowait')->first();
            $revision = $source ? (int) $source->revision : 0;
            if ($source) {
                try {
                    app(CctvAuthority::class)->source($actor, $source, true);
                } catch (HttpExceptionInterface) {
                    $source = null;
                }
            }
            abort_unless(array_diff(array_keys($query), ['choice', 'cursor', 'q', 'collector_id']) === [], 400, 'invalid_request');
            $choice = $query['choice'] ?? null;
            $cursor = $query['cursor'] ?? null;
            $q = $query['q'] ?? '';
            abort_unless(is_string($q) && mb_check_encoding($q, 'UTF-8'), 400, 'invalid_request');
            $q = trim($q);
            CctvContract::text($q, 0, 200);
            abort_if($q !== '' && $choice === null, 400, 'invalid_request');
            abort_unless($choice === null || in_array($choice, ['recorders', 'collectors', 'scopes'], true), 400, 'invalid_request');
            abort_if($cursor !== null && $choice === null, 400, 'invalid_request');
            $collectorId = $query['collector_id'] ?? null;
            if ($collectorId !== null) {
                abort_unless($choice === 'scopes' && is_string($collectorId) && preg_match('/\A[1-9][0-9]{0,18}\z/', $collectorId) && (string) (int) $collectorId === $collectorId, 400, 'invalid_request');
                $collectorId = (int) $collectorId;
                $collector = MonitoringCollector::query()->whereKey($collectorId)->where('site_id', $site)->whereNull('revoked_at')->whereNotNull('enrolled_at')->lock('for share nowait')->first();
                abort_unless($collector && $collector->status !== 'revoked', 404, 'unavailable');
                [, $collectorSite] = app(CctvAuthority::class)->collectorDevice($actor, (int) $collector->collector_device_id);
                abort_unless($collectorSite === $site, 404, 'unavailable');
            }
            $result = [];
            foreach (['recorders', 'collectors', 'scopes'] as $kind) {
                $base = match ($kind) {
                    'recorders' => app(SecurityDevicesAccessService::class)->visibleDevicesForSite($actor, $site)->where('domain', 'security')->where('category', 'cctv')->where('subcategory', 'nvr')->where('provider', 'unifi'),
                    'collectors' => MonitoringCollector::query()->where('site_id', $site)->whereNull('revoked_at')->where('status', '!=', 'revoked')->whereNotNull('enrolled_at')->whereIn('collector_device_id', app(SecurityDevicesAccessService::class)->visibleDevices($actor)->select('devices.id')),
                    'scopes' => DiscoveryScope::query()->where('site_id', $site)->where('status', 'active')->whereIn('collector_id', MonitoringCollector::query()->where('site_id', $site)->whereNull('revoked_at')->where('status', '!=', 'revoked')->whereNotNull('enrolled_at')->whereIn('collector_device_id', app(SecurityDevicesAccessService::class)->visibleDevices($actor)->select('devices.id'))->select('id')),
                };
                if ($kind === 'scopes' && $collectorId !== null) {
                    $base->where('collector_id', $collectorId);
                }
                $search = $kind === $choice ? $q : '';
                if ($search !== '') {
                    $base->whereRaw("name LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%']);
                }
                $queryHash = CctvContract::hash(['actor_id' => $actorId, 'camera_id' => $cameraId, 'choice' => $kind, 'q' => $search, 'collector_id' => $kind === 'scopes' ? $collectorId : null]);
                $page = $reads->query($base)->orderBy('id')->cursorPaginate(20, ['*'], 'cursor', $kind === $choice ? $this->cursor($cursor, $queryHash) : '');
                $href = '/security-devices/devices/'.$cameraId.'/live/source?choice='.$kind.($search !== '' ? '&q='.rawurlencode($search) : '').($kind === 'scopes' && $collectorId !== null ? '&collector_id='.$collectorId : '');
                $result[$kind] = ['items' => $page->getCollection()->filter(fn ($row) => $this->choiceVisible($actor, $site, $kind, $row))->map(fn ($row) => match ($kind) {
                    'recorders' => ['id' => (int) $row->id, 'name' => $row->name],
                    'collectors' => ['id' => (int) $row->id, 'name' => $row->name],
                    'scopes' => ['id' => (int) $row->id, 'name' => $row->name, 'collector_id' => $row->collector_id === null ? null : (int) $row->collector_id],
                })->values()->all(), 'limit' => 20, 'next_href' => $page->nextCursor() ? $href.'&cursor='.$this->encodeCursor($page->nextCursor(), $queryHash) : null, 'previous_href' => $page->previousCursor() ? $href.'&cursor='.$this->encodeCursor($page->previousCursor(), $queryHash) : null];
            }

            return ['actor_id' => $actorId, 'camera_id' => $cameraId, 'camera' => ['id' => $cameraId, 'name' => $camera->name, 'site' => ['id' => $site, 'name' => Site::query()->findOrFail($site)->name]], 'revision' => $revision, 'source' => $source ? $this->projection($source) : null, 'choices' => $result, 'choice' => $choice, 'q' => $q, 'collector_id' => $collectorId];
        }));
    }

    private function choiceVisible(User $actor, int $site, string $kind, object $row): bool
    {
        try {
            if ($kind === 'recorders') {
                [, $currentSite] = app(CctvAuthority::class)->device($actor, (int) $row->id, true);
            } else {
                $collector = $kind === 'collectors' ? $row : MonitoringCollector::query()->whereKey($row->collector_id)->lock('for share nowait')->first();
                if (! $collector || $collector->revoked_at || $collector->status === 'revoked' || ! $collector->enrolled_at || (int) $collector->site_id !== $site) {
                    return false;
                }
                [, $currentSite] = app(CctvAuthority::class)->collectorDevice($actor, (int) $collector->collector_device_id);
            }

            return $currentSite === $site;
        } catch (HttpExceptionInterface) {
            return false;
        }
    }

    private function cursor(mixed $value, string $queryHash): string|Cursor
    {
        if ($value === null) {
            return '';
        }
        abort_unless(is_string($value) && strlen($value) <= 256 && preg_match('/\A[A-Za-z0-9_-]+\z/', $value), 400, 'invalid_request');
        $cursor = Cursor::fromEncoded($value);
        abort_unless($cursor && $cursor->encode() === $value, 400, 'invalid_request');
        $parameters = $cursor->toArray();
        $keys = array_keys($parameters);
        sort($keys);
        abort_unless($keys === ['_pointsToNextItems', 'id', 'q_sha256'] && is_string($parameters['q_sha256']) && hash_equals($queryHash, $parameters['q_sha256']) && is_int($parameters['id']) && $parameters['id'] > 0 && is_bool($parameters['_pointsToNextItems']), 400, 'invalid_request');

        return new Cursor(['id' => $parameters['id']], $parameters['_pointsToNextItems']);
    }

    private function encodeCursor(Cursor $cursor, string $queryHash): string
    {
        return (new Cursor(['id' => (int) $cursor->parameter('id'), 'q_sha256' => $queryHash], $cursor->pointsToNextItems()))->encode();
    }

    private function currentReceipt(User $actor, int $cameraId, CctvRequestReceipt $receipt): array
    {
        $source = CctvSource::query()->where('camera_id', $cameraId)->where('revision', $receipt->response['source']['revision'])->lock('for share nowait')->firstOrFail();
        app(CctvAuthority::class)->source($actor, $source, true);

        return ['actor_id' => (int) $actor->id, 'camera_id' => $cameraId, 'source' => $this->projection($source)];
    }

    public function projection(CctvSource $source): array
    {
        $config = $source->configuration;
        abort_unless(is_array($config) && hash_equals($source->fingerprint, CctvContract::hash($config)), 409, 'context_changed');

        return ['revision' => (int) $source->revision, 'enabled' => (bool) $source->enabled, 'stream_state' => $source->stream_state, 'stream_verified_at' => $source->stream_verified_at?->utc()->format('Y-m-d\TH:i:s\Z'), 'recorder_name' => Device::query()->whereKey($source->recorder_device_id)->lock('for share nowait')->firstOrFail()->name, 'collector_name' => MonitoringCollector::query()->whereKey($source->collector_id)->lock('for share nowait')->firstOrFail()->name, 'discovery_scope_name' => DiscoveryScope::query()->whereKey($source->discovery_scope_id)->lock('for share nowait')->firstOrFail()->name, 'recorder_device_id' => (int) $source->recorder_device_id, 'collector_id' => (int) $source->collector_id, 'discovery_scope_id' => (int) $source->discovery_scope_id, 'console_id' => $config['console_id'], 'provider_camera_id' => $config['provider_camera_id'], 'stream_address' => $config['stream_address'], 'stream_port' => $config['stream_port'], 'tls_server_name' => $config['tls_server_name'], 'tls_pin' => $config['tls_pin'], 'application_version' => $config['application_version'], 'verified_at' => $source->verified_at->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    private function invalidate(int $cameraId): void
    {
        CctvLiveSession::query()->where('camera_id', $cameraId)->whereNotIn('status', CctvContract::TERMINAL)->lockForUpdate()->chunkById(100, function ($sessions): void {
            foreach ($sessions as $session) {
                app(CctvSessions::class)->end($session, 'revoked', 'source_changed');
            }
        });
    }
}
