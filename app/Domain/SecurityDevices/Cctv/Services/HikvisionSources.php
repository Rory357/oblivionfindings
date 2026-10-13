<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\SecurityDevices\Cctv\Models\CctvLiveSession;
use App\Domain\SecurityDevices\Cctv\Models\CctvRequestReceipt;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Cctv\Models\HikvisionDiscoveryRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Canonical reviewed source selection. It does not enable live media or change
 * camera/recorder settings; the production media path remains separately gated. */
final class HikvisionSources
{
    private const INPUT = ['expected_actor_id', 'request_key', 'expected_revision', 'discovery_uuid', 'expected_result_sha256', 'channel_id', 'transport_profile', 'purpose'];

    public function save(int $actorId, int $cameraId, array $input): array
    {
        CctvContract::exact($input, self::INPUT);
        CctvContract::positive($input['expected_actor_id']);
        CctvContract::positive($input['expected_revision'], true);
        CctvContract::uuid($input['request_key']);
        CctvContract::uuid($input['discovery_uuid']);
        CctvContract::text($input['purpose'], 5, 2000);
        abort_unless(is_string($input['channel_id']) && preg_match('/\A[1-9][0-9]{0,8}\z/', $input['channel_id']) && is_string($input['transport_profile']) && $input['transport_profile'] === HikvisionSourcePolicy::PROFILE && is_string($input['expected_result_sha256']) && preg_match('/\A[0-9a-f]{64}\z/', $input['expected_result_sha256']), 400, 'invalid_request');
        $ordered = [];
        foreach (self::INPUT as $key) {
            $ordered[$key] = $input[$key];
        }
        $hash = CctvContract::hash(['camera_id' => $cameraId, 'input' => $ordered]);

        return DB::transaction(function () use ($actorId, $cameraId, $input, $hash): array {
            User::query()->whereKey($actorId)->lock('for update nowait')->firstOrFail();
            app(CctvAuthority::class)->actor($actorId, true);
            abort_unless($actorId === $input['expected_actor_id'], 404, 'unavailable');
            Device::query()->whereKey($cameraId)->lockForUpdate()->firstOrFail();
            $known = $this->receipt($actorId, $cameraId, $input['request_key']);
            if ($known) {
                abort_unless(hash_equals($known->input_hash, $hash), 409, 'context_changed');

                return $this->known($actorId, $cameraId, $known);
            }
            abort_unless(config('cctv.hikvision_mapping_enabled') === true, 503, 'temporarily_unavailable');
            $request = $this->request($actorId, $cameraId, $input['discovery_uuid']);
            $current = app(HikvisionDiscoveryAuthority::class)->request($request);
            abort_unless($current['device']->subcategory !== 'nvr' && $input['expected_revision'] === (int) $request->source_revision, 409, 'context_changed');
            abort_unless($request->status === 'observed' && $request->result_hash !== null && hash_equals($request->result_hash, $input['expected_result_sha256']) && hash_equals($request->result_hash, CctvContract::hash($request->result)), 409, 'context_changed');
            try {
                $this->supported($request->result['inventory']['identity']);
                $selection = HikvisionSourcePolicy::select($request->result, ['model' => $current['device']->model, 'serial' => $current['device']->serial_number], $input['channel_id'], $input['transport_profile'], config('cctv.hikvision_supported_profiles'), now()->toDateTimeImmutable());
            } catch (\InvalidArgumentException) {
                abort(409, 'source_unavailable');
            }
            $configuration = ['version' => 1, 'kind' => 'hikvision_direct_camera', 'discovery_uuid' => $request->uuid, 'result_sha256' => $request->result_hash, 'discovery_configuration' => $request->configuration, 'binding_sha256' => $current['binding'], 'credential_sha256' => $current['credential'], 'identity' => $selection['identity'], 'channel' => $selection['channel'], 'transport_profile' => $selection['transport_profile'], 'support_sha256' => $selection['support_sha256'], 'video_codec' => 'H264', 'audio_enabled' => false, 'observed_at' => $request->result['observed_at']];
            $source = CctvSource::query()->create(['provider' => 'hikvision', 'discovery_request_id' => $request->id, 'camera_id' => $cameraId, 'recorder_device_id' => null, 'site_id' => $current['site'], 'collector_id' => $current['collector']->id, 'discovery_scope_id' => $request->discovery_scope_id, 'actor_id' => $actorId, 'revision' => $input['expected_revision'] + 1, 'enabled' => false, 'stream_state' => 'not_requested', 'stream_verified_at' => null, 'fingerprint' => CctvContract::hash($configuration), 'provider_fingerprint' => $current['credential'], 'configuration' => $configuration, 'verified_at' => $request->observed_at]);
            // Selection is immutable and inactive. A later verified activation has
            // its own action receipt; replacement never silently withdraws media.
            AuditLogger::logOrFail('cctv.hikvision.source_mapped', $current['device'], ['actor_id' => $actorId, 'after' => ['state' => 'mapped']]);
            $receipt = CctvRequestReceipt::query()->create(['owner' => $this->owner($actorId, $cameraId), 'request_key' => $input['request_key'], 'input_hash' => $hash, 'response' => ['source_id' => (int) $source->id], 'expires_at' => null]);

            return $this->known($actorId, $cameraId, $receipt);
        });
    }

    public function action(int $actorId, int $cameraId, array $input, bool $enable): array
    {
        CctvContract::exact($input, ['expected_actor_id', 'expected_revision', 'request_key', 'purpose']);
        CctvContract::positive($input['expected_actor_id']);
        CctvContract::positive($input['expected_revision']);
        CctvContract::uuid($input['request_key']);
        CctvContract::text($input['purpose'], 10, 500);
        abort_unless($actorId === $input['expected_actor_id'], 404, 'unavailable');

        return DB::transaction(function () use ($actorId, $cameraId, $input, $enable): array {
            User::query()->whereKey($actorId)->lock('for update nowait')->firstOrFail();
            $actor = app(CctvAuthority::class)->actor($actorId, true);
            Device::query()->whereKey($cameraId)->lockForUpdate()->firstOrFail();
            [, $site] = app(CctvAuthority::class)->device($actor, $cameraId);
            $owner = 'hikvision_source_action:'.$actorId.':'.$cameraId;
            $hash = CctvContract::hash(['enable' => $enable, 'input' => $input]);
            $known = CctvRequestReceipt::query()->where('owner', $owner)->where('request_key', $input['request_key'])->lockForUpdate()->first();
            if ($known) {
                abort_unless(hash_equals($known->input_hash, $hash), 409, 'context_changed');
                $source = CctvSource::query()->whereKey($known->response['source_id'])->where('camera_id', $cameraId)->where('site_id', $site)->lockForUpdate()->firstOrFail();
            } else {
                $source = CctvSource::query()->where('camera_id', $cameraId)->where('provider', 'hikvision')->where('revision', $input['expected_revision'])->where('site_id', $site)->lockForUpdate()->first();
                abort_unless($source, 409, 'context_changed');
                if ($enable) {
                    [, , $collector] = app(HikvisionLiveAuthority::class)->source($actor, $source, false, true);
                    app(CctvSessions::class)->readiness((int) $collector->id, $source);
                    abort_if(CctvSource::query()->where('camera_id', $cameraId)->where('enabled', true)->whereKeyNot($source->id)->exists(), 409, 'active_source_exists');
                }
                $source->enabled = $enable;
                $source->save();
                if (! $enable) {
                    $sessions = CctvLiveSession::query()->where('source_id', $source->id)->whereNotIn('status', CctvContract::TERMINAL)->orderBy('id')->lockForUpdate()->get();
                    foreach ($sessions as $session) {
                        app(CctvSessions::class)->end($session, 'revoked', 'authority_lost');
                    }
                }
                AuditLogger::logOrFail('cctv.hikvision.source_'.($enable ? 'enabled' : 'disabled'), Device::query()->findOrFail($cameraId), ['actor_id' => $actorId, 'purpose' => $input['purpose'], 'source_id' => (int) $source->id, 'revision' => (int) $source->revision]);
                CctvRequestReceipt::query()->create(['owner' => $owner, 'request_key' => $input['request_key'], 'input_hash' => $hash, 'response' => ['source_id' => (int) $source->id], 'expires_at' => null]);
            }

            // Enabling is approval to attempt viewing, never media verification.
            return ['actor_id' => $actorId, 'camera_id' => $cameraId, 'source' => ['id' => (int) $source->id, 'revision' => (int) $source->revision, 'enabled' => (bool) $source->enabled, 'stream_state' => $source->stream_state, 'stream_verified_at' => $source->stream_verified_at?->utc()->format('Y-m-d\TH:i:s\Z')]];
        });
    }

    public function recover(int $actorId, int $cameraId, string $key, mixed $expected): array
    {
        CctvContract::uuid($key);
        abort_unless(is_string($expected) && (string) $actorId === $expected, 404, 'unavailable');

        return DB::transaction(function () use ($actorId, $cameraId, $key): array {
            app(CctvAuthority::class)->actor($actorId, true);
            $receipt = $this->receipt($actorId, $cameraId, $key);
            abort_unless($receipt, 404, 'unavailable');

            return $this->known($actorId, $cameraId, $receipt);
        });
    }

    public function setup(int $actorId, int $cameraId): array
    {
        return DB::transaction(function () use ($actorId, $cameraId): array {
            $actor = app(CctvAuthority::class)->actor($actorId, true);
            [$camera,$site] = app(CctvAuthority::class)->device($actor, $cameraId);
            abort_unless($camera->provider === 'hikvision', 404, 'unavailable');
            $latest = CctvSource::query()->where('camera_id', $cameraId)->orderByDesc('revision')->lock('for share nowait')->first();

            return ['actor_id' => $actorId, 'camera_id' => $cameraId, 'site_id' => $site, 'revision' => $latest ? (int) $latest->revision : 0, 'mapping_enabled' => config('cctv.hikvision_mapping_enabled') === true, 'source' => $latest && $latest->provider === 'hikvision' ? $this->projection($actorId, $latest) : null, 'live_available' => false];
        });
    }

    public function review(int $actorId, int $cameraId, string $uuid): array
    {
        CctvContract::uuid($uuid);

        return DB::transaction(function () use ($actorId, $cameraId, $uuid): array {
            $request = $this->request($actorId, $cameraId, $uuid);
            $actor = app(HikvisionDiscoveryAuthority::class)->reader($actorId, $request);
            [$camera,$site] = app(CctvAuthority::class)->device($actor, $cameraId);
            $current = false;
            try {
                app(HikvisionDiscoveryAuthority::class)->request($request);
                $current = true;
            } catch (HttpExceptionInterface) {
            }
            $result = $request->result;
            abort_unless($result && $request->result_hash && hash_equals($request->result_hash, CctvContract::hash($result)), 409, 'source_unavailable');
            HikvisionDiscoveryContract::result($result);
            $identity = $result['inventory']['identity'] ?? null;
            $channels = $result['inventory']['channels'] ?? [];
            $reason = ! $current ? 'context_changed' : ($result['status'] !== 'observed' ? 'source_unavailable' : null);
            if ($reason === null && ($identity['model'] !== $camera->model || $identity['serial'] !== $camera->serial_number)) {
                $reason = 'identity_unmatched';
            }
            $observed = new \DateTimeImmutable($result['observed_at']);
            if ($reason === null && ($observed > now() || $observed->modify('+'.HikvisionSourcePolicy::MAX_OBSERVATION_AGE.' seconds') <= now())) {
                $reason = 'observation_expired';
            }
            $support = $identity ? $this->supported($identity) : null;
            $choices = array_map(function ($channel) use ($reason, $support): array {
                $blocked = $reason ?? HikvisionSourcePolicy::eligibility($channel, $support);

                return $channel + ['can_select' => $blocked === null, 'reason_code' => $blocked];
            }, $channels);

            return ['actor_id' => $actorId, 'camera_id' => $cameraId, 'site_id' => $site, 'discovery_uuid' => $uuid, 'expected_revision' => (int) $request->source_revision, 'result_sha256' => $request->result_hash, 'identity' => $identity, 'observed_at' => $result['observed_at'], 'transport_profile' => HikvisionSourcePolicy::PROFILE, 'channels' => $choices, 'current_context' => $current, 'mapping_enabled' => config('cctv.hikvision_mapping_enabled') === true, 'live_available' => false];
        });
    }

    public function authority(int $actorId, CctvSource $source, bool $manage = true): array
    {
        abort_unless($source->provider === 'hikvision' && $source->recorder_device_id === null && $source->configuration && hash_equals($source->fingerprint, CctvContract::hash($source->configuration)), 409, 'context_changed');
        $config = $source->configuration;
        CctvContract::exact($config, ['version', 'kind', 'discovery_uuid', 'result_sha256', 'discovery_configuration', 'binding_sha256', 'credential_sha256', 'identity', 'channel', 'transport_profile', 'support_sha256', 'video_codec', 'audio_enabled', 'observed_at']);
        abort_unless($config['version'] === 1 && $config['kind'] === 'hikvision_direct_camera' && $config['transport_profile'] === HikvisionSourcePolicy::PROFILE && $config['video_codec'] === 'H264' && $config['audio_enabled'] === false, 409, 'context_changed');
        $current = app(HikvisionDiscoveryAuthority::class)->binding($actorId, (int) $source->camera_id, $config['discovery_configuration'], $manage);
        abort_unless($current['device']->subcategory !== 'nvr' && $current['site'] === (int) $source->site_id && (int) $current['collector']->id === (int) $source->collector_id && (int) $source->discovery_scope_id === $config['discovery_configuration']['discovery_scope_id'] && hash_equals($source->provider_fingerprint, $current['credential']) && hash_equals($config['credential_sha256'], $current['credential']) && hash_equals($config['binding_sha256'], $current['binding']), 409, 'context_changed');
        $request = HikvisionDiscoveryRequest::query()->whereKey($source->discovery_request_id)->lock('for share nowait')->first();
        abort_unless($request && $request->uuid === $config['discovery_uuid'] && (int) $request->device_id === (int) $source->camera_id && (int) $request->site_id === (int) $source->site_id && (int) $request->collector_id === (int) $source->collector_id && (int) $request->discovery_scope_id === (int) $source->discovery_scope_id && (int) $request->actor_id === (int) $source->actor_id && (int) $source->revision === (int) $request->source_revision + 1 && $request->configuration === $config['discovery_configuration'] && $request->status === 'observed' && is_string($request->result_hash) && is_array($request->result) && hash_equals($request->result_hash, $config['result_sha256']) && hash_equals($request->result_hash, CctvContract::hash($request->result)) && $request->result['inventory']['identity'] === $config['identity'] && $request->result['observed_at'] === $config['observed_at'], 409, 'context_changed');
        $selected = array_values(array_filter($request->result['inventory']['channels'], fn ($c) => $c['id'] === $config['channel']['id']));
        $support = $this->supported($config['identity']);
        abort_unless(count($selected) === 1 && $selected[0] === $config['channel'] && HikvisionSourcePolicy::eligibility($config['channel'], $support) === null && hash_equals($config['support_sha256'], CctvContract::hash($support)), 409, 'context_changed');

        return $current;
    }

    private function supported(array $identity): ?array
    {
        try {
            return HikvisionSourcePolicy::supported($identity, config('cctv.hikvision_supported_profiles'));
        } catch (\InvalidArgumentException) {
            abort(503, 'temporarily_unavailable');
        }
    }

    private function known(int $actorId, int $cameraId, CctvRequestReceipt $receipt): array
    {
        $source = CctvSource::query()->whereKey($receipt->response['source_id'])->where('camera_id', $cameraId)->where('actor_id', $actorId)->lock('for share nowait')->first();
        abort_unless($source, 404, 'unavailable');

        return ['actor_id' => $actorId, 'camera_id' => $cameraId, 'source' => $this->projection($actorId, $source), 'mapping_enabled' => config('cctv.hikvision_mapping_enabled') === true, 'live_available' => false];
    }

    private function projection(int $actorId, CctvSource $source): array
    {
        $this->authority($actorId, $source);
        $config = $source->configuration;
        $latest = CctvSource::query()->where('camera_id', $source->camera_id)->orderByDesc('revision')->lock('for share nowait')->first();

        return ['id' => (int) $source->id, 'revision' => (int) $source->revision, 'provider' => 'hikvision', 'kind' => 'direct_camera', 'recorder_device_id' => null, 'current_mapping' => $latest && (int) $latest->id === (int) $source->id, 'enabled' => (bool) $source->enabled, 'stream_state' => $source->stream_state, 'stream_verified_at' => $source->stream_verified_at?->utc()->format('Y-m-d\TH:i:s\Z'), 'identity' => $config['identity'], 'channel' => $config['channel'], 'transport_profile' => $config['transport_profile'], 'video_codec' => 'H264', 'audio_enabled' => false, 'observed_at' => $config['observed_at'], 'mapped_at' => $source->created_at->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    private function request(int $actorId, int $cameraId, string $uuid): HikvisionDiscoveryRequest
    {
        app(CctvAuthority::class)->actor($actorId, true);
        $request = HikvisionDiscoveryRequest::query()->where('uuid', $uuid)->where('actor_id', $actorId)->where('device_id', $cameraId)->lock('for share nowait')->first();
        abort_unless($request, 404, 'unavailable');

        return $request;
    }

    private function owner(int $actorId, int $cameraId): string
    {
        return 'hikvision_source:'.$actorId.':'.$cameraId;
    }

    private function receipt(int $actorId, int $cameraId, string $key): ?CctvRequestReceipt
    {
        return CctvRequestReceipt::query()->where('owner', $this->owner($actorId, $cameraId))->where('request_key', $key)->lockForUpdate()->first();
    }
}
