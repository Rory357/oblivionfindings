<?php

namespace App\Console\Commands;

use App\Models\Shift;
use App\Services\ShiftSignalService;
use App\Services\Operations\TimesheetReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DetectShiftOrphans extends Command
{
    protected $signature = 'shifts:detect-orphans
        {--lookback-days=7 : Only scan records from the last N days}
        {--dry-run : Report findings without emitting signals}';

    protected $description = 'Detect orphaned shift/timesheet/attendance records and emit control room signals';

    public const TYPE_MISSING_TIMESHEET = 'orphan_completed_shift_no_timesheet';
    public const TYPE_ORPHAN_ATTENDANCE = 'orphan_attendance_no_timesheet';
    public const TYPE_ORPHAN_TIMESHEET = 'orphan_timesheet_no_shift';

    public function handle(
        TimesheetReconciliationService $reconciliation,
        ShiftSignalService $signals,
    ): int {
        $lookbackDays = (int) $this->option('lookback-days');
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($lookbackDays)->startOfDay();

        $this->info("Scanning for orphaned records since {$cutoff->toDateString()}...");

        $summary = [
            'completed_shifts_without_timesheets' => 0,
            'attendance_without_timesheets' => 0,
            'timesheets_without_valid_shift' => 0,
            'signals_emitted' => 0,
            'signals_deduplicated' => 0,
        ];

        try {
            $this->detectCompletedShiftsWithoutTimesheets($reconciliation, $signals, $cutoff, $dryRun, $summary);
            $this->detectAttendanceWithoutTimesheets($reconciliation, $signals, $cutoff, $dryRun, $summary);
            $this->detectTimesheetsWithoutValidShift($signals, $cutoff, $dryRun, $summary);
        } catch (\Throwable $e) {
            Log::error('shifts:detect-orphans failed', [
                'exception' => $e->getMessage(),
                'summary_at_failure' => $summary,
            ]);

            $this->error("Detection failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        Log::info('shifts:detect-orphans completed', $summary);

        $this->table(
            ['Orphan Type', 'Count'],
            [
                ['Completed shifts without timesheets', $summary['completed_shifts_without_timesheets']],
                ['Attendance without timesheets', $summary['attendance_without_timesheets']],
                ['Timesheets without valid shift', $summary['timesheets_without_valid_shift']],
            ],
        );

        $this->info(sprintf(
            'Signals emitted: %d | Deduplicated (already tracked): %d',
            $summary['signals_emitted'],
            $summary['signals_deduplicated'],
        ));

        return self::SUCCESS;
    }

    protected function detectCompletedShiftsWithoutTimesheets(
        TimesheetReconciliationService $reconciliation,
        ShiftSignalService $signals,
        \DateTimeInterface $cutoff,
        bool $dryRun,
        array &$summary,
    ): void {
        $orphans = $reconciliation->completedShiftsWithoutTimesheets()
            ->filter(fn (Shift $shift) => $shift->updated_at && $shift->updated_at->gte($cutoff));

        $summary['completed_shifts_without_timesheets'] = $orphans->count();

        if ($dryRun || $orphans->isEmpty()) {
            return;
        }

        foreach ($orphans as $shift) {
            $shift->loadMissing('client:id,site_id');
            $signal = $signals->emit([
                'shift_id' => $shift->id,
                'site_id' => $shift->site_id ?: $shift->client?->site_id,
                'client_id' => $shift->client_id,
                'user_id' => $shift->user_id,
                'signal_type' => self::TYPE_MISSING_TIMESHEET,
                'severity_hint' => 'high',
                'occurred_at' => now(),
                'idempotency_key' => $this->buildIdempotencyKey(self::TYPE_MISSING_TIMESHEET, 'shift', $shift->id),
                'payload' => [
                    'orphan_type' => 'completed_shift_no_timesheet',
                    'shift_id' => $shift->id,
                    'staff_user_id' => $shift->user_id,
                    'completed_at' => $shift->actual_ends_at?->toISOString(),
                ],
            ]);

            $signal->wasRecentlyCreated
                ? $summary['signals_emitted']++
                : $summary['signals_deduplicated']++;
        }
    }

    protected function detectAttendanceWithoutTimesheets(
        TimesheetReconciliationService $reconciliation,
        ShiftSignalService $signals,
        \DateTimeInterface $cutoff,
        bool $dryRun,
        array &$summary,
    ): void {
        $orphans = $reconciliation->attendanceWithoutTimesheets()
            ->filter(fn ($session) => $session->clock_out_at && $session->clock_out_at->gte($cutoff));

        $summary['attendance_without_timesheets'] = $orphans->count();

        if ($dryRun || $orphans->isEmpty()) {
            return;
        }

        foreach ($orphans as $session) {
            $signal = $signals->emit([
                'shift_id' => $session->shift_id,
                'site_id' => $session->site_id,
                'user_id' => $session->user_id,
                'signal_type' => self::TYPE_ORPHAN_ATTENDANCE,
                'severity_hint' => 'medium',
                'occurred_at' => now(),
                'idempotency_key' => $this->buildIdempotencyKey(self::TYPE_ORPHAN_ATTENDANCE, 'attendance', $session->id),
                'payload' => [
                    'orphan_type' => 'attendance_no_timesheet',
                    'attendance_session_id' => $session->id,
                    'shift_id' => $session->shift_id,
                    'staff_user_id' => $session->user_id,
                    'clock_in_at' => $session->clock_in_at?->toISOString(),
                    'clock_out_at' => $session->clock_out_at?->toISOString(),
                ],
            ]);

            $signal->wasRecentlyCreated
                ? $summary['signals_emitted']++
                : $summary['signals_deduplicated']++;
        }
    }

    protected function detectTimesheetsWithoutValidShift(
        ShiftSignalService $signals,
        \DateTimeInterface $cutoff,
        bool $dryRun,
        array &$summary,
    ): void {
        $orphans = \App\Models\Timesheet::query()
            ->with(['shift:id', 'attendanceSession:id'])
            ->where('created_at', '>=', $cutoff)
            ->get()
            ->filter(function (\App\Models\Timesheet $timesheet): bool {
                if ($timesheet->shift_id && ! $timesheet->shift) {
                    return true;
                }

                return ! $timesheet->shift_id && ! $timesheet->attendance_session_id;
            })
            ->values();

        $summary['timesheets_without_valid_shift'] = $orphans->count();

        if ($dryRun || $orphans->isEmpty()) {
            return;
        }

        foreach ($orphans as $timesheet) {
            $signal = $signals->emit([
                'site_id' => $timesheet->shift_site_id,
                'user_id' => $timesheet->user_id,
                'signal_type' => self::TYPE_ORPHAN_TIMESHEET,
                'severity_hint' => 'medium',
                'occurred_at' => now(),
                'idempotency_key' => $this->buildIdempotencyKey(self::TYPE_ORPHAN_TIMESHEET, 'timesheet', $timesheet->id),
                'payload' => [
                    'orphan_type' => 'timesheet_no_valid_shift',
                    'timesheet_id' => $timesheet->id,
                    'staff_user_id' => $timesheet->user_id,
                    'work_date' => $timesheet->work_date?->toDateString(),
                    'missing_shift_id' => $timesheet->shift_id,
                ],
            ]);

            $signal->wasRecentlyCreated
                ? $summary['signals_emitted']++
                : $summary['signals_deduplicated']++;
        }
    }

    protected function buildIdempotencyKey(string $signalType, string $recordType, int $recordId): string
    {
        return hash('sha256', implode('|', [
            'orphan_detection',
            $signalType,
            $recordType,
            $recordId,
        ]));
    }
}
