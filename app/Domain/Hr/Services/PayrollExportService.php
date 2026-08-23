<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPayRateRule;
use App\Domain\Hr\Models\HrPayrollExportProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Domain\Hr\Models\HrPayrollSourceUse;
use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayrollExportService
{
    use SanitizesCsvOutput;

    public function __construct(private readonly PayslipService $payslipService) {}

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
    public function createRun(
        Carbon $periodStart,
        Carbon $periodEnd,
        int $createdBy,
        ?string $idempotencyKey = null,
        ?string $notes = null,
    ): HrPayrollRun {
        $actor = $this->authorizedPayrollActor($createdBy);
        $this->assertPeriod($periodStart, $periodEnd);
        $command = $this->commandIdentity(
            operation: 'create',
            actorId: $actor->id,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            idempotencyKey: $idempotencyKey,
            notes: $notes,
        );

        try {
            return DB::transaction(function () use ($periodStart, $periodEnd, $actor, $notes, $command) {
                $this->lockApplicationPayrollMutex();

                return $this->createRunWithinMutex(
                    periodStart: $periodStart,
                    periodEnd: $periodEnd,
                    actor: $actor,
                    notes: $notes,
                    command: $command,
                );
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new \InvalidArgumentException(
                'One or more payroll sources were claimed by another run. Refresh and review the current run.',
                previous: $exception,
            );
        }
    }

    /**
     * Replace an unreleased draft atomically while retaining its source ledger.
     * Posted, exported and paid runs require the Finance-owned reversal flow and
     * are deliberately not made correctable through this service.
     */
    public function correctRun(
        HrPayrollRun $run,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $correctedBy,
        string $reason,
        ?string $idempotencyKey = null,
        ?string $notes = null,
    ): HrPayrollRun {
        $actor = $this->authorizedPayrollActor($correctedBy);
        $this->assertPeriod($periodStart, $periodEnd);
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A payroll correction reason is required.');
        }

        $command = $this->commandIdentity(
            operation: "correct:{$run->getKey()}",
            actorId: $actor->id,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            idempotencyKey: $idempotencyKey,
            notes: $notes,
            correctionReason: $reason,
        );

        try {
            return DB::transaction(function () use ($run, $periodStart, $periodEnd, $actor, $reason, $notes, $command) {
                $this->lockApplicationPayrollMutex();

                if ($replay = $this->resolveCommandReplay($command)) {
                    if ((int) $replay->correction_of_run_id !== (int) $run->getKey()) {
                        throw new \InvalidArgumentException('The payroll correction key belongs to another command.');
                    }

                    return $replay->load(['items', 'sourceUses']);
                }

                $source = HrPayrollRun::query()->lockForUpdate()->findOrFail($run->getKey());
                if ($source->status !== 'draft'
                    || $source->locked_at !== null
                    || $source->exported_at !== null
                    || $source->journal_id !== null
                    || $source->payment_journal_id !== null
                    || $source->net_paid_at !== null
                    || $source->payslips()->exists()) {
                    throw new \LogicException(
                        'Only an unreleased draft without payslip evidence can be corrected here. Exported or paid payroll requires Finance reversal.',
                    );
                }
                if ($source->source_provenance_status !== 'verified') {
                    throw new \LogicException('Legacy payroll evidence requires reconciliation before correction.');
                }

                $uses = $source->sourceUses()
                    ->whereNotNull('active_source_identity')
                    ->lockForUpdate()
                    ->get();
                if ($uses->isEmpty()) {
                    throw new \LogicException('The payroll draft has no active source evidence to correct.');
                }

                foreach ($uses as $use) {
                    $use->update([
                        'active_source_identity' => null,
                        'released_at' => now(),
                        'released_by' => $actor->id,
                        'release_reason' => $reason,
                    ]);
                }

                $source->update([
                    'status' => 'void',
                    'voided_at' => now(),
                    'voided_by' => $actor->id,
                    'void_reason' => $reason,
                ]);

                return $this->createRunWithinMutex(
                    periodStart: $periodStart,
                    periodEnd: $periodEnd,
                    actor: $actor,
                    notes: $notes,
                    command: $command,
                    correctionOfRunId: (int) $source->id,
                );
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new \InvalidArgumentException(
                'The correction conflicts with payroll source evidence already claimed by another run.',
                previous: $exception,
            );
        }
    }

    /**
     * @param  array{key: string, payload: string}  $command
     */
    private function createRunWithinMutex(
        Carbon $periodStart,
        Carbon $periodEnd,
        User $actor,
        ?string $notes,
        array $command,
        ?int $correctionOfRunId = null,
    ): HrPayrollRun {
        if ($replay = $this->resolveCommandReplay($command)) {
            if ($replay->status === 'void') {
                throw new \InvalidArgumentException('That payroll command was voided and cannot be replayed.');
            }

            return $replay->load(['items', 'sourceUses']);
        }

        $overlap = HrPayrollRun::query()
            ->where('status', '!=', 'void')
            ->whereDate('period_start', '<=', $periodEnd->toDateString())
            ->whereDate('period_end', '>=', $periodStart->toDateString())
            ->lockForUpdate()
            ->exists();

        if ($overlap) {
            throw new \InvalidArgumentException('An overlapping non-void payroll run already exists.');
        }

        $items = $this->getRunItems($periodStart, $periodEnd, lockSources: true);
        if ($items === []) {
            throw new \InvalidArgumentException('No approved payroll sources exist in this period.');
        }

        $run = HrPayrollRun::query()->create([
            'command_key_sha256' => $command['key'],
            'command_payload_sha256' => $command['payload'],
            'source_provenance_status' => 'building',
            'correction_of_run_id' => $correctionOfRunId,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'status' => 'draft',
            'created_by' => $actor->id,
            'notes' => filled($notes) ? trim((string) $notes) : null,
            'validation_errors' => [],
        ]);

        $totalHours = 0.0;
        $totalStaff = 0;
        $totalGross = 0.0;

        foreach ($items as $userId => $aggregated) {
            $item = HrPayrollRunItem::query()->create([
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

            foreach ($aggregated['source_uses'] as $sourceUse) {
                HrPayrollSourceUse::query()->create([
                    ...$sourceUse,
                    'payroll_run_id' => $run->id,
                    'payroll_run_item_id' => $item->id,
                ]);
            }

            $totalHours += (float) $aggregated['regular_hours'] + (float) $aggregated['overtime_hours'];
            $totalStaff++;
            $totalGross += (float) $aggregated['gross_pay'];
        }

        $run->update([
            'source_provenance_status' => 'verified',
            'total_hours' => round($totalHours, 2),
            'total_staff' => $totalStaff,
            'total_gross' => round($totalGross, 2),
        ]);

        $validatedRun = $run->fresh([
            'items.user.hrEmployeeProfile',
            'sourceUses.timesheet',
            'sourceUses.leaveRequest',
        ]);
        $sourceErrors = $this->validateSourceProvenance($validatedRun);
        if ($sourceErrors !== []) {
            throw new \InvalidArgumentException('Payroll source provenance validation failed: '.$sourceErrors[0]);
        }
        $validationErrors = $this->validateRunConsistency($validatedRun);
        $run->update(['validation_errors' => $validationErrors]);

        return $run->fresh(['items', 'sourceUses']);
    }

    /**
     * @param  array{key: string, payload: string}  $command
     */
    private function resolveCommandReplay(array $command): ?HrPayrollRun
    {
        $existing = HrPayrollRun::query()
            ->where('command_key_sha256', $command['key'])
            ->lockForUpdate()
            ->first();
        if (! $existing) {
            return null;
        }

        if (! hash_equals((string) $existing->command_payload_sha256, $command['payload'])) {
            throw new \InvalidArgumentException('The payroll idempotency key was already used with a different payload.');
        }

        return $existing;
    }

    /**
     * @return array{key: string, payload: string}
     */
    private function commandIdentity(
        string $operation,
        int $actorId,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?string $idempotencyKey,
        ?string $notes,
        ?string $correctionReason = null,
    ): array {
        $start = $periodStart->toDateString();
        $end = $periodEnd->toDateString();
        $rawKey = trim((string) $idempotencyKey);
        if ($rawKey === '') {
            $rawKey = "{$operation}:{$start}:{$end}";
        }
        $payload = json_encode([
            'operation' => $operation,
            'actor_id' => $actorId,
            'period_start' => $start,
            'period_end' => $end,
            'notes' => filled($notes) ? trim((string) $notes) : null,
            'correction_reason' => $correctionReason,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'key' => hash('sha256', "payroll-run:{$operation}:{$actorId}:{$rawKey}"),
            'payload' => hash('sha256', $payload),
        ];
    }

    private function authorizedPayrollActor(int $actorId): User
    {
        $actor = User::query()->findOrFail($actorId);
        abort_unless(
            $actor->canDo('hr.payroll.view')
                && $actor->canDo('hr.payroll.export')
                && $actor->canDo('hr.employees.viewAllSites'),
            403,
        );

        return $actor;
    }

    private function assertPeriod(Carbon $periodStart, Carbon $periodEnd): void
    {
        if ($periodStart->greaterThanOrEqualTo($periodEnd)) {
            throw new \InvalidArgumentException('Payroll period start must be before period end.');
        }
    }

    private function lockApplicationPayrollMutex(): void
    {
        $mutex = DB::table('hr_payroll_run_mutexes')
            ->where('key', 'application')
            ->lockForUpdate()
            ->first();

        if (! $mutex) {
            throw new \RuntimeException('The application payroll mutex is missing; migration repair is required.');
        }
    }

    /**
     * @throws \LogicException
     * @throws ValidationException
     */
    public function lockRun(HrPayrollRun $run, int $lockedBy): HrPayrollRun
    {
        $actor = $this->authorizedPayrollActor($lockedBy);

        /** @var array{run: HrPayrollRun, validation_errors: array<int, string>} $outcome */
        $outcome = DB::transaction(function () use ($run, $actor): array {
            $this->lockApplicationPayrollMutex();
            $lockedRun = HrPayrollRun::query()->lockForUpdate()->findOrFail($run->getKey());

            if ($lockedRun->locked_at !== null) {
                throw new \LogicException('This payroll run is already locked.');
            }
            if ($lockedRun->status !== 'draft') {
                throw new \LogicException('Only a draft payroll run can be locked.');
            }
            if ($lockedRun->source_provenance_status === 'legacy_unverified_paid_leave') {
                throw new \LogicException(
                    'This legacy run contains paid leave without date-slice provenance and requires reconciliation.',
                );
            }
            if (! in_array($lockedRun->source_provenance_status, ['verified', 'legacy_no_paid_leave'], true)) {
                throw new \LogicException('Payroll source provenance is incomplete.');
            }
            if ($lockedRun->items()->count() === 0) {
                throw new \LogicException('Cannot lock a payroll run with no items.');
            }

            $this->lockRunSourceEvidence($lockedRun);
            $validationErrors = $this->validateRunConsistency($lockedRun->fresh([
                'items.user.hrEmployeeProfile',
                'sourceUses.timesheet',
                'sourceUses.leaveRequest',
            ]));
            if ($validationErrors !== []) {
                $lockedRun->update(['validation_errors' => $validationErrors]);

                return [
                    'run' => $lockedRun->fresh(),
                    'validation_errors' => $validationErrors,
                ];
            }

            $lockedRun->update([
                'status' => 'locked',
                'locked_at' => now(),
                'locked_by' => $actor->id,
                'validation_errors' => [],
            ]);

            // Generate payslips on lock so the GL journal (posted right after by
            // the controller via PostPayrollJournalJob) has payslips to read.
            // Idempotent: a re-run or the standalone Generate endpoint may have
            // already created them.
            $this->payslipService->generateBulkPayslips($lockedRun->fresh('items'));

            return [
                'run' => $lockedRun->fresh(),
                'validation_errors' => [],
            ];
        });

        if ($outcome['validation_errors'] !== []) {
            throw ValidationException::withMessages([
                'lock' => 'Payroll run validation failed. Resolve highlighted issues before locking.',
            ]);
        }

        return $outcome['run'];
    }

    private function lockRunSourceEvidence(HrPayrollRun $run): void
    {
        $uses = HrPayrollSourceUse::query()
            ->where('payroll_run_id', $run->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['timesheet_id', 'leave_request_id']);

        $legacyItemTimesheetIds = $run->source_provenance_status === 'legacy_no_paid_leave'
            ? $run->items()->get(['timesheet_ids'])
                ->flatMap(fn (HrPayrollRunItem $item) => collect($item->timesheet_ids ?? []))
            : collect();
        $timesheetIds = $uses->pluck('timesheet_id')
            ->merge($legacyItemTimesheetIds)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)->unique()->sort()->values();
        if ($timesheetIds->isNotEmpty()) {
            Timesheet::query()->whereIn('id', $timesheetIds->all())
                ->orderBy('id')->lockForUpdate()->get(['id']);
        }

        $leaveRequestIds = $uses->pluck('leave_request_id')
            ->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values();
        if ($leaveRequestIds->isNotEmpty()) {
            HrLeaveRequest::query()->whereIn('id', $leaveRequestIds->all())
                ->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }

    /**
     * Settlement-only cascade: flip every still-approved linked timesheet to
     * paid. ExternalSettlementService calls this in the same transaction as the
     * accepted net-pay journal; locking/exporting alone must never call it.
     */
    public function markRunTimesheetsPaid(HrPayrollRun $run): int
    {
        return DB::transaction(function () use ($run) {
            $run = HrPayrollRun::query()->lockForUpdate()->findOrFail($run->getKey());
            if ($run->locked_at === null || ! in_array($run->status, ['locked', 'exported'], true)) {
                throw new \LogicException('Timesheets can be marked paid only by a locked payroll run.');
            }

            $reference = "hr-payroll-run:{$run->id}";
            $ids = $run->items()->get(['timesheet_ids'])
                ->flatMap(fn (HrPayrollRunItem $item) => collect($item->timesheet_ids ?? []))
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
            if ($ids->isEmpty()) {
                return 0;
            }

            $marked = 0;

            $timesheets = Timesheet::query()
                ->whereIn('id', $ids->all())
                ->lockForUpdate()
                ->get();
            if ($timesheets->count() !== $ids->count()) {
                throw new \LogicException('A payroll run timesheet no longer exists; paid status was not changed.');
            }

            foreach ($timesheets as $timesheet) {
                if ($timesheet->status === 'paid') {
                    if ($timesheet->payroll_reference !== $reference
                        || $timesheet->exported_to_payroll_at === null) {
                        throw new \LogicException('A paid timesheet belongs to different payroll evidence.');
                    }

                    continue;
                }

                if ($timesheet->status !== 'approved') {
                    throw new \LogicException(
                        "Timesheet #{$timesheet->id} is not approved; paid status was not changed.",
                    );
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
        $actor = $this->authorizedPayrollActor($exportedBy);

        return DB::transaction(function () use ($run, $actor, $profile): string {
            $this->lockApplicationPayrollMutex();
            $run = HrPayrollRun::query()->lockForUpdate()->findOrFail($run->getKey());
            if ($run->locked_at === null || ! in_array($run->status, ['locked', 'exported'], true)) {
                throw new \LogicException('Cannot export an unlocked payroll run.');
            }
            if ($run->source_provenance_status === 'legacy_unverified_paid_leave') {
                throw new \LogicException(
                    'This legacy run contains paid leave without date-slice provenance and cannot be re-exported.',
                );
            }
            if (! in_array($run->source_provenance_status, ['verified', 'legacy_no_paid_leave'], true)) {
                throw new \LogicException('Payroll source provenance is incomplete.');
            }

            if ($run->source_provenance_status === 'verified') {
                $this->lockRunSourceEvidence($run);
                $validationErrors = $this->validateRunConsistency($run->fresh([
                    'items.user.hrEmployeeProfile',
                    'sourceUses.timesheet',
                    'sourceUses.leaveRequest',
                ]));
                if ($validationErrors !== []) {
                    throw new \LogicException(
                        'Payroll source provenance changed after lock and cannot be exported.',
                    );
                }
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

            if (! Storage::disk('private')->put($filename, $csv)) {
                throw new \RuntimeException('Unable to persist the payroll export.');
            }

            $run->update([
                'status' => 'exported',
                'exported_at' => $run->exported_at ?? now(),
                'exported_by' => $run->exported_by ?? $actor->id,
                'export_profile_id' => $resolvedProfile?->id,
                'export_format' => 'csv',
                'export_path' => $filename,
            ]);

            return $filename;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRunItems(Carbon $periodStart, Carbon $periodEnd, bool $lockSources = false): array
    {
        $timesheetQuery = Timesheet::query()
            ->with([
                'client:id,service_context_id,site_id',
                'shift:id,site_id,client_id',
                'user:id,role',
            ])
            ->where('status', 'approved')
            ->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()]);

        if ($lockSources) {
            $timesheetQuery->lockForUpdate();
        }

        $timesheets = $timesheetQuery->get();
        $grouped = $timesheets->groupBy('user_id');
        $results = [];

        // Approved PAID leave overlapping the period, keyed by user — consumed
        // per-user inside the loop; whatever remains afterwards belongs to
        // employees with leave but no timesheets in the period.
        $leaveByUser = $this->approvedLeaveHoursByUser($periodStart, $periodEnd, $lockSources);

        foreach ($grouped as $userId => $userTimesheets) {
            $profileQuery = HrEmployeeProfile::query()->where('user_id', $userId);
            if ($lockSources) {
                $profileQuery->lockForUpdate();
            }
            $profile = $profileQuery->first();
            if (! $profile) {
                throw new \InvalidArgumentException(
                    "Employee #{$userId} has no canonical HR profile and cannot enter payroll.",
                );
            }

            $baseRate = (float) ($profile?->hourly_rate ?: 0);
            $overtimeThreshold = (float) config('hr.payroll.overtime_daily_hours', 8);

            $regularHours = 0.0;
            $overtimeHours = 0.0;
            $onCallHours = 0.0;
            $sleepoverCount = 0;
            $publicHolidayHours = 0.0;
            $mileageKm = 0.0;
            $timesheetIds = [];
            $sourceUses = [];

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

                $siteId = $this->canonicalTimesheetSiteId($timesheet, $profile);
                if ($siteId === null) {
                    throw new \InvalidArgumentException(
                        "Timesheet #{$timesheet->id} has no single canonical Site and cannot enter payroll.",
                    );
                }
                $sourceIdentity = "timesheet:{$timesheet->id}";
                $sourceUses[] = [
                    'source_type' => 'timesheet',
                    'timesheet_id' => (int) $timesheet->id,
                    'leave_request_id' => null,
                    'user_id' => (int) $userId,
                    'employee_profile_id' => (int) $profile->id,
                    'site_id' => $siteId,
                    'source_date' => $timesheet->work_date->toDateString(),
                    'hours' => number_format(
                        (float) $hours['regular_hours'] + (float) $hours['overtime_hours'],
                        4,
                        '.',
                        '',
                    ),
                    'hourly_rate' => number_format($baseRate, 4, '.', ''),
                    'amount' => number_format(
                        round($timesheetRegularPay + $timesheetOvertimePay + $timesheetHolidayLoading + $timesheetSleepoverPay + $timesheetOnCallPay, 2),
                        2,
                        '.',
                        '',
                    ),
                    'source_identity' => $sourceIdentity,
                    'active_source_identity' => $sourceIdentity,
                    'source_payload_sha256' => $this->timesheetSourceDigest(
                        $timesheet,
                        (int) $profile->id,
                        $siteId,
                        $baseRate,
                    ),
                ];

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
            $pricedLeave = $this->priceLeaveSlices(
                $leaveByUser[$userId]['slices'] ?? [],
                $profile,
            );
            $leaveHours = $pricedLeave['hours'];
            $leavePay = $pricedLeave['amount'];
            $sourceUses = [...$sourceUses, ...$pricedLeave['source_uses']];
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
                'source_uses' => $sourceUses,
            ];
        }

        // Employees with approved paid leave but no timesheets in the period
        // still need a run item so their leave is paid.
        foreach ($leaveByUser as $userId => $leave) {
            $profileQuery = HrEmployeeProfile::query()->where('user_id', $userId);
            if ($lockSources) {
                $profileQuery->lockForUpdate();
            }
            $profile = $profileQuery->first();

            $baseRate = (float) ($profile?->hourly_rate ?: 0);
            $pricedLeave = $this->priceLeaveSlices($leave['slices'] ?? [], $profile);
            $leaveHours = $pricedLeave['hours'];
            $leavePay = $pricedLeave['amount'];
            if ((float) $leaveHours <= 0) {
                continue;
            }
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
                'source_uses' => $pricedLeave['source_uses'],
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
     * @return array<int, array{hours: string, request_ids: array<int, int>, slices: array<int, array<string, mixed>>}>
     */
    protected function approvedLeaveHoursByUser(
        Carbon $periodStart,
        Carbon $periodEnd,
        bool $lockSources = false,
    ): array {
        $timezone = (string) config('app.worker_timezone', config('app.timezone', 'UTC'));

        // SQL prefilter on UTC dates is safe (UTC date <= local NZ date), the
        // precise overlap is recomputed below on worker-timezone calendar days.
        $requestQuery = HrLeaveRequest::query()
            ->approved()
            ->where('leave_type', '!=', 'unpaid')
            ->whereDate('starts_at', '<=', $periodEnd->toDateString())
            ->whereDate('ends_at', '>=', $periodStart->copy()->subDay()->toDateString());
        if ($lockSources) {
            $requestQuery->lockForUpdate();
        }
        $requests = $requestQuery->get([
            'id',
            'user_id',
            'starts_at',
            'ends_at',
            'hours_requested',
            'leave_type',
            'status',
        ]);

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

            $userId = (int) $request->user_id;
            $requestedHours = number_format((float) $request->hours_requested, 2, '.', '');
            $ordinaryDailyHours = bcdiv($requestedHours, (string) $totalDays, 4);
            $lastDayHours = bcsub(
                $requestedHours,
                bcmul($ordinaryDailyHours, (string) max($totalDays - 1, 0), 4),
                4,
            );

            $sliceDate = $overlapStart->copy();
            while ($sliceDate->lessThanOrEqualTo($overlapEnd)) {
                $hours = $sliceDate->isSameDay($leaveEnd)
                    ? $lastDayHours
                    : $ordinaryDailyHours;
                $byUser[$userId]['hours'] = bcadd(
                    (string) ($byUser[$userId]['hours'] ?? '0'),
                    $hours,
                    4,
                );
                $byUser[$userId]['request_ids'][] = (int) $request->id;
                $byUser[$userId]['slices'][] = [
                    'request_id' => (int) $request->id,
                    'user_id' => $userId,
                    'leave_type' => (string) $request->leave_type,
                    'starts_at' => $request->starts_at->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
                    'ends_at' => $request->ends_at->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
                    'hours_requested' => $requestedHours,
                    'status' => (string) $request->status,
                    'source_date' => $sliceDate->toDateString(),
                    'hours' => $hours,
                ];
                $sliceDate->addDay();
            }
        }

        return $byUser;
    }

    /**
     * @param  array<int, array<string, mixed>>  $slices
     * @return array{hours: string, amount: string, source_uses: array<int, array<string, mixed>>}
     */
    private function priceLeaveSlices(array $slices, ?HrEmployeeProfile $profile): array
    {
        if ($slices === []) {
            return ['hours' => '0.00', 'amount' => '0.00', 'source_uses' => []];
        }
        if (! $profile || ! is_numeric($profile->primary_site_id) || (int) $profile->primary_site_id <= 0) {
            throw new \InvalidArgumentException('Paid leave requires a canonical employee primary Site before payroll.');
        }
        $siteId = (int) $profile->primary_site_id;
        if (! DB::table('sites')->where('id', $siteId)->exists()) {
            throw new \InvalidArgumentException('Paid leave references a missing employee Site and cannot enter payroll.');
        }

        $rate = number_format((float) ($profile->hourly_rate ?? 0), 4, '.', '');
        if (bccomp($rate, '0', 4) < 0) {
            throw new \InvalidArgumentException('Paid leave cannot use a negative hourly rate.');
        }

        $hoursTotal = '0';
        $amountTotal = '0';
        $uses = [];
        foreach ($slices as $slice) {
            $hours = (string) $slice['hours'];
            $amount = $this->roundDecimal(bcmul($hours, $rate, 6), 2);
            $hoursTotal = bcadd($hoursTotal, $hours, 4);
            $amountTotal = bcadd($amountTotal, $amount, 2);
            $sourceIdentity = "leave:{$slice['request_id']}:{$slice['source_date']}";
            $uses[] = [
                'source_type' => 'leave',
                'timesheet_id' => null,
                'leave_request_id' => (int) $slice['request_id'],
                'user_id' => (int) $slice['user_id'],
                'employee_profile_id' => (int) $profile->id,
                'site_id' => $siteId,
                'source_date' => (string) $slice['source_date'],
                'hours' => $hours,
                'hourly_rate' => $rate,
                'amount' => $amount,
                'source_identity' => $sourceIdentity,
                'active_source_identity' => $sourceIdentity,
                'source_payload_sha256' => $this->leaveSourceDigest(
                    $slice,
                    (int) $profile->id,
                    $siteId,
                    $rate,
                ),
            ];
        }

        return [
            'hours' => $this->roundDecimal($hoursTotal, 2),
            'amount' => $amountTotal,
            'source_uses' => $uses,
        ];
    }

    private function canonicalTimesheetSiteId(
        Timesheet $timesheet,
        ?HrEmployeeProfile $profile = null,
    ): ?int {
        $siteIds = collect([
            $timesheet->shift_site_id,
            $timesheet->site_id,
        ])->filter(fn ($siteId) => is_numeric($siteId) && (int) $siteId > 0)
            ->map(fn ($siteId) => (int) $siteId)
            ->unique()
            ->values();

        if ($siteIds->count() === 1) {
            return (int) $siteIds->first();
        }
        if ($siteIds->isNotEmpty()) {
            return null;
        }
        if ($timesheet->client_id !== null || $timesheet->shift_id !== null) {
            return null;
        }

        // Canonical shift/client entries must carry their own immutable Site
        // snapshot. A mutable parent assignment is never inferred at claim
        // time. Truly manual entries may use the employee's primary Site.
        return is_numeric($profile?->primary_site_id) && (int) $profile->primary_site_id > 0
            ? (int) $profile->primary_site_id
            : null;
    }

    private function timesheetSourceDigest(
        Timesheet $timesheet,
        int $employeeProfileId,
        int $siteId,
        float $baseRate,
    ): string {
        return $this->stableDigest([
            'id' => (int) $timesheet->id,
            'user_id' => (int) $timesheet->user_id,
            'employee_profile_id' => $employeeProfileId,
            'site_id' => $siteId,
            'timesheet_client_id' => $timesheet->client_id === null ? null : (int) $timesheet->client_id,
            'timesheet_shift_id' => $timesheet->shift_id === null ? null : (int) $timesheet->shift_id,
            'timesheet_shift_site_id' => $timesheet->shift_site_id === null ? null : (int) $timesheet->shift_site_id,
            'timesheet_site_id' => $timesheet->site_id === null ? null : (int) $timesheet->site_id,
            'timesheet_service_context_id' => $timesheet->shift_service_context_id === null
                ? null
                : (int) $timesheet->shift_service_context_id,
            'work_date' => $timesheet->work_date?->toDateString(),
            'starts_at' => $this->canonicalUtcTimestamp($timesheet->starts_at),
            'ends_at' => $this->canonicalUtcTimestamp($timesheet->ends_at),
            'break_minutes' => (int) ($timesheet->break_minutes ?? 0),
            'mileage_km' => number_format((float) ($timesheet->mileage_km ?? 0), 2, '.', ''),
            'sleepover' => (bool) $timesheet->sleepover,
            'on_call' => (bool) $timesheet->on_call,
            'public_holiday' => (bool) $timesheet->public_holiday,
            'base_hourly_rate' => number_format($baseRate, 4, '.', ''),
        ]);
    }

    /** @param array<string, mixed> $slice */
    private function leaveSourceDigest(
        array $slice,
        int $employeeProfileId,
        int $siteId,
        string $rate,
    ): string {
        return $this->stableDigest([
            ...$slice,
            'employee_profile_id' => $employeeProfileId,
            'site_id' => $siteId,
            'hourly_rate' => $rate,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function stableDigest(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function canonicalUtcTimestamp(mixed $value): ?string
    {
        return filled($value)
            ? Carbon::parse((string) $value)->utc()->format('Y-m-d\TH:i:s.u\Z')
            : null;
    }

    private function roundDecimal(string $value, int $scale): string
    {
        $increment = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', $scale + 1) >= 0
            ? bcadd($value, $increment, $scale)
            : bcsub($value, $increment, $scale);
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
        $run->loadMissing([
            'items.user.hrEmployeeProfile',
            'sourceUses.timesheet',
            'sourceUses.leaveRequest',
        ]);

        $errors = $run->source_provenance_status === 'verified'
            ? $this->validateSourceProvenance($run)
            : [];

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

                if (! $this->isCanonicalTimesheetStateForRun($timesheet, $run)) {
                    $errors[] = "Timesheet #{$timesheetId} is '{$timesheet->status}' (must be approved or canonically paid by this run).";
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

    /** @return array<int, string> */
    private function validateSourceProvenance(HrPayrollRun $run): array
    {
        $errors = [];
        $run->loadMissing([
            'items.user.hrEmployeeProfile',
            'sourceUses.timesheet',
            'sourceUses.leaveRequest',
        ]);
        $items = $run->items->keyBy('id');
        $usesByItem = $run->sourceUses->groupBy('payroll_run_item_id');

        if ($run->sourceUses->isEmpty()) {
            return ['Verified payroll runs require immutable source evidence.'];
        }

        foreach ($run->sourceUses as $use) {
            $item = $items->get($use->payroll_run_item_id);
            if (! $item || (int) $item->user_id !== (int) $use->user_id) {
                $errors[] = "Payroll source #{$use->id} does not match its run item employee.";

                continue;
            }
            if ((int) $use->payroll_run_id !== (int) $run->id
                || $use->source_date?->lt($run->period_start)
                || $use->source_date?->gt($run->period_end)) {
                $errors[] = "Payroll source #{$use->id} is outside its run boundary.";
            }
            if ($run->status !== 'void'
                && ($use->active_source_identity === null
                    || $use->active_source_identity !== $use->source_identity
                    || $use->released_at !== null)) {
                $errors[] = "Payroll source #{$use->id} is not an active immutable claim.";
            }

            if ($use->source_type === 'timesheet') {
                $timesheet = $use->timesheet;
                if (! $timesheet
                    || $use->leave_request_id !== null) {
                    $errors[] = "Payroll source #{$use->id} has invalid timesheet lineage.";

                    continue;
                }
                // Site, profile and rate are claim-time snapshots. Current
                // client/shift/profile assignments must not rewrite historical
                // payroll evidence after a legitimate reassignment.
                $siteId = (int) $use->site_id;
                $baseRate = (float) $use->hourly_rate;
                if ($siteId <= 0
                    || (int) $timesheet->user_id !== (int) $use->user_id
                    || $timesheet->work_date?->toDateString() !== $use->source_date?->toDateString()
                    || ! $this->isCanonicalTimesheetStateForRun($timesheet, $run)
                    || ! hash_equals(
                        (string) $use->source_payload_sha256,
                        $this->timesheetSourceDigest(
                            $timesheet,
                            (int) $use->employee_profile_id,
                            $siteId,
                            $baseRate,
                        ),
                    )) {
                    $errors[] = "Timesheet source #{$use->timesheet_id} changed after the payroll draft was created.";
                }

                continue;
            }

            if ($use->source_type !== 'leave') {
                $errors[] = "Payroll source #{$use->id} has an unsupported source type.";

                continue;
            }

            $request = $use->leaveRequest;
            if (! $request
                || $use->timesheet_id !== null) {
                $errors[] = "Payroll source #{$use->id} has invalid leave lineage.";

                continue;
            }
            $expectedSlice = $this->canonicalLeaveSlice($request, $use->source_date?->toDateString());
            $rate = (string) $use->hourly_rate;
            if (! $expectedSlice
                || (int) $request->user_id !== (int) $use->user_id
                || bccomp((string) $use->hours, (string) ($expectedSlice['hours'] ?? '0'), 4) !== 0
                || ! hash_equals(
                    (string) $use->source_payload_sha256,
                    $this->leaveSourceDigest(
                        $expectedSlice ?? [],
                        (int) $use->employee_profile_id,
                        (int) $use->site_id,
                        $rate,
                    ),
                )) {
                $errors[] = "Leave source #{$use->leave_request_id} on {$use->source_date?->toDateString()} changed after the payroll draft was created.";
            }
        }

        foreach ($run->items as $item) {
            $itemUses = $usesByItem->get($item->id, collect());
            $claimedTimesheets = $itemUses->where('source_type', 'timesheet')
                ->pluck('timesheet_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $itemTimesheets = collect($item->timesheet_ids ?? [])
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            if ($claimedTimesheets !== $itemTimesheets) {
                $errors[] = "Run item #{$item->id} timesheet sources do not match its immutable ledger.";
            }

            $leaveUses = $itemUses->where('source_type', 'leave');
            $leaveHours = $leaveUses->reduce(
                fn (string $sum, HrPayrollSourceUse $use) => bcadd($sum, (string) $use->hours, 4),
                '0',
            );
            $leaveAmount = $leaveUses->reduce(
                fn (string $sum, HrPayrollSourceUse $use) => bcadd($sum, (string) $use->amount, 2),
                '0',
            );
            if (bccomp($this->roundDecimal($leaveHours, 2), (string) ($item->leave_hours ?? '0'), 2) !== 0
                || bccomp($leaveAmount, (string) ($item->leave_pay ?? '0'), 2) !== 0) {
                $errors[] = "Run item #{$item->id} leave totals do not match its immutable date-slice ledger.";
            }
        }

        return array_values(array_unique($errors));
    }

    private function isCanonicalTimesheetStateForRun(
        Timesheet $timesheet,
        HrPayrollRun $run,
    ): bool {
        if ($timesheet->status === 'approved') {
            return true;
        }

        return $timesheet->status === 'paid'
            && $run->locked_at !== null
            && in_array($run->status, ['locked', 'exported'], true)
            && $timesheet->payroll_reference === "hr-payroll-run:{$run->id}"
            && $timesheet->exported_to_payroll_at !== null;
    }

    /** @return array<string, mixed>|null */
    private function canonicalLeaveSlice(HrLeaveRequest $request, ?string $sourceDate): ?array
    {
        if (! $sourceDate
            || $request->status !== 'approved'
            || $request->leave_type === 'unpaid'
            || ! $request->starts_at
            || ! $request->ends_at
            || bccomp((string) $request->hours_requested, '0', 2) <= 0) {
            return null;
        }
        $timezone = (string) config('app.worker_timezone', config('app.timezone', 'UTC'));
        $start = Carbon::parse($request->starts_at->copy()->timezone($timezone)->toDateString());
        $end = Carbon::parse($request->ends_at->copy()->timezone($timezone)->toDateString());
        $date = Carbon::parse($sourceDate);
        if ($end->lessThan($start) || $date->lt($start) || $date->gt($end)) {
            return null;
        }

        $totalDays = (int) $start->diffInDays($end) + 1;
        $requestedHours = number_format((float) $request->hours_requested, 2, '.', '');
        $ordinaryDailyHours = bcdiv($requestedHours, (string) $totalDays, 4);
        $hours = $date->isSameDay($end)
            ? bcsub(
                $requestedHours,
                bcmul($ordinaryDailyHours, (string) max($totalDays - 1, 0), 4),
                4,
            )
            : $ordinaryDailyHours;

        return [
            'request_id' => (int) $request->id,
            'user_id' => (int) $request->user_id,
            'leave_type' => (string) $request->leave_type,
            'starts_at' => $request->starts_at->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'ends_at' => $request->ends_at->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'hours_requested' => $requestedHours,
            'status' => (string) $request->status,
            'source_date' => $date->toDateString(),
            'hours' => $hours,
        ];
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
