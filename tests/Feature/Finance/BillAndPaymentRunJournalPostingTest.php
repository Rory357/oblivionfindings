<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinBillLine;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPaymentRunItem;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Domain\Finance\Services\PaymentRunService;
use App\Domain\Finance\Services\PaymentSettlementSiteScope;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * End-to-end lock-in for the two AP money pipelines that post a GL journal but
 * had no journal-posting coverage: bill approval (DR Expense / CR AP) and payment
 * run processing (DR AP / CR Bank). Both must post a BALANCED journal, and both
 * are idempotent-by-state-machine — replaying the action throws (the status has
 * already advanced) and never posts a second journal.
 */
function seedApJournalAccounts(): void
{
    foreach ([['1000', 'Bank', 'asset'], ['2000', 'Accounts Payable', 'liability'], ['6000', 'Supplies', 'expense']] as [$code, $name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => 1, 'code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true,
        ]);
    }

    FinFiscalPeriod::create([
        'organization_id' => 1,
        'name' => 'FY',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);
}

/** @return array{0:string,1:string} [debits, credits] summed to 2dp. */
function journalTotals(FinJournal $journal): array
{
    $journal->loadMissing('lines.account');

    return [
        $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0'),
        $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0'),
    ];
}

function draftBillWithLine(): FinBill
{
    $expense = FinAccount::where('organization_id', 1)->where('code', '6000')->firstOrFail();
    $vendor = FinVendor::factory()->create(['organization_id' => 1]);
    $bill = FinBill::factory()->create([
        'organization_id' => 1,
        'vendor_id' => $vendor->id,
        'status' => 'draft',
        'bill_date' => now()->subDays(2),
        'total_amount' => '500.00',
        'amount_paid' => 0,
    ]);
    FinBillLine::create([
        'bill_id' => $bill->id,
        'description' => 'Supplies',
        'quantity' => 1,
        'unit_price' => '500.00',
        'gst_rate' => 0,
        'gst_amount' => 0,
        'line_total' => '500.00',
        'account_id' => $expense->id,
    ]);

    return $bill;
}

it('approving a bill posts a single balanced DR Expense / CR AP journal', function () {
    seedApJournalAccounts();
    $bill = draftBillWithLine();
    $user = User::factory()->create(['organization_id' => 1]);

    $result = app(AccountsPayableService::class)->approveBill($bill, $user->id);

    expect($result->status)->toBe('approved')
        ->and($result->journal_id)->not->toBeNull();

    $journal = FinJournal::findOrFail($result->journal_id);
    [$debits, $credits] = journalTotals($journal);
    $dr = $journal->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0);
    $cr = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);

    expect($journal->status)->toBe('posted')
        ->and(bccomp($debits, $credits, 2))->toBe(0)
        ->and($debits)->toBe('500.00')
        ->and($dr->account->code)->toBe('6000')   // expense
        ->and($cr->account->code)->toBe('2000');  // accounts payable
});

it('re-approving a bill throws and posts no second journal', function () {
    seedApJournalAccounts();
    $bill = draftBillWithLine();
    $user = User::factory()->create(['organization_id' => 1]);
    $service = app(AccountsPayableService::class);

    $service->approveBill($bill, $user->id);

    expect(fn () => $service->approveBill($bill->fresh(), $user->id))
        ->toThrow(InvalidArgumentException::class);

    expect(FinJournal::where('source_type', FinBill::class)->where('source_id', $bill->id)->count())->toBe(1);
});

function approvedPaymentRun(): FinPaymentRun
{
    $site = Site::factory()->create();
    $bankGl = FinAccount::where('organization_id', 1)->where('code', '1000')->firstOrFail();
    $bankAccount = FinBankAccount::factory()->create([
        'organization_id' => 1,
        'gl_account_id' => $bankGl->id,
    ]);
    $vendor = FinVendor::factory()->create(['organization_id' => 1]);
    $bill = FinBill::factory()->create([
        'organization_id' => 1,
        'vendor_id' => $vendor->id,
        'site_id' => $site->id,
        'status' => 'approved',
        'total_amount' => '300.00',
        'amount_paid' => 0,
    ]);
    $run = FinPaymentRun::factory()->create([
        'organization_id' => 1,
        'status' => 'approved',
        'payment_date' => now()->subDay(),
        'item_count' => 1,
        'bank_account_id' => $bankAccount->id,
    ]);
    FinPaymentRunItem::create([
        'payment_run_id' => $run->id,
        'site_id' => $site->id,
        'bill_id' => $bill->id,
        'settlement_bill_id' => $bill->id,
        'vendor_id' => $vendor->id,
        'amount' => '300.00',
        'reference' => 'REF-1',
        'status' => 'pending',
        'bank_account_number' => '12-3456-7890123-00',
    ]);

    return $run;
}

function apGlobalPaymentManager(): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    foreach (['finance.ap.view', 'finance.ap.manage', PaymentSettlementSiteScope::GLOBAL_PERMISSION] as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key],
        );
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    return $user;
}

it('processing a payment run posts a single balanced DR AP / CR Bank journal', function () {
    Storage::fake('local');
    seedApJournalAccounts();
    $run = approvedPaymentRun();
    $user = apGlobalPaymentManager();

    $result = app(PaymentRunService::class)->processPaymentRun($run, $user);

    expect($result->status)->toBe('completed')
        ->and($result->journal_id)->not->toBeNull();

    $journal = FinJournal::findOrFail($result->journal_id);
    [$debits, $credits] = journalTotals($journal);
    $dr = $journal->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0);
    $cr = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);

    expect(bccomp($debits, $credits, 2))->toBe(0)
        ->and($debits)->toBe('300.00')
        ->and($dr->account->code)->toBe('2000')   // accounts payable
        ->and($cr->account->code)->toBe('1000')   // bank
        ->and(FinPaymentAllocation::query()
            ->where('source_type', FinPaymentRunItem::class)
            ->where('integrity_state', FinPaymentAllocation::INTEGRITY_TRACEABLE)
            ->count())->toBe(1);
});

it('re-processing a payment run throws and posts no second journal', function () {
    Storage::fake('local');
    seedApJournalAccounts();
    $run = approvedPaymentRun();
    $user = apGlobalPaymentManager();
    $service = app(PaymentRunService::class);

    $service->processPaymentRun($run, $user);

    expect(fn () => $service->processPaymentRun($run->fresh(), $user))
        ->toThrow(InvalidArgumentException::class);

    expect(FinJournal::where('source_type', FinPaymentRun::class)->where('source_id', $run->id)->count())->toBe(1);
});

it('rolls back bill allocation journal and run state when bank-file storage fails', function () {
    seedApJournalAccounts();
    $run = approvedPaymentRun();
    $billId = $run->items()->value('bill_id');
    $disk = Mockery::mock();
    $disk->shouldReceive('put')->once()->andReturn(false);
    $disk->shouldReceive('delete')->once()->andReturn(true);
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    expect(fn () => app(PaymentRunService::class)->processPaymentRun($run, apGlobalPaymentManager()))
        ->toThrow(RuntimeException::class);

    expect($run->fresh()->status)->toBe('approved')
        ->and($run->fresh()->journal_id)->toBeNull()
        ->and(FinBill::query()->findOrFail($billId)->status)->toBe('approved')
        ->and((string) FinBill::query()->findOrFail($billId)->amount_paid)->toBe('0.00')
        ->and(FinPaymentAllocation::query()->count())->toBe(0)
        ->and(FinJournal::where('source_type', FinPaymentRun::class)->count())->toBe(0);
});

it('deletes a written bank file and rolls back DB effects when the later completion audit fails', function () {
    Storage::fake('local');
    seedApJournalAccounts();
    $run = approvedPaymentRun();
    $billId = $run->items()->value('bill_id');
    $path = "finance/payment-runs/{$run->run_number}.csv";
    $blockCompletionAudit = true;

    DB::listen(function ($query) use (&$blockCompletionAudit): void {
        if ($blockCompletionAudit
            && str_contains(strtolower($query->sql), 'insert into `audit_logs`')
            && in_array('finance.payment_run.completed', $query->bindings, true)) {
            throw new RuntimeException('Forced payment-run completion audit failure.');
        }
    });

    try {
        expect(fn () => app(PaymentRunService::class)->processPaymentRun($run, apGlobalPaymentManager()))
            ->toThrow(RuntimeException::class);
    } finally {
        $blockCompletionAudit = false;
    }

    Storage::disk('local')->assertMissing($path);
    expect($run->fresh()->status)->toBe('approved')
        ->and($run->fresh()->journal_id)->toBeNull()
        ->and($run->fresh()->file_path)->toBeNull()
        ->and(FinBill::query()->findOrFail($billId)->status)->toBe('approved')
        ->and((string) FinBill::query()->findOrFail($billId)->amount_paid)->toBe('0.00')
        ->and(FinPaymentAllocation::query()->count())->toBe(0)
        ->and(FinJournal::where('source_type', FinPaymentRun::class)->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'finance.payment_run.completed')->count())->toBe(0);
});

it('conceals wrong-Site payment-run processing and download while explicit global authority passes the scope', function () {
    seedApJournalAccounts();
    $run = approvedPaymentRun();
    $runSite = Site::query()->findOrFail($run->items()->value('site_id'));
    $otherSite = Site::factory()->create();
    $siteUser = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($siteUser, $otherSite);
    foreach (['finance.ap.view', 'finance.ap.manage'] as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key]);
        $siteUser->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    $this->actingAs($siteUser)
        ->post(route('finance.payment-runs.process', $run))
        ->assertNotFound();
    $this->actingAs($siteUser)
        ->get(route('finance.payment-runs.download', $run))
        ->assertNotFound();

    expect($run->fresh()->status)->toBe('approved')
        ->and(FinPaymentAllocation::query()->count())->toBe(0);

    $global = apGlobalPaymentManager();
    $this->actingAs($global)
        ->get(route('finance.payment-runs.download', $run))
        ->assertRedirect()
        ->assertSessionHasErrors('payment_run');

    $runSite->update(['is_active' => false]);
    $this->actingAs($global)
        ->get(route('finance.payment-runs.download', $run))
        ->assertNotFound();
});
