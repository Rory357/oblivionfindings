<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\SecurityDevices\Cctv\Models\CctvRecordingReference;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CctvRecordingReferences
{
    public const VIEW = 'securityDevices.cctv.recordingReferences.view';

    public const CREATE = 'securityDevices.cctv.recordingReferences.create';

    public const RECOVERY = '/security-devices/cctv/recording-references/requests';

    public const LIMIT = 20;

    public function page(Request $request, int $cameraId): array
    {
        return DB::transaction(function () use ($request, $cameraId): array {
            $actor = $this->actor($request);
            [$camera, $siteId] = app(CctvAuthority::class)->referenceDevice($actor, $cameraId);
            $scope = hash('sha256', $actor->id.':'.$cameraId.':'.$siteId);
            $cursor = CctvRecordingReferenceInput::cursor($request->query(), $scope);
            $visibleRecorders = app(SecurityDevicesAccessService::class)->visibleDevicesForSite($actor, $siteId)->where('devices.status', '!=', 'decommissioned')->select('devices.id');
            $query = CctvRecordingReference::query()->where('camera_id', $cameraId)->where('site_id', $siteId)->whereIn('recorder_device_id', $visibleRecorders)->orderBy('id');
            $page = $query->cursorPaginate(self::LIMIT, ['*'], 'cursor', $cursor ?? '');
            $rows = $page->getCollection();
            $items = [];
            foreach ($rows as $row) {
                try {
                    $dto = $this->present($actor, $row);
                    $reference = $dto['reference'];
                    $items[] = ['reference_uuid' => $reference['reference_uuid'], 'requested_start_at' => $reference['requested_start_at'], 'requested_end_at' => $reference['requested_end_at'], 'created_at' => $reference['created_at'], 'source_revision' => $reference['source_revision'], 'recorder' => $dto['recorder'], 'state' => 'reference_only', 'preserved' => false, 'reader_href' => $reference['reader_href'], 'current_source' => $dto['current_source']];
                } catch (HttpExceptionInterface $exception) {
                    if ($exception->getStatusCode() !== 404) {
                        throw $exception;
                    }
                }
            }
            [$source, $current] = $this->current($actor, $cameraId);
            $sourceDto = null;
            if ($source) {
                try {
                    [, $recorder] = app(CctvAuthority::class)->referenceSource($actor, $source, false);
                    $sourceDto = ['revision' => (int) $source->revision, 'recorder_device_id' => (int) $recorder->id, 'recorder_name' => $recorder->name, 'protect_version' => $source->configuration['application_version'], 'source_verified_at' => $this->time($source->verified_at)];
                } catch (HttpExceptionInterface) {
                    // No source identities are disclosed through a restricted recorder.
                }
            }
            $canCreate = $actor->canDo(self::CREATE) && $sourceDto !== null && $current['state'] === 'ready';
            $base = '/security-devices/devices/'.$cameraId.'/recording-references';
            $link = fn ($position): ?string => $position ? $base.'?cursor='.rawurlencode((new Cursor(['id' => (int) $position->parameter('id'), 'scope_sha256' => $scope], $position->pointsToNextItems()))->encode()) : null;

            return ['actor_id' => (int) $actor->id, 'server_now' => $this->time(CarbonImmutable::now('UTC')), 'camera' => $this->camera($camera, $siteId), 'source' => $sourceDto, 'current_source' => $current, 'window_limits' => ['max_seconds' => $this->maximum(), 'operational_policy_applied' => false], 'permissions' => ['can_view' => true, 'can_create' => $canCreate], 'create_href' => $canCreate ? $base : null, 'request_recovery_base_href' => self::RECOVERY, 'references' => ['items' => $items, 'limit' => self::LIMIT, 'previous_href' => $link($page->previousCursor()), 'next_href' => $link($page->nextCursor())]];
        });
    }

    public function create(Request $request, int $cameraId, array $input): array
    {
        // Validate immutable fields first, but known exact receipts are resolved before
        // new-source expectations and time-dependent policy checks are applied.
        CctvContract::exact($input, ['expected_actor_id', 'request_key', 'expected_source_revision', 'requested_start_at', 'requested_end_at', 'purpose']);
        CctvContract::positive($input['expected_actor_id']);
        CctvContract::uuid($input['request_key']);
        $hash = CctvContract::hash(['camera_id' => $cameraId, 'input' => $this->orderedInput($input)]);

        return DB::transaction(function () use ($request, $cameraId, $input, $hash): array {
            $actor = $this->actor($request);
            abort_unless((int) $actor->id === $input['expected_actor_id'] && $actor->canDo(self::CREATE), 404, 'unavailable');
            $known = CctvRecordingReference::query()->where('actor_id', $actor->id)->where('request_key', $input['request_key'])->lock('for share nowait')->first();
            if ($known) {
                abort_unless(hash_equals($known->input_hash, $hash), 409, 'context_changed');

                return $this->present($actor, $known);
            }
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            CctvRecordingReferenceInput::create($input, $now, $this->maximum());
            app(CctvAuthority::class)->referenceDevice($actor, $cameraId);
            $source = CctvSource::query()->where('camera_id', $cameraId)->orderByDesc('revision')->lock('for share nowait')->first();
            abort_unless($source && (int) $source->revision === $input['expected_source_revision'], 409, 'context_changed');
            [$camera, $recorder, $siteId] = app(CctvAuthority::class)->referenceSource($actor, $source);
            $configuration = $source->configuration;
            $uuid = (string) Str::uuid();
            $metadata = ['schema_version' => 1, 'reference_uuid' => $uuid, 'camera_id' => $cameraId, 'recorder_device_id' => (int) $recorder->id, 'site_id' => $siteId, 'provider' => 'unifi', 'console_id' => $configuration['console_id'], 'provider_camera_id' => $configuration['provider_camera_id'], 'provider_recorder_id' => CctvContract::providerId($configuration['recorder_provider_id']), 'source_revision' => (int) $source->revision, 'protect_version' => $configuration['application_version'], 'requested_start_at' => $input['requested_start_at'], 'requested_end_at' => $input['requested_end_at'], 'created_at' => $this->time($now), 'source_verified_at' => $this->time($source->verified_at), 'recording_verified_at' => null, 'vendor_retention_until' => null, 'state' => 'reference_only', 'preserved' => false, 'binding_basis' => 'reviewed_source_revision', 'window_coverage' => 'not_verified', 'created_by' => ['id' => (int) $actor->id, 'name' => $actor->name], 'purpose' => $input['purpose'], 'reader_href' => $this->href($uuid)];
            $reference = CctvRecordingReference::query()->create(['uuid' => $uuid, 'source_id' => $source->id, 'camera_id' => $cameraId, 'recorder_device_id' => $recorder->id, 'site_id' => $siteId, 'actor_id' => $actor->id, 'request_key' => $input['request_key'], 'input_hash' => $hash, 'metadata_hash' => CctvContract::hash($metadata), 'metadata' => $metadata, 'created_at' => $now]);
            AuditLogger::logOrFail('cctv.recordingReference.created', $camera, ['actor_id' => (int) $actor->id, 'reference_uuid' => $uuid, 'source_revision' => (int) $source->revision, 'site_id' => $siteId, 'recorder_device_id' => (int) $recorder->id, 'requested_start_at' => $input['requested_start_at'], 'requested_end_at' => $input['requested_end_at'], 'metadata_hash' => $reference->metadata_hash]);

            return $this->present($actor, $reference);
        });
    }

    public function resolve(Request $request, string $uuid): array
    {
        CctvContract::uuid($uuid);
        CctvContract::exact($request->query(), []);

        return DB::transaction(function () use ($request, $uuid): array {
            $actor = $this->actor($request);
            $reference = CctvRecordingReference::query()->where('uuid', $uuid)->lock('for share nowait')->first();
            abort_unless($reference, 404, 'unavailable');

            return $this->present($actor, $reference);
        });
    }

    public function recover(Request $request, string $key): array
    {
        CctvContract::uuid($key);
        CctvContract::exact($request->query(), ['expected_actor_id']);
        $expected = $request->query('expected_actor_id');
        if (! is_string($expected) || preg_match('/\A[1-9][0-9]{0,18}\z/', $expected) !== 1 || filter_var($expected, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('invalid_request');
        }

        return DB::transaction(function () use ($request, $key, $expected): array {
            $actor = $this->actor($request);
            abort_unless((int) $actor->id === (int) $expected, 404, 'unavailable');
            $reference = CctvRecordingReference::query()->where('actor_id', $actor->id)->where('request_key', $key)->lock('for share nowait')->first();
            abort_unless($reference, 404, 'unavailable');

            return $this->present($actor, $reference);
        });
    }

    private function actor(Request $request): User
    {
        $actor = app(CctvAuthority::class)->actor((int) $request->user()->id, false, true);
        abort_unless($actor->canDo(self::VIEW), 404, 'unavailable');

        return $actor;
    }

    private function present(User $actor, CctvRecordingReference $reference): array
    {
        [$camera, $siteId] = app(CctvAuthority::class)->referenceDevice($actor, (int) $reference->camera_id);
        [$recorder, $recorderSite] = app(CctvAuthority::class)->referenceDevice($actor, (int) $reference->recorder_device_id, true);
        abort_unless($siteId === (int) $reference->site_id && $recorderSite === $siteId, 404, 'unavailable');
        $metadata = $reference->metadata;
        abort_unless(is_array($metadata) && hash_equals($reference->metadata_hash, CctvContract::hash($metadata)) && $metadata['reference_uuid'] === $reference->uuid && $metadata['camera_id'] === (int) $reference->camera_id && $metadata['recorder_device_id'] === (int) $reference->recorder_device_id && $metadata['site_id'] === $siteId, 404, 'unavailable');
        [, $current] = $this->current($actor, (int) $camera->id, (int) $metadata['source_revision']);

        return ['actor_id' => (int) $actor->id, 'server_now' => $this->time(CarbonImmutable::now('UTC')), 'camera' => $this->camera($camera, $siteId), 'recorder' => ['id' => (int) $recorder->id, 'name' => $recorder->name], 'reference' => $metadata, 'current_source' => $current, 'permissions' => ['can_view' => true, 'can_create' => $actor->canDo(self::CREATE) && $current['state'] === 'ready']];
    }

    /** No worker health, TURN or stream locator is read by reference resolution. */
    private function current(User $actor, int $cameraId, ?int $capturedRevision = null): array
    {
        $source = CctvSource::query()->where('camera_id', $cameraId)->orderByDesc('revision')->lock('for share nowait')->first();
        $state = ['state' => 'unavailable', 'reason_code' => 'source_unavailable', 'revision' => null, 'source_verified_at' => null];
        if (! $source) {
            return [null, $state];
        }
        try {
            app(CctvAuthority::class)->referenceSource($actor, $source, false);
            $state['revision'] = (int) $source->revision;
            $state['source_verified_at'] = $this->time($source->verified_at);
            if ($capturedRevision !== null && $capturedRevision !== (int) $source->revision) {
                $state['state'] = 'changed';
                $state['reason_code'] = 'source_changed';
            } elseif (! $source->enabled) {
                $state['state'] = 'withdrawn';
                $state['reason_code'] = 'source_withdrawn';
            } else {
                app(CctvAuthority::class)->referenceSource($actor, $source);
                $state['state'] = 'ready';
                $state['reason_code'] = null;
            }
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 409) {
                $state['state'] = 'changed';
                $state['reason_code'] = 'source_changed';
            }
        }

        return [$source, $state];
    }

    private function camera($camera, int $site): array
    {
        return ['id' => (int) $camera->id, 'name' => $camera->name, 'site' => ['id' => $site, 'name' => Site::query()->whereKey($site)->firstOrFail()->name]];
    }

    private function orderedInput(array $input): array
    {
        $ordered = [];
        foreach (['expected_actor_id', 'request_key', 'expected_source_revision', 'requested_start_at', 'requested_end_at', 'purpose'] as $key) {
            $ordered[$key] = $input[$key];
        }

        return $ordered;
    }

    private function maximum(): int
    {
        return max(60, min(86400, (int) config('cctv.reference_max_window_seconds', 3600)));
    }

    private function time($time): ?string
    {
        return $time?->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function href(string $uuid): string
    {
        return '/security-devices/cctv/recording-references/'.$uuid;
    }
}
