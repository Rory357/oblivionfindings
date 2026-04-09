<?php

namespace App\Jobs;

use App\Models\Timesheet;
use App\Services\Operations\TimesheetReconciliationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled reconciliation of timesheets against their source shifts.
 *
 * Runs periodically to catch drift between shift data and timesheet data
 * that occurred without user interaction (e.g. shift edited after timesheet
 * was created, attendance updated, shift cancelled).
 *
 * Scope:
 *   - draft timesheets: re-syncs data from shift and re-evaluates reconciliation
 *   - submitted timesheets: re-evaluates reconciliation status only (does not overwrite user data)
 *   - approved / payroll-linked: skipped entirely
 *
 * Performance:
 *   - chunks by 50
 *   - loads only necessary relations
 *   - skips timesheets with no linked shift
 *   - skips when no changes detected (idempotent)
 */
class ReconcileTimesheetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How far back to look for candidate timesheets.
     */
    protected int $windowDays;

    public function __construct(int $windowDays = 14)
    {
        $this->windowDays = $windowDays;
    }

    public function handle(TimesheetReconciliationService $reconciliation): void
    {
        $cutoff = Carbon::now()->subDays($this->windowDays);
        $stats = ['scanned' => 0, 'synced' => 0, 'reconciled' => 0, 'skipped' => 0];

        Timesheet::query()
            ->whereIn('status', ['draft', 'submitted'])
            ->whereNotNull('shift_id')
            ->where('created_at', '>=', $cutoff)
            ->with([
                'shift:id,client_id,user_id,starts_at,ends_at,actual_starts_at,actual_ends_at,expected_break_minutes,status,is_sleepover,is_on_call,site_id,service_context_id',
                'shift.attendanceSessions:id,user_id,shift_id,clock_in_at,clock_out_at,break_minutes,status',
            ])
            ->chunkById(50, function ($timesheets) use ($reconciliation, &$stats) {
                foreach ($timesheets as $timesheet) {
                    $stats['scanned']++;

                    try {
                        $this->processTimesheet($timesheet, $reconciliation, $stats);
                    } catch (\Throwable $e) {
                        Log::warning('ReconcileTimesheetsJob: failed to process timesheet', [
                            'timesheet_id' => $timesheet->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($stats['synced'] > 0 || $stats['reconciled'] > 0) {
            Log::info('ReconcileTimesheetsJob completed', $stats);
        }
    }

    protected function processTimesheet(
        Timesheet $timesheet,
        TimesheetReconciliationService $reconciliation,
        array &$stats,
    ): void {
        $shift = $timesheet->shift;

        // No shift loaded (deleted or missing) — skip.
        if (! $shift) {
            $stats['skipped']++;
            return;
        }

        if ($timesheet->status === 'draft') {
            $this->syncDraftTimesheet($timesheet, $reconciliation, $stats);
        } elseif ($timesheet->status === 'submitted') {
            $this->reconcileSubmittedTimesheet($timesheet, $reconciliation, $stats);
        }
    }

    /**
     * Draft timesheets: re-sync data from shift if it has drifted,
     * then re-run reconciliation.
     */
    protected function syncDraftTimesheet(
        Timesheet $timesheet,
        TimesheetReconciliationService $reconciliation,
        array &$stats,
    ): void {
        $shift = $timesheet->shift;
        $changes = $this->detectChanges($timesheet, $shift);

        if (empty($changes)) {
            // No data drift — just re-evaluate reconciliation status.
            $reconciliation->reconcile($timesheet);
            $stats['reconciled']++;
            return;
        }

        // Apply shift data to the draft timesheet.
        $startsAt = $shift->actual_starts_at ?? $shift->starts_at;
        $endsAt = $shift->actual_ends_at ?? $shift->ends_at;

        $updates = [];

        if (isset($changes['starts_at'])) {
            $updates['starts_at'] = $startsAt;
        }
        if (isset($changes['ends_at'])) {
            $updates['ends_at'] = $endsAt;
        }
        if (isset($changes['break_minutes'])) {
            $updates['break_minutes'] = (int) ($shift->expected_break_minutes ?? 0);
        }
        if (isset($changes['sleepover'])) {
            $updates['sleepover'] = (bool) $shift->is_sleepover;
        }
        if (isset($changes['on_call'])) {
            $updates['on_call'] = (bool) $shift->is_on_call;
        }
        if (isset($changes['work_date']) && $startsAt) {
            $updates['work_date'] = $startsAt->toDateString();
        }

        if (! empty($updates)) {
            $timesheet->forceFill($updates)->saveQuietly();
            $stats['synced']++;
        }

        // Re-evaluate reconciliation after sync.
        $reconciliation->reconcile($timesheet);
        $stats['reconciled']++;
    }

    /**
     * Submitted timesheets: re-evaluate reconciliation status only.
     * Do NOT overwrite user-entered data — it's been submitted for review.
     */
    protected function reconcileSubmittedTimesheet(
        Timesheet $timesheet,
        TimesheetReconciliationService $reconciliation,
        array &$stats,
    ): void {
        $reconciliation->reconcile($timesheet);
        $stats['reconciled']++;
    }

    /**
     * Detect meaningful differences between timesheet and its source shift.
     *
     * Returns an associative array of field => description for each difference found.
     * Empty array means no changes detected.
     *
     * @return array<string, string>
     */
    protected function detectChanges(Timesheet $timesheet, \App\Models\Shift $shift): array
    {
        $changes = [];

        $shiftStart = $shift->actual_starts_at ?? $shift->starts_at;
        $shiftEnd = $shift->actual_ends_at ?? $shift->ends_at;

        // Time comparison (1-minute tolerance).
        if ($shiftStart && $timesheet->starts_at) {
            if (abs($shiftStart->diffInMinutes($timesheet->starts_at)) >= 1) {
                $changes['starts_at'] = 'start time differs';
            }
        }

        if ($shiftEnd && $timesheet->ends_at) {
            if (abs($shiftEnd->diffInMinutes($timesheet->ends_at)) >= 1) {
                $changes['ends_at'] = 'end time differs';
            }
        }

        // Work date (derived from start).
        if ($shiftStart && $timesheet->work_date) {
            if ($shiftStart->toDateString() !== $timesheet->work_date->toDateString()) {
                $changes['work_date'] = 'work date differs';
            }
        }

        // Break minutes.
        $shiftBreak = (int) ($shift->expected_break_minutes ?? 0);
        $tsBreak = (int) ($timesheet->break_minutes ?? 0);
        if ($shiftBreak !== $tsBreak) {
            $changes['break_minutes'] = 'break minutes differ';
        }

        // Classification flags.
        if ((bool) $shift->is_sleepover !== (bool) $timesheet->sleepover) {
            $changes['sleepover'] = 'sleepover flag differs';
        }

        if ((bool) $shift->is_on_call !== (bool) $timesheet->on_call) {
            $changes['on_call'] = 'on-call flag differs';
        }

        return $changes;
    }
}
