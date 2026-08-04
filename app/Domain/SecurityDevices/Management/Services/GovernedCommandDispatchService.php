<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Contracts\CommandDispatchPort;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionRoute;
use App\Domain\SecurityDevices\Management\Data\CommandSigningPayload;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Enums\ManagementLevel;
use App\Domain\SecurityDevices\Management\Exceptions\CommandDispatchPreconditionException;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use UnexpectedValueException;

final class GovernedCommandDispatchService implements CommandDispatchPort
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceManagementAuthorizationService $authorization,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly CommandCapabilityRegistry $capabilities,
        private readonly DeclaredDeviceCommandCapabilities $declaredCapabilities,
        private readonly CommandObservationFreshnessService $freshness,
        private readonly CommandAssignmentFingerprint $assignments,
        private readonly CommandParameterValidator $parameters,
        private readonly DeviceCommandParameterPolicyService $parameterPolicy,
        private readonly CommandExecutionRouteResolver $executionRoutes,
        private readonly CommandChangeEligibilityService $changeEligibility,
        private readonly CommandRequestSigner $signer,
        private readonly DeviceCommandAuditService $audit,
        private readonly DeviceCommandBreakGlassService $breakGlass,
    ) {}

    public function dispatch(DeviceCommandRequest $request, User $triggeredBy): DeviceCommandAttempt
    {
        $prepared = DB::transaction(function () use ($request, $triggeredBy): array {
            $locked = DeviceCommandRequest::query()
                ->with(['device', 'requestedBy', 'approvedBy', 'approvals'])
                ->lockForUpdate()
                ->findOrFail($request->id);
            try {
                $route = $this->assertDispatchable($locked, $triggeredBy);
            } catch (CommandDispatchPreconditionException $failure) {
                $this->applyPreconditionFailure($locked, $triggeredBy, $failure);

                return ['failure' => $failure];
            }

            $attempt = DeviceCommandAttempt::query()->create([
                'device_command_request_id' => $locked->id,
                'attempt_number' => ((int) $locked->attempts()->max('attempt_number')) + 1,
                'status' => CommandAttemptStatus::Dispatching,
                'runtime' => $route->mode,
            ]);
            $locked->status = CommandStatus::Dispatching;
            $locked->execution_route = $route->mode;
            $locked->dispatched_at = CarbonImmutable::now('UTC')->startOfSecond();
            $locked->save();
            $this->audit->append($locked, $triggeredBy, 'dispatching', [
                'attempt_number' => $attempt->attempt_number,
                'runtime' => $route->mode,
                'status' => CommandStatus::Dispatching->value,
            ]);

            return [
                'failure' => null,
                'request' => $locked,
                'attempt' => $attempt,
                'context' => $route->mode === 'central' ? $this->context($locked, $attempt) : null,
                'adapter' => $route->adapter,
                'deferred' => $route->mode === 'collector',
            ];
        });
        if ($prepared['failure'] instanceof CommandDispatchPreconditionException) {
            throw $prepared['failure']->asValidationException();
        }
        /** @var DeviceCommandRequest $locked */
        $locked = $prepared['request'];
        /** @var DeviceCommandAttempt $attempt */
        $attempt = $prepared['attempt'];
        if ($prepared['deferred'] === true) {
            return $attempt;
        }
        /** @var CommandExecutionContext $context */
        $context = $prepared['context'];
        /** @var CommandExecutionAdapter $adapter */
        $adapter = $prepared['adapter'];

        try {
            $result = $adapter->execute($context);
            $this->audit->assertSafeContext($result->safeSummary);
            $this->assertBoundedResult($result);
        } catch (Throwable) {
            $result = new CommandExecutionResult(
                status: CommandAttemptStatus::Uncertain,
                safeFailureReason: 'The execution adapter did not return a confirmed final result. Reconcile actual state before any retry.',
            );
        }

        return DB::transaction(function () use ($locked, $attempt, $triggeredBy, $result): DeviceCommandAttempt {
            $command = DeviceCommandRequest::query()->lockForUpdate()->findOrFail($locked->id);
            $execution = DeviceCommandAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($command->status !== CommandStatus::Dispatching
                || $execution->status !== CommandAttemptStatus::Dispatching) {
                throw ValidationException::withMessages([
                    'command' => 'The command lifecycle changed while the provider action was running. Reconcile before any retry.',
                ]);
            }

            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $execution->status = $result->status;
            $execution->provider_request_reference = $result->providerRequestReference;
            $execution->safe_result_summary = $result->safeSummary;
            $execution->evidence_reference = $result->evidenceReference;
            $execution->safe_failure_reason = $result->safeFailureReason;
            $execution->accepted_at = $now;
            $execution->started_at = in_array($result->status, [
                CommandAttemptStatus::Running,
                CommandAttemptStatus::Succeeded,
                CommandAttemptStatus::Failed,
                CommandAttemptStatus::Uncertain,
            ], true) ? $now : null;
            $execution->completed_at = $result->status->isTerminal() ? $now : null;
            $execution->save();

            $command->status = match ($result->status) {
                CommandAttemptStatus::Accepted => CommandStatus::Accepted,
                CommandAttemptStatus::Running => CommandStatus::Running,
                CommandAttemptStatus::Succeeded => CommandStatus::Reconciling,
                CommandAttemptStatus::Failed => CommandStatus::Failed,
                CommandAttemptStatus::Uncertain => CommandStatus::Uncertain,
                default => throw new \LogicException('Unexpected command attempt status.'),
            };
            $command->execution_completed_at = $result->status->isTerminal() ? $now : null;
            $command->safe_result_summary = $result->safeSummary === []
                ? null
                : json_encode($result->safeSummary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $command->safe_failure_reason = $result->safeFailureReason;
            $command->save();
            $this->audit->append($command, $triggeredBy, 'execution_'.$result->status->value, [
                'attempt_number' => $execution->attempt_number,
                'status' => $command->status->value,
                'safe_result' => $result->safeSummary,
            ]);

            return $execution;
        });
    }

    public function assertDispatchable(
        DeviceCommandRequest $request,
        User $triggeredBy,
    ): CommandExecutionRoute {
        $request->loadMissing(['device', 'requestedBy', 'approvedBy', 'approvals']);
        $requester = $request->requestedBy;

        abort_unless($triggeredBy->canDo('securityDevices.devices.view'), 403);
        try {
            $this->access->assertCanViewDevice($triggeredBy, $request->device);
        } catch (HttpExceptionInterface $exception) {
            if ((int) $triggeredBy->id === (int) $request->requested_by_user_id) {
                throw new CommandDispatchPreconditionException(
                    'requester_site_access_revoked',
                    'The original requester no longer has current access to this Device and Site. The request was blocked without execution.',
                );
            }

            throw $exception;
        }
        abort_unless(
            (int) $triggeredBy->id === (int) $request->requested_by_user_id
                || $triggeredBy->canDo('securityDevices.commands.admin'),
            403,
        );
        if (! $requester instanceof User || $requester->approved_at === null) {
            throw new CommandDispatchPreconditionException(
                'requester_unavailable',
                'The original requester is no longer an approved current user. The request was blocked without execution.',
            );
        }
        try {
            $capability = $this->capabilities->definition($request->capability);
        } catch (DomainException) {
            throw new CommandDispatchPreconditionException(
                'capability_policy_withdrawn',
                'The approved capability policy is no longer available. The request was blocked without execution.',
            );
        }
        if ($request->risk !== $capability->risk
            || $request->management_level !== $capability->level
            || $request->confirmation_mode !== $capability->confirmationMode
            || ($capability->isHighRisk() && $request->impact_acknowledged_at === null)) {
            throw new CommandDispatchPreconditionException(
                'risk_policy_changed',
                'The risk safeguards changed after this request was approved. The request was blocked without execution.',
            );
        }
        $triggeredByAuthorization = $this->authorization->evaluate(
            $triggeredBy,
            $request->device,
            $capability,
            fresh: true,
        );
        if (! $triggeredByAuthorization->allowed) {
            if ((int) $triggeredBy->id === (int) $requester->id) {
                throw new CommandDispatchPreconditionException(
                    'requester_authorisation_revoked',
                    'The requester application, Site, workspace, Device-class, ownership, privacy, sensitivity, or command authorisation changed after approval. The request was blocked without execution.',
                );
            }
            abort($triggeredByAuthorization->concealed ? 404 : 403);
        }
        $requesterAuthorization = $this->authorization->evaluate(
            $requester,
            $request->device,
            $capability,
            fresh: true,
        );
        if (! $requesterAuthorization->allowed) {
            throw new CommandDispatchPreconditionException(
                'requester_authorisation_revoked',
                'The requester application, Site, workspace, Device-class, ownership, privacy, sensitivity, or command authorisation changed after approval. The request was blocked without execution.',
            );
        }

        if (! in_array($request->status, [CommandStatus::Ready, CommandStatus::Queued], true)) {
            throw ValidationException::withMessages(['command' => 'Only a ready or queued command can be dispatched.']);
        }
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        if ($request->expires_at->lessThanOrEqualTo($now)) {
            throw new CommandDispatchPreconditionException(
                'command_expired',
                'The command expired before dispatch and was closed without execution.',
                CommandStatus::Expired,
            );
        }
        try {
            $currentSiteId = $this->siteResolver->resolve((int) $request->device_id);
            $currentAssignmentFingerprint = $this->assignments->forDevice($request->device, $now);
        } catch (UnexpectedValueException) {
            throw new CommandDispatchPreconditionException(
                'device_ownership_unresolved',
                'The Device no longer resolves to one current approved Site and assignment. The request was blocked without execution.',
            );
        }
        if ($currentSiteId !== (int) $request->site_id) {
            throw new CommandDispatchPreconditionException(
                'device_site_changed',
                'The Device Site changed after approval. The request was blocked without execution.',
            );
        }
        if (! is_string($request->assignment_fingerprint)
            || ! hash_equals($request->assignment_fingerprint, $currentAssignmentFingerprint)) {
            throw new CommandDispatchPreconditionException(
                'device_assignment_changed',
                'The Device assignment or ownership changed after approval. The request was blocked without execution.',
            );
        }
        if (! $this->declaredCapabilities->supports($request->device, $request->capability)) {
            throw new CommandDispatchPreconditionException(
                'capability_withdrawn',
                'The Device no longer declares this capability. The request was blocked without execution.',
            );
        }
        if ($request->provider !== $request->device->provider) {
            throw new CommandDispatchPreconditionException(
                'device_provider_changed',
                'The Device provider changed after approval. The request was blocked without execution.',
            );
        }
        $deviceState = $request->device->status?->value ?? (string) $request->device->status;
        if (! in_array($deviceState, $capability->allowedCurrentStates, true)) {
            throw new CommandDispatchPreconditionException(
                'device_state_changed',
                'The Device state changed after approval. The request was blocked without execution.',
            );
        }
        if ($capability->requiresFreshObservation) {
            $freshness = $this->freshness->inspect($request->device, $now);
            if (! $freshness->isFresh()) {
                throw new CommandDispatchPreconditionException(
                    $freshness->state === 'never_observed' ? 'observation_missing' : 'observation_stale',
                    $freshness->state === 'never_observed'
                        ? 'A current Device observation is no longer available. The request was blocked without execution.'
                        : 'The last confirmed Device observation became stale. The request was blocked without execution.',
                );
            }
        }
        try {
            $parameters = $this->parameters->validate($capability, $request->encrypted_parameters ?? []);
            $this->parameterPolicy->assertAllowed($request->device, $capability, $parameters);
        } catch (ValidationException) {
            throw new CommandDispatchPreconditionException(
                'parameter_policy_changed',
                'The approved parameter policy changed after the request was signed. The request was blocked without execution.',
            );
        }
        if ($capability->requiresApproval) {
            $approver = $request->approvedBy;
            $approverAuthorization = $approver instanceof User
                ? $this->authorization->evaluate(
                    $approver,
                    $request->device,
                    $capability,
                    ManagementLevel::Observe,
                    true,
                )
                : null;
            $approved = $request->is_break_glass || ($approver instanceof User
                && $approver->approved_at !== null
                && (int) $approver->id !== (int) $request->requested_by_user_id
                && $approver->canDo('securityDevices.commands.approve')
                && $approverAuthorization?->allowed === true
                && $request->approvals->contains(fn ($approval): bool => $approval->decision === CommandApprovalDecision::Approved));
            if (! $approved) {
                throw new CommandDispatchPreconditionException(
                    'approval_no_longer_current',
                    'The independent approval is no longer current for this Device and Site. The request was blocked without execution.',
                );
            }
        }
        if ($capability->requiresChange && ! $request->is_break_glass) {
            if ($request->it_change_id === null) {
                throw new CommandDispatchPreconditionException(
                    'change_missing',
                    'A current approved IT Change is no longer linked. The request was blocked without execution.',
                );
            }
            try {
                foreach ([$requester, $triggeredBy] as $actor) {
                    $this->changeEligibility->assertEligible(
                        (int) $request->it_change_id,
                        $actor,
                        $request->device,
                        (int) $request->site_id,
                        $now,
                    );
                }
            } catch (ValidationException) {
                throw new CommandDispatchPreconditionException(
                    'change_window_closed',
                    'The approved IT Change or maintenance window is no longer current. The request was blocked without execution.',
                );
            }
        }
        if ($capability->requiresStepUp && ! $this->stepUpIsCurrent($request, $now)) {
            throw new CommandDispatchPreconditionException(
                'step_up_stale',
                'The requester identity confirmation is no longer current. The request was blocked without execution.',
            );
        }
        if ($capability->requiresMfa && $requester->two_factor_confirmed_at === null) {
            throw new CommandDispatchPreconditionException(
                'mfa_assurance_removed',
                'Configured multi-factor authentication is no longer present for this critical action. The request was blocked without execution.',
            );
        }
        try {
            $this->breakGlass->assertDispatchable($request);
        } catch (ValidationException) {
            throw new CommandDispatchPreconditionException(
                'break_glass_governance_invalid',
                'Break-glass governance is no longer complete. The request was blocked without execution.',
            );
        }
        if (! $this->signatureIsValid($request)) {
            throw new CommandDispatchPreconditionException(
                'signature_invalid',
                'The signed command contract could not be verified. The request was blocked without execution.',
            );
        }

        $route = $this->executionRoutes->resolve(
            $request->device,
            (int) $request->site_id,
            $request->capability,
        );
        if ($request->collector_id !== null) {
            if (! $route->available
                || $route->mode !== 'collector'
                || (int) $route->collector?->id !== (int) $request->collector_id) {
                throw new CommandDispatchPreconditionException(
                    'collector_delivery_unavailable',
                    'The signed remote route no longer matches one current enrolled Site collector with an approved typed adapter. The request was blocked without execution.',
                );
            }

            return $route;
        }
        if (! $route->available || $route->mode !== 'central' || ! $route->adapter instanceof CommandExecutionAdapter) {
            throw new CommandDispatchPreconditionException(
                'provider_adapter_withdrawn',
                'No approved execution adapter is currently available. The request was blocked without execution.',
            );
        }

        return $route;
    }

    public function applyPreconditionFailure(
        DeviceCommandRequest $request,
        User $actor,
        CommandDispatchPreconditionException $failure,
    ): DeviceCommandRequest {
        if (! in_array($request->status, [CommandStatus::Ready, CommandStatus::Queued], true)) {
            return $request;
        }

        $request->status = $failure->terminalStatus;
        $request->safe_failure_reason = $failure->safeMessage;
        $request->blocked_reason_code = $failure->reasonCode;
        if ($failure->terminalStatus === CommandStatus::Blocked) {
            $request->blocked_at = CarbonImmutable::now('UTC')->startOfSecond();
        }
        $request->save();
        $this->audit->append(
            $request,
            $actor,
            $failure->terminalStatus === CommandStatus::Expired ? 'expired_before_dispatch' : 'dispatch_blocked',
            [
                'reason_code' => $failure->reasonCode,
                'status' => $failure->terminalStatus->value,
            ],
        );

        return $request;
    }

    private function context(DeviceCommandRequest $request, DeviceCommandAttempt $attempt): CommandExecutionContext
    {
        return new CommandExecutionContext(
            commandUuid: $request->command_uuid,
            attemptUuid: $attempt->attempt_uuid,
            attemptNumber: $attempt->attempt_number,
            device: $request->device,
            siteId: $request->site_id,
            capability: $request->capability,
            parameters: $request->encrypted_parameters,
            expectedState: $request->expected_state,
            idempotencyKey: $request->idempotency_key,
            expiresAt: CarbonImmutable::instance($request->expires_at),
        );
    }

    private function signatureIsValid(DeviceCommandRequest $request): bool
    {
        $payload = new CommandSigningPayload(
            commandUuid: $request->command_uuid,
            deviceId: $request->device_id,
            siteId: $request->site_id,
            requestedByUserId: $request->requested_by_user_id,
            capability: $request->capability,
            capabilityVersion: $request->capability_version,
            managementLevel: $request->management_level->value,
            risk: $request->risk->value,
            idempotencyKey: $request->idempotency_key,
            parametersHash: $this->signer->parametersHash($request->encrypted_parameters),
            reasonHash: $this->signer->reasonHash($request->reason),
            expectedState: $request->expected_state,
            reconciliationRule: $request->reconciliation_rule,
            expiresAt: CarbonImmutable::instance($request->expires_at),
            itChangeId: $request->it_change_id,
            collectorId: $request->collector_id,
            isBreakGlass: $request->is_break_glass,
            provider: $request->provider,
            breakGlassReviewerUserId: $request->break_glass_reviewer_user_id,
            breakGlassReasonHash: $request->is_break_glass && is_string($request->break_glass_reason)
                ? $this->signer->reasonHash($request->break_glass_reason)
                : null,
            assignmentFingerprint: $request->assignment_fingerprint,
            confirmationMode: $request->impact_acknowledged_at === null
                ? null
                : $request->confirmation_mode?->value,
            impactAcknowledgedAt: $request->impact_acknowledged_at === null
                ? null
                : CarbonImmutable::instance($request->impact_acknowledged_at),
        );

        return is_string($request->signing_key_id)
            && is_string($request->signature)
            && $this->signer->verify($payload, $request->signing_key_id, $request->signature);
    }

    private function stepUpIsCurrent(DeviceCommandRequest $request, CarbonImmutable $now): bool
    {
        if ($request->step_up_confirmed_at === null || $request->step_up_confirmed_at->isFuture()) {
            return false;
        }
        $maxAge = max(60, (int) config('security_devices.step_up_max_age_seconds', 900));

        return $request->step_up_confirmed_at->greaterThanOrEqualTo($now->subSeconds($maxAge));
    }

    private function assertBoundedResult(CommandExecutionResult $result): void
    {
        foreach ([$result->providerRequestReference, $result->evidenceReference] as $reference) {
            if ($reference !== null && mb_strlen($reference) > 255) {
                throw new UnexpectedValueException('Command provider references must be bounded.');
            }
        }
        if ($result->safeFailureReason !== null && mb_strlen($result->safeFailureReason) > 2000) {
            throw new UnexpectedValueException('Command failure summaries must be bounded.');
        }
    }
}
