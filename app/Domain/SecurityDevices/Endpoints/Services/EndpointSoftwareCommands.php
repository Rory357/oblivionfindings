<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointDelivery;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointSoftwarePackage;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Exceptions\CommandDispatchPreconditionException;
use App\Domain\SecurityDevices\Management\Jobs\ReconcileDeviceCommand;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandAuditService;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Native approved MSI transport extends the canonical request/attempt lifecycle, never a second command owner. */
final class EndpointSoftwareCommands
{
    public function __construct(private readonly EndpointAgentIdentity $identity, private readonly EndpointCommandSigner $signer, private readonly GovernedCommandDispatchService $governance, private readonly DeviceCommandAuditService $audit) {}

    public function prepare(DeviceCommandRequest $request, DeviceCommandAttempt $attempt): EndpointDelivery
    {
        abort_unless(DB::transactionLevel() > 0 && $request->capability === EndpointSoftwareContract::CAPABILITY && $request->status === CommandStatus::Dispatching && $attempt->runtime === 'endpoint' && (int) $attempt->device_command_request_id === (int) $request->id, 409);
        $agent = app(EndpointSoftwareAuthority::class)->assertRequest($request, $request->requestedBy);
        $package = app(EndpointSoftwarePackages::class)->published($request->encrypted_parameters['package_uuid']);
        $payload = ['version' => 1, 'type' => 'endpoint_software_install', 'agent_uuid' => $agent->uuid, 'command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid, 'assignment_fingerprint' => $agent->assignment_fingerprint, 'capability' => EndpointSoftwareContract::CAPABILITY, 'parameters' => app(EndpointSoftwarePackages::class)->signedParameters($package), 'policy' => ['version' => $request->expected_state['policy_version'], 'enabled' => true, 'consent_mode' => $request->expected_state['consent_mode']], 'technician' => $this->display($request->requestedBy->name, 200), 'purpose' => $this->display($request->reason, 500), 'issued_at' => $this->time(now()), 'expires_at' => $this->time($request->expires_at)];
        $signed = $this->signer->sign($payload);

        return EndpointDelivery::query()->create(['endpoint_agent_id' => $agent->id, 'device_command_attempt_id' => $attempt->id, 'agent_uuid' => $agent->uuid, 'encrypted_payload' => $signed, 'payload_hash' => hash('sha256', base64_decode($signed['payload'], true)), 'expires_at' => $request->expires_at]);
    }

    public function poll(string $credential): array
    {
        return $this->authenticated($credential, function (EndpointAgent $agent): array {
            $candidates = EndpointDelivery::query()->where('endpoint_agent_id', $agent->id)->where('agent_uuid', $agent->uuid)->whereHas('attempt.request', fn ($q) => $q->where('capability', EndpointSoftwareContract::CAPABILITY))->whereNull('completed_at')->whereNull('claimed_at')->where('expires_at', '>', now())->orderBy('id')->limit(10)->get();
            foreach ($candidates as $candidate) {
                [$request,$attempt,$delivery] = $this->locked($agent, $candidate->attempt->attempt_uuid);
                try {
                    $this->assertCommand($request, $delivery);
                    $this->governance->assertEndpointDelivery($request);
                } catch (ValidationException|CommandDispatchPreconditionException|HttpExceptionInterface) {
                    continue;
                }$delivery->delivered_at ??= now();
                $delivery->save();

                return ['command' => $delivery->encrypted_payload];
            }

            return ['command' => null];
        });
    }

    /** Download a verified private snapshot, then recheck current execution authority before serving it. */
    public function artifact(string $credential, string $attemptUuid): array
    {
        $authorize = function (EndpointAgent $agent) use ($attemptUuid): array {
            [$request,$attempt,$delivery] = $this->locked($agent, $attemptUuid);
            abort_if($delivery->completed_at || $delivery->claimed_at || $delivery->expires_at->lessThanOrEqualTo(now()), 409);
            $this->assertCommand($request, $delivery);
            $this->governance->assertEndpointDelivery($request);

            return [app(EndpointSoftwarePackages::class)->published($request->encrypted_parameters['package_uuid']), $delivery->payload_hash];
        };
        [$package,$payloadHash] = $this->authenticated($credential, $authorize);
        $stream = app(EndpointSoftwarePackages::class)->copy($package);
        try {
            [$current,$currentHash] = $this->authenticated($credential, $authorize);
            abort_unless($current->uuid === $package->uuid && $current->metadata_hash === $package->metadata_hash && hash_equals($payloadHash, $currentHash), 409);
        } catch (\Throwable $e) {
            fclose($stream);
            throw $e;
        }

        return ['stream' => $stream, 'size_bytes' => $package->size_bytes];
    }

    public function claim(string $credential, string $attemptUuid, array $input): array
    {
        abort_if(array_diff(array_keys($input), ['command_uuid', 'payload_hash', 'artifact_sha256', 'boot_id', 'consent']) !== [], 422);
        $data = Validator::make($input, ['command_uuid' => ['required', 'uuid'], 'payload_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/'], 'artifact_sha256' => ['required', 'regex:/\A[a-f0-9]{64}\z/'], 'boot_id' => ['required', 'uuid'], 'consent' => ['sometimes', 'required', 'array:desktop_session,consent_nonce', 'size:2'], 'consent.desktop_session' => ['required_with:consent', 'uuid'], 'consent.consent_nonce' => ['required_with:consent', 'regex:/\A[a-f0-9]{64}\z/']])->validate();

        return $this->authenticated($credential, function (EndpointAgent $agent) use ($attemptUuid, $data): array {
            [$request,$attempt,$delivery] = $this->locked($agent, $attemptUuid);
            $this->matches($request, $delivery, $data);
            abort_if($delivery->completed_at || $delivery->expires_at->lessThanOrEqualTo(now()), 409);
            $command = $this->assertCommand($request, $delivery);
            $this->governance->assertEndpointDelivery($request);
            abort_unless(hash_equals($command['parameters']['sha256'], $data['artifact_sha256']) && is_string($agent->boot_id) && hash_equals($agent->boot_id, $data['boot_id']), 409);
            $attended = $command['policy']['consent_mode'] === 'attended';
            abort_unless($attended === isset($data['consent']), 422);
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            if ($delivery->encrypted_claim) {
                $existing = json_decode(base64_decode($delivery->encrypted_claim['payload'], true), true, 512, JSON_THROW_ON_ERROR);
                abort_unless($existing['boot_id'] === $data['boot_id'] && $existing['artifact_sha256'] === $data['artifact_sha256'] && $existing['desktop_session'] === ($data['consent']['desktop_session'] ?? null) && $existing['consent_nonce'] === ($data['consent']['consent_nonce'] ?? null) && $delivery->claim_expires_at->greaterThan($now), 409);

                return $delivery->encrypted_claim;
            }
            abort_unless($attempt->status === CommandAttemptStatus::Dispatching, 409);
            app(EndpointMutationGuard::class)->assertAvailable($agent, $delivery);
            $expires = $now->addSeconds(30)->min($delivery->expires_at);
            $claim = ['version' => 1, 'type' => 'endpoint_software_claim', 'agent_uuid' => $agent->uuid, 'command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid, 'assignment_fingerprint' => $agent->assignment_fingerprint, 'payload_hash' => $delivery->payload_hash, 'policy_version' => $command['policy']['version'], 'consent_mode' => $command['policy']['consent_mode'], 'artifact_sha256' => $data['artifact_sha256'], 'boot_id' => $data['boot_id'], 'desktop_session' => $data['consent']['desktop_session'] ?? null, 'consent_nonce' => $data['consent']['consent_nonce'] ?? null, 'issued_at' => $this->time($now), 'expires_at' => $this->time($expires)];
            $delivery->encrypted_claim = $this->signer->sign($claim);
            $delivery->claimed_at = $now;
            $delivery->claim_expires_at = $expires;
            $delivery->status = 'claimed';
            $delivery->save();
            $attempt->status = CommandAttemptStatus::Accepted;
            $attempt->accepted_at = $now;
            $attempt->save();
            $request->status = CommandStatus::Accepted;
            $request->save();
            $this->audit->append($request, null, 'endpoint_software_claimed', ['attempt_number' => $attempt->attempt_number, 'consent_mode' => $command['policy']['consent_mode']]);

            return $delivery->encrypted_claim;
        });
    }

    public function result(string $credential, string $attemptUuid, array $input): array
    {
        $keys = ['command_uuid', 'payload_hash', 'status', 'mutation_started', 'reason_code', 'exit_code', 'reboot_required', 'boot_id'];
        abort_if(array_diff(array_keys($input), $keys) !== [], 422);
        $data = Validator::make($input, ['command_uuid' => ['required', 'uuid'], 'payload_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/'], 'status' => ['required', 'in:awaiting_observation,failed,uncertain,declined,expired'], 'mutation_started' => ['required', 'boolean:strict'], 'reason_code' => ['required', 'in:'.implode(',', EndpointSoftwareContract::REASONS)], 'exit_code' => ['present', 'nullable', 'integer:strict', 'min:0', 'max:4294967295'], 'reboot_required' => ['required', 'boolean:strict'], 'boot_id' => ['required', 'uuid']])->validate();
        EndpointSoftwareContract::validateResult($data);
        $data = array_replace(array_fill_keys($keys, null), $data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $this->authenticated($credential, function (EndpointAgent $agent) use ($attemptUuid, $data, $hash): array {
            [$request,$attempt,$delivery] = $this->locked($agent, $attemptUuid);
            $this->matches($request, $delivery, $data);
            $ack = ['command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid, 'payload_hash' => $delivery->payload_hash, 'accepted' => true];
            if ($delivery->result_hash) {
                abort_unless(hash_equals($delivery->result_hash, $hash), 409);

                return $ack;
            }
            if ($data['mutation_started']) {
                abort_unless($delivery->encrypted_claim, 409);
                $claim = json_decode(base64_decode($delivery->encrypted_claim['payload'], true), true, 512, JSON_THROW_ON_ERROR);
                abort_unless($claim['boot_id'] === $data['boot_id'], 409);
            }
            abort_unless(in_array($attempt->status, [CommandAttemptStatus::Dispatching, CommandAttemptStatus::Accepted, CommandAttemptStatus::Running, CommandAttemptStatus::Uncertain, CommandAttemptStatus::Expired], true), 409);
            $delivery->result = $data;
            $delivery->result_hash = $hash;
            $delivery->status = $data['status'];
            $delivery->completed_at = now();
            $delivery->save();
            if (! $attempt->status->isTerminal() && ! $request->status->isTerminal()) {
                $attempt->safe_result_summary = array_diff_key($data, array_flip(['command_uuid', 'payload_hash', 'boot_id']));
                $attempt->status = match ($data['status']) {
                    'awaiting_observation' => CommandAttemptStatus::Running,'uncertain' => CommandAttemptStatus::Uncertain,'expired' => CommandAttemptStatus::Expired,default => CommandAttemptStatus::Failed
                };
                $attempt->started_at = $data['mutation_started'] ? $delivery->claimed_at : null;
                $attempt->completed_at = $data['status'] === 'awaiting_observation' ? null : now();
                $attempt->save();
                $request->status = match ($data['status']) {
                    'awaiting_observation' => CommandStatus::Reconciling,'uncertain' => CommandStatus::Uncertain,'expired' => CommandStatus::Expired,default => CommandStatus::Failed
                };
                $request->safe_result_summary = json_encode($attempt->safe_result_summary, JSON_THROW_ON_ERROR);
                $request->execution_completed_at = $data['status'] === 'awaiting_observation' ? null : now();
                $request->save();
            }
            $this->audit->append($request, null, 'endpoint_software_result_'.$data['status'], ['attempt_number' => $attempt->attempt_number, 'mutation_started' => $data['mutation_started'], 'reason_code' => $data['reason_code'], 'reboot_required' => $data['reboot_required']]);

            return $ack;
        });
    }

    public function observations(EndpointAgent $agent, array $entries, CarbonImmutable $observed, int $sequence, string $bootId): array
    {
        $accepted = [];
        foreach ($entries as $entry) {
            try {
                [$request,$attempt,$delivery] = $this->locked($agent, $entry['attempt_uuid']);
            } catch (ModelNotFoundException|HttpExceptionInterface) {
                continue;
            }
            if ($entry['command_uuid'] !== $request->command_uuid || $entry['product_code'] !== ($request->expected_state['product_code'] ?? null) || $entry['boot_id'] !== $bootId || ! $entry['transaction_quiescent'] || $entry['state'] === 'unknown' || ! $delivery->completed_at || ($delivery->result['mutation_started'] ?? false) !== true) {
                continue;
            }
            if (in_array($entry['state'], ['installed', 'advertised'], true)) {
                if (! is_string($entry['product_version'])) {
                    continue;
                }
                $parts = array_map('intval', explode('.', $entry['product_version']));
                if (count($parts) !== 3 || $parts[0] > 255 || $parts[1] > 255 || $parts[2] > 65535) {
                    continue;
                }
            } elseif ($entry['product_version'] !== null) {
                continue;
            }
            $time = CarbonImmutable::parse($entry['observed_at']);
            if (! $time->greaterThan($delivery->completed_at) || $time->greaterThan($observed) || $time->lessThan(now()->subSeconds(120))) {
                continue;
            }
            if (! $delivery->software_readback || CarbonImmutable::parse($delivery->software_readback['observed_at'])->lessThan($time)) {
                $delivery->software_readback = $entry;
                $delivery->readback_sequence = $sequence;
                $delivery->readback_received_at = now();
                $delivery->save();
                if ($delivery->result['status'] === 'awaiting_observation' && in_array($request->status, [CommandStatus::Reconciling, CommandStatus::Uncertain], true)) {
                    ReconcileDeviceCommand::dispatch($request->id)->afterCommit();
                }
            }
            $accepted[] = $entry;
        }

        return $accepted;
    }

    public function acceptedObservations(EndpointAgent $agent): array
    {
        $accepted = [];
        foreach ($agent->software_observations ?? [] as $entry) {
            try {
                [$request,$attempt,$delivery] = $this->locked($agent, $entry['attempt_uuid']);
            } catch (ModelNotFoundException|HttpExceptionInterface) {
                continue;
            }
            if (! $delivery->software_readback || ! $this->sameObservation($delivery->software_readback, $entry) || $agent->boot_id !== $entry['boot_id'] || ! $entry['transaction_quiescent'] || $entry['state'] === 'unknown' || ! $delivery->readback_received_at || $delivery->readback_received_at->lessThanOrEqualTo(now()->subSeconds(180)) || CarbonImmutable::parse($entry['observed_at'])->lessThan(now()->subSeconds(120))) {
                continue;
            }
            $succeeded = $delivery->result['status'] === 'awaiting_observation' && $entry['state'] === 'installed' && $entry['product_version'] === ($request->expected_state['product_version'] ?? null);
            $accepted[] = $entry + ['status' => $succeeded ? 'succeeded' : 'uncertain'];
        }

        return $accepted;
    }

    private function sameObservation(array $stored, array $reported): bool
    {
        ksort($stored, SORT_STRING);
        ksort($reported, SORT_STRING);

        return $stored === $reported;
    }

    public function expire(int $limit = 100): int
    {
        if (! app(EndpointSoftwarePolicy::class)->schemaReady()) {
            return 0;
        }
        $candidates = EndpointDelivery::query()->whereHas('attempt.request', fn ($q) => $q->where('capability', EndpointSoftwareContract::CAPABILITY))->where('status', '!=', 'timed_out')->where(fn ($q) => $q->where(fn ($u) => $u->whereNull('completed_at')->where(fn ($expiry) => $expiry->where(fn ($unclaimed) => $unclaimed->whereNull('claimed_at')->where('expires_at', '<=', now()))->orWhere(fn ($claimed) => $claimed->whereNotNull('claimed_at')->where('expires_at', '<=', now()->subSeconds(120)))))->orWhere(fn ($u) => $u->where('status', 'awaiting_observation')->where('expires_at', '<=', now()->subSeconds(300))))->orderBy('id')->limit(max(1, min(500, $limit)))->get();
        $count = 0;
        foreach ($candidates as $candidate) {
            try {
                $count += DB::transaction(function () use ($candidate): int {
                    $found = DeviceCommandAttempt::query()->findOrFail($candidate->device_command_attempt_id);
                    $request = DeviceCommandRequest::query()->whereKey($found->device_command_request_id)->lock('for update nowait')->firstOrFail();
                    $attempt = DeviceCommandAttempt::query()->whereKey($found->id)->lock('for update nowait')->firstOrFail();
                    $delivery = EndpointDelivery::query()->whereKey($candidate->id)->lock('for update nowait')->firstOrFail();
                    if ($request->status->isTerminal() || $attempt->status->isTerminal() || $delivery->expires_at->isFuture() || $delivery->status === 'timed_out') {
                        return 0;
                    }
                    if (! $delivery->completed_at && $delivery->claimed_at && $delivery->expires_at->greaterThan(now()->subSeconds(120))) {
                        return 0;
                    }
                    if ($delivery->completed_at && ($delivery->status !== 'awaiting_observation' || $delivery->expires_at->greaterThan(now()->subSeconds(300)))) {
                        return 0;
                    }
                    $uncertain = (bool) $delivery->claimed_at;
                    $attempt->status = $uncertain ? CommandAttemptStatus::Uncertain : CommandAttemptStatus::Expired;
                    $attempt->completed_at = now();
                    $attempt->save();
                    $request->status = $uncertain ? CommandStatus::Uncertain : CommandStatus::Expired;
                    $request->execution_completed_at = now();
                    $request->safe_failure_reason = $uncertain ? 'Software installation completion is unconfirmed. Do not repeat this action.' : 'The software request expired without an execution claim.';
                    $request->save();
                    $delivery->status = 'timed_out';
                    if (! $uncertain) {
                        $delivery->completed_at = now();
                    } $delivery->save();
                    $this->audit->append($request, null, 'endpoint_software_deadline', ['attempt_number' => $attempt->attempt_number, 'status' => $request->status->value]);

                    return 1;
                });
            } catch (QueryException $e) {
                if (! in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                    throw $e;
                }
            }
        }

        return $count;
    }

    public function projection(DeviceCommandRequest $request, User $viewer, ?DeviceCommandAttempt $attempt): ?array
    {
        if ($request->capability !== EndpointSoftwareContract::CAPABILITY) {
            return null;
        }
        $delivery = $attempt ? EndpointDelivery::query()->where('device_command_attempt_id', $attempt->id)->first() : null;
        $state = match ($request->status) {
            CommandStatus::Reconciled => 'confirmed',CommandStatus::Reconciling => 'awaiting_observation',CommandStatus::Uncertain => 'uncertain',CommandStatus::Expired => 'expired',CommandStatus::Failed,CommandStatus::Rejected,CommandStatus::Blocked,CommandStatus::Mismatch => 'failed',default => 'requested'
        };
        $package = EndpointSoftwarePackage::query()->where('uuid', $request->encrypted_parameters['package_uuid'] ?? null)->first();

        $readback = $delivery?->software_readback;
        $reboot = $delivery?->result ? (bool) ($delivery->result['reboot_required'] ?? false) : null;
        if ($reboot && $readback && $readback['transaction_quiescent'] === true && $readback['boot_id'] !== ($delivery->result['boot_id'] ?? null) && ($delivery->result['status'] === 'uncertain' || ($readback['state'] === 'installed' && $readback['product_version'] === ($request->expected_state['product_version'] ?? null)))) {
            $reboot = false;
        }

        return ['packageUuid' => $request->encrypted_parameters['package_uuid'] ?? null, 'packageName' => $package?->name, 'publisher' => $package?->publisher, 'productCode' => $request->expected_state['product_code'] ?? null, 'productVersion' => $request->expected_state['product_version'] ?? null, 'architecture' => $request->expected_state['architecture'] ?? null, 'consentMode' => $request->expected_state['consent_mode'] ?? null, 'state' => $state, 'rebootRequired' => $reboot, 'exitCode' => $delivery->result['exit_code'] ?? null];
    }

    private function assertCommand(DeviceCommandRequest $request, EndpointDelivery $delivery): array
    {
        $signed = $delivery->encrypted_payload;
        $bytes = base64_decode($signed['payload'], true);
        $payload = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(hash_equals($delivery->payload_hash, hash('sha256', $bytes)) && sodium_crypto_sign_verify_detached(base64_decode($signed['signature'], true), $bytes, base64_decode($this->signer->publicKey(), true)), 409);
        $package = app(EndpointSoftwarePackages::class)->published($request->encrypted_parameters['package_uuid']);
        abort_unless(($payload['type'] ?? null) === 'endpoint_software_install' && $payload['command_uuid'] === $request->command_uuid && $payload['attempt_uuid'] === $delivery->attempt->attempt_uuid && $payload['agent_uuid'] === $delivery->agent_uuid && $payload['assignment_fingerprint'] === $request->assignment_fingerprint && $payload['parameters'] === app(EndpointSoftwarePackages::class)->signedParameters($package) && $payload['policy'] === ['version' => $request->expected_state['policy_version'], 'enabled' => true, 'consent_mode' => $request->expected_state['consent_mode']] && $payload['expires_at'] === $this->time($request->expires_at), 409);

        return $payload;
    }

    private function locked(EndpointAgent $agent, string $attemptUuid): array
    {
        $found = DeviceCommandAttempt::query()->where('attempt_uuid', $attemptUuid)->firstOrFail();
        $request = DeviceCommandRequest::query()->whereKey($found->device_command_request_id)->lock('for update nowait')->firstOrFail();
        $attempt = DeviceCommandAttempt::query()->whereKey($found->id)->lock('for update nowait')->firstOrFail();
        $delivery = EndpointDelivery::query()->where('device_command_attempt_id', $attempt->id)->where('endpoint_agent_id', $agent->id)->where('agent_uuid', $agent->uuid)->lock('for update nowait')->firstOrFail();
        abort_unless((int) $request->device_id === (int) $agent->device_id && $attempt->runtime === 'endpoint' && $request->capability === EndpointSoftwareContract::CAPABILITY && ($request->expected_state['agent_uuid'] ?? null) === $agent->uuid && ($request->expected_state['assignment_fingerprint'] ?? null) === $agent->assignment_fingerprint, 404);

        return [$request, $attempt, $delivery];
    }

    private function matches(DeviceCommandRequest $request, EndpointDelivery $delivery, array $data): void
    {
        abort_unless($request->command_uuid === $data['command_uuid'] && hash_equals($delivery->payload_hash, $data['payload_hash']), 409);
    }

    private function authenticated(string $credential, \Closure $callback): mixed
    {
        try {
            return $this->identity->withAuthenticated($credential, $callback);
        } catch (QueryException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                throw new HttpException(503);
            } throw $e;
        }
    }

    private function time(\DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function display(string $text, int $limit): string
    {
        return mb_substr(trim(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', preg_replace('/\s+/u', ' ', $text))), 0, $limit);
    }
}
