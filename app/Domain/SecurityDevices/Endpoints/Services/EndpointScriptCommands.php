<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\Obli\ObliStaffAccess;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointDelivery;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Exceptions\CommandDispatchPreconditionException;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandAuditService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandContractVerifier;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Prepared transport in the canonical command lifecycle. Queue/dispatch is still closed. */
final class EndpointScriptCommands
{
    public function __construct(
        private readonly EndpointAgentIdentity $identity,
        private readonly EndpointCommandSigner $signer,
        private readonly GovernedCommandDispatchService $governance,
        private readonly DeviceCommandAuditService $audit,
    ) {}

    public function prepare(DeviceCommandRequest $request, DeviceCommandAttempt $attempt): EndpointDelivery
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        $request = DeviceCommandRequest::query()->whereKey($request->id)->lock('for update nowait')->firstOrFail();
        $attempt = DeviceCommandAttempt::query()->whereKey($attempt->id)->lock('for update nowait')->firstOrFail();
        abort_unless($request->status === CommandStatus::Dispatching && $attempt->status === CommandAttemptStatus::Dispatching
            && $attempt->runtime === 'endpoint' && (int) $attempt->device_command_request_id === (int) $request->id, 409);

        return $this->current($request, function (EndpointAgent $agent, array $candidate) use ($request, $attempt): EndpointDelivery {
            $existing = EndpointDelivery::query()->where('device_command_attempt_id', $attempt->id)->lock('for update nowait')->first();
            if ($existing) {
                $this->command($request, $attempt, $existing, $agent, $candidate);

                return $existing;
            }
            $payload = (object) [
                'version' => 1, 'type' => 'endpoint_script_run', 'agent_uuid' => $agent->uuid,
                'command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid,
                'assignment_fingerprint' => $agent->assignment_fingerprint, 'capability' => EndpointScriptContract::CAPABILITY,
                'parameters' => $candidate['parameters'], 'policy' => (object) $candidate['policy'],
                'technician' => $this->display($request->requestedBy->name, 200), 'purpose' => $this->display($request->reason, 500),
                'issued_at' => $this->time(now()), 'expires_at' => $this->time($request->expires_at),
            ];
            EndpointScriptContract::dispatch($payload);
            $signed = $this->signer->sign(get_object_vars($payload));

            return EndpointDelivery::create([
                'endpoint_agent_id' => $agent->id, 'device_command_attempt_id' => $attempt->id, 'agent_uuid' => $agent->uuid,
                'encrypted_payload' => $signed, 'payload_hash' => hash('sha256', base64_decode($signed['payload'], true)),
                'expires_at' => $request->expires_at,
            ]);
        });
    }

    public function poll(string $credential): array
    {
        return $this->authenticated($credential, function (EndpointAgent $agent): array {
            // A stop intent outlives dispatch expiry, publication and launch authority.
            // Prioritize claimed work over prompts that never received a launch claim.
            $cancellations = $this->pollCandidates($agent, cancellation: true);
            foreach ($cancellations as $candidate) {
                [$request, $attempt, $delivery] = $this->locked($agent, $candidate->attempt->attempt_uuid);
                $command = $this->original($request, $attempt, $delivery, $agent, requireCatalogue: false);

                return ['command' => null, 'cancellation' => $this->cancellationLease($request, $attempt, $delivery, $command)];
            }
            $candidates = $this->pollCandidates($agent, cancellation: false);
            foreach ($candidates as $candidate) {
                [$request, $attempt, $delivery] = $this->locked($agent, $candidate->attempt->attempt_uuid);
                try {
                    $this->current($request, function (EndpointAgent $current, array $material) use ($request, $attempt, $delivery): void {
                        $this->command($request, $attempt, $delivery, $current, $material);
                    });
                } catch (ValidationException|CommandDispatchPreconditionException|HttpExceptionInterface|InvalidArgumentException) {
                    continue;
                }
                $delivery->delivered_at ??= now();
                $delivery->save();

                return ['command' => $delivery->encrypted_payload, 'cancellation' => null];
            }

            return ['command' => null, 'cancellation' => null];
        });
    }

    /** Current reads are essential after waiting for the authenticated device mutex. */
    private function pollCandidates(EndpointAgent $agent, bool $cancellation): Collection
    {
        $query = EndpointDelivery::query()->where('endpoint_agent_id', $agent->id)->where('agent_uuid', $agent->uuid)
            ->whereHas('attempt.request', fn ($q) => $q->where('capability', EndpointScriptContract::CAPABILITY));
        if ($cancellation) {
            $query->whereNotNull('cancel_requested_at')
                ->where(fn ($q) => $q->whereNotNull('claimed_at')->orWhereNotNull('delivered_at'))
                ->where(fn ($q) => $q->whereNull('result_hash')->orWhere('result->status', 'uncertain')->orWhere('result->mutation_started', true))
                ->orderByRaw('claimed_at IS NULL');
        } else {
            $query->whereNull('claimed_at')->whereNull('completed_at')->whereNull('cancel_requested_at')->where('expires_at', '>', now());
        }

        return $query->orderBy('id')->limit(10)->lock('for update nowait')->get();
    }

    public function artifact(string $credential, string $attemptUuid): string
    {
        return $this->authenticated($credential, function (EndpointAgent $agent) use ($attemptUuid): string {
            [$request, $attempt, $delivery] = $this->locked($agent, $attemptUuid);
            $this->pending($delivery);
            abort_if($delivery->claimed_at !== null, 409);

            return $this->current($request, function (EndpointAgent $current, array $candidate) use ($request, $attempt, $delivery): string {
                $this->command($request, $attempt, $delivery, $current, $candidate);

                // Bounded immutable bytes were verified under the same authority locks.
                return $candidate['artifact'];
            }, includeArtifact: true);
        });
    }

    public function claim(string $credential, string $attemptUuid, stdClass $input): array
    {
        return $this->authenticated($credential, function (EndpointAgent $agent) use ($attemptUuid, $input): array {
            [$request, $attempt, $delivery] = $this->locked($agent, $attemptUuid);
            $this->pending($delivery);

            return $this->current($request, function (EndpointAgent $current, array $candidate) use ($request, $attempt, $delivery, $input): array {
                $command = $this->command($request, $attempt, $delivery, $current, $candidate);
                try {
                    EndpointScriptContract::claimRequest($input, $command);
                } catch (InvalidArgumentException) {
                    abort(422, 'Invalid script claim.');
                }
                abort_unless(hash_equals($delivery->payload_hash, $input->payload_hash) && $current->boot_id === $input->boot_id, 409);
                $desktop = $input->consent->desktop_session ?? null;
                $nonce = $input->consent->consent_nonce ?? null;
                $now = CarbonImmutable::now('UTC')->startOfSecond();
                if ($delivery->encrypted_claim !== null) {
                    abort_unless($delivery->claimed_at && $delivery->claim_expires_at?->greaterThan($now), 409);
                    try {
                        $claim = $this->verified($delivery->encrypted_claim);
                        EndpointScriptContract::claim($claim, $command, $delivery->payload_hash, $input->boot_id, $desktop, $nonce, $this->time($now));
                    } catch (InvalidArgumentException) {
                        abort(409);
                    }

                    return $delivery->encrypted_claim;
                }
                abort_unless($delivery->claimed_at === null && $attempt->status === CommandAttemptStatus::Dispatching, 409);
                app(EndpointMutationGuard::class)->assertAvailable($current, $delivery);
                $expires = $now->addSeconds(30)->min($delivery->expires_at);
                $claim = (object) [
                    'version' => 1, 'type' => 'endpoint_script_claim', 'agent_uuid' => $current->uuid,
                    'command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid,
                    'assignment_fingerprint' => $current->assignment_fingerprint, 'payload_hash' => $delivery->payload_hash,
                    'policy_version' => $command->policy->version, 'consent_mode' => $command->policy->consent_mode,
                    'artifact_sha256' => $input->artifact_sha256, 'runtime_manifest_sha256' => $input->runtime_manifest_sha256,
                    'boot_id' => $input->boot_id, 'desktop_session' => $desktop, 'consent_nonce' => $nonce,
                    'issued_at' => $this->time($now), 'expires_at' => $this->time($expires),
                ];
                EndpointScriptContract::claim($claim, $command, $delivery->payload_hash, $input->boot_id, $desktop, $nonce, $this->time($now));
                $delivery->forceFill(['encrypted_claim' => $this->signer->sign(get_object_vars($claim)), 'claimed_at' => $now,
                    'claim_expires_at' => $expires, 'status' => 'claimed'])->save();
                $attempt->forceFill(['status' => CommandAttemptStatus::Accepted, 'accepted_at' => $now])->save();
                $request->forceFill(['status' => CommandStatus::Accepted])->save();
                $this->audit->append($request, null, 'endpoint_script_claimed', [
                    'attempt_number' => $attempt->attempt_number, 'consent_mode' => $command->policy->consent_mode,
                ]);

                return $delivery->encrypted_claim;
            });
        });
    }

    /** Historical receipts do not restore execution authority or renew a launch claim. */
    public function result(string $credential, string $attemptUuid, stdClass $input): array
    {
        return $this->authenticated($credential, function (EndpointAgent $agent) use ($attemptUuid, $input): array {
            [$request, $attempt, $delivery] = $this->locked($agent, $attemptUuid);
            $command = $this->original($request, $attempt, $delivery, $agent);
            try {
                EndpointScriptContract::result($input, $command, $delivery->payload_hash);
            } catch (InvalidArgumentException) {
                abort(422, 'Invalid script result.');
            }
            $data = get_object_vars($input);
            ksort($data);
            $hash = hash('sha256', EndpointScriptContract::encode((object) $data));
            $ack = ['command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid,
                'payload_hash' => $delivery->payload_hash, 'accepted' => true];
            if ($delivery->result_hash !== null) {
                abort_unless(hash_equals($delivery->result_hash, $hash), 409);

                return $ack;
            }
            if ($delivery->encrypted_claim !== null) {
                $claim = $this->verified($delivery->encrypted_claim);
                // Validate the original lease at issue time. A delayed receipt cannot
                // extend it and may describe the old boot after a subsequent restart.
                EndpointScriptContract::claim($claim, $command, $delivery->payload_hash, $input->boot_id,
                    $claim->desktop_session ?? null, $claim->consent_nonce ?? null, $claim->issued_at ?? '');
                abort_unless($delivery->claimed_at && $delivery->claim_expires_at
                    && $claim->issued_at === $this->time($delivery->claimed_at)
                    && $claim->expires_at === $this->time($delivery->claim_expires_at), 409);
            } else {
                abort_unless(! $input->mutation_started && $delivery->claimed_at === null
                    && $input->boot_id === $agent->boot_id, 409);
            }
            $serverCancelled = $attempt->status === CommandAttemptStatus::Cancelled && $delivery->cancel_requested_at !== null
                && $delivery->claimed_at === null && ! $input->mutation_started && $input->status !== 'uncertain';
            abort_unless($serverCancelled || in_array($attempt->status, [CommandAttemptStatus::Dispatching, CommandAttemptStatus::Accepted,
                CommandAttemptStatus::Running, CommandAttemptStatus::Uncertain, CommandAttemptStatus::Expired], true), 409);
            abort_if(($attempt->status === CommandAttemptStatus::Running || $request->status === CommandStatus::Running)
                && ! $input->mutation_started && $input->status !== 'uncertain', 409);
            $attemptStatus = match ($input->status) {
                'completed' => CommandAttemptStatus::Succeeded,
                'awaiting_observation' => CommandAttemptStatus::Running,
                'uncertain' => CommandAttemptStatus::Uncertain,
                'expired' => CommandAttemptStatus::Expired,
                'cancelled' => CommandAttemptStatus::Cancelled,
                default => CommandAttemptStatus::Failed,
            };
            $requestStatus = match ($input->status) {
                'completed' => CommandStatus::Succeeded,
                'awaiting_observation' => CommandStatus::Reconciling,
                'uncertain' => CommandStatus::Uncertain,
                'expired' => CommandStatus::Expired,
                'cancelled' => CommandStatus::Cancelled,
                default => CommandStatus::Failed,
            };
            $updateCanonical = ! $attempt->status->isTerminal() && in_array($request->status,
                [CommandStatus::Dispatching, CommandStatus::Accepted, CommandStatus::Running], true);
            if ($updateCanonical) {
                abort_unless($attempt->status->canTransitionTo($attemptStatus) && $request->status->canTransitionTo($requestStatus), 409);
            }
            $delivery->forceFill(['result' => $data, 'result_hash' => $hash,
                'status' => $input->status, 'completed_at' => now()])->save();

            // Preserve already recorded terminal outcomes. A late report is retained
            // as evidence, never a rewrite of an expired/uncertain canonical attempt.
            if ($updateCanonical) {
                $summary = array_diff_key($data, array_flip(['command_uuid', 'payload_hash', 'boot_id']));
                $summary['output_received'] = false;
                $attempt->forceFill([
                    'safe_result_summary' => $summary,
                    'status' => $attemptStatus,
                    // Keep any observed start evidence. A launch claim is not a
                    // process-start observation, and uncertainty cannot erase one.
                    'completed_at' => $input->status === 'awaiting_observation' ? null : now(),
                ])->save();
                $request->forceFill([
                    'status' => $requestStatus,
                    'safe_result_summary' => json_encode($summary, JSON_THROW_ON_ERROR),
                    'execution_completed_at' => $input->status === 'awaiting_observation' ? null : now(),
                    'cancelled_at' => $input->status === 'cancelled' ? now() : null,
                ])->save();
            }
            $this->audit->append($request, null, 'endpoint_script_result_'.$input->status, [
                'attempt_number' => $attempt->attempt_number, 'mutation_started' => $input->mutation_started,
                'reason_code' => $input->reason_code,
            ]);

            return $ack;
        });
    }

    public function output(string $credential, string $attemptUuid, stdClass $header, string $bytes): array
    {
        $attemptUuid = strtolower($attemptUuid);

        return $this->authenticated($credential, function (EndpointAgent $agent) use ($attemptUuid, $header, $bytes): array {
            [$request, $attempt, $delivery] = $this->locked($agent, $attemptUuid);
            $command = $this->original($request, $attempt, $delivery, $agent);
            $stored = app(EndpointScriptOutputs::class)->receive($delivery, $command, $attempt->attempt_uuid, $header, $bytes);
            if ($stored['created']) {
                $this->audit->append($request, null, 'endpoint_script_output_received', ['attempt_number' => $attempt->attempt_number]);
            }

            return get_object_vars($stored['receipt']);
        });
    }

    public function readOutput(User $actor, Device $device, DeviceCommandRequest $command, string $attemptUuid, int $expectedActor, bool $content): array
    {
        $attemptUuid = strtolower($attemptUuid);

        return $this->historicalActor($actor, $device, $command, $expectedActor,
            function (User $current, DeviceCommandRequest $request, EndpointAgent $agent) use ($attemptUuid, $content): array {
                [$attempt, $delivery] = $this->cancellationTarget($request, $agent, $attemptUuid);
                abort_unless($attempt && $delivery, 404);
                $original = $this->original($request, $attempt, $delivery, $agent);
                $response = app(EndpointScriptOutputs::class)->read($delivery, $original, $attempt->attempt_uuid, $content);
                if ($content && $response['content_available']) {
                    $this->audit->append($request, $current, 'endpoint_script_output_read', ['attempt_number' => $attempt->attempt_number]);
                }

                return $response;
            }, EndpointScriptOutputs::VIEW);
    }

    public function cancel(User $actor, Device $device, DeviceCommandRequest $command, stdClass $input): array
    {
        $keys = ['expected_actor_id', 'request_key', 'expected_attempt_uuid', 'reason'];
        $data = get_object_vars($input);
        abort_unless(count($data) === count($keys) && array_diff(array_keys($data), $keys) === [], 422);
        Validator::make($data, [
            'expected_actor_id' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'request_key' => ['required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'expected_attempt_uuid' => ['nullable', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\p{Cc}\p{Cf}\x{FFFD}]/u'],
        ])->validate();
        abort_unless(mb_strlen(trim($data['reason'])) >= 10, 422);
        $data = array_replace(array_fill_keys($keys, null), $data);

        return $this->historicalActor($actor, $device, $command, $data['expected_actor_id'],
            function (User $current, DeviceCommandRequest $request, EndpointAgent $agent) use ($data): array {
                $hash = hash('sha256', EndpointScriptContract::encode(['command_uuid' => $request->command_uuid, 'device_id' => (int) $request->device_id, 'input' => $data]));
                $prior = DB::table('endpoint_script_cancel_receipts')->where('actor_user_id', $current->id)->where('request_key', $data['request_key'])->lock('for update nowait')->first();
                if ($prior) {
                    abort_unless((int) $prior->device_command_request_id === (int) $request->id && hash_equals($prior->request_hash, $hash), 409);

                    return $this->cancellationResponse($request, $agent, $prior);
                }
                [$attempt, $delivery] = $this->cancellationTarget($request, $agent, latest: true);
                abort_unless($attempt?->attempt_uuid === $data['expected_attempt_uuid'], 409);
                $original = $delivery ? $this->original($request, $attempt, $delivery, $agent, requireCatalogue: false) : null;
                $unclaimed = $delivery === null || ($delivery->claimed_at === null && $delivery->encrypted_claim === null && $delivery->claim_expires_at === null);
                if ($unclaimed) {
                    abort_unless(in_array($request->status, [CommandStatus::Requested, CommandStatus::AwaitingStepUp,
                        CommandStatus::AwaitingApproval, CommandStatus::AwaitingChange, CommandStatus::Ready,
                        CommandStatus::Queued, CommandStatus::Dispatching, CommandStatus::Cancelled], true), 409);
                    abort_if($attempt && ! in_array($attempt->status, [CommandAttemptStatus::Dispatching, CommandAttemptStatus::Cancelled], true), 409);
                    if ($attempt && $attempt->status !== CommandAttemptStatus::Cancelled) {
                        $attempt->forceFill(['status' => CommandAttemptStatus::Cancelled, 'completed_at' => now()])->save();
                    }
                    if ($request->status !== CommandStatus::Cancelled) {
                        $request->forceFill(['status' => CommandStatus::Cancelled, 'cancelled_at' => now()])->save();
                    }
                    if ($delivery) {
                        if ($delivery->result_hash === null) {
                            $delivery->status = 'cancelled';
                        }
                        $delivery->completed_at ??= now();
                    }
                    $disposition = 'cancelled_before_claim';
                } else {
                    abort_unless($delivery->claimed_at && $delivery->encrypted_claim && $delivery->claim_expires_at, 409);
                    $claim = $this->verified($delivery->encrypted_claim);
                    EndpointScriptContract::claim($claim, $original, $delivery->payload_hash, $claim->boot_id ?? '',
                        $claim->desktop_session ?? null, $claim->consent_nonce ?? null, $claim->issued_at ?? '');
                    abort_unless($claim->issued_at === $this->time($delivery->claimed_at)
                        && $claim->expires_at === $this->time($delivery->claim_expires_at), 409);
                    abort_if($delivery->result_hash !== null && ($delivery->result['status'] ?? null) !== 'uncertain'
                        && ($delivery->result['mutation_started'] ?? false) !== true, 409);
                    // A claim, a timeout or an entered result never establishes that
                    // the native owned job stopped. Preserve all canonical outcomes.
                    $disposition = 'cancellation_requested';
                }
                if ($delivery) {
                    if ($delivery->cancel_requested_at === null) {
                        $delivery->cancel_requested_at = now();
                        $delivery->cancel_requested_by_user_id = $current->id;
                    }
                    $delivery->save();
                    if ($delivery->delivered_at !== null || $delivery->claimed_at !== null) {
                        $this->cancellationLease($request, $attempt, $delivery, $original);
                    }
                }
                $receipt = (object) [
                    'actor_id' => (int) $current->id, 'device_id' => (int) $request->device_id,
                    'command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt?->attempt_uuid,
                    'request_key' => $data['request_key'], 'disposition' => $disposition,
                    'reason' => $data['reason'], 'recorded_at' => $this->time(now()),
                ];
                $json = EndpointScriptContract::encode($receipt);
                $id = DB::table('endpoint_script_cancel_receipts')->insertGetId([
                    'device_command_request_id' => $request->id, 'actor_user_id' => $current->id,
                    'request_key' => $data['request_key'], 'request_hash' => $hash,
                    'receipt_json' => $json, 'receipt_hash' => hash('sha256', $json), 'created_at' => now(),
                ]);
                $this->audit->append($request, $current, 'endpoint_script_cancel_requested', [
                    'request_key' => $data['request_key'], 'attempt_number' => $attempt?->attempt_number,
                    'disposition' => $disposition, 'reason' => $data['reason'],
                ]);

                return $this->cancellationResponse($request, $agent, DB::table('endpoint_script_cancel_receipts')->where('id', $id)->first());
            });
    }

    public function cancellation(User $actor, Device $device, DeviceCommandRequest $command, string $key, int $expectedActor): array
    {
        abort_unless(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $key), 404);

        return $this->historicalActor($actor, $device, $command, $expectedActor,
            function (User $current, DeviceCommandRequest $request, EndpointAgent $agent) use ($key): array {
                $row = DB::table('endpoint_script_cancel_receipts')->where('device_command_request_id', $request->id)
                    ->where('actor_user_id', $current->id)->where('request_key', $key)->lock('for update nowait')->first();
                abort_unless($row, 404);

                return $this->cancellationResponse($request, $agent, $row);
            });
    }

    private function historicalActor(User $actor, Device $device, DeviceCommandRequest $command, int $expectedActor, Closure $callback, ?string $permission = null): array
    {
        return $this->contention(fn () => DB::transaction(function () use ($actor, $device, $command, $expectedActor, $callback, $permission): array {
            $current = app(ObliStaffAccess::class)->actor((int) $actor->id);
            abort_unless((int) $current->id === $expectedActor, 409);
            abort_unless($current->two_factor_confirmed_at, 403);
            $device = Device::query()->whereKey($device->id)->lock('for update nowait')->firstOrFail();
            $current = app(EndpointRemoteAccess::class)->actor((int) $current->id, $device, 'securityDevices.devices.view');
            abort_unless($permission ? $current->canDo($permission) : ($current->canDo(EndpointScriptPolicy::RUN) || $current->canDo(EndpointScriptPolicy::MANAGE)
                || $current->canDo('securityDevices.commands.admin')), 403);
            $request = DeviceCommandRequest::query()->whereKey($command->id)->where('device_id', $device->id)
                ->where('capability', EndpointScriptContract::CAPABILITY)->lock('for update nowait')->firstOrFail();
            abort_unless((int) $request->requested_by_user_id === (int) $current->id || $current->canDo('securityDevices.commands.admin'), 403);
            abort_unless($permission === EndpointScriptOutputs::VIEW ? Schema::hasTable('endpoint_script_outputs')
                : (Schema::hasTable('endpoint_script_cancel_receipts') && Schema::hasTable('endpoint_script_cancel_leases')), 503);
            abort_unless(app(DeviceCommandContractVerifier::class)->verify($request), 409);
            $agent = EndpointAgent::query()->where('device_id', $device->id)->lock('for update nowait')->firstOrFail();
            $fingerprint = CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads): string => app(CommandAssignmentFingerprint::class)->forDevice($device, reads: $reads));
            abort_unless(($request->expected_state['agent_uuid'] ?? null) === $agent->uuid
                && ($request->expected_state['assignment_fingerprint'] ?? null) === $fingerprint
                && hash_equals($agent->assignment_fingerprint, $fingerprint), 404);

            // No live release/catalogue/runtime or unattended launch grant is
            // required to stop this exact historical action. Revoked credentials
            // still cannot poll; recording intent does not claim delivery or stop.
            return $callback($current, $request, $agent);
        }));
    }

    private function cancellationTarget(DeviceCommandRequest $request, EndpointAgent $agent, ?string $uuid = null, bool $latest = false): array
    {
        $query = $request->attempts()->lock('for update nowait');
        $attempt = $latest ? $query->orderByDesc('attempt_number')->first()
            : ($uuid === null ? null : $query->where('attempt_uuid', $uuid)->firstOrFail());
        $delivery = $attempt ? EndpointDelivery::query()->where('device_command_attempt_id', $attempt->id)->lock('for update nowait')->first() : null;
        abort_if($attempt && $attempt->runtime !== 'endpoint', 409);
        abort_if($delivery && ((int) $delivery->endpoint_agent_id !== (int) $agent->id || $delivery->agent_uuid !== $agent->uuid), 404);

        return [$attempt, $delivery];
    }

    private function cancellationResponse(DeviceCommandRequest $request, EndpointAgent $agent, object $row): array
    {
        abort_unless(hash_equals($row->receipt_hash, hash('sha256', $row->receipt_json)), 409);
        $receipt = EndpointScriptContract::decode($row->receipt_json, 8192);
        abort_unless(count(get_object_vars($receipt)) === 8 && $receipt->actor_id === (int) $row->actor_user_id
            && $receipt->device_id === (int) $request->device_id && $receipt->command_uuid === $request->command_uuid
            && $receipt->request_key === $row->request_key, 409);
        [$attempt, $delivery] = $this->cancellationTarget($request, $agent, $receipt->attempt_uuid);

        return ['receipt' => $receipt, 'current' => [
            'command_status' => $request->status->value, 'attempt_status' => $attempt?->status->value,
            'result_status' => $delivery?->result['status'] ?? null,
            'cancellation_requested_at' => $delivery?->cancel_requested_at ? $this->time($delivery->cancel_requested_at) : null,
            'result_received_at' => $delivery?->result_hash ? $this->time($delivery->completed_at) : null,
        ]];
    }

    private function cancellationLease(DeviceCommandRequest $request, DeviceCommandAttempt $attempt, EndpointDelivery $delivery, stdClass $command): array
    {
        abort_unless($delivery->cancel_requested_at !== null, 409);
        abort_unless(Schema::hasTable('endpoint_script_cancel_leases'), 503);
        $latest = DB::table('endpoint_script_cancel_leases')->where('endpoint_delivery_id', $delivery->id)
            ->orderByDesc('sequence')->lock('for update nowait')->first();
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        if ($latest) {
            abort_unless((int) $latest->sequence >= 1, 409);
            try {
                $envelope = get_object_vars(EndpointScriptContract::decode(Crypt::decryptString($latest->encrypted_envelope)));
            } catch (DecryptException|InvalidArgumentException) {
                abort(409);
            }
            $cancel = $this->verified($envelope);
            EndpointScriptContract::cancellation($cancel, $command, $delivery->payload_hash, $cancel->issued_at ?? '');
            abort_unless(hash_equals($latest->payload_hash, hash('sha256', base64_decode($envelope['payload'], true)))
                && $cancel->issued_at === $this->time(CarbonImmutable::parse($latest->issued_at, 'UTC'))
                && $cancel->expires_at === $this->time(CarbonImmutable::parse($latest->expires_at, 'UTC'))
                && $delivery->encrypted_cancellation === $envelope, 409);
            if (CarbonImmutable::parse($latest->expires_at, 'UTC')->greaterThan($now)) {
                return $envelope;
            }
        } else {
            abort_unless($delivery->encrypted_cancellation === null, 409);
        }
        $sequence = (int) ($latest?->sequence ?? 0) + 1;
        abort_unless($sequence <= 4294967295, 409);
        $expires = $now->addHour();
        $cancel = (object) [
            'version' => 1, 'type' => 'endpoint_script_cancel', 'agent_uuid' => $delivery->agent_uuid,
            'command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid,
            'assignment_fingerprint' => $command->assignment_fingerprint, 'payload_hash' => $delivery->payload_hash,
            'issued_at' => $this->time($now), 'expires_at' => $this->time($expires),
        ];
        EndpointScriptContract::cancellation($cancel, $command, $delivery->payload_hash, $this->time($now));
        $envelope = $this->signer->sign(get_object_vars($cancel));
        $hash = hash('sha256', base64_decode($envelope['payload'], true));
        DB::table('endpoint_script_cancel_leases')->insert([
            'endpoint_delivery_id' => $delivery->id, 'sequence' => $sequence, 'payload_hash' => $hash,
            'encrypted_envelope' => Crypt::encryptString(EndpointScriptContract::encode((object) $envelope)),
            'issued_at' => $now, 'expires_at' => $expires,
        ]);
        $delivery->forceFill(['encrypted_cancellation' => $envelope])->save();
        $this->audit->append($request, null, 'endpoint_script_cancel_delivery_issued', [
            'attempt_number' => $attempt->attempt_number, 'sequence' => $sequence, 'cancellation_payload_hash' => $hash,
        ]);

        return $envelope;
    }

    private function original(DeviceCommandRequest $request, DeviceCommandAttempt $attempt, EndpointDelivery $delivery, EndpointAgent $agent, bool $requireCatalogue = true): stdClass
    {
        abort_unless(app(DeviceCommandContractVerifier::class)->verify($request), 409);
        $parameters = $request->encrypted_parameters;
        $inputs = EndpointScriptRequestParameters::inputs($parameters);
        $dispatch = $this->verified($delivery->encrypted_payload);
        EndpointScriptContract::dispatch($dispatch);
        // A cancellation grants no execution and must remain deliverable when
        // catalogue material is damaged. Its original signed binding still applies.
        $manifest = $requireCatalogue
            ? app(EndpointScripts::class)->retainedManifest($parameters['script_uuid'], $parameters['content_hash'])
            : $dispatch->parameters->manifest;
        $expected = $request->expected_state;
        $candidate = ['parameters' => (object) [
            'script_uuid' => $parameters['script_uuid'], 'script_version' => $parameters['script_version'],
            'manifest' => $manifest, 'inputs' => $inputs,
        ], 'policy' => ['version' => $expected['policy_version'], 'enabled' => true, 'consent_mode' => $expected['consent_mode']]];
        $command = $this->command($request, $attempt, $delivery, $agent, $candidate);
        app(EndpointScriptAuthority::class)->assertBinding($request, [
            'script_execution_completed' => true, 'script_uuid' => $manifest->script_uuid,
            'script_version' => $manifest->script_version, 'content_hash' => $parameters['content_hash'],
            'artifact_sha256' => $manifest->sha256, 'runtime_manifest_sha256' => $manifest->runtime_manifest_sha256,
            'wrapper_sha256' => $manifest->wrapper_sha256, 'architecture' => $manifest->architecture,
            'agent_uuid' => $agent->uuid, 'assignment_fingerprint' => $agent->assignment_fingerprint,
            'policy_version' => $parameters['policy_version'], 'consent_mode' => $command->policy->consent_mode,
        ]);

        return $command;
    }

    private function current(DeviceCommandRequest $request, Closure $callback, bool $includeArtifact = false): mixed
    {
        $this->governance->assertScriptDelivery($request);

        return app(EndpointScriptAuthority::class)->withBinding($request->device, $request->requestedBy, $request->encrypted_parameters,
            function (User $actor, Device $device, array $binding, EndpointAgent $agent, array $candidate) use ($request, $callback): mixed {
                app(EndpointScriptAuthority::class)->assertBinding($request, $binding);

                return $callback($agent, $candidate);
            }, $includeArtifact);
    }

    private function command(DeviceCommandRequest $request, DeviceCommandAttempt $attempt, EndpointDelivery $delivery, EndpointAgent $agent, array $candidate): stdClass
    {
        $command = $this->verified($delivery->encrypted_payload);
        EndpointScriptContract::dispatch($command);
        abort_unless((int) $delivery->endpoint_agent_id === (int) $agent->id && $delivery->agent_uuid === $agent->uuid
            && $command->agent_uuid === $agent->uuid && $command->command_uuid === $request->command_uuid
            && $command->attempt_uuid === $attempt->attempt_uuid && $command->assignment_fingerprint === $agent->assignment_fingerprint
            && hash_equals($delivery->payload_hash, hash('sha256', base64_decode($delivery->encrypted_payload['payload'], true)))
            && EndpointScriptContract::encode($command->parameters) === EndpointScriptContract::encode($candidate['parameters'])
            && EndpointScriptContract::encode($command->policy) === EndpointScriptContract::encode((object) $candidate['policy'])
            && $command->expires_at === $this->time($request->expires_at) && $delivery->expires_at->equalTo($request->expires_at), 409);

        return $command;
    }

    private function verified(array $signed): stdClass
    {
        abort_unless(count($signed) === 2 && is_string($signed['payload'] ?? null) && is_string($signed['signature'] ?? null), 409);
        $bytes = base64_decode($signed['payload'], true);
        $signature = base64_decode($signed['signature'], true);
        abort_unless(is_string($bytes) && strlen($bytes) <= 32768 && is_string($signature) && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($signature, $bytes, base64_decode($this->signer->publicKey(), true)), 409);

        return EndpointScriptContract::decode($bytes);
    }

    private function locked(EndpointAgent $agent, string $uuid): array
    {
        $found = DeviceCommandAttempt::where('attempt_uuid', $uuid)->firstOrFail();
        $request = DeviceCommandRequest::whereKey($found->device_command_request_id)->lock('for update nowait')->firstOrFail();
        $attempt = DeviceCommandAttempt::whereKey($found->id)->lock('for update nowait')->firstOrFail();
        $delivery = EndpointDelivery::where('device_command_attempt_id', $attempt->id)->where('endpoint_agent_id', $agent->id)
            ->where('agent_uuid', $agent->uuid)->lock('for update nowait')->firstOrFail();
        abort_unless((int) $request->device_id === (int) $agent->device_id && $attempt->runtime === 'endpoint'
            && $request->capability === EndpointScriptContract::CAPABILITY && ($request->expected_state['agent_uuid'] ?? null) === $agent->uuid, 404);

        return [$request, $attempt, $delivery];
    }

    private function pending(EndpointDelivery $delivery): void
    {
        abort_if($delivery->completed_at !== null || $delivery->cancel_requested_at !== null || $delivery->expires_at->lessThanOrEqualTo(now()), 409);
    }

    private function authenticated(string $credential, Closure $callback): mixed
    {
        return $this->contention(fn () => $this->identity->withAuthenticated($credential, $callback));
    }

    private function contention(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                throw new HttpException(503);
            }
            throw $error;
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
