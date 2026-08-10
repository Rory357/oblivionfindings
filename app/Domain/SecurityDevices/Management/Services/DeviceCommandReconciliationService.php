<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Enums\CommandReconciliationOutcome;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandReconciliation;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DeviceCommandReconciliationService
{
    public function __construct(
        private readonly CommandExecutionAdapterRegistry $adapters,
        private readonly DeviceCommandAuditService $audit,
    ) {}

    public function reconcile(DeviceCommandRequest $request, ?User $actor = null): DeviceCommandReconciliation
    {
        $request->loadMissing(['device', 'attempts']);
        if (! in_array($request->status, [CommandStatus::Reconciling, CommandStatus::Uncertain], true)) {
            throw ValidationException::withMessages([
                'command' => 'Only a completed or uncertain execution can be reconciled.',
            ]);
        }
        $attempt = $request->attempts->sortByDesc('attempt_number')->first();
        if (! $attempt) {
            throw ValidationException::withMessages(['command' => 'No execution attempt exists to reconcile.']);
        }
        $adapter = $this->adapters->for($request->device, $request->capability);
        $context = new CommandExecutionContext(
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

        try {
            $observation = $adapter->observe($context);
            $observedState = Arr::only($observation->state, array_keys($request->expected_state));
            $this->audit->assertSafeContext($observedState);
            $matched = count($observedState) === count($request->expected_state)
                && collect($request->expected_state)->every(
                    fn (mixed $expected, string $key): bool => array_key_exists($key, $observedState)
                        && $observedState[$key] === $expected,
                );
            $outcome = $matched
                ? CommandReconciliationOutcome::Matched
                : CommandReconciliationOutcome::Mismatch;
            $observedAt = $observation->observedAt;
            $observationReference = $observation->observationReference;
            $safeEvidenceSummary = $observation->safeEvidenceSummary;
        } catch (Throwable) {
            $observedState = null;
            $outcome = CommandReconciliationOutcome::Uncertain;
            $observedAt = CarbonImmutable::now('UTC')->startOfSecond();
            $observationReference = null;
            $safeEvidenceSummary = 'Fresh state could not be confirmed. Do not retry a high-risk command until actual state is known.';
        }

        return DB::transaction(function () use (
            $request,
            $attempt,
            $actor,
            $observedState,
            $outcome,
            $observedAt,
            $observationReference,
            $safeEvidenceSummary,
        ): DeviceCommandReconciliation {
            $locked = DeviceCommandRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (! in_array($locked->status, [CommandStatus::Reconciling, CommandStatus::Uncertain], true)) {
                throw ValidationException::withMessages(['command' => 'The command lifecycle changed before reconciliation.']);
            }

            $reconciliation = DeviceCommandReconciliation::query()->create([
                'device_command_request_id' => $locked->id,
                'device_command_attempt_id' => $attempt->id,
                'outcome' => $outcome,
                'expected_state' => $locked->expected_state,
                'observed_state' => $observedState,
                'observation_reference' => $observationReference,
                'safe_evidence_summary' => $safeEvidenceSummary,
                'observed_at' => $observedAt,
            ]);
            $locked->status = match ($outcome) {
                CommandReconciliationOutcome::Matched => CommandStatus::Reconciled,
                CommandReconciliationOutcome::Mismatch => CommandStatus::Mismatch,
                CommandReconciliationOutcome::Uncertain => CommandStatus::Uncertain,
            };
            $locked->reconciled_at = $outcome === CommandReconciliationOutcome::Matched
                ? $observedAt
                : null;
            $locked->save();
            $this->audit->append($locked, $actor, 'reconciliation_'.$outcome->value, [
                'attempt_number' => $attempt->attempt_number,
                'outcome' => $outcome->value,
                'status' => $locked->status->value,
            ]);
            if ($outcome === CommandReconciliationOutcome::Matched) {
                DeviceEvent::query()->create([
                    'device_id' => $locked->device_id,
                    'event_type' => 'management_command_reconciled',
                    'severity' => 'info',
                    'source' => 'oblivion_command_plane',
                    'occurred_at' => $observedAt,
                    'payload' => [
                        'command_uuid' => $locked->command_uuid,
                        'capability' => $locked->capability,
                        'site_id' => $locked->site_id,
                        'attempt_number' => $attempt->attempt_number,
                        'outcome' => $outcome->value,
                    ],
                ]);
            }

            return $reconciliation;
        });
    }
}
