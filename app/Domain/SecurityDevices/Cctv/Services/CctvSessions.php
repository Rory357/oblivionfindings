<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CctvCollectorPresence;
use App\Domain\SecurityDevices\Cctv\Models\CctvLiveSession;
use App\Domain\SecurityDevices\Cctv\Models\CctvRequestReceipt;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseGrant;
use App\Domain\SecurityDevices\Credentials\Services\CredentialLeaseLifecycleService;
use App\Domain\SecurityDevices\Credentials\Services\CredentialReferenceRules;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CctvSessions
{
    public function page(Request $request, int $cameraId): array
    {
        return DB::transaction(function () use ($request, $cameraId): array {
            $viewer = app(CctvAuthority::class)->actor((int) $request->user()->id, false, true);
            [$device, $siteId] = app(CctvAuthority::class)->device($viewer, $cameraId);
            $site = Site::query()->findOrFail($siteId);
            $source = CctvSource::query()->where('camera_id', $cameraId)->where('enabled', true)->orderByDesc('revision')->lock('for share nowait')->first();
            $ready = false;
            $reason = 'source_unavailable';
            if ($viewer->canDo('securityDevices.cctv.media.view')) {
                try {
                    $actor = app(CctvAuthority::class)->actor((int) $viewer->id);
                    if ($source && ($source->provider === 'hikvision' || $source->stream_state === 'verified')) {
                        $this->authorize($actor, $source);
                        $this->readiness((int) $source->collector_id, $source);
                        $ready = true;
                        $reason = null;
                    }
                } catch (HttpExceptionInterface) {
                    $reason = 'source_unavailable';
                }
            } else {
                $reason = 'authority_lost';
            }
            $manage = $viewer->canDo('securityDevices.integrations.manage') && $viewer->canDo('securityDevices.devices.update');

            return ['actor_id' => (int) $viewer->id, 'camera' => ['id' => $cameraId, 'name' => $device->name, 'site' => ['id' => $siteId, 'name' => $site->name]], 'live' => ['available' => $ready, 'reason_code' => $reason, 'can_start' => $ready, 'source_revision' => $ready ? (int) $source->revision : null, 'transport' => 'webrtc', 'video_codec' => 'H264', 'audio_enabled' => false, 'max_session_seconds' => $this->duration(), 'lease_seconds' => 15, 'start_href' => $ready ? '/security-devices/devices/'.$cameraId.'/live-sessions' : null, 'setup_href' => $manage ? '/security-devices/devices/'.$cameraId.'/live/source' : null, 'setup_ready' => $ready, 'streaming' => false], 'recording_references' => ['state' => 'not_requested', 'reference' => null], 'reference_href' => $viewer->canDo(CctvRecordingReferences::VIEW) && $device->status !== DeviceStatus::Decommissioned ? '/security-devices/devices/'.$cameraId.'/recording-references' : null];
        });
    }

    public function start(Request $request, int $cameraId, array $input): array
    {
        CctvContract::exact($input, ['expected_actor_id', 'request_key', 'source_revision', 'purpose']);
        CctvContract::positive($input['expected_actor_id']);
        CctvContract::positive($input['source_revision']);
        CctvContract::uuid($input['request_key']);
        CctvContract::text($input['purpose'], 10, 500);
        $actorId = (int) $request->user()->id;
        abort_unless($actorId === $input['expected_actor_id'], 409, 'context_changed');
        $browser = $this->browser($request);
        $hash = CctvContract::hash($input);
        $context = DB::transaction(function () use ($actorId, $cameraId, $input, $browser, $hash): array {
            $actor = app(CctvAuthority::class)->actor($actorId);
            app(CctvAuthority::class)->device($actor, $cameraId);
            $hikvisionReplay = CctvLiveSession::query()->where('provider', 'hikvision')->where('actor_id', $actorId)->where('request_key', $input['request_key'])->lockForUpdate()->first();
            abort_if($hikvisionReplay && ! hash_equals($hikvisionReplay->browser_fingerprint, $browser), 404, 'unavailable');
            $replay = $hikvisionReplay ?? CctvLiveSession::query()->where('actor_id', $actorId)->where('browser_fingerprint', $browser)->where('request_key', $input['request_key'])->lockForUpdate()->first();
            if ($replay) {
                abort_unless((int) $replay->camera_id === $cameraId && hash_equals($replay->request_hash, $hash), 409, 'context_changed');
                $this->expire($replay);
                $this->validateOwnedReplay($replay, $actor);

                return ['replay' => $this->dto($replay)];
            }
            $source = CctvSource::query()->where('camera_id', $cameraId)->where('enabled', true)->where('revision', $input['source_revision'])->lock('for share nowait')->first();
            abort_unless($source && ($source->provider === 'hikvision' || $source->stream_state === 'verified'), 409, 'context_changed');
            [$camera,$recorder,$collector,$connection] = $this->authorize($actor, $source);
            $this->readiness((int) $collector->id, $source);

            return compact('actor', 'source', 'camera', 'recorder', 'collector', 'connection');
        });
        if (isset($context['replay'])) {
            return $context['replay'];
        }
        if ($context['source']->provider === 'hikvision') {
            return $this->startHikvision($actorId, $cameraId, $input, $browser, $hash, $context);
        }
        $config = $context['source']->configuration;
        $verified = app(CctvProvider::class)->verify($context['connection'], $config['console_id'], $config['provider_camera_id'], $context['camera']->mac_address, $context['recorder']->mac_address);
        abort_unless($verified['application_version'] === $config['application_version'] && $verified['recorder_provider_id'] === $config['recorder_provider_id'], 409, 'context_changed');
        $locator = app(CctvProvider::class)->existing($context['connection'], $config);

        return DB::transaction(function () use ($actorId, $cameraId, $input, $browser, $hash, $context, $locator): array {
            User::query()->whereKey($actorId)->lock('for update nowait')->firstOrFail();
            $actor = app(CctvAuthority::class)->actor($actorId);
            abort_unless(hash_equals(CctvAuthority::fingerprint($context['actor']), CctvAuthority::fingerprint($actor)), 409, 'context_changed');
            // One collector mutex serializes source/peer capacity and Start replay.
            MonitoringCollector::query()->whereKey($context['collector']->id)->lockForUpdate()->firstOrFail();
            $source = CctvSource::query()->whereKey($context['source']->id)->lock('for share nowait')->firstOrFail();
            $this->authorize($actor, $source);
            $this->readiness((int) $source->collector_id, $source);
            abort_unless(hash_equals($context['source']->fingerprint, $source->fingerprint), 409, 'context_changed');
            $replay = CctvLiveSession::query()->where('actor_id', $actorId)->where('browser_fingerprint', $browser)->where('request_key', $input['request_key'])->lockForUpdate()->first();
            if ($replay) {
                abort_unless(hash_equals($replay->request_hash, $hash) && (int) $replay->camera_id === $cameraId, 409, 'context_changed');
                $this->expire($replay);
                $this->validateOwnedReplay($replay, $actor);

                return $this->dto($replay);
            }
            $active = CctvLiveSession::query()->where(function ($q) use ($actorId, $source) {
                $q->where('actor_id', $actorId)->orWhere('collector_id', $source->collector_id);
            })->whereNotIn('status', CctvContract::TERMINAL)->orderBy('id')->limit(100)->lockForUpdate()->get();
            foreach ($active as $session) {
                $this->expire($session);
            }
            $active = $active->reject(fn ($s) => in_array($s->status, CctvContract::TERMINAL, true));
            $peers = $active->where('collector_id', $source->collector_id);
            abort_if($active->where('actor_id', $actorId)->count() >= 2 || $peers->count() >= 4 || ($peers->pluck('camera_id')->unique()->count() >= 2 && ! $peers->contains('camera_id', $cameraId)), 429, 'capacity_unavailable');
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $expires = $now->addSeconds($this->duration());
            $uuid = (string) Str::uuid();
            $turn = $this->turn($uuid, $expires);
            $sourceJson = CctvContract::json(['url' => $locator, 'username' => null, 'password' => null]);
            $turnJson = CctvContract::json($turn);
            $session = CctvLiveSession::query()->create(['uuid' => $uuid, 'source_id' => $source->id, 'camera_id' => $cameraId, 'actor_id' => $actorId, 'collector_id' => $source->collector_id, 'actor_fingerprint' => CctvAuthority::fingerprint($actor), 'browser_fingerprint' => $browser, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'purpose' => $input['purpose'], 'generation' => 1, 'status' => 'requested', 'authority_until' => $now->addSeconds(15)->min($expires), 'session_expires_at' => $expires, 'ice_expires_at' => $expires, 'material' => ['source_json' => $sourceJson, 'turn_json' => $turnJson, 'offer_sdp' => null, 'answer' => null]]);
            $this->audit($session, 'started');

            return $this->dto($session);
        });
    }

    public function control(Request $request, string $uuid, string $operation, array $input): array
    {
        CctvContract::uuid($uuid);
        $keys = $operation === 'status' ? ['expected_actor_id'] : ($operation === 'offer' ? ['expected_actor_id', 'expected_generation', 'negotiation_uuid', 'sdp'] : ['expected_actor_id', 'expected_generation']);
        CctvContract::exact($input, $keys);
        // A GET query integer is the only accepted scalar coercion.
        if ($operation === 'status' && is_string($input['expected_actor_id']) && preg_match('/\A[1-9][0-9]{0,18}\z/', $input['expected_actor_id'])) {
            $input['expected_actor_id'] = (int) $input['expected_actor_id'];
        }
        CctvContract::positive($input['expected_actor_id']);
        if ($operation !== 'status') {
            CctvContract::positive($input['expected_generation']);
        }
        if ($operation === 'offer') {
            CctvContract::uuid($input['negotiation_uuid']);
            CctvContract::sdp($input['sdp'], 'recvonly');
        }
        abort_unless($input['expected_actor_id'] === (int) $request->user()->id, 409, 'context_changed');

        return DB::transaction(function () use ($request, $uuid, $operation, $input): array {
            $actor = app(CctvAuthority::class)->actor((int) $request->user()->id);
            $session = CctvLiveSession::query()->where('uuid', $uuid)->lockForUpdate()->first();
            abort_unless($session && (int) $session->actor_id === (int) $actor->id && hash_equals($session->browser_fingerprint, $this->browser($request)), 404, 'unavailable');
            app(CctvAuthority::class)->device($actor, (int) $session->camera_id);
            abort_unless(hash_equals($session->actor_fingerprint, CctvAuthority::fingerprint($actor)), 404, 'unavailable');
            $this->expire($session);
            if ($operation === 'stop') {
                if (! in_array($session->status, CctvContract::TERMINAL, true)) {
                    $this->end($session, 'stopped', 'stopped');
                }

                return $this->dto($session);
            }
            if (in_array($session->status, CctvContract::TERMINAL, true)) {
                return $this->dto($session);
            }
            $source = CctvSource::query()->whereKey($session->source_id)->lock('for share nowait')->firstOrFail();
            $this->authorize($actor, $source, $session);
            $this->readiness((int) $session->collector_id, $source);
            if ($operation === 'offer') {
                CctvContract::relaySdp($input['sdp'], json_decode($session->material['turn_json'], true, 8, JSON_THROW_ON_ERROR), 'recvonly');
            }
            if ($operation === 'offer' && $session->negotiation_uuid !== null) {
                abort_unless($session->negotiation_uuid === $input['negotiation_uuid'] && hash_equals($session->material['offer_sdp'], $input['sdp']), 409, 'context_changed');

                return $this->dto($session);
            }
            $renewReceipt = null;
            if ($operation === 'renew' && $session->provider === 'hikvision') {
                $owner = 'hikvision_live_renew:'.$session->uuid.':'.$input['expected_generation'];
                $renewReceipt = CctvRequestReceipt::query()->where('owner', $owner)->where('request_key', $session->uuid)->lockForUpdate()->first();
                if ($renewReceipt) {
                    abort_unless(hash_equals($renewReceipt->input_hash, CctvContract::hash($input)), 409, 'context_changed');

                    return $this->dto($session);
                }
                $renewReceipt = CctvRequestReceipt::query()->create(['owner' => $owner, 'request_key' => $session->uuid, 'input_hash' => CctvContract::hash($input), 'response' => null, 'expires_at' => null]);
            }
            if ($operation !== 'status') {
                abort_unless((int) $session->generation === $input['expected_generation'], 409, 'context_changed');
            }
            if ($operation === 'offer') {
                $material = $session->material;
                $material['offer_sdp'] = $input['sdp'];
                $session->material = $material;
                $session->negotiation_uuid = $input['negotiation_uuid'];
                $session->status = 'negotiating';
                $session->save();
                $this->audit($session, 'negotiation');
            }
            if ($operation === 'renew') {
                $session->generation++;
                $session->authority_until = CarbonImmutable::now('UTC')->startOfSecond()->addSeconds(15)->min($session->session_expires_at)->min($session->ice_expires_at);
                $session->worker_grant = null;
                $session->save();
                if ($renewReceipt) {
                    $renewReceipt->response = ['generation' => (int) $session->generation];
                    $renewReceipt->save();
                    $this->audit($session, 'renewed');
                }
            }

            return $this->dto($session);
        });
    }

    public function authorize(User $actor, CctvSource $source, ?CctvLiveSession $session = null): array
    {
        if ($source->provider !== 'hikvision') {
            return app(CctvAuthority::class)->source($actor, $source);
        }
        $authority = app(HikvisionLiveAuthority::class);
        $current = $authority->source($actor, $source);
        if ($session !== null) {
            $authority->session($session, $source, $current[3]);
        }

        return $current;
    }

    private function startHikvision(int $actorId, int $cameraId, array $input, string $browser, string $hash, array $context): array
    {
        $prepared = DB::transaction(function () use ($actorId, $cameraId, $input, $browser, $hash, $context): array {
            User::query()->whereKey($actorId)->lock('for update nowait')->firstOrFail();
            $actor = app(CctvAuthority::class)->actor($actorId);
            abort_unless(hash_equals(CctvAuthority::fingerprint($context['actor']), CctvAuthority::fingerprint($actor)), 409, 'context_changed');
            MonitoringCollector::query()->whereKey($context['collector']->id)->lockForUpdate()->firstOrFail();
            $source = CctvSource::query()->whereKey($context['source']->id)->lock('for share nowait')->firstOrFail();
            [, , , $current] = $this->authorize($actor, $source);
            $this->readiness((int) $source->collector_id, $source);
            abort_unless(hash_equals($context['source']->fingerprint, $source->fingerprint), 409, 'context_changed');
            $known = CctvLiveSession::query()->where('provider', 'hikvision')->where('actor_id', $actorId)->where('request_key', $input['request_key'])->lockForUpdate()->first();
            if ($known) {
                abort_unless(hash_equals($known->browser_fingerprint, $browser), 404, 'unavailable');
                abort_unless((int) $known->camera_id === $cameraId && hash_equals($known->request_hash, $hash), 409, 'context_changed');
                $this->expire($known);
                $this->validateOwnedReplay($known, $actor);

                return ['response' => $this->dto($known)];
            }
            $active = CctvLiveSession::query()->where(function ($q) use ($actorId, $source) {
                $q->where('actor_id', $actorId)->orWhere('collector_id', $source->collector_id);
            })->whereNotIn('status', CctvContract::TERMINAL)->orderBy('id')->limit(100)->lockForUpdate()->get();
            foreach ($active as $session) {
                $this->expire($session);
            }
            $active = $active->reject(fn ($s) => in_array($s->status, CctvContract::TERMINAL, true));
            $peers = $active->where('collector_id', $source->collector_id);
            abort_if($active->where('actor_id', $actorId)->count() >= 2 || $peers->count() >= 4 || ($peers->pluck('camera_id')->unique()->count() >= 2 && ! $peers->contains('camera_id', $cameraId)), 429, 'capacity_unavailable');
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $expires = $now->addSeconds($this->duration());
            $session = CctvLiveSession::query()->create(['provider' => 'hikvision', 'uuid' => (string) Str::uuid(), 'source_id' => $source->id, 'camera_id' => $cameraId, 'actor_id' => $actorId, 'collector_id' => $source->collector_id, 'actor_fingerprint' => CctvAuthority::fingerprint($actor), 'browser_fingerprint' => $browser, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'purpose' => $input['purpose'], 'source_context' => app(HikvisionLiveAuthority::class)->snapshot($source, $current), 'preparation_started_at' => $now, 'generation' => 1, 'status' => 'requested', 'authority_until' => $now->addSeconds(15), 'session_expires_at' => $expires, 'ice_expires_at' => $expires, 'material' => null]);
            $this->audit($session, 'started');

            return ['id' => (int) $session->id, 'site' => $current['site'], 'reference' => $current['reference']->reference_key];
        });
        if (isset($prepared['response'])) {
            return $prepared['response'];
        }
        $lease = null;
        $material = [];
        $keep = false;
        try {
            // Durable preparation precedes external retrieval; no domain locks
            // cross this boundary and a lost response cannot issue another lease.
            $lease = app(CredentialLeaseProvider::class)->acquire($prepared['site'], $prepared['reference'], [HikvisionLiveContract::CAPABILITY]);
            $material = $lease->material();
            CctvContract::exact($material, ['username', 'password']);
            $response = DB::transaction(function () use ($prepared, $lease, $material): array {
                $session = CctvLiveSession::query()->whereKey($prepared['id'])->lockForUpdate()->firstOrFail();
                $this->expire($session);
                abort_unless($session->status === 'requested' && $session->material === null, 409, 'context_changed');
                $actor = app(CctvAuthority::class)->actor((int) $session->actor_id);
                abort_unless(hash_equals($session->actor_fingerprint, CctvAuthority::fingerprint($actor)), 409, 'context_changed');
                $source = CctvSource::query()->whereKey($session->source_id)->lock('for share nowait')->firstOrFail();
                [, , , $current] = $this->authorize($actor, $source);
                $this->readiness((int) $session->collector_id, $source);
                abort_unless(hash_equals(CctvContract::hash($session->source_context), CctvContract::hash(app(HikvisionLiveAuthority::class)->snapshot($source, $current))), 409, 'context_changed');
                $grant = CredentialLeaseGrant::query()->where('lease_fingerprint', app(CredentialReferenceRules::class)->fingerprint($lease->leaseId))->lockForUpdate()->first();
                abort_unless($grant && (int) $grant->site_id === $current['site'] && (int) $grant->credential_reference_id === (int) $current['reference']->id && (int) $grant->reference_version === (int) $current['reference']->version && $grant->status === CredentialLeaseGrant::STATUS_ISSUED && $grant->capabilities === [HikvisionLiveContract::CAPABILITY], 503, 'temporarily_unavailable');
                $expires = $session->session_expires_at->min($lease->expiresAt->startOfSecond())->min($grant->expires_at->startOfSecond());
                abort_unless($expires->gt(now()), 503, 'temporarily_unavailable');
                foreach (['username' => 128, 'password' => 1024] as $field => $max) {
                    abort_unless(is_string($material[$field]) && strlen($material[$field]) > 0 && strlen($material[$field]) <= $max && ! preg_match('/[^\x20-\x7e]/', $material[$field]), 503, 'temporarily_unavailable');
                }
                $private = array_intersect_key($source->configuration['discovery_configuration'], array_flip(['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin'])) + $material;
                $session->session_expires_at = $session->ice_expires_at = $expires;
                $session->authority_until = $session->authority_until->min($expires);
                $session->credential_lease_grant_id = $grant->id;
                $session->material = ['source_json' => CctvContract::json($private), 'turn_json' => CctvContract::json($this->turn($session->uuid, $expires)), 'offer_sdp' => null, 'answer' => null];
                $session->save();
                app(HikvisionLiveAuthority::class)->session($session, $source, $current);
                $this->audit($session, 'credential_prepared');

                return $this->dto($session);
            });
            $keep = true;

            return $response;
        } catch (\Throwable $exception) {
            DB::transaction(function () use ($prepared): void {
                $session = CctvLiveSession::query()->whereKey($prepared['id'])->lockForUpdate()->first();
                if ($session && ! in_array($session->status, CctvContract::TERMINAL, true)) {
                    $this->end($session, 'failed', 'credential_unavailable');
                }
            });
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            abort(503, 'temporarily_unavailable');
        } finally {
            foreach ($material as &$value) {
                if (is_string($value)) {
                    sodium_memzero($value);
                }
            } unset($value);
            if ($lease !== null && ! $keep) {
                app(CredentialLeaseLifecycleService::class)->release($lease);
            }
        }
    }

    private function validateOwnedReplay(CctvLiveSession $session, User $actor): void
    {
        abort_unless(hash_equals($session->actor_fingerprint, CctvAuthority::fingerprint($actor)), 404, 'unavailable');
        if (in_array($session->status, CctvContract::TERMINAL, true)) {
            return;
        }
        $source = CctvSource::query()->whereKey($session->source_id)->lock('for share nowait')->firstOrFail();
        $this->authorize($actor, $source, $session);
        $this->readiness((int) $session->collector_id, $source);
    }

    public function readiness(int $collectorId, ?CctvSource $source = null): void
    {
        abort_unless(config('cctv.enabled') === true, 503, 'temporarily_unavailable');
        $presence = CctvCollectorPresence::query()->whereKey($collectorId)->lock('for share nowait')->first();
        abort_unless($presence && $presence->available && $presence->observed_at->betweenIncluded(now()->subSeconds(15), now()), 503, 'temporarily_unavailable');
        if ($source?->provider === 'hikvision') {
            abort_unless(config('cctv.hikvision_live_enabled') === true && $presence->hikvision_profile === HikvisionSourcePolicy::PROFILE, 503, 'temporarily_unavailable');
        }
    }

    public function expire(CctvLiveSession $session): void
    {
        if (in_array($session->status, CctvContract::TERMINAL, true)) {
            return;
        }
        $now = CarbonImmutable::now('UTC');
        if ($session->session_expires_at->lte($now) || $session->ice_expires_at?->lte($now)) {
            $this->end($session, 'expired', 'session_expired');
        } elseif ($session->authority_until->lte($now)) {
            $this->end($session, 'expired', 'lease_expired');
        }
    }

    public function end(CctvLiveSession $session, string $status, string $reason): void
    {
        if ($session->provider === 'hikvision' && in_array($session->status, CctvContract::TERMINAL, true)) {
            return;
        }
        $session->status = $status;
        $session->reason_code = $reason;
        $session->material = null;
        $session->worker_grant = null;
        $session->authority_until = CarbonImmutable::now('UTC')
            ->min($session->authority_until)
            ->min($session->session_expires_at);
        if ($session->ice_expires_at !== null) {
            $session->authority_until = $session->authority_until->min($session->ice_expires_at);
        }
        $session->save();
        if ($session->provider === 'hikvision') {
            CctvRequestReceipt::query()->firstOrCreate(['owner' => 'hikvision_live_close:'.$session->collector_id, 'request_key' => $session->uuid], ['input_hash' => CctvContract::hash(['session_uuid' => $session->uuid, 'collector_id' => (int) $session->collector_id, 'generation' => (int) $session->generation]), 'response' => null, 'expires_at' => null]);
        }
        $this->audit($session, $status);
        if ($session->provider === 'hikvision' && $session->credential_lease_grant_id !== null) {
            $id = (int) $session->credential_lease_grant_id;
            DB::afterCommit(fn () => app(CredentialLeaseLifecycleService::class)->releaseGrant($id));
        }
    }

    public function dto(CctvLiveSession $session): array
    {
        $material = $session->material;
        $turn = $material ? json_decode($material['turn_json'], true, 8, JSON_THROW_ON_ERROR) : null;
        $href = '/security-devices/cctv/live-sessions/'.$session->uuid;

        return ['actor_id' => (int) $session->actor_id, 'camera_id' => (int) $session->camera_id, 'session' => ['uuid' => $session->uuid, 'actor_id' => (int) $session->actor_id, 'camera_id' => (int) $session->camera_id, 'negotiation_uuid' => $session->negotiation_uuid, 'generation' => (int) $session->generation, 'status' => $session->status, 'reason_code' => $session->reason_code, 'server_now' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'), 'authority_until' => $session->authority_until->utc()->format('Y-m-d\TH:i:s\Z'), 'session_expires_at' => $session->session_expires_at->utc()->format('Y-m-d\TH:i:s\Z'), 'ice_expires_at' => ($turn ? $session->ice_expires_at?->utc()->format('Y-m-d\TH:i:s\Z') : null), 'transport' => 'webrtc', 'video_codec' => 'H264', 'audio_enabled' => false, 'ice_servers' => $turn ? [$turn] : [], 'answer' => $material['answer'] ?? null, 'status_href' => $href, 'offer_href' => $href.'/offer', 'renew_href' => $href.'/renew', 'stop_href' => $href.'/stop']];
    }

    private function turn(string $uuid, CarbonImmutable $expires): array
    {
        $urls = config('cctv.relay_urls');
        $secret = config('cctv.relay_secret');
        abort_unless(is_array($urls) && array_is_list($urls) && count($urls) >= 1 && count($urls) <= 4 && is_string($secret) && strlen($secret) >= 32 && strlen($secret) <= 512, 503, 'temporarily_unavailable');
        foreach ($urls as $url) {
            abort_unless(is_string($url) && preg_match('/\A(turns?):(\[[0-9a-fA-F:]+\]|[a-zA-Z0-9.-]+):([0-9]{1,5})(?:\?transport=(udp|tcp))?\z/', $url, $match) && ((int) $match[3]) >= 1 && ((int) $match[3]) <= 65535 && ($match[1] !== 'turns' || ($match[4] ?? 'tcp') === 'tcp'), 503, 'temporarily_unavailable');
        }
        $username = $expires->timestamp.':obli-cctv-'.hash_hmac('sha256', $uuid, (string) config('app.key'));

        $turn = ['urls' => $urls, 'username' => $username, 'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)), 'expires_at' => $expires->format('Y-m-d\TH:i:s\Z'), 'relay_cidrs' => config('cctv.relay_cidrs'), 'relay_port_min' => config('cctv.relay_port_min'), 'relay_port_max' => config('cctv.relay_port_max')];
        try {
            CctvContract::relay($turn);
        } catch (\InvalidArgumentException) {
            abort(503, 'temporarily_unavailable');
        }

        return $turn;
    }

    private function browser(Request $request): string
    {
        abort_unless($request->hasSession(), 404, 'unavailable');

        return hash_hmac('sha256', $request->session()->getId(), (string) config('app.key'));
    }

    private function duration(): int
    {
        return max(60, min(3600, (int) config('cctv.max_session_seconds', 300)));
    }

    private function audit(CctvLiveSession $session, string $action): void
    {
        AuditLogger::logOrFail('cctv.live.'.$action, Device::query()->findOrFail($session->camera_id), ['actor_id' => (int) $session->actor_id, 'session_uuid' => $session->uuid, 'purpose' => $session->purpose, 'reason_code' => $session->reason_code]);
    }
}
