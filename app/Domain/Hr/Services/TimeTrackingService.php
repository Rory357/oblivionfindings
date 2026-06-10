<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimeEntryAmendment;
use App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TimeTrackingService
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly DraftTimesheetService $draftTimesheets,
    ) {}

    /**
     * Clock in a user via the attendance system, creating both an
     * HrAttendanceSession and a corresponding HrTimeEntry.
     */
    public function clockIn(User $user, ?string $notes = null, ?string $projectCode = null, ?int $shiftId = null): HrTimeEntry
    {
        $tenantId = $this->resolveTenantId($user);

        // Check HrTimeEntry level for active clock
        $existing = HrTimeEntry::forTenant($tenantId)
            ->forUser($user->id)
            ->active()
            ->first();

        if ($existing) {
            throw new \LogicException('You are already clocked in. Please clock out first.');
        }

        $session = $this->attendanceService->clockIn($user, [
            'tenant_id' => $tenantId,
            'shift_id' => $shiftId,
            'notes' => $notes,
            'source' => 'hr_module',
        ]);

        return HrTimeEntry::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'shift_id' => $session->shift_id,
            'attendance_session_id' => $session->id,
            'site_id' => $session->site_id,
            'client_id' => $session->shift?->client_id,
            'entry_date' => $session->clock_in_at->toDateString(),
            'clock_in' => $session->clock_in_at,
            'entry_type' => 'clock',
            'status' => 'active',
            'source_type' => 'attendance',
            'source_id' => $session->id,
            'pay_type' => $session->shift?->is_sleepover ? 'sleepover' : ($session->shift?->is_on_call ? 'on_call' : 'standard'),
            'is_sleepover' => (bool) $session->shift?->is_sleepover,
            'is_on_call' => (bool) $session->shift?->is_on_call,
            'notes' => $notes,
            'project_code' => $projectCode,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Clock out a user via the attendance system, updating the
     * HrTimeEntry with calculated hours and break compliance.
     */
    public function clockOut(User $user, int $breakMinutes = 0, ?string $notes = null, ?float $mileageKm = null): HrTimeEntry
    {
        $tenantId = $this->resolveTenantId($user);

        $entry = HrTimeEntry::forTenant($tenantId)
            ->forUser($user->id)
            ->active()
            ->first();

        if (! $entry) {
            throw new \LogicException('No active clock-in found.');
        }

        // Clock out via attendance service (creates Operations Timesheet too)
        $session = $this->attendanceService->clockOut($user, null, [
            'break_minutes' => $breakMinutes,
            'notes' => $notes,
        ]);

        $clockOut = $session->clock_out_at ?? now();
        $totalMinutes = $entry->clock_in->diffInMinutes($clockOut) - $breakMinutes;
        $totalHours = max(0, round($totalMinutes / 60, 2));

        // NZ break compliance check
        $workedHours = $totalMinutes / 60;
        $requiredBreak = $workedHours >= 4 ? 30 : ($workedHours >= 2 ? 10 : 0);
        $breakCompliant = $breakMinutes >= $requiredBreak;

        $entry->update([
            'clock_out' => $clockOut,
            'break_minutes' => $breakMinutes,
            'total_hours' => $totalHours,
            'mileage_km' => $mileageKm,
            'break_compliance_met' => $breakCompliant,
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
        $tenantId = $this->resolveTenantId($user);
        $clockInLocal = $this->parseWorkerLocalDateTime($data['clock_in']);
        $clockOutLocal = $this->parseWorkerLocalDateTime($data['clock_out']);
        $clockIn = $clockInLocal->copy()->utc();
        $clockOut = $clockOutLocal->copy()->utc();
        $breakMinutes = (int) ($data['break_minutes'] ?? 0);
        $totalMinutes = $clockIn->diffInMinutes($clockOut) - $breakMinutes;
        $totalHours = max(0, round($totalMinutes / 60, 2));

        return DB::transaction(function () use ($tenantId, $data, $user, $clockInLocal, $clockIn, $clockOut, $breakMinutes, $totalHours) {
            $entry = HrTimeEntry::create([
                'tenant_id' => $tenantId,
                'user_id' => $data['user_id'] ?? $user->id,
                'shift_id' => $data['shift_id'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'entry_date' => $clockInLocal->toDateString(),
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
                'break_minutes' => $breakMinutes,
                'total_hours' => $totalHours,
                'entry_type' => 'manual',
                'status' => 'submitted',
                'notes' => $data['notes'] ?? null,
                'project_code' => $data['project_code'] ?? null,
                'cost_centre' => $data['cost_centre'] ?? null,
                'pay_type' => $data['pay_type'] ?? 'standard',
                'is_sleepover' => (bool) ($data['is_sleepover'] ?? false),
                'is_on_call' => (bool) ($data['is_on_call'] ?? false),
                'is_public_holiday' => (bool) ($data['is_public_holiday'] ?? false),
                'mileage_km' => $data['mileage_km'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->draftTimesheets->fromManualEntry($entry->fresh(), $user->id);

            return $entry->fresh();
        });
    }

    /**
     * Get a weekly summary of hours for a user.
     */
    public function getWeeklySummary(?int $tenantId, int $userId, ?string $weekStart = null): array
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

    /* ------------------------------------------------------------------ */
    /*  Team helpers */
    /* ------------------------------------------------------------------ */

    public function getTeamUserIds(User $manager): array
    {
        return HrEmployeeProfile::where('manager_user_id', $manager->id)
            ->where('is_active', true)
            ->pluck('user_id')
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Edit / Amend time entry */
    /* ------------------------------------------------------------------ */

    public function editTimeEntry(HrTimeEntry $entry, User $editor, array $data, string $reason): HrTimeEntry
    {
        if (in_array($entry->status, ['approved'], true)) {
            throw new \LogicException('Cannot edit an approved time entry.');
        }

        return DB::transaction(function () use ($entry, $editor, $data, $reason) {
            $editableFields = ['clock_in', 'clock_out', 'break_minutes', 'pay_type', 'notes', 'is_sleepover', 'is_on_call', 'mileage_km'];
            $originalValues = [];
            $tenantId = $entry->tenant_id;

            foreach ($editableFields as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $oldValue = $entry->getOriginal($field);
                $newValue = $data[$field];

                if ((string) $oldValue === (string) $newValue) {
                    continue;
                }

                $originalValues[$field] = $oldValue;

                HrTimeEntryAmendment::create([
                    'tenant_id' => $tenantId,
                    'hr_time_entry_id' => $entry->id,
                    'amended_by' => $editor->id,
                    'field_name' => $field,
                    'old_value' => $oldValue !== null ? (string) $oldValue : null,
                    'new_value' => $newValue !== null ? (string) $newValue : null,
                    'reason' => $reason,
                ]);
            }

            if (empty($originalValues)) {
                return $entry;
            }

            // Parse time fields
            if (isset($data['clock_in'])) {
                $clockInLocal = $this->parseWorkerLocalDateTime($data['clock_in']);
                $data['clock_in'] = $clockInLocal->copy()->utc();
                $data['entry_date'] = $clockInLocal->toDateString();
            }
            if (isset($data['clock_out'])) {
                $data['clock_out'] = $this->parseWorkerLocalDateTime($data['clock_out'])->utc();
            }

            // Recalculate hours if times changed
            $clockIn = $data['clock_in'] ?? $entry->clock_in;
            $clockOut = $data['clock_out'] ?? $entry->clock_out;
            $breakMinutes = $data['break_minutes'] ?? $entry->break_minutes;

            if ($clockIn && $clockOut) {
                $totalMinutes = Carbon::parse($clockIn)->diffInMinutes(Carbon::parse($clockOut)) - (int) $breakMinutes;
                $data['total_hours'] = max(0, round($totalMinutes / 60, 2));

                // NZ break compliance check
                $workedHours = $totalMinutes / 60;
                $requiredBreak = $workedHours >= 4 ? 30 : ($workedHours >= 2 ? 10 : 0);
                $data['break_compliance_met'] = (int) $breakMinutes >= $requiredBreak;
            }

            $data['amended_by'] = $editor->id;
            $data['amended_at'] = now();
            $data['amendment_reason'] = $reason;
            $data['original_values'] = array_merge($entry->original_values ?? [], $originalValues);

            $entry->update($data);

            $freshEntry = $entry->fresh();
            if ($freshEntry->clock_out && ! $freshEntry->attendance_session_id) {
                $this->draftTimesheets->fromManualEntry($freshEntry, $editor->id);
            }

            return $freshEntry;
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Clock on behalf */
    /* ------------------------------------------------------------------ */

    public function clockOnBehalf(User $manager, int $targetUserId, array $data): HrTimeEntry
    {
        $tenantId = $this->resolveTenantId($manager);
        $teamUserIds = $this->getTeamUserIds($manager);
        $isAdmin = $manager->canDo('timesheets.manageAny');

        if (! $isAdmin && ! in_array($targetUserId, $teamUserIds, true)) {
            throw new \LogicException('You can only clock on behalf of your direct reports.');
        }

        $clockInLocal = $this->parseWorkerLocalDateTime($data['clock_in']);
        $clockOutLocal = isset($data['clock_out']) ? $this->parseWorkerLocalDateTime($data['clock_out']) : null;
        $clockIn = $clockInLocal->copy()->utc();
        $clockOut = $clockOutLocal?->copy()->utc();
        $breakMinutes = (int) ($data['break_minutes'] ?? 0);

        $totalHours = null;
        $breakCompliant = null;
        if ($clockOut) {
            $totalMinutes = $clockIn->diffInMinutes($clockOut) - $breakMinutes;
            $totalHours = max(0, round($totalMinutes / 60, 2));
            $workedHours = $totalMinutes / 60;
            $requiredBreak = $workedHours >= 4 ? 30 : ($workedHours >= 2 ? 10 : 0);
            $breakCompliant = $breakMinutes >= $requiredBreak;
        }

        return DB::transaction(function () use ($tenantId, $targetUserId, $data, $clockInLocal, $clockIn, $clockOut, $breakMinutes, $totalHours, $breakCompliant, $manager) {
            $entry = HrTimeEntry::create([
                'tenant_id' => $tenantId,
                'user_id' => $targetUserId,
                'shift_id' => $data['shift_id'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'entry_date' => $clockInLocal->toDateString(),
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
                'break_minutes' => $breakMinutes,
                'total_hours' => $totalHours,
                'entry_type' => 'admin_clock',
                'status' => $clockOut ? 'submitted' : 'active',
                'pay_type' => $data['pay_type'] ?? 'standard',
                'is_sleepover' => (bool) ($data['is_sleepover'] ?? false),
                'is_on_call' => (bool) ($data['is_on_call'] ?? false),
                'is_public_holiday' => (bool) ($data['is_public_holiday'] ?? false),
                'mileage_km' => $data['mileage_km'] ?? null,
                'break_compliance_met' => $breakCompliant,
                'notes' => $data['notes'] ?? null,
                'created_by' => $manager->id,
            ]);

            if ($clockOut) {
                $this->draftTimesheets->fromManualEntry($entry->fresh(), $manager->id);
            }

            return $entry->fresh();
        });
    }

    private function parseWorkerLocalDateTime(mixed $value): Carbon
    {
        $timezone = (string) config('app.worker_timezone', config('app.timezone', 'UTC'));

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone($timezone);
        }

        return Carbon::parse((string) $value, $timezone);
    }

    private function resolveTenantId(User $user): int
    {
        $candidateTenantId = $user->getAttribute('tenant_id');
        if (is_numeric($candidateTenantId)) {
            return (int) $candidateTenantId;
        }

        $organizationTenantId = $user->getAttribute('organization_id');
        if (is_numeric($organizationTenantId)) {
            return (int) $organizationTenantId;
        }

        $profileTenantId = HrEmployeeProfile::query()
            ->where('user_id', $user->id)
            ->value('tenant_id');

        if (is_numeric($profileTenantId)) {
            return (int) $profileTenantId;
        }

        // No silent cross-tenant fallback: writing time entries against an
        // arbitrary tenant_id would corrupt audit and payroll data. Surface
        // the malformed user instead so the caller can fix configuration.
        throw new \LogicException(sprintf(
            'Cannot resolve tenant_id for user %d (no tenant_id, organization_id, or HR profile).',
            $user->id,
        ));
    }
}
