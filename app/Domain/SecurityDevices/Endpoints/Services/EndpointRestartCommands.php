<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\Obli\ObliStaffAccess;
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
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Native restart transport extends the canonical request/attempt lifecycle, never a second command owner. */
final class EndpointRestartCommands
{
    public function __construct(private readonly EndpointAgentIdentity $identity, private readonly EndpointCommandSigner $signer, private readonly GovernedCommandDispatchService $governance, private readonly DeviceCommandAuditService $audit) {}

    public function prepare(DeviceCommandRequest $request, DeviceCommandAttempt $attempt): EndpointDelivery
    {
        abort_unless(DB::transactionLevel() > 0 && $request->capability === EndpointRestartContract::CAPABILITY && $request->status === CommandStatus::Dispatching && $attempt->runtime === 'endpoint' && (int) $attempt->device_command_request_id === (int) $request->id, 409);
        $agent = app(EndpointRestartAuthority::class)->assertRequest($request, $request->requestedBy);
        $parameters = EndpointRestartContract::parameters($request->encrypted_parameters);
        abort_unless(now()->lessThanOrEqualTo(CarbonImmutable::parse($parameters['execute_at'])->subSeconds($parameters['warning_seconds'])), 422);
        $payload = ['version' => 1, 'type' => 'endpoint_restart_schedule', 'agent_uuid' => $agent->uuid, 'command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid, 'assignment_fingerprint' => $agent->assignment_fingerprint, 'capability' => EndpointRestartContract::CAPABILITY, 'parameters' => $parameters + ['force_close' => false], 'policy' => ['version' => $request->expected_state['policy_version'], 'enabled' => true, 'consent_mode' => $request->expected_state['consent_mode']], 'technician' => $this->display($request->requestedBy->name, 200), 'purpose' => $this->display($request->reason, 500), 'issued_at' => $this->time(now()), 'expires_at' => $this->time($request->expires_at)];
        $signed = $this->signer->sign($payload);

        return EndpointDelivery::query()->create(['endpoint_agent_id' => $agent->id, 'device_command_attempt_id' => $attempt->id, 'agent_uuid' => $agent->uuid, 'encrypted_payload' => $signed, 'payload_hash' => hash('sha256', base64_decode($signed['payload'], true)), 'expires_at' => $request->expires_at]);
    }

    public function poll(string $credential): array
    {
        return $this->authenticated($credential, function (EndpointAgent $agent): array {
            $candidates = EndpointDelivery::query()->where('endpoint_agent_id', $agent->id)->where('agent_uuid', $agent->uuid)->whereHas('attempt.request', fn ($q) => $q->where('capability', EndpointRestartContract::CAPABILITY))->whereNull('completed_at')->where('expires_at', '>', now())->orderByRaw('cancel_requested_at IS NULL')->orderBy('id')->limit(10)->get();
            foreach ($candidates as $candidate) {
                [$request,$attempt,$delivery] = $this->locked($agent, $candidate->attempt->attempt_uuid);
                if ($delivery->cancel_requested_at && $delivery->encrypted_cancellation) {
                    return ['command' => null, 'cancellation' => $delivery->encrypted_cancellation];
                }
                if ($delivery->claimed_at || ! app(EndpointRestartPolicy::class)->available()) {
                    continue;
                }
                try {
                    $this->assertSchedule($request, $delivery);
                    $this->governance->assertEndpointDelivery($request);
                } catch (ValidationException|CommandDispatchPreconditionException|HttpExceptionInterface) {
                    continue;
                }
                $delivery->delivered_at ??= now();
                $delivery->save();

                return ['command' => $delivery->encrypted_payload, 'cancellation' => null];
            }

            return ['command' => null, 'cancellation' => null];
        });
    }

    public function claim(string $credential, string $attemptUuid, array $input): array
    {
        abort_if(array_diff(array_keys($input), ['command_uuid', 'payload_hash', 'boot_id', 'warning_started_at', 'consent']) !== [], 422);
        $data = Validator::make($input, ['command_uuid' => ['required', 'uuid'], 'payload_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/'], 'boot_id' => ['required', 'uuid'], 'warning_started_at' => ['present', 'nullable', 'date_format:Y-m-d\TH:i:s\Z'], 'consent' => ['sometimes', 'required', 'array:desktop_session,consent_nonce', 'size:2'], 'consent.desktop_session' => ['required_with:consent', 'uuid'], 'consent.consent_nonce' => ['required_with:consent', 'regex:/\A[a-f0-9]{64}\z/']])->validate();

        return $this->authenticated($credential, function (EndpointAgent $agent) use ($attemptUuid, $data): array {
            [$request,$attempt,$delivery] = $this->locked($agent, $attemptUuid);
            $this->matches($request, $delivery, $data);
            abort_if($delivery->completed_at || $delivery->cancel_requested_at, 409);
            $schedule = $this->assertSchedule($request, $delivery);
            $this->governance->assertEndpointDelivery($request);
            $execute = CarbonImmutable::parse($schedule['parameters']['execute_at']);
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            abort_unless($now->betweenIncluded($execute->subSeconds(3), $execute->addSeconds(5)) && $now->lessThan($delivery->expires_at), 409);
            abort_unless(is_string($agent->boot_id) && hash_equals($agent->boot_id, $data['boot_id']), 409);
            $attended = $schedule['policy']['consent_mode'] === 'attended';
            abort_unless($attended === isset($data['consent']), 422);
            if ($attended) {
                abort_unless($data['warning_started_at'] !== null, 422);
                $warning = CarbonImmutable::parse($data['warning_started_at']);
                abort_unless($warning->greaterThanOrEqualTo(CarbonImmutable::parse($schedule['issued_at'])) && $warning->lessThanOrEqualTo($execute->subSeconds($schedule['parameters']['warning_seconds'])) && $warning->lessThanOrEqualTo($now->subSeconds($schedule['parameters']['warning_seconds'])), 409);
            } else {
                abort_unless($data['warning_started_at'] === null, 422);
            }
            if ($delivery->encrypted_claim) {
                $existing = json_decode(base64_decode($delivery->encrypted_claim['payload'], true), true, 512, JSON_THROW_ON_ERROR);
                abort_unless($existing['boot_id'] === $data['boot_id'] && $existing['warning_started_at'] === $data['warning_started_at'] && $existing['desktop_session'] === ($data['consent']['desktop_session'] ?? null) && $existing['consent_nonce'] === ($data['consent']['consent_nonce'] ?? null) && $delivery->claim_expires_at->greaterThan($now), 409);

                return $delivery->encrypted_claim;
            }
            abort_unless($attempt->status === CommandAttemptStatus::Dispatching, 409);
            app(EndpointMutationGuard::class)->assertAvailable($agent, $delivery);
            $claim = ['version' => 1, 'type' => 'endpoint_restart_claim', 'agent_uuid' => $agent->uuid, 'command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid, 'assignment_fingerprint' => $agent->assignment_fingerprint, 'payload_hash' => $delivery->payload_hash, 'policy_version' => $schedule['policy']['version'], 'consent_mode' => $schedule['policy']['consent_mode'], 'boot_id' => $data['boot_id'], 'execute_at' => $schedule['parameters']['execute_at'], 'warning_started_at' => $data['warning_started_at'], 'desktop_session' => $data['consent']['desktop_session'] ?? null, 'consent_nonce' => $data['consent']['consent_nonce'] ?? null, 'issued_at' => $this->time($now), 'expires_at' => $this->time($execute->addSeconds(5))];
            $delivery->encrypted_claim = $this->signer->sign($claim);
            $delivery->claimed_at = $now;
            $delivery->claim_expires_at = $execute->addSeconds(5);
            $delivery->status = 'claimed';
            $delivery->save();
            $attempt->status = CommandAttemptStatus::Accepted;
            $attempt->accepted_at = $now;
            $attempt->save();
            $request->status = CommandStatus::Accepted;
            $request->save();
            $this->audit->append($request, null, 'endpoint_restart_claimed', ['attempt_number' => $attempt->attempt_number, 'consent_mode' => $schedule['policy']['consent_mode']]);

            return $delivery->encrypted_claim;
        });
    }

    public function result(string $credential, string $attemptUuid, array $input): array
    {
        abort_if(array_diff(array_keys($input), ['command_uuid', 'payload_hash', 'status', 'mutation_started', 'reason_code', 'boot_id']) !== [], 422);
        $data = Validator::make($input, ['command_uuid' => ['required', 'uuid'], 'payload_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/'], 'status' => ['required', 'in:awaiting_boot,failed,uncertain,declined,cancelled,expired'], 'mutation_started' => ['required', 'boolean:strict'], 'reason_code' => ['required', 'in:'.implode(',', EndpointRestartContract::REASONS)], 'boot_id' => ['required', 'uuid']])->validate();
        EndpointRestartContract::validateResult($data);
        $data = array_replace(array_fill_keys(['command_uuid', 'payload_hash', 'status', 'mutation_started', 'reason_code', 'boot_id'], null), $data);
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
                    'awaiting_boot' => CommandAttemptStatus::Running,'uncertain' => CommandAttemptStatus::Uncertain,'expired' => CommandAttemptStatus::Expired,'cancelled' => CommandAttemptStatus::Cancelled,default => CommandAttemptStatus::Failed
                };
                $attempt->started_at = $data['mutation_started'] ? $delivery->claimed_at : null;
                $attempt->completed_at = $data['status'] === 'awaiting_boot' ? null : now();
                $attempt->save();
                $request->status = match ($data['status']) {
                    'awaiting_boot' => CommandStatus::Reconciling,'uncertain' => CommandStatus::Uncertain,'expired' => CommandStatus::Expired,'cancelled' => CommandStatus::Cancelled,default => CommandStatus::Failed
                };
                $request->safe_result_summary = json_encode($attempt->safe_result_summary, JSON_THROW_ON_ERROR);
                $request->execution_completed_at = $data['status'] === 'awaiting_boot' ? null : now();
                $request->cancelled_at = $data['status'] === 'cancelled' ? now() : null;
                $request->save();
            }
            $this->audit->append($request, null, 'endpoint_restart_result_'.$data['status'], ['attempt_number' => $attempt->attempt_number, 'mutation_started' => $data['mutation_started'], 'reason_code' => $data['reason_code']]);

            return $ack;
        });
    }

    public function cancel(User $actor, Device $device, DeviceCommandRequest $command, array $input): void
    {
        abort_if(array_diff(array_keys($input), ['expected_actor_id', 'reason']) !== [], 422);
        $data = Validator::make($input, ['expected_actor_id' => ['required', 'integer:strict', 'min:1'], 'reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\p{Cc}\p{Cf}]/u']])->validate();
        DB::transaction(function () use ($actor, $device, $command, $data): void {
            $actor = app(ObliStaffAccess::class)->actor((int) $actor->id);
            $device = Device::query()->whereKey($device->id)->lock('for update nowait')->firstOrFail();
            $actor = app(EndpointRemoteAccess::class)->actor((int) $actor->id, $device, EndpointRestartPolicy::SERVICE);
            abort_unless((int) $actor->id === $data['expected_actor_id'], 409);
            $request = DeviceCommandRequest::query()->whereKey($command->id)->lock('for update nowait')->firstOrFail();
            abort_unless((int) $request->device_id === (int) $device->id && $request->capability === EndpointRestartContract::CAPABILITY, 404);
            abort_unless((int) $request->requested_by_user_id === (int) $actor->id || $actor->canDo('securityDevices.commands.admin'), 403);
            abort_if($request->status->isTerminal() || in_array($request->status, [CommandStatus::Running, CommandStatus::Reconciling, CommandStatus::Uncertain], true), 409);
            $attempt = $request->attempts()->orderByDesc('attempt_number')->lock('for update nowait')->first();
            $delivery = $attempt ? EndpointDelivery::query()->where('device_command_attempt_id', $attempt->id)->lock('for update nowait')->first() : null;
            if ($delivery?->cancel_requested_at) {
                return;
            }
            if (! $delivery || (! $delivery->delivered_at && ! $delivery->claimed_at)) {
                if ($attempt) {
                    $attempt->status = CommandAttemptStatus::Cancelled;
                    $attempt->completed_at = now();
                    $attempt->save();
                }
                if ($delivery) {
                    $delivery->status = 'cancelled';
                    $delivery->completed_at = now();
                    $delivery->save();
                }
                $request->status = CommandStatus::Cancelled;
                $request->cancelled_at = now();
                $request->save();
            } else {
                abort_if($delivery->completed_at || $delivery->expires_at->lessThanOrEqualTo(now()), 409);
                $delivery->cancel_requested_at = now();
                $delivery->cancel_requested_by_user_id = $actor->id;
                $delivery->encrypted_cancellation = $this->signer->sign(['version' => 1, 'type' => 'endpoint_restart_cancel', 'agent_uuid' => $delivery->agent_uuid, 'command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid, 'assignment_fingerprint' => $request->assignment_fingerprint, 'payload_hash' => $delivery->payload_hash, 'issued_at' => $this->time(now()), 'expires_at' => $this->time($delivery->expires_at)]);
                $delivery->save();
            }
            $this->audit->append($request, $actor, 'endpoint_restart_cancel_requested', ['reason' => $data['reason'], 'confirmed' => $request->status === CommandStatus::Cancelled]);
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
            if ($entry['command_uuid'] !== $request->command_uuid || $entry['boot_id'] !== $bootId || $entry['before_boot_id'] === $entry['boot_id'] || ! in_array($delivery->result['status'] ?? null, ['awaiting_boot', 'uncertain'], true) || ($delivery->result['mutation_started'] ?? false) !== true || ($delivery->result['boot_id'] ?? null) !== $entry['before_boot_id']) {
                continue;
            }
            $time = CarbonImmutable::parse($entry['observed_at']);
            if (! $delivery->completed_at || ! $time->greaterThan($delivery->completed_at) || $time->greaterThan($observed) || $time->lessThan(now()->subSeconds(120)) || $time->lessThan(CarbonImmutable::parse($request->encrypted_parameters['execute_at']))) {
                continue;
            }
            if (! $delivery->restart_readback || CarbonImmutable::parse($delivery->restart_readback['observed_at'])->lessThan($time)) {
                $delivery->restart_readback = $entry;
                $delivery->readback_sequence = $sequence;
                $delivery->readback_received_at = now();
                $delivery->save();
                if (($delivery->result['status'] ?? null) === 'awaiting_boot' && in_array($request->status, [CommandStatus::Reconciling, CommandStatus::Uncertain], true)) {
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
        foreach ($agent->restart_observations ?? [] as $entry) {
            [$request,$attempt,$delivery] = $this->locked($agent, $entry['attempt_uuid']);
            if (! $delivery->restart_readback || ! $this->sameObservation($delivery->restart_readback, $entry) || $agent->boot_id !== $entry['boot_id'] || ! $delivery->readback_received_at || $delivery->readback_received_at->lessThanOrEqualTo(now()->subSeconds(180)) || CarbonImmutable::parse($entry['observed_at'])->lessThan(now()->subSeconds(120))) {
                continue;
            }
            $accepted[] = array_diff_key($entry, array_flip(['observed_at'])) + ['status' => ($delivery->result['status'] === 'awaiting_boot' ? 'succeeded' : 'uncertain')];
        }

        return $accepted;
    }

    /** JSON object storage may reorder keys; every field and type must still match exactly. */
    private function sameObservation(array $stored, array $reported): bool
    {
        ksort($stored, SORT_STRING);
        ksort($reported, SORT_STRING);

        return $stored === $reported;
    }

    public function expire(int $limit = 100): int
    {
        if (! app(EndpointRestartPolicy::class)->schemaReady()) {
            return 0;
        }
        $candidates = EndpointDelivery::query()->whereHas('attempt.request', fn ($q) => $q->where('capability', EndpointRestartContract::CAPABILITY))->where('status', '!=', 'timed_out')->where(fn ($q) => $q->where(fn ($u) => $u->whereNull('completed_at')->where(fn ($expiry) => $expiry->where(fn ($unclaimed) => $unclaimed->whereNull('claimed_at')->where('expires_at', '<=', now()))->orWhere(fn ($claimed) => $claimed->whereNotNull('claimed_at')->where('expires_at', '<=', now()->subSeconds(120)))))->orWhere(fn ($u) => $u->where('status', 'awaiting_boot')->where('expires_at', '<=', now()->subSeconds(300))))->orderBy('id')->limit(max(1, min(500, $limit)))->get();
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
                    if ($delivery->completed_at && ($delivery->status !== 'awaiting_boot' || $delivery->expires_at->greaterThan(now()->subSeconds(300)))) {
                        return 0;
                    }
                    $uncertain = (bool) $delivery->claimed_at;
                    $attempt->status = $uncertain ? CommandAttemptStatus::Uncertain : CommandAttemptStatus::Expired;
                    $attempt->completed_at = now();
                    $attempt->save();
                    $request->status = $uncertain ? CommandStatus::Uncertain : CommandStatus::Expired;
                    $request->execution_completed_at = now();
                    $request->safe_failure_reason = $uncertain ? 'Restart completion is unconfirmed. Do not repeat this action.' : 'The restart deadline passed without an execution claim.';
                    $request->save();
                    $delivery->status = 'timed_out';
                    if (! $uncertain) {
                        $delivery->completed_at = now();
                    } $delivery->save();
                    $this->audit->append($request, null, 'endpoint_restart_deadline', ['attempt_number' => $attempt->attempt_number, 'status' => $request->status->value]);

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
        if ($request->capability !== EndpointRestartContract::CAPABILITY) {
            return null;
        }
        $delivery = $attempt ? EndpointDelivery::query()->where('device_command_attempt_id', $attempt->id)->first() : null;
        $state = match ($request->status) {
            CommandStatus::Reconciled => 'confirmed', CommandStatus::Cancelled => 'cancelled', CommandStatus::Expired => 'expired',
            CommandStatus::Failed, CommandStatus::Rejected, CommandStatus::Blocked, CommandStatus::Mismatch => 'failed',
            CommandStatus::Uncertain => 'uncertain', CommandStatus::Reconciling => 'awaiting_boot',
            default => $delivery?->cancel_requested_at ? 'cancel_requested' : 'scheduled',
        };
        $canCancel = $viewer->canDo(EndpointRestartPolicy::SERVICE) && ((int) $viewer->id === (int) $request->requested_by_user_id || $viewer->canDo('securityDevices.commands.admin'))
            && ! $request->status->isTerminal() && ! in_array($request->status, [CommandStatus::Running, CommandStatus::Reconciling, CommandStatus::Uncertain], true)
            && $request->expires_at->isFuture() && ! $delivery?->completed_at && ! $delivery?->cancel_requested_at;

        return ['executeAt' => $request->safe_parameter_summary['execute_at'] ?? null, 'warningSeconds' => $request->safe_parameter_summary['warning_seconds'] ?? null, 'consentMode' => $request->expected_state['consent_mode'] ?? null, 'state' => $state, 'cancelRequestedAt' => $delivery?->cancel_requested_at?->toISOString(), 'canCancel' => $canCancel, 'cancelHref' => "/security-devices/devices/{$request->device_id}/commands/{$request->id}/cancel"];
    }

    private function assertSchedule(DeviceCommandRequest $request, EndpointDelivery $delivery): array
    {
        $signed = $delivery->encrypted_payload;
        $bytes = base64_decode($signed['payload'], true);
        $payload = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(hash_equals($delivery->payload_hash, hash('sha256', $bytes)) && sodium_crypto_sign_verify_detached(base64_decode($signed['signature'], true), $bytes, base64_decode($this->signer->publicKey(), true)), 409);
        $parameters = EndpointRestartContract::parameters($request->encrypted_parameters);
        abort_unless(($payload['type'] ?? null) === 'endpoint_restart_schedule' && $payload['command_uuid'] === $request->command_uuid && $payload['attempt_uuid'] === $delivery->attempt->attempt_uuid && $payload['agent_uuid'] === $delivery->agent_uuid && $payload['assignment_fingerprint'] === $request->assignment_fingerprint && $payload['parameters'] === ($parameters + ['force_close' => false]) && $payload['policy'] === ['version' => $request->expected_state['policy_version'], 'enabled' => true, 'consent_mode' => $request->expected_state['consent_mode']] && $payload['expires_at'] === $this->time($request->expires_at), 409);

        return $payload;
    }

    private function locked(EndpointAgent $agent, string $attemptUuid): array
    {
        $found = DeviceCommandAttempt::query()->where('attempt_uuid', $attemptUuid)->firstOrFail();
        $request = DeviceCommandRequest::query()->whereKey($found->device_command_request_id)->lock('for update nowait')->firstOrFail();
        $attempt = DeviceCommandAttempt::query()->whereKey($found->id)->lock('for update nowait')->firstOrFail();
        $delivery = EndpointDelivery::query()->where('device_command_attempt_id', $attempt->id)->where('endpoint_agent_id', $agent->id)->where('agent_uuid', $agent->uuid)->lock('for update nowait')->firstOrFail();
        abort_unless((int) $request->device_id === (int) $agent->device_id && $attempt->runtime === 'endpoint' && $request->capability === EndpointRestartContract::CAPABILITY && ($request->expected_state['agent_uuid'] ?? null) === $agent->uuid && ($request->expected_state['assignment_fingerprint'] ?? null) === $agent->assignment_fingerprint, 404);

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
