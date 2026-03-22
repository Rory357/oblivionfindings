<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TimeTrackingService
{
    /**
     * Clock in a user, creating a new active time entry.
     */
    public function clockIn(User $user, ?string $notes = null, ?string $projectCode = null): HrTimeEntry
    {
        // Ensure no existing active clock-in
        $existing = HrTimeEntry::forTenant($user->tenant_id)
            ->forUser($user->id)
            ->active()
            ->first();

        if ($existing) {
            throw new \LogicException('You are already clocked in. Please clock out first.');
        }

        return HrTimeEntry::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'entry_date' => now()->toDateString(),
            'clock_in' => now(),
            'entry_type' => 'clock',
            'status' => 'active',
            'notes' => $notes,
            'project_code' => $projectCode,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Clock out a user, calculating total hours.
     */
    public function clockOut(User $user, int $breakMinutes = 0, ?string $notes = null): HrTimeEntry
    {
        $entry = HrTimeEntry::forTenant($user->tenant_id)
            ->forUser($user->id)
            ->active()
            ->first();

        if (! $entry) {
            throw new \LogicException('No active clock-in found.');
        }

        $clockOut = now();
        $totalMinutes = $entry->clock_in->diffInMinutes($clockOut) - $breakMinutes;
        $totalHours = max(0, round($totalMinutes / 60, 2));

        $entry->update([
            'clock_out' => $clockOut,
            'break_minutes' => $breakMinutes,
            'total_hours' => $totalHours,
            'notes' => $notes ?? $entry->notes,
            'status' => 'submitted',
        ]);

        return $entry->fresh();
    }

    /**
     * Create a manual time entry.
     */
    public function createManualEntry(User $user, array $data): HrTimeEntry
    {
        $clockIn = Carbon::parse($data['clock_in']);
        $clockOut = Carbon::parse($data['clock_out']);
        $breakMinutes = (int) ($data['break_minutes'] ?? 0);
        $totalMinutes = $clockIn->diffInMinutes($clockOut) - $breakMinutes;
        $totalHours = max(0, round($totalMinutes / 60, 2));

        return HrTimeEntry::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $data['user_id'] ?? $user->id,
            'entry_date' => $clockIn->toDateString(),
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
            'break_minutes' => $breakMinutes,
            'total_hours' => $totalHours,
            'entry_type' => 'manual',
            'status' => 'submitted',
            'notes' => $data['notes'] ?? null,
            'project_code' => $data['project_code'] ?? null,
            'cost_centre' => $data['cost_centre'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Submit a timesheet for approval.
     */
    public function submitTimesheet(HrTimesheet $timesheet, User $user): HrTimesheet
    {
        if ($timesheet->status !== 'draft') {
            throw new \LogicException("Cannot submit a '{$timesheet->status}' timesheet.");
        }

        return DB::transaction(function () use ($timesheet, $user) {
            // Calculate total hours from entries in the period
            $totalHours = HrTimeEntry::forTenant($timesheet->tenant_id)
                ->forUser($timesheet->user_id)
                ->forDateRange(
                    $timesheet->period_start->toDateString(),
                    $timesheet->period_end->toDateString()
                )
                ->whereNotNull('clock_out')
                ->sum('total_hours');

            $timesheet->update([
                'status' => 'submitted',
                'total_hours' => $totalHours,
                'submitted_at' => now(),
            ]);

            // Mark related entries as submitted
            HrTimeEntry::forTenant($timesheet->tenant_id)
                ->forUser($timesheet->user_id)
                ->forDateRange(
                    $timesheet->period_start->toDateString(),
                    $timesheet->period_end->toDateString()
                )
                ->where('status', 'active')
                ->whereNotNull('clock_out')
                ->update(['status' => 'submitted']);

            return $timesheet->fresh();
        });
    }

    /**
     * Approve a submitted timesheet.
     */
    public function approveTimesheet(HrTimesheet $timesheet, User $approver): HrTimesheet
    {
        if ($timesheet->status !== 'submitted') {
            throw new \LogicException("Cannot approve a '{$timesheet->status}' timesheet.");
        }

        return DB::transaction(function () use ($timesheet, $approver) {
            $timesheet->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            // Approve related entries
            HrTimeEntry::forTenant($timesheet->tenant_id)
                ->forUser($timesheet->user_id)
                ->forDateRange(
                    $timesheet->period_start->toDateString(),
                    $timesheet->period_end->toDateString()
                )
                ->where('status', 'submitted')
                ->update([
                    'status' => 'approved',
                    'approved_by' => $approver->id,
                    'approved_at' => now(),
                ]);

            return $timesheet->fresh();
        });
    }

    /**
     * Reject a submitted timesheet.
     */
    public function rejectTimesheet(HrTimesheet $timesheet, User $reviewer, string $reason): HrTimesheet
    {
        if ($timesheet->status !== 'submitted') {
            throw new \LogicException("Cannot reject a '{$timesheet->status}' timesheet.");
        }

        return DB::transaction(function () use ($timesheet, $reviewer, $reason) {
            $timesheet->update([
                'status' => 'rejected',
                'approved_by' => $reviewer->id,
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ]);

            // Reset related entries to active
            HrTimeEntry::forTenant($timesheet->tenant_id)
                ->forUser($timesheet->user_id)
                ->forDateRange(
                    $timesheet->period_start->toDateString(),
                    $timesheet->period_end->toDateString()
                )
                ->where('status', 'submitted')
                ->update(['status' => 'rejected']);

            return $timesheet->fresh();
        });
    }

    /**
     * Get a weekly summary of hours for a user.
     */
    public function getWeeklySummary(int $tenantId, int $userId, ?string $weekStart = null): array
    {
        $start = $weekStart ? Carbon::parse($weekStart)->startOfWeek() : now()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        $entries = HrTimeEntry::forTenant($tenantId)
            ->forUser($userId)
            ->forDateRange($start->toDateString(), $end->toDateString())
            ->whereNotNull('clock_out')
            ->get();

        $dailyHours = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dateStr = $day->toDateString();
            $dailyHours[$dateStr] = $entries
                ->where('entry_date', $dateStr)
                ->sum('total_hours');
        }

        return [
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'daily_hours' => $dailyHours,
            'total_hours' => round($entries->sum('total_hours'), 2),
            'total_entries' => $entries->count(),
        ];
    }
}
