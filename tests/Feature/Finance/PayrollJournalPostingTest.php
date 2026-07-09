<?php

use App\Domain\Finance\Jobs\PostPayrollJournalJob;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\PayrollJournalService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayslip;
use App\Domain\Hr\Services\PayrollExportService;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

it('posts one balanced payroll journal with allocations when a payroll run is locked', function () {
    createPayrollJournalPostingAccounts();
    createPayrollJournalPostingOpenPeriod();

    $hr = createPayrollJournalPostingUser('hr');
    createPayrollJournalPostingTimesheet($hr, 'EMP-PGL-001', '2026-04-03 06:00:00');
    createPayrollJournalPostingTimesheet($hr, 'EMP-PGL-002', '2026-04-04 06:00:00');

    $run = app(PayrollExportService::class)->createRun(
        tenantId: 1,
        periodStart: Carbon::parse('2026-04-01')->startOfDay(),
        periodEnd: Carbon::parse('2026-04-15')->endOfDay(),
        createdBy: $hr->id,
    );

    expect($run->items)->toHaveCount(2);

    $this->actingAs($hr);

    // Locking now generates the payslips itself (no manual pre-generation).
    $this->post(route('hr.payroll.runs.lock', $run))
        ->assertRedirect();

    $payslips = HrPayslip::where('payroll_run_id', $run->id)->get();
    expect($payslips)->toHaveCount(2);
    foreach ($payslips as $payslip) {
        expect(round((float) $payslip->net_pay, 2))
            ->toBe(round((float) $payslip->gross_pay + (float) $payslip->holiday_pay - (float) $payslip->total_deductions, 2));
    }
    // Payslip gross ties to the run total (gross-parity override).
    $run->refresh();
    expect(round((float) $payslips->sum('gross_pay'), 2))
        ->toBe(round((float) $run->total_gross, 2));

    $run->refresh();
    $journal = FinJournal::query()
        ->where('organization_id', 1)
        ->where('type', 'payroll')
        ->where('source_type', 'payroll_run')
        ->where('source_id', $run->id)
        ->firstOrFail()
        ->load(['lines.account']);

    $debits = $journal->lines->reduce(
        fn (string $total, $line) => bcadd($total, (string) $line->debit, 2),
        '0'
    );
    $credits = $journal->lines->reduce(
        fn (string $total, $line) => bcadd($total, (string) $line->credit, 2),
        '0'
    );

    expect($run->status)->toBe('locked')
        ->and($run->journal_id)->toBe($journal->id)
        ->and($run->gl_posted_at)->not->toBeNull()
        ->and($run->cost_allocated_at)->not->toBeNull()
        ->and($run->oncost_allocated_at)->not->toBeNull()
        ->and($journal->status)->toBe('posted')
        ->and(bccomp($debits, $credits, 2))->toBe(0);

    foreach (['5000', '5010', '5020', '2100', '2110', '2120', '2130', '2300'] as $code) {
        expect($journal->lines->firstWhere('account.code', $code))->not->toBeNull();
    }

    $allocations = FinCostAllocation::query()
        ->where('journal_id', $journal->id)
        ->get();

    expect($allocations->where('event_type', 'payroll_cost'))->toHaveCount(2)
        ->and($allocations->where('event_type', 'employer_oncost'))->toHaveCount(2)
        ->and($allocations->whereNull('site_id'))->toHaveCount(0)
        ->and($allocations->whereNull('shift_id'))->toHaveCount(0);

    PostPayrollJournalJob::dispatchSync($run->fresh());
    app(PayrollJournalService::class)->postPayrollJournal($run->fresh());

    expect(FinJournal::where('type', 'payroll')->where('source_id', $run->id)->count())->toBe(1)
        ->and(FinCostAllocation::where('journal_id', $journal->id)->count())->toBe($allocations->count());
});

it('pays employee net pay, clearing accrued wages against the bank (idempotently)', function () {
    createPayrollJournalPostingAccounts();
    createPayrollJournalPostingOpenPeriod();

    $bankGl = FinAccount::factory()->create([
        'organization_id' => 1,
        'code' => '1000',
        'name' => 'Bank',
        'type' => 'asset',
        'opening_balance' => 0,
        'is_active' => true,
    ]);
    FinBankAccount::factory()->create([
        'organization_id' => 1,
        'gl_account_id' => $bankGl->id,
        'is_primary' => true,
        'is_active' => true,
    ]);

    $hr = createPayrollJournalPostingUser('hr');
    createPayrollJournalPostingTimesheet($hr, 'EMP-NP-001', '2026-04-03 06:00:00');
    createPayrollJournalPostingTimesheet($hr, 'EMP-NP-002', '2026-04-04 06:00:00');

    $run = app(PayrollExportService::class)->createRun(
        tenantId: 1,
        periodStart: Carbon::parse('2026-04-01')->startOfDay(),
        periodEnd: Carbon::parse('2026-04-15')->endOfDay(),
        createdBy: $hr->id,
    );

    $this->actingAs($hr);
    $this->post(route('hr.payroll.runs.lock', $run))->assertRedirect();
    $run->refresh();
    expect($run->journal_id)->not->toBeNull(); // accrual journal posted on lock

    $totalNet = (float) HrPayslip::where('payroll_run_id', $run->id)->sum('net_pay');

    $this->post(route('hr.payroll.runs.pay', $run))->assertRedirect();
    $run->refresh();

    expect($run->net_paid_at)->not->toBeNull()
        ->and($run->payment_journal_id)->not->toBeNull();

    $payJournal = FinJournal::findOrFail($run->payment_journal_id)->load('lines.account');
    $debits = $payJournal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $credits = $payJournal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');

    expect(bccomp($debits, $credits, 2))->toBe(0)
        ->and(bccomp($debits, (string) round($totalNet, 2), 2))->toBe(0);

    // DR 2300 Accrued Wages, CR 1000 Bank
    expect((float) $payJournal->lines->firstWhere('account.code', '2300')->debit)->toBeGreaterThan(0)
        ->and((float) $payJournal->lines->firstWhere('account.code', '1000')->credit)->toBeGreaterThan(0);

    // Every payslip flipped to paid.
    expect(HrPayslip::where('payroll_run_id', $run->id)->where('status', 'paid')->count())->toBe(2);

    // Idempotent: a second pay is blocked and posts no second journal.
    $this->post(route('hr.payroll.runs.pay', $run))->assertRedirect();
    expect(
        FinJournal::where('type', 'payroll')
            ->where('source_type', 'payroll_net_pay')
            ->where('source_id', $run->id)
            ->count()
    )->toBe(1);
});

function createPayrollJournalPostingAccounts(): void
{
    foreach ([
        '5000' => ['Wages & Salaries', 'expense'],
        '5010' => ['KiwiSaver Employer', 'expense'],
        '5020' => ['ACC Employer Levy', 'expense'],
        '2100' => ['PAYE Payable', 'liability'],
        '2110' => ['ACC Levy Payable', 'liability'],
        '2120' => ['KiwiSaver Payable', 'liability'],
        '2130' => ['Student Loan Payable', 'liability'],
        '2150' => ['ESCT Payable', 'liability'],
        '2300' => ['Accrued Wages', 'liability'],
    ] as $code => [$name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'opening_balance' => 0,
            'is_active' => true,
        ]);
    }
}

function createPayrollJournalPostingOpenPeriod(): FinFiscalPeriod
{
    return FinFiscalPeriod::create([
        'organization_id' => 1,
        'name' => 'FY2026 Payroll',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);
}

function createPayrollJournalPostingUser(string $roleName): User
{
    $user = User::factory()->create([
        'organization_id' => 1,
        'role' => $roleName,
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', $roleName)->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    return $user;
}

function createPayrollJournalPostingTimesheet(User $hr, string $employeeNumber, string $startsAt): Timesheet
{
    $worker = createPayrollJournalPostingUser('support_worker');
    $site = Site::factory()->create(['type' => 'house']);
    $serviceContext = ServiceContext::factory()->create([
        'name' => 'Residential Support',
        'type' => 'residential',
        'is_active' => true,
    ]);
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'service_context_id' => $serviceContext->id,
        'status' => 'active',
    ]);
    $start = Carbon::parse($startsAt);
    $end = $start->copy()->addHours(16);

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $worker->id,
        'employee_number' => $employeeNumber,
        'work_email' => $worker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'pay_frequency' => 'fortnightly',
        'tax_code' => 'M SL',
        'kiwisaver_rate' => 3,
        'start_date' => '2025-01-01',
        'hourly_rate' => '80.00',
        'is_active' => true,
        'primary_site_id' => $site->id,
        'created_by' => $hr->id,
        'updated_by' => $hr->id,
    ]);

    $shift = Shift::factory()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => $worker->id,
        'starts_at' => $start,
        'ends_at' => $end,
        'expected_break_minutes' => 0,
        'status' => 'scheduled',
        'created_by' => $hr->id,
    ]);

    return Timesheet::factory()->approved()->create([
        'shift_id' => $shift->id,
        'user_id' => $worker->id,
        'client_id' => $client->id,
        'shift_site_id' => $site->id,
        'shift_service_context_id' => $serviceContext->id,
        'work_date' => $start->toDateString(),
        'starts_at' => $start,
        'ends_at' => $end,
        'break_minutes' => 0,
        'approved_by' => $hr->id,
        'created_by' => $worker->id,
        'shift_site_name_snapshot' => $site->name,
        'shift_location_snapshot' => 'Payroll House',
        'service_context_name_snapshot' => $serviceContext->name,
        'client_name_snapshot' => $client->full_name,
        'staff_name_snapshot' => $worker->name,
        'shift_type_snapshot' => 'standard',
        'coverage_roles_snapshot' => ['support_worker'],
    ]);
}
