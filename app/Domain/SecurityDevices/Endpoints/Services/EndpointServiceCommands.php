<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointDelivery;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Exceptions\CommandDispatchPreconditionException;
use App\Domain\SecurityDevices\Management\Jobs\ReconcileDeviceCommand;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandAuditService;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class EndpointServiceCommands
{
    public const REASONS = ['confirmed', 'already_in_state', 'declined', 'expired', 'policy_unavailable', 'consent_lost', 'service_unavailable', 'service_busy', 'dependent_services', 'unsupported_service', 'disabled_service', 'operation_failed', 'operation_timeout', 'interrupted', 'state_unavailable'];

    /** Only the canonical dispatcher creates deliveries, beneath its request/attempt transaction. */
    public function prepare(DeviceCommandRequest $request, DeviceCommandAttempt $attempt): EndpointDelivery
    {
        if (DB::transactionLevel() < 1 || $attempt->runtime !== 'endpoint' || $request->status !== CommandStatus::Dispatching || $attempt->device_command_request_id !== $request->id) {
            throw new \LogicException('A canonical dispatch transaction owns native delivery.');
        }
        $agent = app(EndpointServiceAuthority::class)->assertRequest($request, $request->requestedBy);
        $state = $request->expected_state;
        $payload = ['version' => 2, 'type' => 'endpoint_service_command', 'agent_uuid' => $agent->uuid, 'command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid,
            'assignment_fingerprint' => $agent->assignment_fingerprint, 'capability' => EndpointServiceAuthority::CAPABILITY, 'parameters' => $request->encrypted_parameters,
            'policy' => ['version' => $state['policy_version'], 'enabled' => true, 'consent_mode' => $state['consent_mode'], 'catalogue_digest' => $state['catalogue_digest']],
            'technician' => self::display($request->requestedBy->name, 200), 'purpose' => self::display($request->reason, 500), 'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'expires_at' => $request->expires_at->utc()->format('Y-m-d\TH:i:s\Z')];
        $signed = app(EndpointCommandSigner::class)->sign($payload);

        return EndpointDelivery::query()->create(['endpoint_agent_id' => $agent->id, 'device_command_attempt_id' => $attempt->id, 'agent_uuid' => $agent->uuid,
            'encrypted_payload' => $signed, 'payload_hash' => hash('sha256', base64_decode($signed['payload'], true)), 'expires_at' => $request->expires_at]);
    }

    public function poll(string $credential): array
    {
        return $this->authenticated($credential, function (EndpointAgent $agent): array {
            if (! app(EndpointActionPolicy::class)->available()) {
                return ['command' => null];
            }
            $rows = EndpointDelivery::query()->where('endpoint_agent_id', $agent->id)->where('agent_uuid', $agent->uuid)->whereHas('attempt.request', fn ($q) => $q->where('capability', EndpointServiceAuthority::CAPABILITY))->whereNull('completed_at')->whereNull('claimed_at')->orderBy('id')->limit(10)->get();
            foreach ($rows as $candidate) {
                if ($candidate->expires_at->isPast()) {
                    continue;
                }
                [$delivery, $attempt, $request] = $this->locked($agent, $candidate->attempt->attempt_uuid);
                if ($delivery->completed_at || $delivery->claimed_at) {
                    continue;
                }
                try {
                    app(GovernedCommandDispatchService::class)->assertEndpointDelivery($request);
                } catch (ValidationException|CommandDispatchPreconditionException|HttpExceptionInterface) {
                    continue;
                }
                $delivery->delivered_at ??= now();
                $delivery->save();

                return ['command' => $delivery->encrypted_payload];
            }

            return ['command' => null];
        });
    }

    public function claim(string $credential, string $attemptUuid, array $input): array
    {
        $data = Validator::make($input, [
            'command_uuid' => ['required', 'uuid'], 'payload_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'consent' => ['sometimes', 'required', 'array:desktop_session,consent_nonce', 'size:2'],
            'consent.desktop_session' => ['required_with:consent', 'string', 'uuid'],
            'consent.consent_nonce' => ['required_with:consent', 'regex:/\A[a-f0-9]{64}\z/'],
        ])->validate();
        abort_if(array_diff(array_keys($input), ['command_uuid', 'payload_hash', 'consent']) !== [], 422);

        return $this->authenticated($credential, function (EndpointAgent $agent) use ($attemptUuid, $data): array {
            [$delivery, $attempt, $request] = $this->locked($agent, $attemptUuid);
            $this->matches($delivery, $request, $data);
            abort_if($delivery->completed_at, 409);
            app(GovernedCommandDispatchService::class)->assertEndpointDelivery($request);
            $attended = $request->expected_state['consent_mode'] === 'attended';
            abort_unless($attended === isset($data['consent']), 422);
            if ($delivery->encrypted_claim) {
                $existing = $delivery->encrypted_claim;
                $payload = json_decode(base64_decode($existing['payload'], true), true, flags: JSON_THROW_ON_ERROR);
                abort_unless($payload['desktop_session'] === ($data['consent']['desktop_session'] ?? null) && $payload['consent_nonce'] === ($data['consent']['consent_nonce'] ?? null), 409);
                abort_unless($delivery->claim_expires_at?->isFuture(), 409);

                return $existing;
            }
            abort_unless($delivery->expires_at->isFuture() && $attempt->status === CommandAttemptStatus::Dispatching, 409);
            app(EndpointMutationGuard::class)->assertAvailable($agent, $delivery);
            $expires = now()->addSeconds(30)->min($delivery->expires_at);
            $payload = ['version' => 1, 'type' => 'endpoint_service_claim', 'agent_uuid' => $agent->uuid, 'command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid,
                'assignment_fingerprint' => $agent->assignment_fingerprint, 'payload_hash' => $delivery->payload_hash, 'policy_version' => $request->expected_state['policy_version'],
                'catalogue_digest' => $request->expected_state['catalogue_digest'], 'consent_mode' => $request->expected_state['consent_mode'],
                'desktop_session' => $data['consent']['desktop_session'] ?? null, 'consent_nonce' => $data['consent']['consent_nonce'] ?? null, 'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'expires_at' => $expires->utc()->format('Y-m-d\TH:i:s\Z')];
            $delivery->encrypted_claim = app(EndpointCommandSigner::class)->sign($payload);
            $delivery->claimed_at = now();
            $delivery->claim_expires_at = $expires;
            $delivery->status = 'claimed';
            $delivery->save();
            $attempt->status = CommandAttemptStatus::Accepted;
            $attempt->accepted_at = now();
            $attempt->save();
            $request->status = CommandStatus::Accepted;
            $request->save();
            app(DeviceCommandAuditService::class)->append($request, $request->requestedBy, 'endpoint_claimed', ['attempt_number' => $attempt->attempt_number, 'consent_mode' => $payload['consent_mode']]);

            return $delivery->encrypted_claim;
        });
    }

    public function result(string $credential, string $attemptUuid, array $input): array
    {
        $fields = ['command_uuid', 'payload_hash', 'status', 'operation', 'service_name', 'before_state', 'after_state', 'mutation_started', 'reason_code'];
        abort_unless(count($input) === count($fields) && array_diff(array_keys($input), $fields) === [], 422);
        $data = Validator::make($input, [
            'command_uuid' => ['required', 'uuid'], 'payload_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'status' => ['required', 'in:succeeded,failed,uncertain,declined,expired'], 'operation' => ['required', 'in:start,stop,restart'],
            'service_name' => ['required', 'string', 'max:200'], 'before_state' => ['required', 'in:running,stopped,unknown'], 'after_state' => ['required', 'in:running,stopped,unknown'],
            'mutation_started' => ['required', 'boolean:strict'], 'reason_code' => ['required', 'in:'.implode(',', self::REASONS)],
        ])->validate();
        abort_unless(EndpointServiceCatalogue::safeName($data['service_name']), 422);
        $this->validateResult($data);
        // Fixed schema order is the immutable result contract, not browser object ordering.
        $data = array_replace(array_fill_keys($fields, null), $data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $this->authenticated($credential, function (EndpointAgent $agent) use ($attemptUuid, $data, $hash): array {
            [$delivery, $attempt, $request] = $this->locked($agent, $attemptUuid);
            $this->matches($delivery, $request, $data);
            abort_unless(($request->encrypted_parameters['service_name'] ?? null) === $data['service_name'] && ($request->encrypted_parameters['operation'] ?? null) === $data['operation'], 409);
            $ack = ['command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid, 'payload_hash' => $delivery->payload_hash, 'accepted' => true];
            if ($delivery->result_hash) {
                abort_unless(hash_equals($delivery->result_hash, $hash), 409);

                return $ack;
            }
            $safeUnclaimed = ! $data['mutation_started'] && in_array($data['status'], ['declined', 'expired', 'failed', 'uncertain'], true);
            abort_unless($delivery->claimed_at || $safeUnclaimed, 409);
            abort_unless(in_array($attempt->status, [CommandAttemptStatus::Dispatching, CommandAttemptStatus::Accepted, CommandAttemptStatus::Uncertain, CommandAttemptStatus::Expired], true), 409);
            $status = match ($data['status']) {
                'succeeded' => CommandAttemptStatus::Succeeded, 'uncertain' => CommandAttemptStatus::Uncertain, 'expired' => CommandAttemptStatus::Expired, default => CommandAttemptStatus::Failed
            };
            $delivery->result = $data;
            $delivery->result_hash = $hash;
            $delivery->completed_at = now();
            $delivery->status = 'completed';
            $delivery->save();
            $timedOut = $attempt->status->isTerminal();
            if (! $timedOut) {
                $attempt->status = $status;
                $attempt->safe_result_summary = array_diff_key($data, array_flip(['command_uuid', 'payload_hash']));
                $attempt->completed_at = now();
                $attempt->started_at = $data['mutation_started'] ? $delivery->claimed_at : null;
                $attempt->save();
                $request->status = match ($status) {
                    CommandAttemptStatus::Succeeded => CommandStatus::Reconciling, CommandAttemptStatus::Uncertain => CommandStatus::Uncertain, CommandAttemptStatus::Expired => CommandStatus::Expired, default => CommandStatus::Failed
                };
                $request->execution_completed_at = now();
                $request->safe_result_summary = json_encode($attempt->safe_result_summary, JSON_THROW_ON_ERROR);
                $request->save();
            }
            app(DeviceCommandAuditService::class)->append($request, null, 'endpoint_result_'.$data['status'], ['attempt_number' => $attempt->attempt_number, 'safe_result' => array_diff_key($data, array_flip(['command_uuid', 'payload_hash'])), 'late_after_timeout' => $timedOut]);
            if (in_array($status, [CommandAttemptStatus::Succeeded, CommandAttemptStatus::Uncertain], true)) {
                ReconcileDeviceCommand::dispatch($request->id, $request->requested_by_user_id)->onQueue((string) config('security_devices.command_queue', 'monitoring-commands'))->delay(now()->addSeconds(65))->afterCommit();
            }

            return $ack;
        });
    }

    /** Authenticated independently collected observations, never result.AfterState. */
    public function observations(EndpointAgent $agent, array $observations, CarbonImmutable $heartbeatObservedAt, ?int $sequence = null): array
    {
        foreach ($observations as $entry) {
            abort_unless(EndpointServiceCatalogue::safeName($entry['service_name']), 422);
            [$delivery, $attempt, $request] = $this->locked($agent, $entry['attempt_uuid']);
            $observed = CarbonImmutable::parse($entry['observed_at']);
            abort_unless($delivery->result_hash && $delivery->completed_at && $request->command_uuid === $entry['command_uuid']
                && ($request->encrypted_parameters['service_name'] ?? null) === $entry['service_name']
                && $observed->lessThanOrEqualTo($heartbeatObservedAt), 422);
            if (! $delivery->service_readback || $observed->greaterThan(CarbonImmutable::parse($delivery->service_readback['observed_at']))) {
                $delivery->service_readback = $entry;
                $delivery->readback_sequence = $sequence ?? $agent->sequence;
                $delivery->readback_received_at = now();
                $delivery->save();
            }
        }

        return $observations;
    }

    /** Transport acknowledgement is not sufficient evidence of an eligible measurement. */
    public function acceptedObservations(EndpointAgent $agent): array
    {
        $accepted = [];
        foreach ($agent->service_action_observations ?? [] as $entry) {
            [$delivery, $attempt, $request] = $this->locked($agent, $entry['attempt_uuid']);
            $observed = CarbonImmutable::parse($entry['observed_at']);
            if (in_array($entry['state'], ['running', 'stopped'], true)
                && $delivery->completed_at && $observed->greaterThan($delivery->completed_at)
                && $observed->greaterThanOrEqualTo(now()->subSeconds(360))
                && $agent->last_seen_at && $agent->last_seen_at->greaterThan(now()->subSeconds(180))
                && $agent->observed_at && $observed->lessThanOrEqualTo($agent->observed_at)
                && $request->command_uuid === $entry['command_uuid']) {
                $accepted[] = array_intersect_key($entry, array_flip(['command_uuid', 'attempt_uuid', 'observed_at']));
            }
        }

        return $accepted;
    }

    /** Bounded bookkeeping only. Never redispatch an expired or uncertain attempt. */
    public function expire(int $limit = 100): int
    {
        if (! app(EndpointActionPolicy::class)->schemaReady()) {
            return 0;
        }
        $candidates = EndpointDelivery::query()->whereHas('attempt.request', fn ($q) => $q->where('capability', EndpointServiceAuthority::CAPABILITY))->whereNull('completed_at')->where('status', '!=', 'timed_out')
            ->where(fn ($q) => $q->where(fn ($unclaimed) => $unclaimed->whereNull('claimed_at')->where('expires_at', '<=', now()))
                ->orWhere(fn ($claimed) => $claimed->whereNotNull('claimed_at')->where('claim_expires_at', '<=', now()->subSeconds(120))))
            ->orderBy('id')->limit(max(1, min(500, $limit)))->get();
        $count = 0;
        foreach ($candidates as $candidate) {
            try {
                $changed = DB::transaction(function () use ($candidate): bool {
                    $found = DeviceCommandAttempt::query()->findOrFail($candidate->device_command_attempt_id);
                    $request = DeviceCommandRequest::query()->whereKey($found->device_command_request_id)->lock('for update nowait')->firstOrFail();
                    $attempt = DeviceCommandAttempt::query()->whereKey($found->id)->lock('for update nowait')->firstOrFail();
                    $delivery = EndpointDelivery::query()->whereKey($candidate->id)->lock('for update nowait')->firstOrFail();
                    if ($delivery->completed_at || $delivery->status === 'timed_out' || ! in_array($attempt->status, [CommandAttemptStatus::Dispatching, CommandAttemptStatus::Accepted], true)) {
                        return false;
                    }
                    if ($delivery->claimed_at) {
                        if (! $delivery->claim_expires_at || $delivery->claim_expires_at->greaterThan(now()->subSeconds(120))) {
                            return false;
                        }
                        $attempt->status = CommandAttemptStatus::Uncertain;
                        $request->status = CommandStatus::Uncertain;
                        $delivery->status = 'timed_out';
                    } else {
                        if ($delivery->expires_at->isFuture()) {
                            return false;
                        }
                        $attempt->status = CommandAttemptStatus::Expired;
                        $request->status = CommandStatus::Expired;
                        $delivery->status = 'expired';
                        $delivery->completed_at = now();
                    }
                    $attempt->completed_at = now();
                    $attempt->safe_failure_reason = 'No confirmed native result was received. Do not repeat an uncertain action.';
                    $attempt->save();
                    $request->execution_completed_at = now();
                    $request->safe_failure_reason = $attempt->safe_failure_reason;
                    $request->save();
                    $delivery->save();
                    app(DeviceCommandAuditService::class)->append($request, null, 'endpoint_delivery_'.$delivery->status, ['attempt_number' => $attempt->attempt_number]);

                    return true;
                });
                $count += $changed ? 1 : 0;
            } catch (QueryException $error) {
                if (! in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                    throw $error;
                }
            }
        }

        return $count;
    }

    private function validateResult(array $data): void
    {
        if ($data['status'] === 'succeeded') {
            abort_unless(in_array($data['reason_code'], ['confirmed', 'already_in_state'], true) && $data['after_state'] === ($data['operation'] === 'stop' ? 'stopped' : 'running'), 422);
            abort_unless($data['reason_code'] !== 'already_in_state' || (! $data['mutation_started'] && $data['operation'] !== 'restart' && $data['before_state'] === $data['after_state']), 422);
            abort_unless($data['reason_code'] !== 'confirmed' || $data['mutation_started'], 422);
        } elseif (in_array($data['status'], ['declined', 'expired'], true)) {
            abort_unless(! $data['mutation_started'] && $data['reason_code'] === $data['status'], 422);
        } else {
            abort_unless(! in_array($data['reason_code'], ['confirmed', 'already_in_state', 'declined', 'expired'], true), 422);
            // A partially changed service cannot be asserted as a clean failed operation.
            abort_unless($data['status'] !== 'failed' || ! $data['mutation_started'], 422);
        }
    }

    private function authenticated(string $credential, \Closure $callback): mixed
    {
        try {
            return app(EndpointAgentIdentity::class)->withAuthenticated($credential, $callback);
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                throw new HttpException(503);
            } throw $error;
        }
    }

    private function locked(EndpointAgent $agent, string $attemptUuid): array
    {
        // Identity owns Device/Agent. Never block on the canonical request-first dispatcher chain.
        $candidate = DeviceCommandAttempt::query()->where('attempt_uuid', $attemptUuid)->firstOrFail();
        $request = DeviceCommandRequest::query()->whereKey($candidate->device_command_request_id)->lock('for update nowait')->firstOrFail();
        $attempt = DeviceCommandAttempt::query()->whereKey($candidate->id)->lock('for update nowait')->firstOrFail();
        $delivery = EndpointDelivery::query()->where('device_command_attempt_id', $attempt->id)->where('endpoint_agent_id', $agent->id)->where('agent_uuid', $agent->uuid)->lock('for update nowait')->firstOrFail();
        abort_unless($request->device_id === $agent->device_id && $attempt->runtime === 'endpoint' && $request->capability === EndpointServiceAuthority::CAPABILITY && ($request->expected_state['agent_uuid'] ?? null) === $agent->uuid && ($request->expected_state['assignment_fingerprint'] ?? null) === $agent->assignment_fingerprint, 404);

        return [$delivery, $attempt, $request];
    }

    private function matches(EndpointDelivery $delivery, DeviceCommandRequest $request, array $data): void
    {
        abort_unless($request->command_uuid === $data['command_uuid'] && hash_equals($delivery->payload_hash, $data['payload_hash']), 409);
    }

    private static function display(string $text, int $limit): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = trim(preg_replace('/[\p{Cc}\p{Cf}]/u', '', $text) ?? '');

        return mb_substr($text !== '' ? $text : 'Approved service action', 0, $limit);
    }
}
