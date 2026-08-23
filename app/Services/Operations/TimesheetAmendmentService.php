<?php

namespace App\Services\Operations;

use App\Models\Timesheet;
use App\Models\TimesheetAmendment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimesheetAmendmentService
{
    /**
     * Request a correction to an approved timesheet.
     */
    public function request(Timesheet $timesheet, User $requester, array $proposedValues, string $reason): TimesheetAmendment
    {
        if ($timesheet->status !== 'approved') {
            throw ValidationException::withMessages([
                'timesheet' => 'Only approved timesheets can have amendment requests. Draft or submitted timesheets can be edited directly.',
            ]);
        }

        $pending = TimesheetAmendment::query()
            ->where('timesheet_id', $timesheet->id)
            ->where('status', TimesheetAmendment::STATUS_PENDING)
            ->exists();

        if ($pending) {
            throw ValidationException::withMessages([
                'timesheet' => 'This timesheet already has a pending amendment. Review or cancel the existing request first.',
            ]);
        }

        $invalidFields = array_diff(array_keys($proposedValues), TimesheetAmendment::AMENDABLE_FIELDS);
        if ($invalidFields !== []) {
            throw ValidationException::withMessages([
                'proposed_values' => 'The following fields cannot be amended: '.implode(', ', $invalidFields),
            ]);
        }

        $originalValues = [];
        foreach (array_keys($proposedValues) as $field) {
            $value = $timesheet->getAttribute($field);
            $originalValues[$field] = $value instanceof \DateTimeInterface ? $value->toISOString() : $value;
        }

        $isPayrollLinked = filled($timesheet->payroll_reference)
            || filled($timesheet->exported_to_payroll_at)
            || $timesheet->hasActivePayrollClaim();

        $amendment = TimesheetAmendment::create([
            'timesheet_id' => $timesheet->id,
            'status' => TimesheetAmendment::STATUS_PENDING,
            'original_values' => $originalValues,
            'proposed_values' => $proposedValues,
            'reason' => $reason,
            'requested_by' => $requester->id,
            'requested_at' => now(),
            'payroll_adjustment_required' => $isPayrollLinked,
        ]);

        AuditLogger::log('timesheet.amendment.requested', $timesheet, [
            'amendment_id' => $amendment->id,
            'requested_by' => $requester->id,
            'proposed_fields' => array_keys($proposedValues),
            'payroll_adjustment_required' => $isPayrollLinked,
        ]);

        return $amendment;
    }

    /**
     * Approve an amendment.
     *
     * For non-exported timesheets: applies proposed values to the original record.
     * For exported/payroll-linked timesheets: records approval but does NOT mutate
     * the original — the amendment stands as an approved correction awaiting
     * downstream payroll adjustment processing.
     */
    public function approve(TimesheetAmendment $amendment, User $reviewer, ?string $reviewNotes = null): TimesheetAmendment
    {
        if ($amendment->status !== TimesheetAmendment::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'amendment' => 'Only pending amendments can be approved.',
            ]);
        }

        if ((int) $amendment->requested_by === (int) $reviewer->id) {
            throw ValidationException::withMessages([
                'amendment' => 'You cannot approve your own amendment request.',
            ]);
        }

        return DB::transaction(function () use ($amendment, $reviewer, $reviewNotes) {
            $timesheet = Timesheet::query()
                ->lockForUpdate()
                ->findOrFail($amendment->timesheet_id);

            $isPayrollLinked = filled($timesheet->payroll_reference)
                || filled($timesheet->exported_to_payroll_at)
                || $timesheet->hasActivePayrollClaim();

            if ($isPayrollLinked) {
                // Exported timesheets: approve the correction record but do NOT
                // mutate the original. applied_at stays null to indicate the
                // values have not been written to the timesheet yet.
                $amendment->update([
                    'status' => TimesheetAmendment::STATUS_APPROVED,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'review_notes' => $reviewNotes,
                    'payroll_adjustment_required' => true,
                ]);

                AuditLogger::log('timesheet.amendment.approved_pending_payroll', $timesheet, [
                    'amendment_id' => $amendment->id,
                    'reviewed_by' => $reviewer->id,
                    'proposed_values' => $amendment->proposed_values,
                    'original_values' => $amendment->original_values,
                    'payroll_adjustment_required' => true,
                    'applied' => false,
                ]);
            } else {
                // Non-exported timesheets: apply proposed values directly.
                // This is the ONLY sanctioned path for mutating approved timesheets.
                $amendment->update([
                    'status' => TimesheetAmendment::STATUS_APPROVED,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'review_notes' => $reviewNotes,
                    'applied_at' => now(),
                ]);

                $timesheet->forceFill($amendment->proposed_values)->saveQuietly();

                AuditLogger::log('timesheet.amendment.approved', $timesheet, [
                    'amendment_id' => $amendment->id,
                    'reviewed_by' => $reviewer->id,
                    'applied_values' => $amendment->proposed_values,
                    'original_values' => $amendment->original_values,
                    'payroll_adjustment_required' => false,
                    'applied' => true,
                ]);
            }

            return $amendment->fresh();
        });
    }

    /**
     * Reject an amendment request.
     */
    public function reject(TimesheetAmendment $amendment, User $reviewer, ?string $reviewNotes = null): TimesheetAmendment
    {
        if ($amendment->status !== TimesheetAmendment::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'amendment' => 'Only pending amendments can be rejected.',
            ]);
        }

        $amendment->update([
            'status' => TimesheetAmendment::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $reviewNotes,
        ]);

        AuditLogger::log('timesheet.amendment.rejected', $amendment->timesheet, [
            'amendment_id' => $amendment->id,
            'reviewed_by' => $reviewer->id,
            'review_notes' => $reviewNotes,
        ]);

        return $amendment->fresh();
    }
}
