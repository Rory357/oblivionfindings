<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Management\Data\CommandCapabilityDefinition;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Data\CommandSigningPayload;
use App\Domain\SecurityDevices\Management\Enums\CommandConfirmationMode;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class DeviceCommandRequestService
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
        private readonly CommandExpectedStateResolver $expectedState,
        private readonly CommandChangeEligibilityService $changeEligibility,
        private readonly CommandExecutionRouteResolver $executionRoutes,
        private readonly CommandRequestSigner $signer,
        private readonly DeviceCommandAuditService $audit,
        private readonly DeviceCommandBreakGlassService $breakGlass,
        private readonly DeviceCommandIntakeAuditService $intakeAudit,
    ) {}

    public function request(Device $device, User $actor, CommandRequestInput $input): DeviceCommandRequest
    {
        abort_unless($actor->canDo('securityDevices.devices.view'), 403);
        $this->access->assertCanViewDevice($actor, $device);
        try {
            $capability = $this->capabilities->definition($input->capability);
        } catch (DomainException) {
            throw ValidationException::withMessages([
                'capability' => 'This management action is not recognised.',
            ]);
        }
        $authorization = $this->authorization->evaluate(
            $actor,
            $device,
            $capability,
            fresh: true,
        );
        abort_unless($authorization->allowed, $authorization->concealed ? 404 : 403);

        if (! $this->declaredCapabilities->supports($device, $capability->key)) {
            throw ValidationException::withMessages([
                'capability' => 'This device does not declare support for that management action.',
            ]);
        }

        $deviceState = $device->status?->value ?? (string) $device->status;
        if (! in_array($deviceState, $capability->allowedCurrentStates, true)) {
            throw ValidationException::withMessages([
                'device' => 'This action is not available in the device current state.',
            ]);
        }

        try {
            $siteId = $this->siteResolver->resolve((int) $device->id);
            $assignmentFingerprint = $this->assignments->forDevice($device);
        } catch (UnexpectedValueException) {
            abort(404);
        }
        $executionRoute = $this->executionRoutes->resolve($device, $siteId, $capability->key);
        $collectorId = $executionRoute->mode === 'collector'
            ? (int) $executionRoute->collector?->id
            : null;
        if ($capability->requiresFreshObservation) {
            $freshness = $this->freshness->inspect($device);
            if (! $freshness->isFresh()) {
                throw ValidationException::withMessages([
                    'device' => $freshness->state === 'never_observed'
                        ? 'A current Device observation is required before this action can be requested.'
                        : 'The last confirmed Device observation is stale. Refresh monitoring evidence before requesting this action.',
                ]);
            }
        }
        if ($capability->requiresMfa && $actor->two_factor_confirmed_at === null) {
            throw ValidationException::withMessages([
                'device' => 'Configured multi-factor authentication is required for this critical action.',
            ]);
        }
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $impactAcknowledgedAt = null;
        if ($capability->isHighRisk()) {
            if (! $input->impactAcknowledged) {
                throw ValidationException::withMessages([
                    'impact_acknowledged' => 'You must acknowledge the stated impact before requesting this high-risk action.',
                ]);
            }
            if ($capability->confirmationMode === CommandConfirmationMode::TypeDeviceName
                && ! hash_equals($device->name, trim((string) $input->confirmationText))) {
                throw ValidationException::withMessages([
                    'confirmation_text' => 'Type the exact Device name to confirm this critical action.',
                ]);
            }
            $impactAcknowledgedAt = $now;
        }
        $parameters = $this->parameters->validate($capability, $input->parameters);
        $this->parameterPolicy->assertAllowed($device, $capability, $parameters);
        $reason = trim($input->reason);
        $idempotencyKey = trim($input->idempotencyKey);
        Validator::make([
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
        ], [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9:_-]+$/'],
        ])->validate();

        $stepUpConfirmedAt = $this->validStepUp($input->stepUpConfirmedAt, $now);
        $breakGlassReviewer = null;
        $breakGlassReason = null;
        if ($input->breakGlass) {
            if (! $capability->allowsBreakGlass) {
                throw ValidationException::withMessages([
                    'break_glass' => 'Break glass is not permitted for this management action.',
                ]);
            }
            $this->breakGlass->assertRequesterEligible($actor);
            if ($stepUpConfirmedAt === null) {
                throw ValidationException::withMessages([
                    'break_glass' => 'Recent identity confirmation is required before declaring break glass.',
                ]);
            }
            if ($input->itChangeId !== null) {
                throw ValidationException::withMessages([
                    'it_change_id' => 'Choose either the approved IT Change path or break glass, not both.',
                ]);
            }
            $breakGlassReason = $this->breakGlass->emergencyReason($input->breakGlassReason);
            $breakGlassReviewer = $this->breakGlass->reviewerFor(
                $actor,
                $device,
                $input->breakGlassReviewerUserId,
                $capability->key,
            );
        }
        if (! $input->breakGlass && ($input->breakGlassReason !== null || $input->breakGlassReviewerUserId !== null)) {
            throw ValidationException::withMessages([
                'break_glass' => 'Emergency reason and reviewer are accepted only for an explicit break-glass declaration.',
            ]);
        }
        if (! $input->breakGlass && $capability->requiresChange && $input->itChangeId === null) {
            throw ValidationException::withMessages([
                'it_change_id' => 'This action requires a current approved IT Change linked to this Device and Site.',
            ]);
        }
        if (! $input->breakGlass && ! $capability->requiresChange && $input->itChangeId !== null) {
            throw ValidationException::withMessages([
                'it_change_id' => 'This action does not use an IT Change maintenance window.',
            ]);
        }

        $change = $input->itChangeId !== null
            ? $this->changeEligibility->assertEligible($input->itChangeId, $actor, $device, $siteId, $now)
            : null;
        $existing = DeviceCommandRequest::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('device_id', $device->id)
            ->where('requested_by_user_id', $actor->id)
            ->where('capability', $capability->key)
            ->first();
        if ($existing) {
            $resumed = $this->resumeExisting(
                $existing,
                $actor,
                $capability,
                $parameters,
                $reason,
                $input->itChangeId,
                $input->breakGlass,
                $breakGlassReason,
                $breakGlassReviewer?->id,
                $stepUpConfirmedAt,
                $now,
            );
            $this->intakeAudit->recordAllowed($resumed, $actor);

            return $resumed;
        }

        $expiresAt = $now->addSeconds($capability->expiresAfterSeconds);
        if ($input->breakGlass) {
            $breakGlassExpiry = $now->addSeconds(max(30, (int) config('security_devices.break_glass_max_age_seconds', 120)));
            if ($breakGlassExpiry->lessThan($expiresAt)) {
                $expiresAt = $breakGlassExpiry;
            }
        }
        if ($change?->maintenance_ends_at !== null && $change->maintenance_ends_at->lessThan($expiresAt)) {
            $expiresAt = CarbonImmutable::instance($change->maintenance_ends_at);
        }
        $status = $this->initialStatus(
            $capability,
            $stepUpConfirmedAt !== null,
            $change !== null,
            $input->breakGlass,
        );
        $expectedState = $this->expectedState->resolve($capability->key, $parameters);
        $safeSummary = Arr::only($parameters, $capability->safeSummaryFields);
        $commandUuid = (string) Str::orderedUuid();
        $payload = new CommandSigningPayload(
            commandUuid: $commandUuid,
            deviceId: (int) $device->id,
            siteId: $siteId,
            requestedByUserId: (int) $actor->id,
            capability: $capability->key,
            capabilityVersion: 1,
            managementLevel: $capability->level->value,
            risk: $capability->risk->value,
            idempotencyKey: $idempotencyKey,
            parametersHash: $this->signer->parametersHash($parameters),
            reasonHash: $this->signer->reasonHash($reason),
            expectedState: $expectedState,
            reconciliationRule: $capability->reconciliation,
            expiresAt: $expiresAt,
            itChangeId: $change?->id,
            collectorId: $collectorId,
            isBreakGlass: $input->breakGlass,
            provider: $device->provider,
            breakGlassReviewerUserId: $breakGlassReviewer?->id,
            breakGlassReasonHash: $breakGlassReason === null ? null : $this->signer->reasonHash($breakGlassReason),
            assignmentFingerprint: $assignmentFingerprint,
            confirmationMode: $capability->isHighRisk() ? $capability->confirmationMode->value : null,
            impactAcknowledgedAt: $impactAcknowledgedAt,
        );
        $signature = $this->signer->sign($payload);

        return DB::transaction(function () use (
            $device,
            $actor,
            $capability,
            $parameters,
            $reason,
            $idempotencyKey,
            $siteId,
            $collectorId,
            $assignmentFingerprint,
            $stepUpConfirmedAt,
            $impactAcknowledgedAt,
            $status,
            $expectedState,
            $safeSummary,
            $commandUuid,
            $signature,
            $expiresAt,
            $change,
            $input,
            $breakGlassReviewer,
            $breakGlassReason,
            $now,
        ): DeviceCommandRequest {
            $existing = DeviceCommandRequest::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('device_id', $device->id)
                ->where('requested_by_user_id', $actor->id)
                ->where('capability', $capability->key)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $this->assertIdempotentContract(
                    $existing,
                    $parameters,
                    $reason,
                    $change?->id,
                    $input->breakGlass,
                    $breakGlassReason,
                    $breakGlassReviewer?->id,
                );
                $this->intakeAudit->recordAllowed($existing, $actor);

                return $existing;
            }

            $request = DeviceCommandRequest::query()->create([
                'command_uuid' => $commandUuid,
                'device_id' => $device->id,
                'site_id' => $siteId,
                'assignment_fingerprint' => $assignmentFingerprint,
                'requested_by_user_id' => $actor->id,
                'it_change_id' => $change?->id,
                'collector_id' => $collectorId,
                'capability' => $capability->key,
                'capability_version' => 1,
                'management_level' => $capability->level,
                'risk' => $capability->risk,
                'confirmation_mode' => $capability->confirmationMode,
                'status' => $status,
                'encrypted_parameters' => $parameters,
                'safe_parameter_summary' => $safeSummary,
                'reason' => $reason,
                'expected_state' => $expectedState,
                'reconciliation_rule' => $capability->reconciliation,
                'idempotency_key' => $idempotencyKey,
                'signing_key_id' => $signature['key_id'],
                'signature' => $signature['signature'],
                'provider' => $device->provider,
                'is_break_glass' => $input->breakGlass,
                'break_glass_reason' => $breakGlassReason,
                'break_glass_reviewer_user_id' => $breakGlassReviewer?->id,
                'break_glass_declared_at' => $input->breakGlass ? $now : null,
                'break_glass_review_due_at' => $input->breakGlass
                    ? $now->addSeconds(max(300, (int) config('security_devices.break_glass_review_due_seconds', 86400)))
                    : null,
                'step_up_confirmed_at' => $stepUpConfirmedAt,
                'impact_acknowledged_at' => $impactAcknowledgedAt,
                'expires_at' => $expiresAt,
            ]);
            $this->audit->append($request, $actor, 'requested', [
                'capability' => $capability->key,
                'risk' => $capability->risk->value,
                'site_id' => $siteId,
                'status' => $status->value,
                'execution_route' => $collectorId === null ? 'central' : 'collector',
                'safe_parameters' => $safeSummary,
                'confirmation_mode' => $capability->confirmationMode->value,
                'impact_acknowledged' => $impactAcknowledgedAt !== null,
            ]);
            $this->intakeAudit->recordAllowed($request, $actor);
            if ($input->breakGlass && $breakGlassReviewer instanceof User) {
                $this->audit->append($request, $actor, 'break_glass_declared', [
                    'reviewer_user_id' => (int) $breakGlassReviewer->id,
                    'review_due_at' => $request->break_glass_review_due_at->toISOString(),
                    'site_id' => $siteId,
                    'status' => $status->value,
                ]);
                $this->breakGlass->notifyReviewer($request, $actor, $breakGlassReviewer);
            }

            return $request;
        });
    }

    private function validStepUp(?CarbonImmutable $confirmedAt, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($confirmedAt === null || $confirmedAt->isFuture()) {
            return null;
        }

        $maxAge = max(60, (int) config('security_devices.step_up_max_age_seconds', 900));

        return $confirmedAt->greaterThanOrEqualTo($now->subSeconds($maxAge)) ? $confirmedAt : null;
    }

    /** @param array<string, mixed> $parameters */
    private function resumeExisting(
        DeviceCommandRequest $existing,
        User $actor,
        CommandCapabilityDefinition $capability,
        array $parameters,
        string $reason,
        ?int $itChangeId,
        bool $isBreakGlass,
        ?string $breakGlassReason,
        ?int $breakGlassReviewerUserId,
        ?CarbonImmutable $stepUpConfirmedAt,
        CarbonImmutable $now,
    ): DeviceCommandRequest {
        $this->assertIdempotentContract(
            $existing,
            $parameters,
            $reason,
            $itChangeId,
            $isBreakGlass,
            $breakGlassReason,
            $breakGlassReviewerUserId,
        );

        return DB::transaction(function () use ($existing, $actor, $capability, $stepUpConfirmedAt, $now): DeviceCommandRequest {
            $locked = DeviceCommandRequest::query()->with('device')->lockForUpdate()->findOrFail($existing->id);
            if ($locked->expires_at->lessThanOrEqualTo($now) && ! $locked->status->isTerminal()) {
                $locked->status = CommandStatus::Expired;
                $locked->save();
                $this->audit->append($locked, $actor, 'expired', ['status' => CommandStatus::Expired->value]);

                return $locked;
            }

            if ($locked->status === CommandStatus::AwaitingStepUp && $stepUpConfirmedAt !== null) {
                $locked->step_up_confirmed_at = $stepUpConfirmedAt;
                $locked->status = $this->initialStatus(
                    $capability,
                    true,
                    $locked->it_change_id !== null,
                    $locked->is_break_glass,
                );
                $locked->save();
                $this->audit->append($locked, $actor, 'step_up_confirmed', ['status' => $locked->status->value]);
            }
            if ($locked->status === CommandStatus::AwaitingChange
                && $locked->it_change_id !== null
                && (! $capability->requiresApproval || $locked->approved_by_user_id !== null)
                && $this->changeEligibility->isEligible(
                    (int) $locked->it_change_id,
                    $actor,
                    $locked->device,
                    (int) $locked->site_id,
                    $now,
                )) {
                $locked->status = CommandStatus::Ready;
                $locked->save();
                $this->audit->append($locked, $actor, 'change_eligibility_confirmed', [
                    'status' => CommandStatus::Ready->value,
                ]);
            }

            return $locked;
        });
    }

    /** @param array<string, mixed> $parameters */
    private function assertIdempotentContract(
        DeviceCommandRequest $existing,
        array $parameters,
        string $reason,
        ?int $itChangeId,
        bool $isBreakGlass,
        ?string $breakGlassReason,
        ?int $breakGlassReviewerUserId,
    ): void {
        if ($existing->encrypted_parameters !== $parameters
            || $existing->reason !== $reason
            || (int) ($existing->it_change_id ?? 0) !== (int) ($itChangeId ?? 0)
            || $existing->is_break_glass !== $isBreakGlass
            || ($existing->break_glass_reason ?? null) !== $breakGlassReason
            || (int) ($existing->break_glass_reviewer_user_id ?? 0) !== (int) ($breakGlassReviewerUserId ?? 0)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This idempotency key is already bound to a different command contract.',
            ]);
        }
    }

    private function initialStatus(
        CommandCapabilityDefinition $capability,
        bool $hasRecentStepUp,
        bool $hasEligibleChange,
        bool $isBreakGlass = false,
    ): CommandStatus {
        if ($capability->requiresStepUp && ! $hasRecentStepUp) {
            return CommandStatus::AwaitingStepUp;
        }
        if ($capability->requiresApproval && ! $isBreakGlass) {
            return CommandStatus::AwaitingApproval;
        }
        if ($capability->requiresChange && ! $hasEligibleChange && ! $isBreakGlass) {
            return CommandStatus::AwaitingChange;
        }

        return CommandStatus::Ready;
    }
}
