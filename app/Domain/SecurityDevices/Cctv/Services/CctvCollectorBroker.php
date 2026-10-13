<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorCredentialLeaseSealer;
use App\Domain\SecurityDevices\Cctv\Models\CctvCollectorPresence;
use App\Domain\SecurityDevices\Cctv\Models\CctvLiveSession;
use App\Domain\SecurityDevices\Cctv\Models\CctvRequestReceipt;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Models\Device;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CctvCollectorBroker
{
    public function work(MonitoringCollector $transport, array $input): array
    {
        CctvContract::exact($input, ['collector_id', 'request_key', 'media_capability']);
        CctvContract::uuid($input['collector_id']);
        CctvContract::uuid($input['request_key']);
        abort_unless($input['collector_id'] === $transport->collector_uuid, 404, 'unavailable');
        $cap = $input['media_capability'];
        if (! is_array($cap)) {
            throw new \InvalidArgumentException('invalid_request');
        }
        CctvContract::exact($cap, array_key_exists('hikvision_profile', $cap) ? ['version', 'available', 'video_codec', 'audio', 'hikvision_profile'] : ['version', 'available', 'video_codec', 'audio']);
        abort_unless(($cap['hikvision_profile'] ?? null) === null || $cap['hikvision_profile'] === HikvisionSourcePolicy::PROFILE, 400, 'invalid_request');
        abort_unless($cap['version'] === 1 && is_bool($cap['available']) && $cap['video_codec'] === 'H264' && $cap['audio'] === false, 400, 'invalid_request');

        return DB::transaction(function () use ($transport, $input, $cap): array {
            $collector = $this->collector($transport);
            $receipt = $this->receipt('work:'.$collector->id, $input);
            if ($receipt->response !== null) {
                abort_unless($receipt->expires_at->gt(now()), 409, 'context_changed');
                foreach ($receipt->response['operations'] as $operation) {
                    if ($operation['operation'] === 'close') {
                        continue;
                    }
                    $payload = json_decode(base64_decode($operation['grant']['payload'], true), true, 32, JSON_THROW_ON_ERROR);
                    $session = CctvLiveSession::query()->where('uuid', $payload['session_uuid'])->where('collector_id', $collector->id)->lockForUpdate()->first();
                    abort_unless($session && ! in_array($session->status, CctvContract::TERMINAL, true) && (int) $session->generation === $payload['generation'] && $session->authority_until->gt(now()), 409, 'context_changed');
                    $this->authority($session, $collector);
                }

                return $receipt->response;
            }
            CctvCollectorPresence::query()->updateOrCreate(['collector_id' => $collector->id], ['available' => $cap['available'], 'observed_at' => now(), 'hikvision_profile' => $cap['hikvision_profile'] ?? null]);
            $operations = [];
            $pendingCloses = CctvRequestReceipt::query()->where('owner', 'hikvision_live_close:'.$collector->id)->whereNull('response')->orderBy('id')->limit(4)->lockForUpdate()->get();
            foreach ($pendingCloses as $closeReceipt) {
                $closed = CctvLiveSession::query()->where('uuid', $closeReceipt->request_key)->where('collector_id', $collector->id)->where('provider', 'hikvision')->whereIn('status', CctvContract::TERMINAL)->lockForUpdate()->firstOrFail();
                abort_unless(hash_equals($closeReceipt->input_hash, $this->closeHash($closed)), 409, 'context_changed');
                $operations[] = $this->close($closed);
            }
            $sessions = CctvLiveSession::query()->where('collector_id', $collector->id)->whereNotIn('status', CctvContract::TERMINAL)->orderBy('id')->limit(4 - count($operations))->lockForUpdate()->get();
            foreach ($sessions as $session) {
                app(CctvSessions::class)->expire($session);
                if (in_array($session->status, CctvContract::TERMINAL, true)) {
                    $operations[] = $this->close($session);

                    continue;
                }
                if (! $cap['available'] || config('cctv.enabled') !== true || ($session->provider === 'hikvision' && ($cap['hikvision_profile'] ?? null) !== HikvisionSourcePolicy::PROFILE)) {
                    app(CctvSessions::class)->end($session, 'failed', 'worker_unavailable');
                    $operations[] = $this->close($session);

                    continue;
                }
                try {
                    [$source] = $this->authority($session, $collector);
                } catch (HttpExceptionInterface) {
                    app(CctvSessions::class)->end($session, 'revoked', 'authority_lost');
                    $operations[] = $this->close($session);

                    continue;
                }
                if ($session->negotiation_uuid === null) {
                    continue;
                }
                $grant = $session->worker_grant;
                if ($grant === null) {
                    $grant = $this->grant($collector, $session, $source);
                    $session->worker_grant = $grant;
                    $session->save();
                }
                $operation = ['operation' => $grant['operation'], 'request_uuid' => $grant['request_uuid'], 'grant' => $grant['envelope']];
                if ($operation['operation'] === 'open') {
                    if ($session->provider === 'hikvision') {
                        $operation['credential_lease'] = $grant['credential_lease'];
                    } else {
                        $material = $session->material;
                        $lease = new CredentialLease('cctv:'.$session->uuid.':'.$session->generation, CarbonImmutable::parse($grant['expires_at']), ['source_json' => $material['source_json'], 'turn_json' => $material['turn_json'], 'offer_sdp' => $material['offer_sdp']]);
                        $operation['credential_lease'] = app(CollectorCredentialLeaseSealer::class)->seal($collector, (string) $session->camera_id, 'cctv.live', $session->uuid, $lease);
                    }
                }
                $operations[] = $operation;
            }

            $response = ['server_now' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'), 'operations' => $operations];
            $receipt->response = $response;
            $receipt->expires_at = CarbonImmutable::now('UTC')->addSeconds(15);
            foreach ($sessions as $session) {
                if (! in_array($session->status, CctvContract::TERMINAL, true)) {
                    $receipt->expires_at = $receipt->expires_at->min($session->authority_until);
                }
            }
            $receipt->save();

            return $response;
        });
    }

    public function results(MonitoringCollector $transport, array $input): array
    {
        CctvContract::exact($input, ['collector_id', 'request_key', 'results']);
        CctvContract::uuid($input['collector_id']);
        CctvContract::uuid($input['request_key']);
        abort_unless($input['collector_id'] === $transport->collector_uuid, 404, 'unavailable');
        abort_unless(is_array($input['results']) && array_is_list($input['results']) && count($input['results']) <= 4, 400, 'invalid_request');
        foreach ($input['results'] as $result) {
            if (! is_array($result)) {
                throw new \InvalidArgumentException('invalid_request');
            }
            CctvContract::exact($result, ['version', 'request_uuid', 'session_uuid', 'generation', 'status', 'answer', 'reason_code']);
            CctvContract::uuid($result['request_uuid']);
            CctvContract::uuid($result['session_uuid']);
            CctvContract::positive($result['generation']);
            abort_unless($result['version'] === 1 && in_array($result['status'], ['negotiating', 'ready', 'stopped', 'expired', 'revoked', 'failed'], true) && ($result['reason_code'] === null || in_array($result['reason_code'], CctvContract::REASONS, true)), 400, 'invalid_request');
            if ($result['answer'] !== null) {
                abort_unless(is_array($result['answer']), 400, 'invalid_request');
                CctvContract::exact($result['answer'], ['type', 'sdp']);
                abort_unless($result['answer']['type'] === 'answer' && $result['status'] === 'ready', 400, 'invalid_request');
                CctvContract::sdp($result['answer']['sdp'], 'sendonly');
            }
            abort_unless(($result['status'] === 'ready') === ($result['answer'] !== null), 400, 'invalid_request');
        }

        return DB::transaction(function () use ($transport, $input): array {
            $collector = $this->collector($transport);
            $receipt = $this->receipt('results:'.$collector->id, $input);
            if ($receipt->response !== null) {
                return $receipt->response;
            }
            $accepted = [];
            foreach ($input['results'] as $result) {
                $session = CctvLiveSession::query()->where('uuid', $result['session_uuid'])->where('collector_id', $collector->id)->lockForUpdate()->first();
                if ($session && $session->provider === 'hikvision' && in_array($session->status, CctvContract::TERMINAL, true)) {
                    if ($this->acknowledgeClose($session, $result)) {
                        $accepted[] = ['session_uuid' => $session->uuid, 'generation' => (int) $session->generation];
                    }

                    continue;
                }
                if (! $session || in_array($session->status, CctvContract::TERMINAL, true)) {
                    continue;
                }
                app(CctvSessions::class)->expire($session);
                if (in_array($session->status, CctvContract::TERMINAL, true)) {
                    continue;
                }
                try {
                    $this->authority($session, $collector);
                } catch (HttpExceptionInterface) {
                    app(CctvSessions::class)->end($session, 'revoked', 'authority_lost');

                    continue;
                }
                $grant = $session->worker_grant;
                if (! $grant || $grant['request_uuid'] !== $result['request_uuid'] || (int) $session->generation !== $result['generation'] || CarbonImmutable::parse($grant['expires_at'])->lte(now())) {
                    continue;
                }
                $resultReceipt = null;
                if ($session->provider === 'hikvision') {
                    $owner = 'hikvision_live_result:'.$session->uuid.':'.($result['status'] === 'negotiating' ? 'negotiating' : 'final');
                    $resultReceipt = CctvRequestReceipt::query()->where('owner', $owner)->where('request_key', $result['request_uuid'])->lockForUpdate()->first();
                    if ($resultReceipt) {
                        abort_unless(hash_equals($resultReceipt->input_hash, CctvContract::hash($result)), 409, 'context_changed');
                        $accepted[] = ['session_uuid' => $session->uuid, 'generation' => (int) $session->generation];

                        continue;
                    }

                }
                if ($result['answer'] !== null) {
                    CctvContract::relaySdp($result['answer']['sdp'], json_decode($session->material['turn_json'], true, 8, JSON_THROW_ON_ERROR), 'sendonly');
                }
                if (in_array($result['status'], CctvContract::TERMINAL, true)) {
                    app(CctvSessions::class)->end($session, $result['status'], $result['reason_code'] ?? 'transport_unavailable');
                } elseif ($result['status'] === 'ready') {
                    $material = $session->material;
                    if ($material['answer'] !== null && ! hash_equals(CctvContract::json($material['answer']), CctvContract::json($result['answer']))) {
                        continue;
                    }
                    $material['answer'] = $result['answer'];
                    $session->material = $material;
                    $session->status = 'ready';
                    $session->save();
                    if ($session->provider === 'hikvision') {
                        $source = CctvSource::query()->whereKey($session->source_id)->lockForUpdate()->firstOrFail();
                        $source->stream_state = 'verified';
                        $source->stream_verified_at = now();
                        $source->save();
                    }
                }
                if ($session->provider === 'hikvision') {
                    $resultReceipt = CctvRequestReceipt::query()->create(['owner' => $owner, 'request_key' => $result['request_uuid'], 'input_hash' => CctvContract::hash($result), 'response' => ['session_uuid' => $session->uuid, 'generation' => (int) $session->generation, 'status' => $session->status], 'expires_at' => null]);
                    AuditLogger::logOrFail('cctv.hikvision.live_result', Device::query()->findOrFail($session->camera_id), ['actor_id' => (int) $session->actor_id, 'session_uuid' => $session->uuid, 'request_uuid' => $result['request_uuid'], 'generation' => (int) $session->generation, 'result_sha256' => $resultReceipt->input_hash, 'status' => $session->status]);
                }
                $accepted[] = ['session_uuid' => $session->uuid, 'generation' => (int) $session->generation];
            }

            $receipt->response = ['accepted' => $accepted];
            $receipt->save();

            return $receipt->response;
        });
    }

    private function authority(CctvLiveSession $session, MonitoringCollector $collector): array
    {
        $actor = app(CctvAuthority::class)->actor((int) $session->actor_id);
        abort_unless(hash_equals($session->actor_fingerprint, CctvAuthority::fingerprint($actor)), 404, 'unavailable');
        $source = CctvSource::query()->whereKey($session->source_id)->lock('for share nowait')->firstOrFail();
        [, , $current] = app(CctvSessions::class)->authorize($actor, $source, $session->material !== null ? $session : null);
        if ($source->provider === 'hikvision') {
            app(CctvSessions::class)->readiness((int) $collector->id, $source);
        }
        abort_unless((int) $current->id === (int) $collector->id && (int) $source->site_id === (int) $collector->site_id, 404, 'unavailable');

        return [$source];
    }

    private function grant(MonitoringCollector $collector, CctvLiveSession $session, CctvSource $source): array
    {
        $config = $source->configuration;
        $material = $session->material;
        if ($source->provider === 'hikvision') {
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $expires = $session->authority_until->min($now->addSeconds(15))->min($session->session_expires_at)->min($session->ice_expires_at);
            $context = $session->source_context;
            $binding = ['collector_uuid' => $collector->collector_uuid, 'site_id' => (int) $source->site_id, 'camera_id' => (int) $source->camera_id, 'discovery_scope_id' => (int) $source->discovery_scope_id, 'source_id' => (int) $source->id, 'source_revision' => (int) $source->revision, 'source_fingerprint' => $context['source_fingerprint'], 'binding_sha256' => $context['binding_sha256'], 'credential_binding_sha256' => $context['credential_binding_sha256'], 'support_sha256' => $context['support_sha256'], 'discovery_result_sha256' => $context['discovery_result_sha256'], 'identity' => $config['identity'], 'channel' => $config['channel'], 'session_uuid' => $session->uuid, 'negotiation_uuid' => $session->negotiation_uuid, 'generation' => (int) $session->generation, 'session_expires_at' => $session->session_expires_at->utc()->format('Y-m-d\TH:i:s\Z')];
            $secret = base64_decode((string) config('monitoring.collector.signing_secret_key'), true);
            abort_unless(is_string($secret) && strlen($secret) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 503, 'temporarily_unavailable');
            try {
                $operation = HikvisionLiveContract::issue($collector, $binding, json_decode($material['source_json'], true, 8, JSON_THROW_ON_ERROR), json_decode($material['turn_json'], true, 8, JSON_THROW_ON_ERROR), $material['offer_sdp'], (string) Str::uuid(), $now, $expires, $secret, $session->status === 'ready' ? 'renew' : 'open', $material['source_json'], $material['turn_json']);
            } finally {
                sodium_memzero($secret);
            }

            return ['operation' => $operation['operation'], 'request_uuid' => $operation['request_uuid'], 'expires_at' => $expires->utc()->format('Y-m-d\TH:i:s\Z'), 'envelope' => $operation['grant']] + (isset($operation['credential_lease']) ? ['credential_lease' => $operation['credential_lease']] : []);
        }
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $expires = $session->authority_until->min($now->addSeconds(15))->min($session->session_expires_at)->min($session->ice_expires_at);
        abort_unless($expires->gt($now), 409, 'context_changed');
        $payload = ['version' => 1, 'type' => 'collector_cctv_live', 'collector_uuid' => $collector->collector_uuid, 'site_id' => (int) $source->site_id, 'camera_id' => (int) $source->camera_id, 'recorder_device_id' => (int) $source->recorder_device_id, 'provider' => 'unifi', 'console_id' => $config['console_id'], 'provider_camera_id' => $config['provider_camera_id'], 'session_uuid' => $session->uuid, 'negotiation_uuid' => $session->negotiation_uuid, 'generation' => (int) $session->generation, 'source_revision' => (int) $source->revision, 'source_sha256' => hash('sha256', $material['source_json']), 'turn_sha256' => hash('sha256', $material['turn_json']), 'offer_sha256' => hash('sha256', $material['offer_sdp']), 'quality' => 'low', 'video_codec' => 'H264', 'audio' => false, 'discovery_scope_id' => (int) $source->discovery_scope_id, 'allowed_ip' => $config['stream_address'], 'allowed_port' => $config['stream_port'], 'tls_server_name' => $config['tls_server_name'], 'tls_pin' => $config['tls_pin'], 'issued_at' => $now->format('Y-m-d\TH:i:s\Z'), 'expires_at' => $expires->format('Y-m-d\TH:i:s\Z'), 'session_expires_at' => $session->session_expires_at->format('Y-m-d\TH:i:s\Z')];
        $secret = base64_decode((string) config('monitoring.collector.signing_secret_key'), true);
        abort_unless(is_string($secret) && strlen($secret) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 503, 'temporarily_unavailable');
        $bytes = CctvContract::json($payload);
        try {
            $envelope = ['payload' => base64_encode($bytes), 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secret))];
        } finally {
            sodium_memzero($secret);
        }

        return ['operation' => $session->status === 'ready' ? 'renew' : 'open', 'request_uuid' => (string) Str::uuid(), 'expires_at' => $payload['expires_at'], 'envelope' => $envelope];
    }

    private function collector(MonitoringCollector $transport): MonitoringCollector
    {
        $collector = MonitoringCollector::query()->whereKey($transport->id)->lockForUpdate()->firstOrFail();
        abort_unless(! $collector->revoked_at && $collector->status !== 'revoked' && $collector->enrolled_at && $collector->collector_uuid === $transport->collector_uuid && hash_equals((string) $collector->public_key, (string) $transport->public_key) && hash_equals((string) $collector->client_certificate_fingerprint, (string) $transport->client_certificate_fingerprint), 404, 'unavailable');

        return $collector;
    }

    private function receipt(string $owner, array $input): CctvRequestReceipt
    {
        $receipt = CctvRequestReceipt::query()->where('owner', $owner)->where('request_key', $input['request_key'])->lockForUpdate()->first();
        $hash = CctvContract::hash($input);
        if ($receipt) {
            abort_unless($receipt->expires_at === null || $receipt->expires_at->gt(now()), 409, 'context_changed');
            abort_unless(hash_equals($receipt->input_hash, $hash), 409, 'context_changed');

            return $receipt;
        }

        return CctvRequestReceipt::query()->create(['owner' => $owner, 'request_key' => $input['request_key'], 'input_hash' => $hash, 'response' => null, 'expires_at' => now()->addMinutes(5)]);
    }

    private function closeHash(CctvLiveSession $session): string
    {
        return CctvContract::hash(['session_uuid' => $session->uuid, 'collector_id' => (int) $session->collector_id, 'generation' => (int) $session->generation]);
    }

    private function acknowledgeClose(CctvLiveSession $session, array $result): bool
    {
        if ($result['request_uuid'] !== $session->uuid || (int) $session->generation !== $result['generation'] || ! in_array($result['status'], ['stopped', 'expired', 'revoked'], true) || $result['answer'] !== null) {
            return false;
        }
        $receipt = CctvRequestReceipt::query()->where('owner', 'hikvision_live_close:'.$session->collector_id)->where('request_key', $session->uuid)->lockForUpdate()->first();
        if (! $receipt) {
            return false;
        }
        abort_unless(hash_equals($receipt->input_hash, $this->closeHash($session)), 409, 'context_changed');
        $hash = CctvContract::hash($result);
        if ($receipt->response !== null) {
            abort_unless(hash_equals($receipt->response['result_sha256'], $hash), 409, 'context_changed');

            return true;
        }
        $receipt->response = ['result_sha256' => $hash, 'status' => $result['status'], 'acknowledged_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z')];
        $receipt->save();
        AuditLogger::logOrFail('cctv.hikvision.live_closed', Device::query()->findOrFail($session->camera_id), ['session_uuid' => $session->uuid, 'generation' => (int) $session->generation, 'status' => $result['status'], 'result_sha256' => $hash]);

        return true;
    }

    private function close(CctvLiveSession $session): array
    {
        return ['operation' => 'close', 'request_uuid' => $session->provider === 'hikvision' ? $session->uuid : (string) Str::uuid(), 'session_uuid' => $session->uuid, 'generation' => (int) $session->generation];
    }
}
