<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PayrollExportService
{
    /**
     * Create a new payroll run for a pay period.
     *
     * Aggregates timesheet data for all active employees in the tenant
     * within the given date range and creates HrPayrollRunItem records.
     *
     * @param  int     $tenantId
     * @param  Carbon  $periodStart
     * @param  Carbon  $periodEnd
     * @param  int     $createdBy  User ID
     * @return HrPayrollRun
     *
     * @throws \InvalidArgumentException If dates are invalid or an overlapping unlocked run exists
     */
    public function createRun(?int $tenantId, Carbon $periodStart, Carbon $periodEnd, int $createdBy): HrPayrollRun
    {
        // TODO: Validate periodStart < periodEnd
        // TODO: Check for overlapping payroll runs that are not yet exported
        // TODO: Query all approved timesheets in the date range for the tenant
        // TODO: Group timesheets by user_id and aggregate hours by type
        //       (regular, overtime, sleepover, on_call, public_holiday, mileage)
        // TODO: Look up each employee's hourly_rate from HrEmployeeProfile
        // TODO: Calculate gross_pay = regular_hours * hourly_rate + overtime * 1.5 + allowances
        // TODO: Create HrPayrollRun record with status 'draft'
        // TODO: Create HrPayrollRunItem records for each employee
        // TODO: Update run totals (total_hours, total_staff)
        // TODO: Log audit trail entry

        return DB::transaction(function () use ($tenantId, $periodStart, $periodEnd, $createdBy) {
            $existing = HrPayrollRun::where('tenant_id', $tenantId)
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->whereNull('locked_at')
                ->first();

            if ($existing) {
                throw new \InvalidArgumentException(
                    "An unlocked payroll run already exists for this period (ID: {$existing->id})."
                );
            }

            $run = HrPayrollRun::create([
                'tenant_id' => $tenantId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => 'draft',
                'created_by' => $createdBy,
            ]);

            $items = $this->getRunItems($tenantId, $periodStart, $periodEnd);
            $totalHours = 0;
            $totalStaff = 0;

            foreach ($items as $userId => $aggregated) {
                HrPayrollRunItem::create([
                    'payroll_run_id' => $run->id,
                    'user_id' => $userId,
                    'timesheet_ids' => $aggregated['timesheet_ids'],
                    'regular_hours' => $aggregated['regular_hours'],
                    'overtime_hours' => $aggregated['overtime_hours'],
                    'sleepover_count' => $aggregated['sleepover_count'],
                    'on_call_hours' => $aggregated['on_call_hours'],
                    'mileage_km' => $aggregated['mileage_km'],
                    'public_holiday_hours' => $aggregated['public_holiday_hours'],
                    'gross_pay' => $aggregated['gross_pay'],
                    'allowances' => $aggregated['allowances'],
                ]);

                $totalHours += $aggregated['regular_hours'] + $aggregated['overtime_hours'];
                $totalStaff++;
            }

            $run->update([
                'total_hours' => $totalHours,
                'total_staff' => $totalStaff,
            ]);

            return $run->load('items');
        });
    }

    /**
     * Lock a payroll run to prevent further edits.
     *
     * Once locked, no items can be added, removed, or modified.
     * Locking is a prerequisite for export.
     *
     * @param  HrPayrollRun  $run
     * @param  int           $lockedBy  User ID
     * @return HrPayrollRun
     *
     * @throws \LogicException If run is already locked or has no items
     */
    public function lockRun(HrPayrollRun $run, int $lockedBy): HrPayrollRun
    {
        // TODO: Verify run is not already locked
        // TODO: Verify run has at least one item
        // TODO: Verify all items have been reviewed (optional review step)
        // TODO: Set locked_at, locked_by, and status = 'locked'
        // TODO: Prevent any further modifications to run items
        // TODO: Fire PayrollRunLocked event
        // TODO: Log audit trail entry

        if ($run->locked_at !== null) {
            throw new \LogicException('This payroll run is already locked.');
        }

        if ($run->items()->count() === 0) {
            throw new \LogicException('Cannot lock a payroll run with no items.');
        }

        $run->update([
            'status' => 'locked',
            'locked_at' => now(),
            'locked_by' => $lockedBy,
        ]);

        return $run->fresh();
    }

    /**
     * Generate a CSV export file for a locked payroll run.
     *
     * The CSV includes one row per employee with columns for all pay
     * components. The file is stored on the configured disk and the
     * path is recorded on the run.
     *
     * @param  HrPayrollRun  $run
     * @param  int           $exportedBy  User ID
     * @return string  Storage path of the generated CSV
     *
     * @throws \LogicException If run is not locked
     */
    public function generateExport(HrPayrollRun $run, int $exportedBy): string
    {
        // TODO: Verify run is locked (locked_at is not null)
        // TODO: Load all run items with user relationships
        // TODO: Build CSV header row:
        //       employee_number, name, position_title, regular_hours, overtime_hours,
        //       sleepover_count, on_call_hours, public_holiday_hours, mileage_km,
        //       gross_pay, allowances_total
        // TODO: Build CSV data rows from HrPayrollRunItem records
        // TODO: Store CSV file to 'private' disk under payroll-exports/
        // TODO: Update run with exported_at, exported_by, export_format, export_path
        // TODO: Fire PayrollExported event
        // TODO: Log audit trail entry
        // TODO: Return the storage path

        if ($run->locked_at === null) {
            throw new \LogicException('Cannot export an unlocked payroll run.');
        }

        $items = $run->items()->with(['user.staffProfile'])->get();

        $headers = [
            'employee_number',
            'name',
            'position_title',
            'regular_hours',
            'overtime_hours',
            'sleepover_count',
            'on_call_hours',
            'public_holiday_hours',
            'mileage_km',
            'gross_pay',
            'allowances_total',
        ];

        $rows = [];
        foreach ($items as $item) {
            $profile = $item->user?->staffProfile;
            $allowancesTotal = collect($item->allowances ?? [])->sum('amount');

            $rows[] = [
                $profile?->employee_number ?? '',
                $item->user?->name ?? '',
                $profile?->position_title ?? '',
                $item->regular_hours,
                $item->overtime_hours,
                $item->sleepover_count,
                $item->on_call_hours,
                $item->public_holiday_hours,
                $item->mileage_km,
                $item->gross_pay,
                number_format($allowancesTotal, 2, '.', ''),
            ];
        }

        $csv = implode(',', $headers) . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $row)) . "\n";
        }

        $filename = sprintf(
            'payroll-exports/%s_%s_%s.csv',
            $run->tenant_id,
            $run->period_start->format('Y-m-d'),
            $run->period_end->format('Y-m-d')
        );

        Storage::disk('private')->put($filename, $csv);

        $run->update([
            'status' => 'exported',
            'exported_at' => now(),
            'exported_by' => $exportedBy,
            'export_format' => 'csv',
            'export_path' => $filename,
        ]);

        return $filename;
    }

    /**
     * Aggregate timesheet data for a pay period, grouped by employee.
     *
     * Queries approved timesheets within the date range and aggregates
     * hours by type for each user. Calculates gross pay using the
     * employee's hourly rate.
     *
     * @param  int     $tenantId
     * @param  Carbon  $periodStart
     * @param  Carbon  $periodEnd
     * @return array<int, array>  Keyed by user_id with aggregated pay components
     */
    public function getRunItems(?int $tenantId, Carbon $periodStart, Carbon $periodEnd): array
    {
        // TODO: Query Timesheet records for the tenant within the period
        // TODO: Filter to approved timesheets only
        // TODO: Group by user_id
        // TODO: For each user, sum: regular_hours, overtime_hours, sleepover_count,
        //       on_call_hours, mileage_km, public_holiday_hours
        // TODO: Collect timesheet IDs for audit trail
        // TODO: Look up employee hourly_rate from HrEmployeeProfile
        // TODO: Calculate gross_pay:
        //       regular_hours * hourly_rate
        //       + overtime_hours * hourly_rate * 1.5
        //       + sleepover_count * flat_sleepover_rate
        //       + on_call_hours * on_call_rate
        //       + public_holiday_hours * hourly_rate * 1.5
        // TODO: Return aggregated array keyed by user_id

        $timesheets = Timesheet::where('tenant_id', $tenantId)
            ->where('status', 'approved')
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->get();

        $grouped = $timesheets->groupBy('user_id');
        $results = [];

        foreach ($grouped as $userId => $userTimesheets) {
            $profile = HrEmployeeProfile::where('user_id', $userId)->first();
            $hourlyRate = $profile ? (float) $profile->hourly_rate : 0;

            $regular = $userTimesheets->sum('regular_hours');
            $overtime = $userTimesheets->sum('overtime_hours');
            $sleepover = $userTimesheets->sum('sleepover_count');
            $onCall = $userTimesheets->sum('on_call_hours');
            $mileage = $userTimesheets->sum('mileage_km');
            $publicHoliday = $userTimesheets->sum('public_holiday_hours');

            $grossPay = ($regular * $hourlyRate)
                + ($overtime * $hourlyRate * 1.5)
                + ($publicHoliday * $hourlyRate * 1.5);

            $results[$userId] = [
                'timesheet_ids' => $userTimesheets->pluck('id')->toArray(),
                'regular_hours' => $regular,
                'overtime_hours' => $overtime,
                'sleepover_count' => $sleepover,
                'on_call_hours' => $onCall,
                'mileage_km' => $mileage,
                'public_holiday_hours' => $publicHoliday,
                'gross_pay' => round($grossPay, 2),
                'allowances' => [],
            ];
        }

        return $results;
    }
}
