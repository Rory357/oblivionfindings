<?php

namespace App\Domain\Shifts\Timesheets;

use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Services\AlternativeHolidayService;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\BillingService;
use App\Services\Operations\TimesheetHrSyncService;
use App\Services\Operations\TimesheetReconciliationService;
use App\Services\ShiftOperationalSnapshotService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TimesheetApprovalService
{
    public function __construct(
        private readonly TimesheetReconciliationService $reconciliation,
        private readonly ShiftOperationalSnapshotService $snapshots,
        private readonly TimesheetHrSyncService $hrSync,
        private readonly BillingService $billing,
        private readonly AlternativeHolidayService $alternativeHolidays,
    ) {}

    public function submit(Timesheet $timesheet, User $actor): TimesheetWorkflowResult
    {
        try {
            return DB::transaction(function () use ($timesheet, $actor): TimesheetWorkflowResult {
                $locked = $this->lock($timesheet);

                $this->assertSubmittable($locked, 'submitted');
                $this->reconciliation->assertWorkflowAllowed($locked, 'submitted');

                $locked->forceFill($this->submittedFields($actor))->save();

                return new TimesheetWorkflowResult(
                    $locked->fresh(['shift.client']) ?? $locked,
                    true,
                );
            });
        } catch (ValidationException $exception) {
            $this->persistReconciliationBlockAfterRollback($timesheet, $exception);

            throw $exception;
        }
    }

    /**
     * Persist editable fields without allowing a concurrent workflow
     * transition to be overwritten by a stale route-bound model.
     *
     * @param  array<string, mixed>  $updates
     */
    public function updateEditable(Timesheet $timesheet, array $updates): TimesheetWorkflowResult
    {
        try {
            return DB::transaction(function () use ($timesheet, $updates): TimesheetWorkflowResult {
                $locked = $this->lock($timesheet);

                $this->assertSubmittable($locked, 'updated');
                $originalClientId = $this->clientId($locked);

                $locked->fill($updates);
                $locked->save();

                $this->invalidateChangedManualAllocations($locked, $originalClientId);
                $this->reconciliation->reconcile($locked->fresh() ?? $locked);

                return new TimesheetWorkflowResult(
                    $locked->fresh(['shift.client']) ?? $locked,
                    true,
                );
            });
        } catch (ValidationException $exception) {
            $this->persistReconciliationBlockAfterRollback($timesheet, $exception);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    public function resubmit(Timesheet $timesheet, User $actor, array $updates): TimesheetWorkflowResult
    {
        try {
            return DB::transaction(function () use ($timesheet, $actor, $updates): TimesheetWorkflowResult {
                $locked = $this->lock($timesheet);

                $this->assertSubmittable($locked, 'resubmitted');
                $originalClientId = $this->clientId($locked);

                $locked->fill($updates);
                $locked->save();

                $this->invalidateChangedManualAllocations($locked, $originalClientId);

                $this->reconciliation->assertWorkflowAllowed($locked->fresh() ?? $locked, 'submitted');

                $locked->forceFill($this->submittedFields($actor));
                $locked->save();

                return new TimesheetWorkflowResult(
                    $locked->fresh(['shift.client']) ?? $locked,
                    true,
                );
            });
        } catch (ValidationException $exception) {
            $this->persistReconciliationBlockAfterRollback($timesheet, $exception);

            throw $exception;
        }
    }

    public function approve(Timesheet $timesheet, User $actor, ?string $decisionNotes = null): TimesheetWorkflowResult
    {
        try {
            return DB::transaction(function () use ($timesheet, $actor, $decisionNotes): TimesheetWorkflowResult {
                $locked = $this->lock($timesheet);

                if ($locked->status === 'approved') {
                    return new TimesheetWorkflowResult(
                        $locked->fresh(['shift.client']) ?? $locked,
                        false,
                    );
                }

                if ($locked->status !== 'submitted') {
                    throw ValidationException::withMessages([
                        'timesheet' => 'Only submitted timesheets can be approved.',
                    ]);
                }

                $this->assertApprovalAllowed($locked, $actor);

                $locked->forceFill([
                    'status' => 'approved',
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'decision_notes' => $decisionNotes,
                ])->save();

                $this->syncApprovedTimesheet($locked);

                try {
                    $this->alternativeHolidays->accrueForTimesheet($locked->fresh() ?? $locked);
                } catch (\Throwable $exception) {
                    Log::warning('Alternative holiday accrual failed for approved timesheet', [
                        'timesheet_id' => $locked->id,
                        'error' => $exception->getMessage(),
                    ]);
                }

                return new TimesheetWorkflowResult(
                    $locked->fresh(['shift.client']) ?? $locked,
                    true,
                );
            });
        } catch (ValidationException $exception) {
            $this->persistReconciliationBlockAfterRollback($timesheet, $exception);

            throw $exception;
        }
    }

    public function returnForChanges(Timesheet $timesheet, User $actor, string $notes): TimesheetWorkflowResult
    {
        try {
            return DB::transaction(function () use ($timesheet, $actor, $notes): TimesheetWorkflowResult {
                $locked = $this->lock($timesheet);

                if ($locked->status !== 'submitted') {
                    return new TimesheetWorkflowResult(
                        $locked->fresh(['shift.client']) ?? $locked,
                        false,
                    );
                }

                $this->assertNotPayrollLinked($locked, 'returned after export preparation');
                // Status downgrade inside a locked/exported payroll period would
                // let the row be re-edited after payroll figures were frozen.
                $this->assertNotLockedByPayroll($locked, 'returned');
                $this->reconciliation->assertWorkflowAllowed($locked, 'returned');

                $locked->forceFill([
                    'status' => 'returned',
                    'returned_by' => $actor->id,
                    'returned_at' => now(),
                    'returned_notes' => $notes,
                    'approved_by' => null,
                    'approved_at' => null,
                    'decision_notes' => null,
                ])->save();

                return new TimesheetWorkflowResult(
                    $locked->fresh(['shift.client']) ?? $locked,
                    true,
                );
            });
        } catch (ValidationException $exception) {
            $this->persistReconciliationBlockAfterRollback($timesheet, $exception);

            throw $exception;
        }
    }

    public function reject(Timesheet $timesheet, User $actor, string $notes): TimesheetWorkflowResult
    {
        try {
            return DB::transaction(function () use ($timesheet, $actor, $notes): TimesheetWorkflowResult {
                $locked = $this->lock($timesheet);

                if ($locked->status !== 'submitted') {
                    return new TimesheetWorkflowResult(
                        $locked->fresh(['shift.client']) ?? $locked,
                        false,
                    );
                }

                $this->assertNotPayrollLinked($locked, 'rejected after export preparation');
                // Same payroll-period freeze as returnForChanges: a rejection is
                // a status change on a work_date payroll has already locked.
                $this->assertNotLockedByPayroll($locked, 'rejected');
                $this->reconciliation->assertWorkflowAllowed($locked, 'rejected');

                $locked->forceFill([
                    'status' => 'rejected',
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'decision_notes' => $notes,
                ])->save();

                return new TimesheetWorkflowResult(
                    $locked->fresh(['shift.client']) ?? $locked,
                    true,
                );
            });
        } catch (ValidationException $exception) {
            $this->persistReconciliationBlockAfterRollback($timesheet, $exception);

            throw $exception;
        }
    }

    /**
     * @param  Collection<int, Timesheet>  $timesheets
     */
    public function bulkApprove(Collection $timesheets, User $actor, ?string $decisionNotes = null): BulkResult
    {
        $timesheets
            ->filter(fn (Timesheet $timesheet): bool => $timesheet->status !== 'approved')
            ->each(fn (Timesheet $timesheet) => $this->assertApprovalAllowed($timesheet, $actor));

        return BulkResult::fromResults(
            $timesheets->map(fn (Timesheet $timesheet) => $this->approve($timesheet, $actor, $decisionNotes))
        );
    }

    /**
     * @param  Collection<int, Timesheet>  $timesheets
     */
    public function bulkReturn(Collection $timesheets, User $actor, string $notes): BulkResult
    {
        return BulkResult::fromResults(
            $timesheets->map(fn (Timesheet $timesheet) => $this->returnForChanges($timesheet, $actor, $notes))
        );
    }

    /**
     * @param  Collection<int, Timesheet>  $timesheets
     */
    public function bulkReject(Collection $timesheets, User $actor, string $notes): BulkResult
    {
        return BulkResult::fromResults(
            $timesheets->map(fn (Timesheet $timesheet) => $this->reject($timesheet, $actor, $notes))
        );
    }

    protected function lock(Timesheet $timesheet): Timesheet
    {
        return Timesheet::query()
            ->lockForUpdate()
            ->findOrFail($timesheet->id);
    }

    protected function clientId(Timesheet $timesheet): ?int
    {
        return $timesheet->client_id !== null
            ? (int) $timesheet->client_id
            : null;
    }

    protected function invalidateChangedManualAllocations(Timesheet $timesheet, ?int $originalClientId): void
    {
        $newClientId = $this->clientId($timesheet);

        // Materialised allocations belong to the previous primary client
        // selection. Invalidate them whenever a shiftless manual entry changes
        // or clears that client, inside the same transaction as the mutation.
        if ($timesheet->shift_id === null
            && ($newClientId === null || $originalClientId !== $newClientId)) {
            $timesheet->clientAllocations()->delete();
        }
    }

    protected function assertSubmittable(Timesheet $timesheet, string $action): void
    {
        if (! in_array($timesheet->status, ['draft', 'returned'], true)) {
            throw ValidationException::withMessages([
                'timesheet' => "Only draft or returned timesheets can be {$action}.",
            ]);
        }

        $this->assertNotLockedByPayroll($timesheet, $action);

        if ($timesheet->is_protected_from_changes) {
            throw ValidationException::withMessages([
                'timesheet' => 'Approved or payroll-linked timesheets require a controlled correction workflow.',
            ]);
        }

        if ($timesheet->linkedShiftIsCancelled()) {
            throw ValidationException::withMessages([
                'shift_id' => "Timesheets linked to cancelled shifts cannot be {$action}.",
            ]);
        }
    }

    protected function assertApprovalAllowed(Timesheet $timesheet, User $actor): void
    {
        if ((int) $timesheet->user_id === (int) $actor->id) {
            abort(403, 'You cannot approve your own timesheet.');
        }

        if ($timesheet->linkedShiftIsCancelled()) {
            abort(422, 'Timesheets linked to cancelled shifts cannot be approved.');
        }

        // Approving into a locked/exported payroll period would create an
        // approved timesheet the (already frozen) run never picked up — the
        // hours would silently never be paid. Block it like every other
        // in-period status mutation.
        $this->assertNotLockedByPayroll($timesheet, 'approved');

        $this->reconciliation->assertWorkflowAllowed($timesheet, 'approved');
    }

    protected function assertNotLockedByPayroll(Timesheet $timesheet, string $action): void
    {
        if (! $timesheet->work_date) {
            return;
        }

        $user = $timesheet->relationLoaded('user')
            ? $timesheet->user
            : User::query()->with('hrEmployeeProfile')->find($timesheet->user_id);

        $user?->loadMissing('hrEmployeeProfile');

        $tenantId = $user?->hrEmployeeProfile?->tenant_id
            ?? $user?->organization_id;

        if (! $tenantId) {
            return;
        }

        $locked = HrPayrollRun::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['locked', 'exported'])
            ->where('period_start', '<=', $timesheet->work_date)
            ->where('period_end', '>=', $timesheet->work_date)
            ->exists();

        if ($locked) {
            throw ValidationException::withMessages([
                'timesheet' => "This timesheet is locked by a payroll run and cannot be {$action}.",
            ]);
        }
    }

    protected function assertNotPayrollLinked(Timesheet $timesheet, string $action): void
    {
        if (! $timesheet->is_payroll_segment_complete && ! $timesheet->payroll_reference) {
            return;
        }

        throw ValidationException::withMessages([
            'timesheet' => "Payroll-linked timesheets cannot be {$action}.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function submittedFields(User $actor): array
    {
        return [
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => $actor->id,
            'approved_by' => null,
            'approved_at' => null,
            'decision_notes' => null,
            'returned_at' => null,
            'returned_by' => null,
            'returned_notes' => null,
        ];
    }

    protected function syncApprovedTimesheet(Timesheet $timesheet): void
    {
        // Use `load` (not `loadMissing`) for relations that may have been
        // partially eager-loaded by site access checks before approval.
        $timesheet->loadMissing([
            'user.hrEmployeeProfile',
        ]);
        $timesheet->load([
            'shift.site:id,name',
            'shift.client:id,first_name,last_name,site_id',
            'shift.serviceContext:id,name',
            'shift.staff:id,name',
            'client:id,first_name,last_name',
            'staff:id,name',
        ]);

        $snapshot = $this->snapshots->snapshotForTimesheet($timesheet);

        $timesheet->forceFill([
            'shift_site_id' => $snapshot['shift_site_id'] ?? $timesheet->shift_site_id,
            'shift_service_context_id' => $snapshot['shift_service_context_id'] ?? $timesheet->shift_service_context_id,
            'shift_site_name_snapshot' => $snapshot['shift_site_name_snapshot'] ?: $timesheet->shift_site_name_snapshot,
            'shift_location_snapshot' => $snapshot['shift_location_snapshot'] ?: $timesheet->shift_location_snapshot,
            'service_context_name_snapshot' => $snapshot['service_context_name_snapshot'] ?: $timesheet->service_context_name_snapshot,
            'client_name_snapshot' => $snapshot['client_name_snapshot'] ?: $timesheet->client_name_snapshot,
            'staff_name_snapshot' => $snapshot['staff_name_snapshot'] ?: $timesheet->staff_name_snapshot,
            'shift_type_snapshot' => $snapshot['shift_type_snapshot'] ?: $timesheet->shift_type_snapshot ?: 'standard',
            'coverage_roles_snapshot' => $snapshot['coverage_roles_snapshot'] ?? $timesheet->coverage_roles_snapshot ?? [],
        ])->saveQuietly();

        $freshTimesheet = $timesheet->fresh();
        $missingSnapshotFields = array_keys(array_filter([
            'client_name_snapshot' => blank($freshTimesheet?->client_name_snapshot),
            'staff_name_snapshot' => blank($freshTimesheet?->staff_name_snapshot),
            'shift_type_snapshot' => blank($freshTimesheet?->shift_type_snapshot),
        ]));

        if ($missingSnapshotFields !== []) {
            throw ValidationException::withMessages([
                'timesheet' => 'This timesheet is missing required snapshot data and cannot be approved safely: '.implode(', ', $missingSnapshotFields).'.',
            ]);
        }

        $this->hrSync->syncToHr($freshTimesheet);
        $this->billing->generateFromTimesheet($freshTimesheet);
    }

    protected function persistReconciliationBlockAfterRollback(Timesheet $timesheet, ValidationException $exception): void
    {
        $messages = collect($exception->errors())->flatten()->implode(' ');

        if (! str_contains($messages, 'reconciliation found blocking issues')) {
            return;
        }

        $freshTimesheet = $timesheet->fresh();

        if ($freshTimesheet) {
            $this->reconciliation->reconcile($freshTimesheet);
        }
    }
}
