<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimesheet;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HrTimesheetApprovalService
{
    public function submit(HrTimesheet $timesheet, User $actor): HrTimesheetWorkflowResult
    {
        return DB::transaction(function () use ($timesheet, $actor): HrTimesheetWorkflowResult {
            $locked = $this->lock($timesheet);

            if (! in_array($locked->status, ['draft', 'returned'], true)) {
                throw new \LogicException("Cannot submit a '{$locked->status}' timesheet.");
            }

            $totalHours = $this->closedEntries($locked)->sum('total_hours');

            $locked->forceFill([
                'status' => 'submitted',
                'total_hours' => $totalHours,
                'submitted_at' => now(),
                'submitted_by' => $actor->id,
                'approved_by' => null,
                'approved_at' => null,
                'decision_notes' => null,
                'rejection_reason' => null,
                'returned_by' => null,
                'returned_at' => null,
                'returned_notes' => null,
            ])->save();

            $this->closedEntries($locked)
                ->whereNotIn('status', ['approved', 'rejected'])
                ->update(['status' => 'submitted']);

            $fresh = $locked->fresh() ?? $locked;
            app(HrNotificationService::class)->notifyTimesheetSubmitted($fresh);

            return new HrTimesheetWorkflowResult($fresh, true);
        });
    }

    public function approve(HrTimesheet $timesheet, User $actor, ?string $notes = null): HrTimesheetWorkflowResult
    {
        return DB::transaction(function () use ($timesheet, $actor, $notes): HrTimesheetWorkflowResult {
            $locked = $this->lock($timesheet);

            if ($locked->status === 'approved') {
                return new HrTimesheetWorkflowResult($locked->fresh() ?? $locked, false);
            }

            if ($locked->status !== 'submitted') {
                throw new \LogicException("Cannot approve a '{$locked->status}' timesheet.");
            }

            if ((int) $locked->user_id === (int) $actor->id) {
                throw new \LogicException('You cannot approve your own timesheet.');
            }

            $locked->forceFill([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'decision_notes' => $notes,
                'rejection_reason' => null,
                'returned_by' => null,
                'returned_at' => null,
                'returned_notes' => null,
            ])->save();

            $this->closedEntries($locked)
                ->where('status', 'submitted')
                ->update([
                    'status' => 'approved',
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                ]);

            return new HrTimesheetWorkflowResult($locked->fresh() ?? $locked, true);
        });
    }

    public function returnForChanges(HrTimesheet $timesheet, User $actor, string $notes): HrTimesheetWorkflowResult
    {
        return DB::transaction(function () use ($timesheet, $actor, $notes): HrTimesheetWorkflowResult {
            $locked = $this->lock($timesheet);

            if ($locked->status !== 'submitted') {
                return new HrTimesheetWorkflowResult($locked->fresh() ?? $locked, false);
            }

            $locked->forceFill([
                'status' => 'returned',
                'returned_by' => $actor->id,
                'returned_at' => now(),
                'returned_notes' => $notes,
                'approved_by' => null,
                'approved_at' => null,
                'decision_notes' => $notes,
                'rejection_reason' => null,
            ])->save();

            $this->closedEntries($locked)
                ->where('status', 'submitted')
                ->update(['status' => 'active']);

            return new HrTimesheetWorkflowResult($locked->fresh() ?? $locked, true);
        });
    }

    public function reject(HrTimesheet $timesheet, User $actor, string $notes): HrTimesheetWorkflowResult
    {
        return DB::transaction(function () use ($timesheet, $actor, $notes): HrTimesheetWorkflowResult {
            $locked = $this->lock($timesheet);

            if ($locked->status !== 'submitted') {
                return new HrTimesheetWorkflowResult($locked->fresh() ?? $locked, false);
            }

            $locked->forceFill([
                'status' => 'rejected',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'decision_notes' => $notes,
                'rejection_reason' => $notes,
                'returned_by' => null,
                'returned_at' => null,
                'returned_notes' => null,
            ])->save();

            $this->closedEntries($locked)
                ->where('status', 'submitted')
                ->update(['status' => 'rejected']);

            return new HrTimesheetWorkflowResult($locked->fresh() ?? $locked, true);
        });
    }

    /**
     * @param  Collection<int, HrTimesheet>  $timesheets
     */
    public function bulkApprove(Collection $timesheets, User $actor, ?string $notes = null): HrBulkTimesheetResult
    {
        return HrBulkTimesheetResult::fromResults(
            $timesheets->map(fn (HrTimesheet $timesheet) => $this->approve($timesheet, $actor, $notes))
        );
    }

    /**
     * @param  Collection<int, HrTimesheet>  $timesheets
     */
    public function bulkReject(Collection $timesheets, User $actor, string $notes): HrBulkTimesheetResult
    {
        return HrBulkTimesheetResult::fromResults(
            $timesheets->map(fn (HrTimesheet $timesheet) => $this->reject($timesheet, $actor, $notes))
        );
    }

    /**
     * @param  Collection<int, HrTimesheet>  $timesheets
     */
    public function bulkReturn(Collection $timesheets, User $actor, string $notes): HrBulkTimesheetResult
    {
        return HrBulkTimesheetResult::fromResults(
            $timesheets->map(fn (HrTimesheet $timesheet) => $this->returnForChanges($timesheet, $actor, $notes))
        );
    }

    protected function lock(HrTimesheet $timesheet): HrTimesheet
    {
        return HrTimesheet::query()
            ->lockForUpdate()
            ->findOrFail($timesheet->id);
    }

    protected function closedEntries(HrTimesheet $timesheet)
    {
        return HrTimeEntry::query()
            ->forTenant($timesheet->tenant_id)
            ->forUser($timesheet->user_id)
            ->forDateRange(
                $timesheet->period_start->toDateString(),
                $timesheet->period_end->toDateString(),
            )
            ->whereNotNull('clock_out');
    }
}
