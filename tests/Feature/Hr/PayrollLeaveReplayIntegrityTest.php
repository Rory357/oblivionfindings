<?php

use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\PayrollJournalService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Domain\Hr\Models\HrPayrollSourceUse;
use App\Domain\Hr\Models\HrPayslip;
use App\Domain\Hr\Services\HrPayrollAccessService;
use App\Domain\Hr\Services\PayrollExportService;
use App\Domain\Hr\Services\PayslipService;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\TimesheetAmendmentService;
use App\Services\Operations\TimesheetHrSyncService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    config(['app.worker_timezone' => 'Pacific/Auckland']);
    $this->siteA = Site::factory()->create(['name' => 'Payroll replay Site A']);
    $this->siteB = Site::factory()->create(['name' => 'Payroll replay Site B']);
});

/** @param array<int, string> $permissions */
function payrollReplayActor(Site $site, array $permissions): User
{
    $actor = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    ensureCanonicalHrStaffProfile($actor, $site);

    foreach ($permissions as $key) {
        $permission = Permission::query()->where('key', $key)->firstOrFail();
        $actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    return $actor->fresh();
}

function payrollReplayWorker(Site $site, string $name = 'Payroll replay worker'): User
{
    $worker = User::factory()->create([
        'name' => $name,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'employee_number' => 'PAY-REPLAY-'.$worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'hourly_rate' => '30.00',
        'start_date' => '2025-01-01',
        'end_date' => null,
        'is_active' => true,
    ]);

    return $worker;
}

function payrollReplayLeave(
    User $worker,
    string $start,
    string $end,
    string $hours,
): HrLeaveRequest {
    return HrLeaveRequest::query()->create([
        'user_id' => $worker->id,
        'leave_type' => 'annual',
        'starts_at' => Carbon::parse($start, 'Pacific/Auckland')->startOfDay()->utc(),
        'ends_at' => Carbon::parse($end, 'Pacific/Auckland')->startOfDay()->utc(),
        'hours_requested' => $hours,
        'status' => 'approved',
        'created_by' => $worker->id,
    ]);
}

function payrollReplayGlobalActor(Site $site): User
{
    return payrollReplayActor($site, [
        'hr.payroll.view',
        'hr.payroll.export',
        'hr.employees.viewAllSites',
    ]);
}

function payrollReplayTimesheet(User $worker, Site $site, string $date): Timesheet
{
    $client = Client::factory()->create(['site_id' => $site->id]);

    return Timesheet::query()->create([
        'user_id' => $worker->id,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'work_date' => $date,
        'starts_at' => Carbon::parse("{$date} 09:00:00", 'Pacific/Auckland'),
        'ends_at' => Carbon::parse("{$date} 17:00:00", 'Pacific/Auckland'),
        'break_minutes' => 0,
        'status' => 'approved',
        'submitted_at' => Carbon::parse("{$date} 17:05:00", 'Pacific/Auckland'),
        'approved_at' => Carbon::parse("{$date} 18:00:00", 'Pacific/Auckland'),
        'created_by' => $worker->id,
    ]);
}

test('application payroll requires action plus explicit all-Site authority and conceals foreign direct objects', function (): void {
    $actionOnly = payrollReplayActor($this->siteA, ['hr.payroll.view', 'hr.payroll.export']);
    $globalOnly = payrollReplayActor($this->siteA, ['hr.payroll.view', 'hr.employees.viewAllSites']);
    $globalPayroll = payrollReplayGlobalActor($this->siteA);
    $worker = payrollReplayWorker($this->siteB);
    payrollReplayLeave($worker, '2026-08-03', '2026-08-03', '8.00');

    $payload = [
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-14',
        'idempotency_key' => 'site-authority-positive',
    ];

    $this->actingAs($actionOnly)
        ->post(route('hr.payroll.runs.store'), $payload)
        ->assertForbidden();
    $this->actingAs($globalOnly)
        ->post(route('hr.payroll.runs.store'), $payload)
        ->assertForbidden();
    expect(HrPayrollRun::query()->count())->toBe(0);

    $this->actingAs($globalPayroll)
        ->post(route('hr.payroll.runs.store'), $payload)
        ->assertSessionHas('success');
    $run = HrPayrollRun::query()->sole();

    $this->actingAs($actionOnly)
        ->post(route('hr.payroll.runs.lock', $run))
        ->assertNotFound();
    expect($run->fresh()->status)->toBe('draft');

    $allowedWorker = payrollReplayWorker($this->siteA, 'Allowed direct-object worker');
    $allowedRun = HrPayrollRun::factory()->create([
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-14',
        'source_provenance_status' => 'legacy_no_paid_leave',
    ]);
    HrPayrollRunItem::query()->create([
        'payroll_run_id' => $allowedRun->id,
        'user_id' => $allowedWorker->id,
    ]);

    $this->actingAs($actionOnly)
        ->post(route('hr.payroll.runs.lock', $allowedRun))
        ->assertForbidden();
    expect($allowedRun->fresh()->status)->toBe('draft');
});

test('verified mixed-Site source evidence is indivisible and concealed from a partial-Site payslip generator', function (): void {
    $globalPayroll = payrollReplayGlobalActor($this->siteA);
    $partialViewer = payrollReplayActor($this->siteA, [
        'hr.payroll.view',
        'hr.payslips.view',
        'hr.payslips.generate',
    ]);
    $worker = payrollReplayWorker($this->siteA);
    payrollReplayTimesheet($worker, $this->siteA, '2026-09-03');
    payrollReplayTimesheet($worker, $this->siteB, '2026-09-04');

    $run = app(PayrollExportService::class)->createRun(
        Carbon::parse('2026-09-01'),
        Carbon::parse('2026-09-14'),
        $globalPayroll->id,
        'mixed-source-Sites',
    );

    expect(app(HrPayrollAccessService::class)
        ->visibleRunsQuery($partialViewer)
        ->whereKey($run->id)
        ->exists())->toBeFalse();

    $this->actingAs($partialViewer)
        ->post('/hr/payroll/payslips/generate', [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-14',
            'payroll_run_id' => $run->id,
        ])
        ->assertNotFound();
    expect($run->payslips()->count())->toBe(0);
});

test('active draft claims route amendments to adjustment and block quiet HR sync or draft payslips', function (): void {
    $actor = payrollReplayGlobalActor($this->siteA);
    $reviewer = payrollReplayActor($this->siteA, []);
    $worker = payrollReplayWorker($this->siteA);
    $timesheet = payrollReplayTimesheet($worker, $this->siteA, '2026-10-06');
    $run = app(PayrollExportService::class)->createRun(
        Carbon::parse('2026-10-01'),
        Carbon::parse('2026-10-14'),
        $actor->id,
        'claimed-timesheet-quiet-paths',
    );

    $amendment = app(TimesheetAmendmentService::class)->request(
        $timesheet,
        $actor,
        ['break_minutes' => 30],
        'Correct the recorded break.',
    );
    expect($amendment->payroll_adjustment_required)->toBeTrue();

    app(TimesheetAmendmentService::class)->approve($amendment, $reviewer);
    expect($timesheet->fresh()->break_minutes)->toBe(0)
        ->and($amendment->fresh()->applied_at)->toBeNull()
        ->and($amendment->fresh()->payroll_adjustment_required)->toBeTrue();

    expect(fn () => app(TimesheetHrSyncService::class)->syncToHr($timesheet->fresh()))
        ->toThrow(ValidationException::class, 'claimed by an active payroll run');
    expect(fn () => app(PayslipService::class)->generateBulkPayslips($run->fresh()))
        ->toThrow(LogicException::class, 'only after the payroll run is locked');
    expect(fn () => app(PayrollExportService::class)->markRunTimesheetsPaid($run->fresh()))
        ->toThrow(LogicException::class, 'only by a locked payroll run');
    expect($run->payslips()->count())->toBe(0)
        ->and($timesheet->fresh()->status)->toBe('approved');

    $partialPayslip = HrPayslip::query()->create([
        'payroll_run_id' => $run->id,
        'employee_profile_id' => $worker->hrEmployeeProfile->id,
        'user_id' => $worker->id,
        'pay_period_start' => $run->period_start,
        'pay_period_end' => $run->period_end,
        'gross_pay' => '0.00',
        'paye' => '0.00',
        'acc_levy' => '0.00',
        'kiwisaver_employee' => '0.00',
        'kiwisaver_employer' => '0.00',
        'student_loan' => '0.00',
        'holiday_pay' => '0.00',
        'total_deductions' => '0.00',
        'net_pay' => '0.00',
        'status' => 'draft',
        'created_by' => $actor->id,
    ]);
    expect(fn () => app(PayrollExportService::class)->lockRun($run->fresh(), $actor->id))
        ->toThrow(LogicException::class, 'incomplete or do not match');
    expect($run->fresh()->status)->toBe('draft');
    $partialPayslip->delete();

    app(PayrollExportService::class)->lockRun($run->fresh(), $actor->id);
    $canonicalPayslip = $run->payslips()->sole();
    $canonicalGross = (string) $canonicalPayslip->gross_pay;
    expect(fn () => app(PayslipService::class)->generatePayslip(
        $worker->hrEmployeeProfile,
        $run->period_start->toDateString(),
        $run->period_end->toDateString(),
    ))->toThrow(DomainException::class, 'run-backed payslip is immutable');
    expect((string) $canonicalPayslip->fresh()->gross_pay)->toBe($canonicalGross);
});

test('create command replays once and every non-void exported or paid overlap remains blocking', function (): void {
    $actor = payrollReplayGlobalActor($this->siteA);
    $otherActor = payrollReplayGlobalActor($this->siteB);
    $worker = payrollReplayWorker($this->siteA);
    $leave = payrollReplayLeave($worker, '2026-07-06', '2026-07-06', '8.00');
    $service = app(PayrollExportService::class);
    $start = Carbon::parse('2026-07-01');
    $end = Carbon::parse('2026-07-14');

    $first = $service->createRun($start, $end, $actor->id, 'stable-create-key', 'Fortnight A');
    $replay = $service->createRun($start, $end, $actor->id, 'stable-create-key', 'Fortnight A');

    expect($replay->id)->toBe($first->id)
        ->and(HrPayrollRun::query()->count())->toBe(1)
        ->and(HrPayrollSourceUse::query()->where('source_type', 'leave')->count())->toBe(1);
    expect(fn () => $service->createRun(
        $start,
        $end,
        $actor->id,
        'stable-create-key',
        'Changed replay payload',
    ))->toThrow(InvalidArgumentException::class, 'different payload');

    $first->forceFill([
        'status' => 'exported',
        'exported_at' => now(),
        'net_paid_at' => now(),
    ])->saveQuietly();

    expect(fn () => $leave->update(['hours_requested' => '4.00']))
        ->toThrow(LogicException::class, 'claimed by an active payroll run');

    expect(fn () => $service->createRun(
        $start,
        $end,
        $otherActor->id,
        'same-period-after-paid',
    ))->toThrow(InvalidArgumentException::class, 'overlapping non-void');
    expect(fn () => $service->createRun(
        Carbon::parse('2026-07-14'),
        Carbon::parse('2026-07-20'),
        $otherActor->id,
        'partial-overlap-after-paid',
    ))->toThrow(InvalidArgumentException::class, 'overlapping non-void');
    expect(HrPayrollRun::query()->count())->toBe(1);
});

test('spanning paid leave is claimed once per worker-timezone date slice across adjacent runs', function (): void {
    $actor = payrollReplayGlobalActor($this->siteA);
    $worker = payrollReplayWorker($this->siteA);
    $leave = payrollReplayLeave($worker, '2026-06-01', '2026-06-10', '80.00');
    $service = app(PayrollExportService::class);

    $first = $service->createRun(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-05'),
        $actor->id,
        'spanning-leave-first',
    );
    $second = $service->createRun(
        Carbon::parse('2026-06-06'),
        Carbon::parse('2026-06-10'),
        $actor->id,
        'spanning-leave-second',
    );

    $uses = HrPayrollSourceUse::query()
        ->where('leave_request_id', $leave->id)
        ->orderBy('source_date')
        ->get();
    expect($uses)->toHaveCount(10)
        ->and($uses->pluck('active_source_identity')->unique())->toHaveCount(10)
        ->and($uses->reduce(fn (string $sum, $use) => bcadd($sum, (string) $use->hours, 4), '0'))
        ->toBe('80.0000')
        ->and((string) $first->items->sole()->leave_hours)->toBe('40.00')
        ->and((string) $second->items->sole()->leave_hours)->toBe('40.00');
});

test('draft correction releases immutable claims and replays one replacement with durable lineage', function (): void {
    $actor = payrollReplayGlobalActor($this->siteA);
    $worker = payrollReplayWorker($this->siteA);
    payrollReplayLeave($worker, '2026-05-05', '2026-05-08', '8.00');
    $service = app(PayrollExportService::class);
    $start = Carbon::parse('2026-05-01');
    $end = Carbon::parse('2026-05-14');
    $correctionStart = Carbon::parse('2026-05-05');
    $correctionEnd = Carbon::parse('2026-05-06');
    $source = $service->createRun($start, $end, $actor->id, 'correction-source');

    $legacyDraftPayslip = HrPayslip::query()->create([
        'payroll_run_id' => $source->id,
        'employee_profile_id' => $worker->hrEmployeeProfile->id,
        'user_id' => $worker->id,
        'pay_period_start' => $start->toDateString(),
        'pay_period_end' => $end->toDateString(),
        'gross_pay' => '0.00',
        'paye' => '0.00',
        'acc_levy' => '0.00',
        'kiwisaver_employee' => '0.00',
        'kiwisaver_employer' => '0.00',
        'student_loan' => '0.00',
        'holiday_pay' => '0.00',
        'total_deductions' => '0.00',
        'net_pay' => '0.00',
        'status' => 'draft',
        'created_by' => $actor->id,
    ]);
    expect(fn () => $service->correctRun(
        $source,
        $correctionStart,
        $correctionEnd,
        $actor->id,
        'Cannot orphan a draft payslip',
        'correction-with-payslip',
    ))->toThrow(LogicException::class, 'without payslip evidence');
    $legacyDraftPayslip->delete();

    $replacement = $service->correctRun(
        $source,
        $correctionStart,
        $correctionEnd,
        $actor->id,
        'Correct approved source selection',
        'correction-source',
    );
    $replay = $service->correctRun(
        $source,
        $correctionStart,
        $correctionEnd,
        $actor->id,
        'Correct approved source selection',
        'correction-source',
    );

    expect($source->fresh()->status)->toBe('void')
        ->and($source->fresh()->void_reason)->toBe('Correct approved source selection')
        ->and($source->sourceUses()->whereNull('active_source_identity')->count())->toBe(4)
        ->and($replacement->correction_of_run_id)->toBe($source->id)
        ->and($replacement->sourceUses()->whereNotNull('active_source_identity')->count())->toBe(2)
        ->and((string) $replacement->items->sole()->leave_hours)->toBe('4.00')
        ->and($replay->id)->toBe($replacement->id)
        ->and(HrPayrollRun::query()->count())->toBe(2);

    $releasedUse = $source->sourceUses()->firstOrFail();
    expect(fn () => $releasedUse->update(['release_reason' => 'rewrite']))
        ->toThrow(LogicException::class, 'only be released with complete correction provenance');

    $replacement->forceFill(['status' => 'exported', 'exported_at' => now(), 'net_paid_at' => now()])->saveQuietly();
    expect(fn () => $service->correctRun(
        $replacement,
        $correctionStart,
        $correctionEnd,
        $actor->id,
        'Unsafe post-payment correction',
        'paid-correction-command',
    ))->toThrow(LogicException::class, 'Finance reversal');
});

test('correction rolls back void and claim release when rebuilt source evidence fails', function (): void {
    $actor = payrollReplayGlobalActor($this->siteA);
    $worker = payrollReplayWorker($this->siteA);
    $leave = payrollReplayLeave($worker, '2026-04-07', '2026-04-07', '8.00');
    $service = app(PayrollExportService::class);
    $start = Carbon::parse('2026-04-01');
    $end = Carbon::parse('2026-04-14');
    $source = $service->createRun($start, $end, $actor->id, 'rollback-source');

    // Simulate upstream corruption outside the model guard. The correction
    // releases the old claim first, then must roll the whole transaction back
    // when no canonical approved source can rebuild the replacement.
    DB::table('hr_leave_requests')->where('id', $leave->id)->update(['status' => 'rejected']);

    expect(fn () => $service->correctRun(
        $source,
        $start,
        $end,
        $actor->id,
        'Rollback proof',
        'rollback-correction',
    ))->toThrow(InvalidArgumentException::class, 'No approved payroll sources');

    expect($source->fresh()->status)->toBe('draft')
        ->and($source->fresh()->voided_at)->toBeNull()
        ->and($source->sourceUses()->whereNotNull('active_source_identity')->count())->toBe(1)
        ->and(HrPayrollRun::query()->count())->toBe(1);
});

test('legacy paid-leave aggregates remain quarantined from release', function (): void {
    $actor = payrollReplayGlobalActor($this->siteA);
    $worker = payrollReplayWorker($this->siteA);
    $run = HrPayrollRun::factory()->create([
        'source_provenance_status' => 'legacy_unverified_paid_leave',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-14',
    ]);
    HrPayrollRunItem::query()->create([
        'payroll_run_id' => $run->id,
        'user_id' => $worker->id,
        'leave_hours' => '8.00',
        'leave_pay' => '240.00',
        'gross_pay' => '240.00',
    ]);

    expect(fn () => app(PayrollExportService::class)->lockRun($run, $actor->id))
        ->toThrow(LogicException::class, 'requires reconciliation');
    expect($run->fresh()->status)->toBe('draft');

    $run->forceFill(['status' => 'locked', 'locked_at' => now()])->saveQuietly();
    expect(fn () => app(PayrollJournalService::class)->postPayrollJournal($run->fresh()))
        ->toThrow(RuntimeException::class, 'unverified paid-leave provenance');

    $postedJournal = FinJournal::factory()->create([
        'organization_id' => $run->tenant_id,
        'type' => 'payroll',
        'source_type' => 'payroll_run',
        'source_id' => $run->id,
        'status' => 'posted',
        'posted_at' => now(),
    ]);
    expect(app(PayrollJournalService::class)->postPayrollJournal($run->fresh())->id)
        ->toBe($postedJournal->id)
        ->and($run->fresh()->journal_id)->toBe($postedJournal->id);

    $paymentJournal = FinJournal::factory()->create(['organization_id' => 1]);
    $run->forceFill([
        'payment_journal_id' => $paymentJournal->id,
    ])->saveQuietly();
    expect(app(PayrollJournalService::class)->postPayrollJournal($run->fresh())->id)
        ->toBe($postedJournal->id)
        ->and(app(PayrollJournalService::class)->postNetPayPayment($run->fresh())->id)
        ->toBe($paymentJournal->id);
});

test('payroll replay migration removes self-reference before its supporting correction index', function (): void {
    $migration = file_get_contents(database_path(
        'migrations/2026_08_23_000210_govern_payroll_source_replay.php',
    ));
    $down = substr($migration, strpos($migration, 'public function down(): void'));

    $foreign = strpos($down, "dropForeign(['correction_of_run_id'])");
    $unique = strpos($down, "dropUnique('hr_payroll_runs_correction_unique')");

    expect($foreign)->not->toBeFalse()
        ->and($unique)->not->toBeFalse()
        ->and($foreign)->toBeLessThan($unique);
});

test('two independent MySQL creators serialize to one period run', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');
    $actor = payrollReplayGlobalActor($this->siteA);
    $worker = payrollReplayWorker($this->siteA);
    $leave = payrollReplayLeave($worker, '2026-02-03', '2026-02-03', '8.00');
    $database = $connection->getDatabaseName();
    $userIds = [$actor->id, $worker->id];
    $siteIds = [$this->siteA->id, $this->siteB->id];
    $token = Str::uuid()->toString();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."payroll-create-go-{$token}";
    $readyPaths = [
        sys_get_temp_dir().DIRECTORY_SEPARATOR."payroll-create-ready-a-{$token}",
        sys_get_temp_dir().DIRECTORY_SEPARATOR."payroll-create-ready-b-{$token}",
    ];
    $processes = [];

    $connection->commit();

    try {
        $connection->beginTransaction();
        DB::table('hr_payroll_run_mutexes')
            ->where('key', 'application')
            ->lockForUpdate()
            ->first();

        foreach ($readyPaths as $index => $readyPath) {
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/PayrollRunCreationWorker.php'),
                $database,
                (string) $actor->id,
                '2026-02-01',
                '2026-02-14',
                "concurrent-payroll-{$index}",
                $readyPath,
                $releasePath,
            ]);
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }

        $deadline = microtime(true) + 20;
        while (collect($readyPaths)->contains(fn (string $path): bool => ! is_file($path))) {
            foreach ($processes as $process) {
                if (! $process->isRunning() && ! $process->isSuccessful()) {
                    $this->fail(trim($process->getErrorOutput()) ?: 'Payroll creation worker exited early.');
                }
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Timed out waiting for payroll creation workers.');
            }
            usleep(20_000);
        }

        file_put_contents($releasePath, 'go', LOCK_EX);
        usleep(250_000);
        foreach ($processes as $process) {
            expect($process->isRunning())->toBeTrue(
                'Both payroll creators must wait behind the guaranteed application mutex.',
            );
        }
        $connection->commit();

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())
                ->toBeTrue(trim($process->getErrorOutput()) ?: 'Payroll creation worker failed.');
            $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        }

        expect(collect($results)->pluck('status')->sort()->values()->all())
            ->toBe(['created', 'denied'])
            ->and(HrPayrollRun::query()->count())->toBe(1)
            ->and(HrPayrollSourceUse::query()->where('source_type', 'leave')->count())->toBe(1);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        @unlink($releasePath);
        foreach ($readyPaths as $readyPath) {
            @unlink($readyPath);
        }

        $runIds = DB::table('hr_payroll_runs')
            ->where('created_by', $actor->id)
            ->whereDate('period_start', '2026-02-01')
            ->whereDate('period_end', '2026-02-14')
            ->pluck('id');
        DB::table('audit_logs')->delete();
        DB::table('hr_payslips')->whereIn('payroll_run_id', $runIds)->delete();
        DB::table('hr_payroll_source_uses')->whereIn('payroll_run_id', $runIds)->delete();
        DB::table('hr_payroll_run_items')->whereIn('payroll_run_id', $runIds)->delete();
        DB::table('hr_payroll_runs')->whereIn('id', $runIds)->delete();
        DB::table('hr_leave_requests')->where('id', $leave->id)->delete();
        DB::table('hr_employee_profiles')->whereIn('user_id', $userIds)->delete();
        DB::table('permission_user')->whereIn('user_id', $userIds)->delete();
        DB::table('role_user')->whereIn('user_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
        DB::table('sites')->whereIn('id', $siteIds)->delete();

        $connection->beginTransaction();
    }
});
