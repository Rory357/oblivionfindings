<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPayRateRule;
use App\Domain\Hr\Models\HrPayrollExportProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayrollExportService
{
    use SanitizesCsvOutput;

    public function __construct(
        private readonly PayslipService $payslipService,
    ) {}

    /**
     * @return array<string, string>
     */
    public function exportFieldCatalog(): array
    {
        return [
            'employee_number' => 'Employee Number',
            'name' => 'Employee Name',
            'email' => 'Employee Email',
            'position_title' => 'Position Title',
            'period_start' => 'Period Start',
            'period_end' => 'Period End',
            'regular_hours' => 'Regular Hours',
            'overtime_hours' => 'Overtime Hours',
            'sleepover_count' => 'Sleepover Count',
            'on_call_hours' => 'On Call Hours',
            'public_holiday_hours' => 'Public Holiday Hours',
            'leave_hours' => 'Leave Hours',
            'leave_pay' => 'Leave Pay',
            'mileage_km' => 'Mileage (KM)',
            'base_hourly_rate' => 'Base Hourly Rate',
            'overtime_multiplier' => 'Overtime Multiplier',
            'public_holiday_multiplier' => 'Public Holiday Multiplier',
            'sleepover_rate' => 'Sleepover Rate',
            'on_call_rate' => 'On Call Rate',
            'gross_pay' => 'Gross Pay',
            'allowances_total' => 'Allowances Total',
            'timesheet_ids' => 'Timesheet IDs',
            'item_notes' => 'Item Notes',
        ];
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function createRun(Carbon $periodStart, Carbon $periodEnd, int $createdBy): HrPayrollRun
    {
        if ($periodStart->greaterThanOrEqualTo($periodEnd)) {
            throw new \InvalidArgumentException('Payroll period start must be before period end.');
        }

        return DB::transaction(function () use ($periodStart, $periodEnd, $createdBy) {
            $overlap = HrPayrollRun::query()
                ->whereIn('status', ['draft', 'locked'])
                ->whereDate('period_start', '<=', $periodEnd->toDateString())
                ->whereDate('period_end', '>=', $periodStart->toDateString())
                ->exists();

            if ($overlap) {
                throw new \InvalidArgumentException('An overlapping draft/locked payroll run already exists.');
            }

            $run = HrPayrollRun::create([
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'status' => 'draft',
                'created_by' => $createdBy,
                'validation_errors' => [],
            ]);

            $items = $this->getRunItems($periodStart, $periodEnd);
            $totalHours = 0.0;
            $totalStaff = 0;
            $totalGross = 0.0;

            foreach ($items as $userId => $aggregated) {
                HrPayrollRunItem::create([
                    'payroll_run_id' => $run->id,
                    'user_id' => $userId,
                    'timesheet_ids' => $aggregated['timesheet_ids'],
                    'base_hourly_rate' => $aggregated['base_hourly_rate'],
                    'overtime_multiplier' => $aggregated['overtime_multiplier'],
                    'public_holiday_multiplier' => $aggregated['public_holiday_multiplier'],
                    'sleepover_rate' => $aggregated['sleepover_rate'],
                    'on_call_rate' => $aggregated['on_call_rate'],
                    'regular_hours' => $aggregated['regular_hours'],
                    'overtime_hours' => $aggregated['overtime_hours'],
                    'sleepover_count' => $aggregated['sleepover_count'],
                    'on_call_hours' => $aggregated['on_call_hours'],
                    'mileage_km' => $aggregated['mileage_km'],
                    'public_holiday_hours' => $aggregated['public_holiday_hours'],
                    'leave_hours' => $aggregated['leave_hours'] ?? 0,
                    'leave_pay' => $aggregated['leave_pay'] ?? 0,
                    'gross_pay' => $aggregated['gross_pay'],
                    'allowances' => $aggregated['allowances'],
                    'rate_breakdown' => $aggregated['rate_breakdown'],
                    'notes' => $aggregated['notes'] ?? null,
                ]);

                $totalHours += (float) $aggregated['regular_hours'] + (float) $aggregated['overtime_hours'];
                $totalStaff++;
                $totalGross += (float) $aggregated['gross_pay'];
            }

            $run->update([
                'total_hours' => round($totalHours, 2),
                'total_staff' => $totalStaff,
                'total_gross' => round($totalGross, 2),
            ]);

            $validationErrors = $this->validateRunConsistency($run->fresh(['items.user.hrEmployeeProfile']));
            $run->update(['validation_errors' => $validationErrors]);

            return $run->fresh('items');
        });
    }

    /**
     * @throws \LogicException
     * @throws ValidationException
     */
    public function lockRun(HrPayrollRun $run, int $lockedBy): HrPayrollRun
    {
        if ($run->locked_at !== null) {
            throw new \LogicException('This payroll run is already locked.');
        }

        if ($run->status === 'exported') {
            throw new \LogicException('Exported payroll runs cannot be relocked.');
        }

        if ($run->items()->count() === 0) {
            throw new \LogicException('Cannot lock a payroll run with no items.');
        }

        $validationErrors = $this->validateRunConsistency($run->fresh(['items.user.hrEmployeeProfile']));
        if ($validationErrors !== []) {
            $run->update(['validation_errors' => $validationErrors]);

            throw ValidationException::withMessages([
                'lock' => 'Payroll run validation failed. Resolve highlighted issues before locking.',
            ]);
        }

        return DB::transaction(function () use ($run, $lockedBy) {
            $run->update([
                'status' => 'locked',
                'locked_at' => now(),
                'locked_by' => $lockedBy,
                'validation_errors' => [],
            ]);

            // Generate payslips on lock so the GL journal (posted right after by
            // the controller via PostPayrollJournalJob) has payslips to read.
            // Idempotent: a re-run or the standalone Generate endpoint may have
            // already created them.
            if ($run->payslips()->count() === 0) {
                $this->payslipService->generateBulkPayslips($run->fresh('items'));
            }

            $this->markRunTimesheetsPaid($run);

            return $run->fresh();
        });
    }

    /**
     * Cascade: flip every still-approved timesheet linked to this run to 'paid'.
     * Idempotent and safe to call repeatedly. 'paid' is terminal — the system has
     * no reverse-run path, so this is a one-way transition. Returns count newly paid.
     */
    public function markRunTimesheetsPaid(HrPayrollRun $run): int
    {
        $reference = "hr-payroll-run:{$run->id}";

        $ids = $run->items()
            ->pluck('timesheet_ids')
            ->flatMap(fn ($arr) => collect($arr ?? []))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($ids, $reference, $run) {
            $marked = 0;

            $timesheets = Timesheet::query()
                ->whereIn('id', $ids->all())
                ->lockForUpdate()
                ->get();

            foreach ($timesheets as $timesheet) {
                if ($timesheet->status === 'paid') {
                    continue; // idempotent skip
                }

                if ($timesheet->status !== 'approved') {
                    Log::info('Skipping non-approved timesheet during payroll paid cascade.', [
                        'payroll_run_id' => $run->id,
                        'timesheet_id' => $timesheet->id,
                        'status' => $timesheet->status,
                    ]);

                    continue; // timesheet_ids array can be stale — never trust it blindly
                }

                $payload = [
                    'status' => 'paid',
                    'exported_to_payroll_at' => now(),
                    'payroll_reference' => $reference,
                ];

                $alreadyLinked = filled($timesheet->getOriginal('payroll_reference'))
                    || filled($timesheet->getOriginal('exported_to_payroll_at'));

                if ($alreadyLinked) {
                    // Pre-existing legacy-stamped row: a normal update() would hit the
                    // workflow guard, so bypass it. (New data can't reach this state
                    // once Workstream 2 removes the legacy export path.)
                    $timesheet->forceFill($payload)->saveQuietly();
                } else {
                    $timesheet->update($payload); // works WITH the guard
                }

                $marked++;
            }

            return $marked;
        });
    }

    /**
     * @throws \LogicException
     */
    public function generateExport(HrPayrollRun $run, int $exportedBy, ?HrPayrollExportProfile $profile = null): string
    {
        if ($run->locked_at === null) {
            throw new \LogicException('Cannot export an unlocked payroll run.');
        }

        $items = $run->items()->with(['user.hrEmployeeProfile'])->orderBy('id')->get();
        $resolvedProfile = $profile;
        if (! $resolvedProfile) {
            $resolvedProfile = HrPayrollExportProfile::query()
                ->where('is_default', true)
                ->orderByDesc('id')
                ->first();
        }

        $rows = $this->buildCanonicalRows($run, $items->all());

        if ($resolvedProfile) {
            $mappings = $this->normalizeMappings((array) ($resolvedProfile->mappings ?? []));
            if ($mappings === []) {
                throw new \LogicException('Export profile has no mappings configured.');
            }

            $csv = $this->buildCsvFromRows(
                rows: $rows,
                mappings: $mappings,
                delimiter: (string) ($resolvedProfile->delimiter ?: ','),
                enclosure: (string) ($resolvedProfile->enclosure ?: '"'),
                lineEnding: (string) ($resolvedProfile->line_ending ?: "\n"),
                includeHeaders: (bool) $resolvedProfile->include_headers,
            );
        } else {
            $csv = $this->buildCsvFromRows(
                rows: $rows,
                mappings: $this->defaultExportMappings(),
                delimiter: ',',
                enclosure: '"',
                lineEnding: "\n",
                includeHeaders: true,
            );
        }

        $profileSuffix = $resolvedProfile ? '_'.Str::slug($resolvedProfile->name) : '';

        $filename = sprintf(
            'payroll-exports/run-%d_%s_%s%s.csv',
            $run->id,
            $run->period_start->format('Y-m-d'),
            $run->period_end->format('Y-m-d'),
            $profileSuffix
        );

        Storage::disk('private')->put($filename, $csv);

        $run->update([
            'status' => 'exported',
            'exported_at' => now(),
            'exported_by' => $exportedBy,
            'export_profile_id' => $resolvedProfile?->id,
            'export_format' => 'csv',
            'export_path' => $filename,
        ]);

        return $filename;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRunItems(Carbon $periodStart, Carbon $periodEnd): array
    {
        $timesheetQuery = Timesheet::query()
            ->with(['client:id,service_context_id', 'user:id,role'])
            ->where('status', 'approved')
            ->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()]);

        $timesheets = $timesheetQuery->get();
        $grouped = $timesheets->groupBy('user_id');
        $results = [];

        // Approved PAID leave overlapping the period, keyed by user — consumed
        // per-user inside the loop; whatever remains afterwards belongs to
        // employees with leave but no timesheets in the period.
        $leaveByUser = $this->approvedLeaveHoursByUser($periodStart, $periodEnd);

        foreach ($grouped as $userId => $userTimesheets) {
            $profile = HrEmployeeProfile::query()
                ->where('user_id', $userId)
                ->first();

            $baseRate = (float) ($profile?->hourly_rate ?: 0);
            $overtimeThreshold = (float) config('hr.payroll.overtime_daily_hours', 8);

            $regularHours = 0.0;
            $overtimeHours = 0.0;
            $onCallHours = 0.0;
            $sleepoverCount = 0;
            $publicHolidayHours = 0.0;
            $mileageKm = 0.0;
            $timesheetIds = [];

            $regularPay = 0.0;
            $overtimePay = 0.0;
            $holidayLoading = 0.0;
            $sleepoverPay = 0.0;
            $onCallPay = 0.0;

            $ruleBuckets = [];
            $ruleUsageWeights = [];

            foreach ($userTimesheets as $timesheet) {
                $hours = $this->calculateTimesheetHours($timesheet);
                $regularHours += $hours['regular_hours'];
                $overtimeHours += $hours['overtime_hours'];
                $publicHolidayHours += $hours['public_holiday_hours'];
                $onCallHours += $hours['on_call_hours'];
                $sleepoverCount += $hours['sleepover_count'];
                $mileageKm += $hours['mileage_km'];
                $timesheetIds[] = $timesheet->id;

                $effectiveRule = $this->resolvePayRateRule($profile, $timesheet);
                $rates = $this->resolveRateInputs($effectiveRule);

                $timesheetRegularPay = $hours['regular_hours'] * $baseRate * $rates['regular_multiplier'];
                $timesheetOvertimePay = $hours['overtime_hours'] * $baseRate * $rates['overtime_multiplier'];
                $timesheetHolidayLoading = $hours['public_holiday_hours'] * $baseRate * max($rates['public_holiday_multiplier'] - $rates['regular_multiplier'], 0);
                $timesheetSleepoverPay = $hours['sleepover_count'] * $rates['sleepover_rate'];
                $timesheetOnCallPay = $hours['on_call_hours'] * $rates['on_call_rate'];

                $regularPay += $timesheetRegularPay;
                $overtimePay += $timesheetOvertimePay;
                $holidayLoading += $timesheetHolidayLoading;
                $sleepoverPay += $timesheetSleepoverPay;
                $onCallPay += $timesheetOnCallPay;

                $bucketKey = $effectiveRule ? "rule:{$effectiveRule->id}" : 'default';
                if (! isset($ruleBuckets[$bucketKey])) {
                    $ruleBuckets[$bucketKey] = [
                        'rule_id' => $effectiveRule?->id,
                        'rule_name' => $effectiveRule?->name ?? 'Default rates',
                        'regular_multiplier' => $rates['regular_multiplier'],
                        'overtime_multiplier' => $rates['overtime_multiplier'],
                        'public_holiday_multiplier' => $rates['public_holiday_multiplier'],
                        'sleepover_rate' => $rates['sleepover_rate'],
                        'on_call_rate' => $rates['on_call_rate'],
                        'timesheet_ids' => [],
                        'regular_hours' => 0.0,
                        'overtime_hours' => 0.0,
                        'public_holiday_hours' => 0.0,
                        'sleepover_count' => 0,
                        'on_call_hours' => 0.0,
                        'gross_pay' => 0.0,
                    ];
                }

                $ruleBuckets[$bucketKey]['timesheet_ids'][] = $timesheet->id;
                $ruleBuckets[$bucketKey]['regular_hours'] += $hours['regular_hours'];
                $ruleBuckets[$bucketKey]['overtime_hours'] += $hours['overtime_hours'];
                $ruleBuckets[$bucketKey]['public_holiday_hours'] += $hours['public_holiday_hours'];
                $ruleBuckets[$bucketKey]['sleepover_count'] += $hours['sleepover_count'];
                $ruleBuckets[$bucketKey]['on_call_hours'] += $hours['on_call_hours'];
                $ruleBuckets[$bucketKey]['gross_pay'] += ($timesheetRegularPay + $timesheetOvertimePay + $timesheetHolidayLoading + $timesheetSleepoverPay + $timesheetOnCallPay);

                $ruleUsageWeights[$bucketKey] = ($ruleUsageWeights[$bucketKey] ?? 0)
                    + $hours['regular_hours']
                    + $hours['overtime_hours']
                    + $hours['public_holiday_hours'];
            }

            $dominantRuleKey = collect($ruleUsageWeights)->sortDesc()->keys()->first();
            $dominantRule = $dominantRuleKey ? ($ruleBuckets[$dominantRuleKey] ?? null) : null;
            $dominantRates = $this->resolveRateInputs(
                $dominantRule && isset($dominantRule['rule_id']) && $dominantRule['rule_id']
                    ? HrPayRateRule::query()->find($dominantRule['rule_id'])
                    : null
            );

            // Approved paid leave for this user in the period — additive on top
            // of worked-time pay, valued at the employee's base hourly rate.
            $leaveHours = round((float) ($leaveByUser[$userId]['hours'] ?? 0), 2);
            $leavePay = round($leaveHours * $baseRate, 2);
            unset($leaveByUser[$userId]);

            $grossPay = round($regularPay + $overtimePay + $holidayLoading + $sleepoverPay + $onCallPay + $leavePay, 2);
            $bucketLines = collect($ruleBuckets)
                ->map(function (array $bucket) {
                    return [
                        'rule_id' => $bucket['rule_id'],
                        'rule_name' => $bucket['rule_name'],
                        'timesheet_count' => count($bucket['timesheet_ids']),
                        'regular_multiplier' => round((float) $bucket['regular_multiplier'], 2),
                        'overtime_multiplier' => round((float) $bucket['overtime_multiplier'], 2),
                        'public_holiday_multiplier' => round((float) $bucket['public_holiday_multiplier'], 2),
                        'sleepover_rate' => round((float) $bucket['sleepover_rate'], 2),
                        'on_call_rate' => round((float) $bucket['on_call_rate'], 2),
                        'regular_hours' => round((float) $bucket['regular_hours'], 2),
                        'overtime_hours' => round((float) $bucket['overtime_hours'], 2),
                        'public_holiday_hours' => round((float) $bucket['public_holiday_hours'], 2),
                        'sleepover_count' => (int) $bucket['sleepover_count'],
                        'on_call_hours' => round((float) $bucket['on_call_hours'], 2),
                        'gross_pay' => round((float) $bucket['gross_pay'], 2),
                    ];
                })
                ->values()
                ->all();

            $results[$userId] = [
                'timesheet_ids' => $timesheetIds,
                'base_hourly_rate' => round($baseRate, 2),
                'regular_hours' => round($regularHours, 2),
                'overtime_hours' => round($overtimeHours, 2),
                'sleepover_count' => $sleepoverCount,
                'on_call_hours' => round($onCallHours, 2),
                'mileage_km' => round($mileageKm, 2),
                'public_holiday_hours' => round($publicHolidayHours, 2),
                'leave_hours' => $leaveHours,
                'leave_pay' => $leavePay,
                'overtime_multiplier' => round($dominantRates['overtime_multiplier'], 2),
                'public_holiday_multiplier' => round($dominantRates['public_holiday_multiplier'], 2),
                'sleepover_rate' => round($dominantRates['sleepover_rate'], 2),
                'on_call_rate' => round($dominantRates['on_call_rate'], 2),
                'gross_pay' => $grossPay,
                'allowances' => [],
                'rate_breakdown' => [
                    'rule_id' => $dominantRule['rule_id'] ?? null,
                    'rule_name' => $dominantRule['rule_name'] ?? 'Default rates',
                    'regular_pay' => round($regularPay, 2),
                    'overtime_pay' => round($overtimePay, 2),
                    'holiday_loading' => round($holidayLoading, 2),
                    'sleepover_pay' => round($sleepoverPay, 2),
                    'on_call_pay' => round($onCallPay, 2),
                    'leave_pay' => $leavePay,
                    'overtime_threshold_daily_hours' => $overtimeThreshold,
                    'line_items' => $bucketLines,
                ],
            ];
        }

        // Employees with approved paid leave but no timesheets in the period
        // still need a run item so their leave is paid.
        foreach ($leaveByUser as $userId => $leave) {
            $leaveHours = round((float) ($leave['hours'] ?? 0), 2);
            if ($leaveHours <= 0) {
                continue;
            }

            $profile = HrEmployeeProfile::query()
                ->where('user_id', $userId)
                ->first();

            $baseRate = (float) ($profile?->hourly_rate ?: 0);
            $leavePay = round($leaveHours * $baseRate, 2);
            $defaultRates = $this->resolveRateInputs(null);

            $results[$userId] = [
                'timesheet_ids' => [],
                'base_hourly_rate' => round($baseRate, 2),
                'regular_hours' => 0.0,
                'overtime_hours' => 0.0,
                'sleepover_count' => 0,
                'on_call_hours' => 0.0,
                'mileage_km' => 0.0,
                'public_holiday_hours' => 0.0,
                'leave_hours' => $leaveHours,
                'leave_pay' => $leavePay,
                'overtime_multiplier' => round($defaultRates['overtime_multiplier'], 2),
                'public_holiday_multiplier' => round($defaultRates['public_holiday_multiplier'], 2),
                'sleepover_rate' => round($defaultRates['sleepover_rate'], 2),
                'on_call_rate' => round($defaultRates['on_call_rate'], 2),
                'gross_pay' => $leavePay,
                'allowances' => [],
                'rate_breakdown' => [
                    'rule_id' => null,
                    'rule_name' => 'Default rates',
                    'regular_pay' => 0.0,
                    'overtime_pay' => 0.0,
                    'holiday_loading' => 0.0,
                    'sleepover_pay' => 0.0,
                    'on_call_pay' => 0.0,
                    'leave_pay' => $leavePay,
                    'overtime_threshold_daily_hours' => (float) config('hr.payroll.overtime_daily_hours', 8),
                    'line_items' => [],
                ],
            ];
        }

        return $results;
    }

    /**
     * Sum approved PAID leave hours per user for leave requests overlapping the
     * pay period. Multi-day requests are pro-rated by calendar days inside the
     * period (hours_requested apportioned evenly across the request's days).
     *
     * Paid-leave rule: leave types are plain strings (LeaveService::LEAVE_TYPES)
     * with no per-type "paid" flag anywhere in the schema, and the only type the
     * system explicitly models as unpaid is 'unpaid' (zero default entitlement,
     * named as such). Payroll therefore includes every approved leave type
     * EXCEPT 'unpaid'.
     *
     * @return array<int, array{hours: float, request_ids: array<int, int>}>
     */
    protected function approvedLeaveHoursByUser(Carbon $periodStart, Carbon $periodEnd): array
    {
        $timezone = (string) config('app.worker_timezone', config('app.timezone', 'UTC'));

        // SQL prefilter on UTC dates is safe (UTC date <= local NZ date), the
        // precise overlap is recomputed below on worker-timezone calendar days.
        $requests = HrLeaveRequest::query()
            ->approved()
            ->where('leave_type', '!=', 'unpaid')
            ->whereDate('starts_at', '<=', $periodEnd->toDateString())
            ->whereDate('ends_at', '>=', $periodStart->copy()->subDay()->toDateString())
            ->get(['id', 'user_id', 'starts_at', 'ends_at', 'hours_requested', 'leave_type']);

        $windowStart = Carbon::parse($periodStart->toDateString());
        $windowEnd = Carbon::parse($periodEnd->toDateString());

        $byUser = [];

        foreach ($requests as $request) {
            if (! $request->starts_at || ! $request->ends_at || (float) $request->hours_requested <= 0) {
                continue;
            }

            $leaveStart = Carbon::parse($request->starts_at->copy()->timezone($timezone)->toDateString());
            $leaveEnd = Carbon::parse($request->ends_at->copy()->timezone($timezone)->toDateString());

            if ($leaveEnd->lessThan($leaveStart)) {
                continue;
            }

            $totalDays = (int) $leaveStart->diffInDays($leaveEnd) + 1;

            $overlapStart = $leaveStart->greaterThan($windowStart) ? $leaveStart : $windowStart;
            $overlapEnd = $leaveEnd->lessThan($windowEnd) ? $leaveEnd : $windowEnd;

            if ($overlapStart->greaterThan($overlapEnd)) {
                continue;
            }

            $overlapDays = (int) $overlapStart->diffInDays($overlapEnd) + 1;
            $hours = (float) $request->hours_requested * ($overlapDays / $totalDays);

            $userId = (int) $request->user_id;
            $byUser[$userId]['hours'] = ($byUser[$userId]['hours'] ?? 0.0) + $hours;
            $byUser[$userId]['request_ids'][] = (int) $request->id;
        }

        return $byUser;
    }

    /**
     * @return array{regular_hours: float, overtime_hours: float, on_call_hours: float, public_holiday_hours: float, sleepover_count: int, mileage_km: float}
     */
    protected function calculateTimesheetHours(Timesheet $timesheet): array
    {
        $totalHours = max((float) $timesheet->total_hours, 0);
        $overtimeThreshold = (float) config('hr.payroll.overtime_daily_hours', 8);
        $overtimeHours = max(0, $totalHours - $overtimeThreshold);
        $regularHours = max($totalHours - $overtimeHours, 0);

        $isPublicHoliday = (bool) ($timesheet->public_holiday ?? false);
        $isOnCall = (bool) ($timesheet->on_call ?? false);
        $isSleepover = (bool) ($timesheet->sleepover ?? false);

        return [
            'regular_hours' => round($regularHours, 2),
            'overtime_hours' => round($overtimeHours, 2),
            'on_call_hours' => $isOnCall ? round($totalHours, 2) : 0.0,
            'public_holiday_hours' => $isPublicHoliday ? round($totalHours, 2) : 0.0,
            'sleepover_count' => $isSleepover ? 1 : 0,
            'mileage_km' => (float) ($timesheet->mileage_km ?: 0),
        ];
    }

    protected function resolvePayRateRule(?HrEmployeeProfile $profile, Timesheet $timesheet): ?HrPayRateRule
    {
        $positionRole = $profile?->position_role ?? $timesheet->user?->role;
        $siteId = $profile?->primary_site_id ?? null;
        $serviceContextId = $timesheet->client?->service_context_id ?? null;
        $isPublicHoliday = (bool) ($timesheet->public_holiday ?? false);
        $isSleepover = (bool) ($timesheet->sleepover ?? false);
        $isOnCall = (bool) ($timesheet->on_call ?? false);

        $query = HrPayRateRule::query()
            ->active()
            ->where(function ($builder) use ($positionRole) {
                $builder->whereNull('position_role')
                    ->orWhere('position_role', $positionRole);
            })
            ->where(function ($builder) use ($siteId) {
                $builder->whereNull('site_id');
                if ($siteId) {
                    $builder->orWhere('site_id', $siteId);
                }
            })
            ->where(function ($builder) use ($serviceContextId) {
                $builder->whereNull('service_context_id');
                if ($serviceContextId) {
                    $builder->orWhere('service_context_id', $serviceContextId);
                }
            })
            ->where(function ($builder) use ($isPublicHoliday) {
                $builder->whereNull('applies_on_public_holiday')
                    ->orWhere('applies_on_public_holiday', $isPublicHoliday);
            })
            ->where(function ($builder) use ($isSleepover) {
                $builder->whereNull('applies_on_sleepover')
                    ->orWhere('applies_on_sleepover', $isSleepover);
            })
            ->where(function ($builder) use ($isOnCall) {
                $builder->whereNull('applies_on_call')
                    ->orWhere('applies_on_call', $isOnCall);
            })
            ->where(function ($builder) use ($timesheet) {
                $builder->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $timesheet->work_date);
            })
            ->where(function ($builder) use ($timesheet) {
                $builder->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $timesheet->work_date);
            })
            ->orderBy('priority')
            ->orderByDesc('id');

        return $query->first();
    }

    /**
     * @return array{regular_multiplier: float, overtime_multiplier: float, public_holiday_multiplier: float, sleepover_rate: float, on_call_rate: float}
     */
    protected function resolveRateInputs(?HrPayRateRule $rule): array
    {
        return [
            'regular_multiplier' => (float) ($rule?->regular_multiplier ?? config('hr.payroll.default_regular_multiplier', 1.00)),
            'overtime_multiplier' => (float) ($rule?->overtime_multiplier ?? config('hr.payroll.default_overtime_multiplier', 1.50)),
            'public_holiday_multiplier' => (float) ($rule?->public_holiday_multiplier ?? config('hr.payroll.default_public_holiday_multiplier', 1.50)),
            'sleepover_rate' => (float) ($rule?->sleepover_flat_rate ?? config('hr.payroll.default_sleepover_flat_rate', 0)),
            'on_call_rate' => (float) ($rule?->on_call_hourly_rate ?? config('hr.payroll.default_on_call_hourly_rate', 0)),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function validateRunConsistency(HrPayrollRun $run): array
    {
        $run->loadMissing(['items.user.hrEmployeeProfile']);

        $errors = [];

        if ($run->items->isEmpty()) {
            $errors[] = 'No payroll items generated for this run.';
        }

        $timesheetToItem = [];
        $itemById = [];

        foreach ($run->items as $item) {
            $itemById[$item->id] = $item;

            if ($item->user_id === null) {
                $errors[] = "Run item #{$item->id} has no employee assigned.";

                continue;
            }

            $profile = $item->user?->hrEmployeeProfile;
            if (! $profile) {
                $errors[] = "Run item #{$item->id} has no HR employee profile for user #{$item->user_id}.";
            } elseif (trim((string) $profile->employee_number) === '') {
                $errors[] = "Run item #{$item->id} is missing an employee number.";
            }

            $timesheetIds = collect($item->timesheet_ids ?? [])
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            // Leave-only items (approved leave, no worked time in the period)
            // legitimately carry no timesheets.
            if ($timesheetIds->isEmpty() && (float) $item->leave_hours <= 0) {
                $errors[] = "Run item #{$item->id} has no linked timesheets.";
            }

            foreach ($timesheetIds as $timesheetId) {
                if (isset($timesheetToItem[$timesheetId])) {
                    $errors[] = "Timesheet #{$timesheetId} is duplicated across run items #{$timesheetToItem[$timesheetId]['item_id']} and #{$item->id}.";

                    continue;
                }

                $timesheetToItem[$timesheetId] = [
                    'item_id' => $item->id,
                    'user_id' => (int) $item->user_id,
                ];
            }

            if ((float) $item->gross_pay < 0) {
                $errors[] = "Run item #{$item->id} has a negative gross pay.";
            }
        }

        if ($timesheetToItem !== []) {
            $timesheets = Timesheet::query()
                ->whereIn('id', array_keys($timesheetToItem))
                ->get()
                ->keyBy('id');

            foreach ($timesheetToItem as $timesheetId => $meta) {
                /** @var Timesheet|null $timesheet */
                $timesheet = $timesheets->get($timesheetId);
                if (! $timesheet) {
                    $errors[] = "Timesheet #{$timesheetId} linked to item #{$meta['item_id']} does not exist.";

                    continue;
                }

                if ($timesheet->status !== 'approved') {
                    $errors[] = "Timesheet #{$timesheetId} is '{$timesheet->status}' (must be approved).";
                }

                if ((int) $timesheet->user_id !== (int) $meta['user_id']) {
                    $errors[] = "Timesheet #{$timesheetId} staff user does not match payroll run item #{$meta['item_id']}.";
                }

                if ($timesheet->work_date?->lt($run->period_start) || $timesheet->work_date?->gt($run->period_end)) {
                    $errors[] = "Timesheet #{$timesheetId} work date is outside payroll period.";
                }
            }
        }

        $itemGrossTotal = round((float) $run->items->sum(fn ($item) => (float) $item->gross_pay), 2);
        if (abs($itemGrossTotal - (float) $run->total_gross) > 0.01) {
            $errors[] = 'Run total gross does not match payroll item gross totals.';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<int, HrPayrollRunItem>  $items
     * @return array<int, array<string, scalar|null>>
     */
    protected function buildCanonicalRows(HrPayrollRun $run, array $items): array
    {
        return collect($items)
            ->map(function (HrPayrollRunItem $item) use ($run) {
                $profile = $item->user?->hrEmployeeProfile;
                $allowancesTotal = collect($item->allowances ?? [])->sum('amount');

                return [
                    'employee_number' => $profile?->employee_number ?? '',
                    'name' => $item->user?->name ?? '',
                    'email' => $item->user?->email ?? '',
                    'position_title' => $profile?->position_title ?? '',
                    'period_start' => optional($run->period_start)->toDateString(),
                    'period_end' => optional($run->period_end)->toDateString(),
                    'regular_hours' => (float) $item->regular_hours,
                    'overtime_hours' => (float) $item->overtime_hours,
                    'sleepover_count' => (int) $item->sleepover_count,
                    'on_call_hours' => (float) $item->on_call_hours,
                    'public_holiday_hours' => (float) $item->public_holiday_hours,
                    'leave_hours' => (float) ($item->leave_hours ?? 0),
                    'leave_pay' => (float) ($item->leave_pay ?? 0),
                    'mileage_km' => (float) $item->mileage_km,
                    'base_hourly_rate' => (float) $item->base_hourly_rate,
                    'overtime_multiplier' => (float) $item->overtime_multiplier,
                    'public_holiday_multiplier' => (float) $item->public_holiday_multiplier,
                    'sleepover_rate' => (float) $item->sleepover_rate,
                    'on_call_rate' => (float) $item->on_call_rate,
                    'gross_pay' => (float) $item->gross_pay,
                    'allowances_total' => round((float) $allowancesTotal, 2),
                    'timesheet_ids' => collect($item->timesheet_ids ?? [])->join('|'),
                    'item_notes' => $item->notes ?? '',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{header: string, source: string, value?: mixed}>
     */
    protected function defaultExportMappings(): array
    {
        return [
            ['header' => 'employee_number', 'source' => 'employee_number'],
            ['header' => 'name', 'source' => 'name'],
            ['header' => 'position_title', 'source' => 'position_title'],
            ['header' => 'regular_hours', 'source' => 'regular_hours'],
            ['header' => 'overtime_hours', 'source' => 'overtime_hours'],
            ['header' => 'sleepover_count', 'source' => 'sleepover_count'],
            ['header' => 'on_call_hours', 'source' => 'on_call_hours'],
            ['header' => 'public_holiday_hours', 'source' => 'public_holiday_hours'],
            ['header' => 'leave_hours', 'source' => 'leave_hours'],
            ['header' => 'leave_pay', 'source' => 'leave_pay'],
            ['header' => 'mileage_km', 'source' => 'mileage_km'],
            ['header' => 'base_hourly_rate', 'source' => 'base_hourly_rate'],
            ['header' => 'gross_pay', 'source' => 'gross_pay'],
            ['header' => 'allowances_total', 'source' => 'allowances_total'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $mappings
     * @return array<int, array{header: string, source: string, value?: mixed}>
     */
    protected function normalizeMappings(array $mappings): array
    {
        $catalog = $this->exportFieldCatalog();

        return collect($mappings)
            ->filter(fn ($mapping) => is_array($mapping))
            ->map(function (array $mapping) use ($catalog) {
                $header = trim((string) ($mapping['header'] ?? ''));
                $source = trim((string) ($mapping['source'] ?? ''));

                if ($header === '' || $source === '') {
                    return null;
                }

                if ($source !== 'static' && ! array_key_exists($source, $catalog)) {
                    return null;
                }

                $normalized = [
                    'header' => $header,
                    'source' => $source,
                ];

                if ($source === 'static') {
                    $normalized['value'] = $mapping['value'] ?? '';
                }

                return $normalized;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, scalar|null>>  $rows
     * @param  array<int, array{header: string, source: string, value?: mixed}>  $mappings
     */
    protected function buildCsvFromRows(
        array $rows,
        array $mappings,
        string $delimiter,
        string $enclosure,
        string $lineEnding,
        bool $includeHeaders = true,
    ): string {
        $safeDelimiter = mb_substr($delimiter !== '' ? $delimiter : ',', 0, 1);
        $safeEnclosure = mb_substr($enclosure !== '' ? $enclosure : '"', 0, 1);
        $safeLineEnding = $this->normalizeLineEnding($lineEnding);

        $lines = [];
        if ($includeHeaders) {
            $headers = collect($mappings)->map(fn (array $mapping) => $mapping['header'])->all();
            $lines[] = $this->encodeCsvRow($headers, $safeDelimiter, $safeEnclosure);
        }

        foreach ($rows as $row) {
            $values = [];
            foreach ($mappings as $mapping) {
                if ($mapping['source'] === 'static') {
                    $values[] = $mapping['value'] ?? '';

                    continue;
                }

                $values[] = $row[$mapping['source']] ?? '';
            }

            $lines[] = $this->encodeCsvRow($values, $safeDelimiter, $safeEnclosure);
        }

        return implode($safeLineEnding, $lines).$safeLineEnding;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    protected function encodeCsvRow(array $values, string $delimiter, string $enclosure): string
    {
        return collect($values)
            ->map(function ($value) use ($enclosure) {
                // Employee names, notes and static profile values are user-chosen —
                // neutralise formula-leading cells (OWASP CSV injection) before enclosing.
                $stringValue = (string) $this->sanitizeCsvCell((string) ($value ?? ''));
                $escaped = str_replace($enclosure, $enclosure.$enclosure, $stringValue);

                return $enclosure.$escaped.$enclosure;
            })
            ->implode($delimiter);
    }

    protected function normalizeLineEnding(string $lineEnding): string
    {
        $trimmed = trim($lineEnding);
        if ($trimmed === '\r\n') {
            return "\r\n";
        }
        if ($trimmed === '\r') {
            return "\r";
        }
        if ($trimmed === '\n' || $trimmed === '') {
            return "\n";
        }

        return $lineEnding;
    }
}
