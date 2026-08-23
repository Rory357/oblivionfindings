<?php

use App\Domain\Finance\Jobs\PostPayrollJournalJob;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\FinExternalSettlement;
use App\Domain\Finance\Models\FinExternalSettlementEvent;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\ExternalSettlementService;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    Storage::fake('private');
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
    $bankAccount = FinBankAccount::factory()->create([
        'organization_id' => 1,
        'gl_account_id' => $bankGl->id,
        'is_primary' => true,
        'is_active' => true,
    ]);

    $hr = createPayrollJournalPostingUser('hr');
    $checker = createPayrollJournalPostingUser('hr');
    createPayrollJournalPostingTimesheet($hr, 'EMP-NP-001', '2026-04-03 06:00:00');
    createPayrollJournalPostingTimesheet($hr, 'EMP-NP-002', '2026-04-04 06:00:00');

    $run = app(PayrollExportService::class)->createRun(
        periodStart: Carbon::parse('2026-04-01')->startOfDay(),
        periodEnd: Carbon::parse('2026-04-15')->endOfDay(),
        createdBy: $hr->id,
    );

    $this->actingAs($hr);
    $this->post(route('hr.payroll.runs.lock', $run))->assertRedirect();
    $run->refresh();
    expect($run->journal_id)->not->toBeNull(); // accrual journal posted on lock

    $unauthorized = createPayrollJournalPostingUser('support_worker');
    expect(fn () => app(ExternalSettlementService::class)->preparePayrollNetPay($run, $unauthorized))
        ->toThrow(HttpException::class);
    $foreignHr = createPayrollJournalPostingUser('hr');
    $foreignHr->forceFill(['organization_id' => 2])->save();
    expect(fn () => app(ExternalSettlementService::class)->preparePayrollNetPay($run, $foreignHr))
        ->toThrow(NotFoundHttpException::class);

    $totalNet = HrPayslip::query()
        ->where('payroll_run_id', $run->id)
        ->pluck('net_pay')
        ->reduce(fn (string $total, $amount): string => bcadd($total, (string) $amount, 2), '0.00');

    $this->post(route('hr.payroll.runs.prepare-net-pay', $run))->assertRedirect();
    $run->refresh();
    expect($run->net_paid_at)->toBeNull()
        ->and($run->payment_journal_id)->toBeNull()
        ->and(HrPayslip::where('payroll_run_id', $run->id)->where('status', 'paid')->count())->toBe(0);

    $settlement = $run->externalSettlement()->firstOrFail();
    $path = $settlement->artifact_path;
    $original = Storage::disk('private')->get($path);
    Storage::disk('private')->put($path, 'tampered payroll instruction');
    $this->post(route('hr.payroll.runs.prepare-net-pay', $run))
        ->assertRedirect()
        ->assertSessionHasErrors('net_pay');
    $this->get(route('hr.payroll.runs.net-pay-file', $run))
        ->assertRedirect()
        ->assertSessionHasErrors('net_pay');
    expect($settlement->fresh()->status)->toBe('prepared')
        ->and($run->fresh()->net_paid_at)->toBeNull()
        ->and($run->fresh()->payment_journal_id)->toBeNull()
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'exported')->count())->toBe(0)
        ->and(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(0)
        ->and(HrPayslip::query()->where('payroll_run_id', $run->id)->where('status', 'paid')->count())->toBe(0);

    Storage::disk('private')->put($path, $original);
    $this->get(route('hr.payroll.runs.net-pay-file', $run))
        ->assertOk()
        ->assertDownload("net-pay-run-{$run->id}.csv");
    expect($settlement->fresh()->status)->toBe('exported');

    Storage::disk('private')->delete($path);
    $this->get(route('hr.payroll.runs.net-pay-file', $run))
        ->assertRedirect()
        ->assertSessionHasErrors('net_pay');
    expect($settlement->fresh()->status)->toBe('exported')
        ->and($run->fresh()->net_paid_at)->toBeNull()
        ->and($run->fresh()->payment_journal_id)->toBeNull()
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'exported')->count())->toBe(1)
        ->and(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(0)
        ->and(HrPayslip::query()->where('payroll_run_id', $run->id)->where('status', 'paid')->count())->toBe(0);
    Storage::disk('private')->put($path, $original);

    $this->actingAs($checker)->post(route('hr.payroll.runs.reject-net-pay', $run), [
        'idempotency_key' => 'payroll-net-pay-rejected-1',
        'reference' => 'BANK-PAYROLL-REJECTED-1',
        'reason' => 'Correct employee bank details and generate a new instruction.',
        'evidence' => [
            'rejection_digest' => hash('sha256', 'bank-payroll-rejection-1'),
        ],
    ])->assertRedirect()->assertSessionHas('success');
    expect($settlement->fresh()->status)->toBe('rejected')
        ->and($settlement->fresh()->active_source_key)->toBeNull()
        ->and($run->fresh()->net_paid_at)->toBeNull()
        ->and($run->fresh()->payment_journal_id)->toBeNull()
        ->and(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(0)
        ->and(HrPayslip::query()->where('payroll_run_id', $run->id)->where('status', 'paid')->count())->toBe(0)
        ->and(Timesheet::query()->where('status', 'paid')->count())->toBe(0);

    Storage::disk('private')->delete($path);
    $this->actingAs($hr)->post(route('hr.payroll.runs.prepare-net-pay', $run))
        ->assertRedirect()
        ->assertSessionHasErrors('net_pay');
    expect(FinExternalSettlement::query()
        ->where('source_type', $run->getMorphClass())
        ->where('source_id', $run->id)
        ->where('purpose', 'payroll_net_pay')
        ->count())->toBe(1)
        ->and($run->fresh()->net_paid_at)->toBeNull()
        ->and(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(0);
    Storage::disk('private')->put($path, $original);

    $this->actingAs($hr)->post(route('hr.payroll.runs.prepare-net-pay', $run))
        ->assertRedirect()
        ->assertSessionHas('success');
    $correctedSettlement = $run->fresh()->externalSettlement()->firstOrFail();
    expect($correctedSettlement->attempt_number)->toBe(2)
        ->and($correctedSettlement->status)->toBe('prepared')
        ->and($correctedSettlement->artifact_path)->not->toBe($path)
        ->and(FinExternalSettlement::query()
            ->where('source_type', $run->getMorphClass())
            ->where('source_id', $run->id)
            ->where('purpose', 'payroll_net_pay')
            ->count())->toBe(2)
        ->and(Storage::disk('private')->exists($path))->toBeTrue()
        ->and(Storage::disk('private')->exists($correctedSettlement->artifact_path))->toBeTrue();
    expect(fn () => app(ExternalSettlementService::class)->reject(
        $run->fresh(),
        ExternalSettlementService::PAYROLL_NET_PAY,
        $checker,
        'payroll-net-pay-rejected-1',
        'BANK-PAYROLL-REJECTED-1',
        'Correct employee bank details and generate a new instruction.',
        ['rejection_digest' => hash('sha256', 'bank-payroll-rejection-1')],
    ))->toThrow(InvalidArgumentException::class, 'prior settlement attempt');
    expect($correctedSettlement->fresh()->status)->toBe('prepared');
    $this->get(route('hr.payroll.runs.net-pay-file', $run))
        ->assertOk()
        ->assertDownload("net-pay-run-{$run->id}.csv");
    expect($correctedSettlement->fresh()->status)->toBe('exported');

    $blockPayrollSettlementAudit = true;
    DB::listen(function ($query) use (&$blockPayrollSettlementAudit): void {
        if ($blockPayrollSettlementAudit
            && str_contains(strtolower($query->sql), 'insert into `audit_logs`')
            && in_array('hr.payroll_net_pay.settled', $query->bindings, true)) {
            throw new RuntimeException('Forced post-journal payroll settlement audit failure.');
        }
    });
    $this->actingAs($checker)->post(route('hr.payroll.runs.pay', $run), [
        'idempotency_key' => 'payroll-net-pay-accepted-1',
        'acceptance_reference' => 'BANK-PAYROLL-ACCEPTED-1',
        'acceptance_evidence' => [
            'confirmation_digest' => hash('sha256', 'bank-payroll-confirmation-1'),
        ],
    ])->assertRedirect()->assertSessionHas('error');
    expect($correctedSettlement->fresh()->status)->toBe('accepted')
        ->and($run->fresh()->net_paid_at)->toBeNull()
        ->and($run->fresh()->payment_journal_id)->toBeNull()
        ->and(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(0)
        ->and(HrPayslip::query()->where('payroll_run_id', $run->id)->where('status', 'paid')->count())->toBe(0)
        ->and(Timesheet::query()->where('status', 'paid')->count())->toBe(0);

    $blockPayrollSettlementAudit = false;
    $this->post(route('hr.payroll.runs.pay', $run), [
        'idempotency_key' => 'payroll-net-pay-accepted-1',
    ])->assertRedirect()->assertSessionHas('success');
    $run->refresh();

    expect($run->net_paid_at)->not->toBeNull()
        ->and($run->payment_journal_id)->not->toBeNull();

    $payJournal = FinJournal::findOrFail($run->payment_journal_id)->load('lines.account');
    $debits = $payJournal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $credits = $payJournal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');

    expect(bccomp($debits, $credits, 2))->toBe(0)
        ->and(bccomp($debits, $totalNet, 2))->toBe(0);

    // DR 2300 Accrued Wages, CR 1000 Bank
    expect((float) $payJournal->lines->firstWhere('account.code', '2300')->debit)->toBeGreaterThan(0)
        ->and((float) $payJournal->lines->firstWhere('account.code', '1000')->credit)->toBeGreaterThan(0);

    // Every payslip flipped to paid.
    expect(HrPayslip::where('payroll_run_id', $run->id)->where('status', 'paid')->count())->toBe(2)
        ->and(Timesheet::query()->where('status', 'paid')->count())->toBe(2);

    // Idempotent: the same evidence replays successfully without a second journal.
    $this->post(route('hr.payroll.runs.pay', $run), [
        'idempotency_key' => 'payroll-net-pay-accepted-1',
    ])->assertRedirect()->assertSessionHas('success');
    expect(
        FinJournal::where('type', 'payroll')
            ->where('source_type', FinExternalSettlement::class)
            ->count()
    )->toBe(1);

    $bankLine = $payJournal->lines->firstWhere('account.code', '1000');
    $clearedTransaction = FinBankTransaction::query()->create([
        'organization_id' => 1,
        'bank_account_id' => $bankAccount->id,
        'transaction_date' => now()->toDateString(),
        'amount' => bcsub('0.00', $totalNet, 2),
        'description' => 'Cleared payroll net-pay instruction',
        'reference' => 'BANK-PAYROLL-CLEARED-1',
        'source' => 'manual',
        'matched_journal_line_id' => $bankLine->id,
        'status' => 'reconciled',
    ]);
    $reconciliationPayload = [
        'idempotency_key' => 'payroll-net-pay-reconciled-1',
        'reference' => 'BANK-PAYROLL-CLEARED-1',
        'evidence' => ['digest' => hash('sha256', 'payroll-cleared-1')],
        'bank_transaction_id' => $clearedTransaction->id,
    ];
    $this->actingAs($checker)
        ->post(route('hr.payroll.runs.reconcile-net-pay', $run), $reconciliationPayload)
        ->assertRedirect()
        ->assertSessionHas('success');
    $this->post(route('hr.payroll.runs.reconcile-net-pay', $run), $reconciliationPayload)
        ->assertRedirect()
        ->assertSessionHas('success');
    expect($correctedSettlement->fresh()->status)->toBe('reconciled')
        ->and($correctedSettlement->fresh()->reconciled_bank_transaction_id)->toBe($clearedTransaction->id)
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'reconciled')->count())->toBe(1);
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
