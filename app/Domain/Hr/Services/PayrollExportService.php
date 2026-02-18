<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayRateRule;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PayrollExportService
{
    /**
     * @throws \InvalidArgumentException
     */
    public function createRun(?int $tenantId, Carbon $periodStart, Carbon $periodEnd, int $createdBy): HrPayrollRun
    {
        if ($periodStart->greaterThanOrEqualTo($periodEnd)) {
            throw new \InvalidArgumentException('Payroll period start must be before period end.');
        }

        $resolvedTenantId = $this->resolveTenantId($tenantId, $createdBy);

        return DB::transaction(function () use ($resolvedTenantId, $periodStart, $periodEnd, $createdBy) {
            $overlap = HrPayrollRun::query()
                ->where('tenant_id', $resolvedTenantId)
                ->whereIn('status', ['draft', 'locked'])
                ->whereDate('period_start', '<=', $periodEnd->toDateString())
                ->whereDate('period_end', '>=', $periodStart->toDateString())
                ->exists();

            if ($overlap) {
                throw new \InvalidArgumentException('An overlapping draft/locked payroll run already exists.');
            }

            $run = HrPayrollRun::create([
                'tenant_id' => $resolvedTenantId,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'status' => 'draft',
                'created_by' => $createdBy,
                'validation_errors' => [],
            ]);

            $items = $this->getRunItems($resolvedTenantId, $periodStart, $periodEnd);
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

        $run->update([
            'status' => 'locked',
            'locked_at' => now(),
            'locked_by' => $lockedBy,
            'validation_errors' => [],
        ]);

        return $run->fresh();
    }

    /**
     * @throws \LogicException
     */
    public function generateExport(HrPayrollRun $run, int $exportedBy): string
    {
        if ($run->locked_at === null) {
            throw new \LogicException('Cannot export an unlocked payroll run.');
        }

        $items = $run->items()->with(['user.hrEmployeeProfile'])->orderBy('id')->get();

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
            'base_hourly_rate',
            'gross_pay',
            'allowances_total',
        ];

        $rows = [];
        foreach ($items as $item) {
            $profile = $item->user?->hrEmployeeProfile;
            $allowancesTotal = collect($item->allowances ?? [])->sum('amount');

            $rows[] = [
                $profile?->employee_number ?? '',
                $item->user?->name ?? '',
                $profile?->position_title ?? '',
                (float) $item->regular_hours,
                (float) $item->overtime_hours,
                (int) $item->sleepover_count,
                (float) $item->on_call_hours,
                (float) $item->public_holiday_hours,
                (float) $item->mileage_km,
                (float) $item->base_hourly_rate,
                (float) $item->gross_pay,
                round((float) $allowancesTotal, 2),
            ];
        }

        $csv = implode(',', $headers) . "\n";
        foreach ($rows as $row) {
            $escaped = array_map(
                fn ($value) => '"' . str_replace('"', '""', (string) $value) . '"',
                $row
            );
            $csv .= implode(',', $escaped) . "\n";
        }

        $filename = sprintf(
            'payroll-exports/run-%d_%s_%s.csv',
            $run->id,
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
     * @return array<int, array<string, mixed>>
     */
    public function getRunItems(?int $tenantId, Carbon $periodStart, Carbon $periodEnd): array
    {
        $timesheetQuery = Timesheet::query()
            ->with(['client:id,service_context_id', 'user:id,role'])
            ->where('status', 'approved')
            ->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()]);

        $this->applyTenantScope($timesheetQuery, $tenantId);

        $timesheets = $timesheetQuery->get();
        $grouped = $timesheets->groupBy('user_id');
        $results = [];

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

                $effectiveRule = $this->resolvePayRateRule($tenantId, $profile, $timesheet);
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

            $grossPay = round($regularPay + $overtimePay + $holidayLoading + $sleepoverPay + $onCallPay, 2);
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
                    'overtime_threshold_daily_hours' => $overtimeThreshold,
                    'line_items' => $bucketLines,
                ],
            ];
        }

        return $results;
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

    protected function resolvePayRateRule(?int $tenantId, ?HrEmployeeProfile $profile, Timesheet $timesheet): ?HrPayRateRule
    {
        $positionRole = $profile?->position_role ?? $timesheet->user?->role;
        $siteId = $profile?->primary_site_id ?? null;
        $serviceContextId = $timesheet->client?->service_context_id ?? null;
        $isPublicHoliday = (bool) ($timesheet->public_holiday ?? false);
        $isSleepover = (bool) ($timesheet->sleepover ?? false);
        $isOnCall = (bool) ($timesheet->on_call ?? false);

        $query = HrPayRateRule::query()
            ->active()
            ->when($tenantId !== null, fn ($builder) => $builder->where('tenant_id', $tenantId))
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

            if ($timesheetIds->isEmpty()) {
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

    protected function resolveTenantId(?int $tenantId, int $userId): int
    {
        if ($tenantId !== null) {
            return $tenantId;
        }

        $profileTenantId = HrEmployeeProfile::query()
            ->where('user_id', $userId)
            ->value('tenant_id');

        if (is_numeric($profileTenantId)) {
            return (int) $profileTenantId;
        }

        $fallbackTenantId = HrPayrollRun::query()
            ->orderByDesc('id')
            ->value('tenant_id')
            ?? HrEmployeeProfile::query()->orderBy('id')->value('tenant_id');

        return (int) ($fallbackTenantId ?? 1);
    }

    protected function applyTenantScope($query, ?int $tenantId): void
    {
        if ($tenantId === null) {
            return;
        }

        if (Schema::hasColumn('timesheets', 'tenant_id')) {
            $query->where('tenant_id', $tenantId);
            return;
        }

        if (Schema::hasColumn('users', 'tenant_id')) {
            $query->whereHas('user', fn ($userQuery) => $userQuery->where('tenant_id', $tenantId));
        }
    }
}
