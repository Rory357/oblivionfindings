<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CctvRequestReceipt;
use App\Domain\SecurityDevices\Cctv\Models\HikvisionDiscoveryRequest;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseGrant;
use App\Domain\SecurityDevices\Credentials\Services\CredentialLeaseLifecycleService;
use App\Domain\SecurityDevices\Credentials\Services\CredentialReferenceRules;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class HikvisionDiscoveryRequests
{
    private const INPUT = ['expected_actor_id', 'request_key', 'expected_source_revision', 'collector_id', 'discovery_scope_id', 'credential_reference_id', 'allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin', 'purpose'];

    public const TERMINAL = ['observed', 'failed', 'revoked', 'expired'];

    public function create(int $actorId, int $deviceId, array $input): array
    {
        CctvContract::exact($input, self::INPUT);
        foreach (['expected_actor_id', 'collector_id', 'discovery_scope_id', 'credential_reference_id'] as $key) {
            CctvContract::positive($input[$key]);
        }
        CctvContract::positive($input['expected_source_revision'], true);
        CctvContract::uuid($input['request_key']);
        CctvContract::text($input['purpose'], 5, 2000);
        $configuration = [];
        foreach (['expected_source_revision', 'collector_id', 'discovery_scope_id', 'credential_reference_id', 'allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin'] as $key) {
            $configuration[$key] = $input[$key];
        }
        HikvisionDiscoveryContract::endpoint(array_intersect_key($configuration, array_flip(['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin'])));
        $ordered = [];
        foreach (self::INPUT as $key) {
            $ordered[$key] = $input[$key];
        }
        $hash = CctvContract::hash(['device_id' => $deviceId, 'input' => $ordered]);

        return DB::transaction(function () use ($actorId, $deviceId, $input, $configuration, $hash): array {
            User::query()->whereKey($actorId)->lock('for update nowait')->firstOrFail();
            app(CctvAuthority::class)->actor($actorId, true);
            abort_unless($actorId === $input['expected_actor_id'], 404, 'unavailable');
            $known = HikvisionDiscoveryRequest::query()->where('actor_id', $actorId)->where('request_key', $input['request_key'])->lockForUpdate()->first();
            if ($known) {
                abort_unless(hash_equals($known->input_hash, $hash), 409, 'context_changed');

                return $this->present($actorId, $known);
            }
            abort_unless(config('cctv.hikvision_discovery_enabled') === true, 503, 'temporarily_unavailable');
            MonitoringCollector::query()->whereKey($input['collector_id'])->lock('for update nowait')->firstOrFail();
            $current = app(HikvisionDiscoveryAuthority::class)->current($actorId, $deviceId, $configuration);
            abort_if(HikvisionDiscoveryRequest::query()->where('collector_id', $current['collector']->id)->whereNotIn('status', self::TERMINAL)->where('request_expires_at', '>', now())->count() >= 64, 409, 'capacity_unavailable');
            $row = HikvisionDiscoveryRequest::query()->create(['uuid' => (string) Str::uuid(), 'actor_id' => $actorId, 'device_id' => $deviceId, 'site_id' => $current['site'], 'collector_id' => $current['collector']->id, 'discovery_scope_id' => $input['discovery_scope_id'], 'credential_reference_id' => $input['credential_reference_id'], 'request_key' => $input['request_key'], 'input_hash' => $hash, 'source_revision' => $input['expected_source_revision'], 'actor_fingerprint' => CctvAuthority::fingerprint($current['actor']), 'context_fingerprint' => $current['context'], 'credential_fingerprint' => $current['credential'], 'purpose' => $input['purpose'], 'configuration' => $configuration, 'status' => 'queued', 'request_expires_at' => CarbonImmutable::now('UTC')->addMinutes(5)]);
            $this->audit($row, 'requested', false);

            return $this->present($actorId, $row);
        });
    }

    public function read(int $actorId, string $uuid): array
    {
        CctvContract::uuid($uuid);

        return DB::transaction(function () use ($actorId, $uuid): array {
            app(CctvAuthority::class)->actor($actorId, true);
            $row = HikvisionDiscoveryRequest::query()->where('uuid', $uuid)->where('actor_id', $actorId)->lockForUpdate()->first();
            abort_unless($row, 404, 'unavailable');

            return $this->present($actorId, $row);
        });
    }

    public function recover(int $actorId, string $key, mixed $expected): array
    {
        CctvContract::uuid($key);
        abort_unless(is_string($expected) && preg_match('/\A[1-9][0-9]{0,18}\z/', $expected) && (string) $actorId === $expected, 404, 'unavailable');

        return DB::transaction(function () use ($actorId, $key): array {
            app(CctvAuthority::class)->actor($actorId, true);
            $row = HikvisionDiscoveryRequest::query()->where('actor_id', $actorId)->where('request_key', $key)->lockForUpdate()->first();
            abort_unless($row, 404, 'unavailable');

            return $this->present($actorId, $row);
        });
    }

    public function work(MonitoringCollector $transport, array $input): array
    {
        CctvContract::exact($input, ['collector_id', 'request_key', 'capability']);
        $this->transportInput($transport, $input);
        abort_unless(is_array($input['capability']), 400, 'invalid_request');
        CctvContract::exact($input['capability'], array_key_exists('recorder_profile', $input['capability']) ? ['version', 'available', 'profile', 'recorder_profile'] : ['version', 'available', 'profile']);
        abort_unless(! array_key_exists('recorder_profile', $input['capability']) || $input['capability']['recorder_profile'] === null || $input['capability']['recorder_profile'] === HikvisionRecorderDiscoveryContract::PROFILE, 400, 'invalid_request');
        abort_unless($input['capability']['version'] === 1 && is_bool($input['capability']['available']) && $input['capability']['profile'] === 'hikvision_isapi_https_v1', 400, 'invalid_request');
        $prepared = DB::transaction(function () use ($transport, $input): array {
            $collector = $this->collector($transport);
            $receipt = $this->receipt($collector, 'work', $input);
            if ($receipt->response !== null) {
                $this->validateWorkReplay($collector, $receipt->response);

                return ['response' => $receipt->response];
            }
            // A concurrent exact retry cannot turn an unfinished preparation into
            // a different successful response under the same immutable key.
            abort_if(HikvisionDiscoveryRequest::query()->where('collector_id', $collector->id)->where('preparation_key', $input['request_key'])->where('status', 'preparing')->exists(), 503, 'temporarily_unavailable');
            if (config('cctv.hikvision_discovery_enabled') !== true || ! $input['capability']['available']) {
                return ['response' => $this->finishWork($receipt, [])];
            }
            $pending = HikvisionDiscoveryRequest::query()->where('collector_id', $collector->id)->whereNotIn('status', self::TERMINAL);
            if (($input['capability']['recorder_profile'] ?? null) !== HikvisionRecorderDiscoveryContract::PROFILE) {
                // Unsupported recorder work must not occupy the camera-only
                // collector's bounded candidate window or starve camera jobs.
                $pending->whereHas('device', fn ($query) => $query->where('subcategory', '!=', 'nvr'));
            }
            $rows = $pending->orderBy('id')->limit(16)->lockForUpdate()->get();
            foreach ($rows as $row) {
                $this->expire($row);
                if (in_array($row->status, self::TERMINAL, true)) {
                    continue;
                }
                try {
                    $current = app(HikvisionDiscoveryAuthority::class)->request($row);
                } catch (HttpExceptionInterface) {
                    $this->end($row, 'revoked', 'authority_lost');

                    continue;
                }
                abort_unless((int) $current['collector']->id === (int) $collector->id, 404, 'unavailable');
                if ($current['capability'] === HikvisionRecorderDiscoveryContract::CAPABILITY && ($input['capability']['recorder_profile'] ?? null) !== HikvisionRecorderDiscoveryContract::PROFILE) {
                    continue;
                }
                if ($row->status === 'preparing') {
                    return ['response' => $this->finishWork($receipt, [])];
                }
                if ($row->status === 'dispatched') {
                    return ['response' => $this->finishWork($receipt, [$this->operation($row)])];
                }
                $row->status = 'preparing';
                $row->preparation_key = $input['request_key'];
                $row->preparation_until = CarbonImmutable::now('UTC')->addSeconds(30);
                $row->save();

                return ['id' => (int) $row->id, 'site' => (int) $row->site_id, 'reference' => $current['reference']->reference_key, 'receipt_id' => (int) $receipt->id, 'capability' => $current['capability']];
            }

            return ['response' => $this->finishWork($receipt, [])];
        });
        if (isset($prepared['response'])) {
            return $prepared['response'];
        }
        $lease = null;
        $material = [];
        $keep = false;
        try {
            // External credential retrieval happens without actor/device/collector
            // transaction locks; its own governed broker records the lease.
            $lease = app(CredentialLeaseProvider::class)->acquire($prepared['site'], $prepared['reference'], [$prepared['capability']]);
            $material = $lease->material();
            CctvContract::exact($material, ['username', 'password']);
            $response = DB::transaction(function () use ($transport, $input, $prepared, $lease, $material): array {
                $collector = $this->collector($transport);
                $receipt = CctvRequestReceipt::query()->whereKey($prepared['receipt_id'])->lockForUpdate()->firstOrFail();
                abort_unless(hash_equals($receipt->input_hash, CctvContract::hash($input)) && $receipt->response === null, 409, 'context_changed');
                $row = HikvisionDiscoveryRequest::query()->whereKey($prepared['id'])->lockForUpdate()->firstOrFail();
                $this->expire($row);
                if ($row->status !== 'preparing' || $row->preparation_key !== $input['request_key'] || config('cctv.hikvision_discovery_enabled') !== true) {
                    return $this->finishWork($receipt, []);
                }
                try {
                    $current = app(HikvisionDiscoveryAuthority::class)->request($row);
                } catch (HttpExceptionInterface) {
                    $this->end($row, 'revoked', 'authority_lost');

                    return $this->finishWork($receipt, []);
                }
                $grant = CredentialLeaseGrant::query()->where('lease_fingerprint', app(CredentialReferenceRules::class)->fingerprint($lease->leaseId))->lockForUpdate()->first();
                abort_unless($grant && (int) $grant->credential_reference_id === (int) $row->credential_reference_id && (int) $grant->reference_version === (int) $current['reference']->version && $grant->status === CredentialLeaseGrant::STATUS_ISSUED && in_array($current['capability'], $grant->capabilities ?? [], true), 503, 'temporarily_unavailable');
                $issued = CarbonImmutable::now('UTC')->startOfSecond();
                $expires = $issued->addSeconds(15)->min($lease->expiresAt->startOfSecond())->min($row->request_expires_at->startOfSecond());
                abort_unless($expires->gt($issued), 503, 'temporarily_unavailable');
                $source = array_intersect_key($row->configuration, array_flip(['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin']));
                $source += ['username' => $material['username'], 'password' => $material['password']];
                $secret = base64_decode((string) config('monitoring.collector.signing_secret_key'), true);
                abort_unless(is_string($secret) && strlen($secret) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 503, 'temporarily_unavailable');
                try {
                    $contract = $current['capability'] === HikvisionRecorderDiscoveryContract::CAPABILITY ? HikvisionRecorderDiscoveryContract::class : HikvisionDiscoveryContract::class;
                    $operation = $contract::issue($collector, ['collector_uuid' => $collector->collector_uuid, 'site_id' => (int) $row->site_id, 'device_id' => (int) $row->device_id, 'discovery_scope_id' => (int) $row->discovery_scope_id, 'request_uuid' => $row->uuid, 'context_sha256' => $row->context_fingerprint, 'credential_binding_sha256' => $row->credential_fingerprint], $source, $issued, $expires, $secret);
                } finally {
                    sodium_memzero($secret);
                    foreach ($source as &$value) {
                        if (is_string($value)) {
                            sodium_memzero($value);
                        }
                    }unset($value);
                }
                $row->operation = $operation;
                $row->operation_hash = CctvContract::hash($operation);
                $row->issued_at = $issued;
                $row->authority_until = $expires;
                $row->credential_lease_grant_id = $grant->id;
                $row->status = 'dispatched';
                $row->save();
                $this->audit($row, 'dispatched', true);
                $response = $this->finishWork($receipt, [$operation]);

                return $response;
            });
            $keep = $response['operations'] !== [];

            return $response;
        } catch (Throwable $exception) {
            // No prepared request is silently re-issued after an uncertain lease
            // step; it becomes a durable failure and needs a new reviewed request.
            DB::transaction(function () use ($prepared): void {
                $row = HikvisionDiscoveryRequest::query()->whereKey($prepared['id'])->lockForUpdate()->first();
                if ($row && $row->status === 'preparing') {
                    $this->end($row, 'failed', 'credential_unavailable');
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
            }unset($value);
            if ($lease !== null && ! $keep) {
                app(CredentialLeaseLifecycleService::class)->release($lease);
            }
        }
    }

    public function results(MonitoringCollector $transport, array $input): array
    {
        CctvContract::exact($input, ['collector_id', 'request_key', 'results']);
        $this->transportInput($transport, $input);
        abort_unless(is_array($input['results']) && array_is_list($input['results']) && count($input['results']) <= 1, 400, 'invalid_request');
        foreach ($input['results'] as $result) {
            abort_unless(is_array($result), 400, 'invalid_request');
            if (($result['version'] ?? null) === 2 && ($result['operation'] ?? null) === HikvisionRecorderDiscoveryContract::OPERATION) {
                HikvisionRecorderDiscoveryContract::result($result);
            } else {
                HikvisionDiscoveryContract::result($result);
            }
        }

        return DB::transaction(function () use ($transport, $input): array {
            $collector = $this->collector($transport);
            $receipt = $this->receipt($collector, 'results', $input);
            $accepted = [];
            foreach ($input['results'] as $result) {
                $row = HikvisionDiscoveryRequest::query()->where('uuid', $result['request_uuid'])->where('collector_id', $collector->id)->lockForUpdate()->first();
                abort_unless($row, 404, 'unavailable');
                $current = app(HikvisionDiscoveryAuthority::class)->request($row);
                $recorder = $current['capability'] === HikvisionRecorderDiscoveryContract::CAPABILITY;
                abort_unless($result['version'] === ($recorder ? 2 : 1) && $result['operation'] === ($recorder ? HikvisionRecorderDiscoveryContract::OPERATION : HikvisionDiscoveryContract::OPERATION), 409, 'context_changed');
                abort_unless((int) $row->device_id === $result['device_id'] && hash_equals($row->context_fingerprint, $result['context_sha256']) && hash_equals($row->credential_fingerprint, $result['credential_binding_sha256']), 409, 'context_changed');
                $hash = CctvContract::hash($result);
                if ($row->result_hash !== null) {
                    abort_unless(hash_equals($row->result_hash, $hash), 409, 'context_changed');
                } else {
                    abort_unless(config('cctv.hikvision_discovery_enabled') === true && $row->status === 'dispatched' && $row->authority_until->gt(now()) && $row->request_expires_at->gt(now()), 409, 'context_changed');
                    $observed = CarbonImmutable::parse($result['observed_at']);
                    abort_unless($observed->gte($row->issued_at) && $observed->lt($row->authority_until) && $observed->lte(CarbonImmutable::now('UTC')), 409, 'context_changed');
                    $row->result = $result;
                    $row->result_hash = $hash;
                    $row->observed_at = $observed;
                    $row->status = $result['status'];
                    $row->reason_code = $result['reason_code'];
                    $row->operation = null;
                    $row->save();
                    $this->audit($row, 'result_recorded', true);
                    $this->releaseAfterCommit($row);
                }
                $accepted[] = ['request_uuid' => $row->uuid, 'result_sha256' => $row->result_hash];
            }
            if ($receipt->response !== null) {
                abort_unless($receipt->response === ['accepted' => $accepted], 409, 'context_changed');

                return $receipt->response;
            }
            $receipt->response = ['accepted' => $accepted];
            $receipt->expires_at = null;
            $receipt->save();

            return $receipt->response;
        });
    }

    private function present(int $actorId, HikvisionDiscoveryRequest $row): array
    {
        app(HikvisionDiscoveryAuthority::class)->reader($actorId, $row);
        $this->expire($row);
        $current = false;
        try {
            app(HikvisionDiscoveryAuthority::class)->request($row);
            $current = true;
        } catch (HttpExceptionInterface) {
        }

        return ['actor_id' => $actorId, 'request_uuid' => $row->uuid, 'device_id' => (int) $row->device_id, 'site_id' => (int) $row->site_id, 'source_revision' => (int) $row->source_revision, 'status' => $row->status, 'reason_code' => $row->reason_code, 'current_context' => $current, 'execution_enabled' => config('cctv.hikvision_discovery_enabled') === true, 'created_at' => $row->created_at->utc()->format('Y-m-d\TH:i:s\Z'), 'observed_at' => $row->observed_at?->utc()->format('Y-m-d\TH:i:s\Z'), 'inventory' => $row->result['inventory'] ?? null, 'reader_href' => '/security-devices/cctv/hikvision-discoveries/'.$row->uuid];
    }

    public function expirePending(): int
    {
        $count = 0;
        HikvisionDiscoveryRequest::query()->whereNotIn('status', self::TERMINAL)->where(function ($query) {
            $query->where('request_expires_at', '<=', now())->orWhere(fn ($q) => $q->where('status', 'preparing')->where('preparation_until', '<=', now()))->orWhere(fn ($q) => $q->where('status', 'dispatched')->where('authority_until', '<=', now()));
        })->select('id')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $item) {
                DB::transaction(function () use ($item, &$count) {
                    $row = HikvisionDiscoveryRequest::query()->whereKey($item->id)->lockForUpdate()->first();
                    if ($row) {
                        $before = $row->status;
                        $this->expire($row);
                        if ($before !== $row->status) {
                            $count++;
                        }
                    }
                });
            }
        });

        return $count;
    }

    private function expire(HikvisionDiscoveryRequest $row): void
    {
        if (in_array($row->status, self::TERMINAL, true)) {
            return;
        }
        if ($row->request_expires_at->lte(now())) {
            $this->end($row, 'expired', 'request_expired');
        } elseif ($row->status === 'preparing' && $row->preparation_until->lte(now())) {
            $this->end($row, 'failed', 'preparation_lost');
        } elseif ($row->status === 'dispatched' && $row->authority_until->lte(now())) {
            $this->end($row, 'expired', 'lease_expired');
        }
    }

    private function end(HikvisionDiscoveryRequest $row, string $status, string $reason): void
    {
        if (in_array($row->status, self::TERMINAL, true)) {
            return;
        }$row->status = $status;
        $row->reason_code = $reason;
        $row->operation = null;
        $row->save();
        $this->audit($row, $status, true);
        $this->releaseAfterCommit($row);
    }

    private function releaseAfterCommit(HikvisionDiscoveryRequest $row): void
    {
        if ($row->credential_lease_grant_id !== null) {
            $id = (int) $row->credential_lease_grant_id;
            DB::afterCommit(fn () => app(CredentialLeaseLifecycleService::class)->releaseGrant($id));
        }
    }

    private function operation(HikvisionDiscoveryRequest $row): array
    {
        abort_unless(is_array($row->operation) && hash_equals($row->operation_hash, CctvContract::hash($row->operation)) && $row->authority_until->gt(now()), 409, 'context_changed');

        return $row->operation;
    }

    private function validateWorkReplay(MonitoringCollector $collector, array $response): void
    {
        foreach ($response['operations'] as $operation) {
            abort_unless(config('cctv.hikvision_discovery_enabled') === true, 409, 'context_changed');
            $row = HikvisionDiscoveryRequest::query()->where('uuid', $operation['request_uuid'])->where('collector_id', $collector->id)->lockForUpdate()->first();
            abort_unless($row && $row->status === 'dispatched', 409, 'context_changed');
            app(HikvisionDiscoveryAuthority::class)->request($row);
            abort_unless($this->operation($row) === $operation, 409, 'context_changed');
        }
    }

    private function finishWork(CctvRequestReceipt $receipt, array $operations): array
    {
        $response = ['server_now' => CarbonImmutable::now('UTC')->startOfSecond()->format('Y-m-d\TH:i:s\Z'), 'operations' => $operations];
        $receipt->response = $response;
        $receipt->expires_at = CarbonImmutable::now('UTC')->addSeconds(15);
        foreach ($operations as $operation) {
            $payload = json_decode(base64_decode($operation['grant']['payload'], true), true, 16, JSON_THROW_ON_ERROR);
            $receipt->expires_at = $receipt->expires_at->min(CarbonImmutable::parse($payload['expires_at']));
        }
        $receipt->save();

        return $response;
    }

    private function collector(MonitoringCollector $transport): MonitoringCollector
    {
        $collector = MonitoringCollector::query()->whereKey($transport->id)->lockForUpdate()->first();
        abort_unless($collector && $collector->enrolled_at && ! $collector->revoked_at && $collector->status !== 'revoked' && $collector->collector_uuid === $transport->collector_uuid && $collector->public_key === $transport->public_key && $collector->client_certificate_fingerprint === $transport->client_certificate_fingerprint, 404, 'unavailable');

        return $collector;
    }

    private function transportInput(MonitoringCollector $transport, array $input): void
    {
        CctvContract::uuid($input['collector_id']);
        CctvContract::uuid($input['request_key']);
        abort_unless($input['collector_id'] === $transport->collector_uuid, 404, 'unavailable');
    }

    private function receipt(MonitoringCollector $collector, string $kind, array $input): CctvRequestReceipt
    {
        $owner = 'hikvision_'.$kind.':'.$collector->id;
        $hash = CctvContract::hash($input);
        $receipt = CctvRequestReceipt::query()->where('owner', $owner)->where('request_key', $input['request_key'])->lockForUpdate()->first();
        if ($receipt) {
            abort_unless(hash_equals($receipt->input_hash, $hash) && ($receipt->expires_at === null || $receipt->expires_at->gt(now())), 409, 'context_changed');

            return $receipt;
        }

        return CctvRequestReceipt::query()->create(['owner' => $owner, 'request_key' => $input['request_key'], 'input_hash' => $hash, 'response' => null, 'expires_at' => $kind === 'results' ? null : CarbonImmutable::now('UTC')->addSeconds(30)]);
    }

    private function audit(HikvisionDiscoveryRequest $row, string $state, bool $system): void
    {
        AuditLogger::logOrFail('cctv.hikvision.discovery.'.$state, $row, ['actor_id' => (int) $row->actor_id, 'request_uuid' => $row->uuid, 'device_id' => (int) $row->device_id, 'site_id' => (int) $row->site_id, 'after' => ['status' => $row->status]], systemActor: $system);
    }
}
