<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinBillLine;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinExternalSettlement;
use App\Domain\Finance\Models\FinExternalSettlementEvent;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPaymentRunItem;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Domain\Finance\Services\ExternalSettlementService;
use App\Domain\Finance\Services\PaymentRunService;
use App\Domain\Finance\Services\PaymentSettlementSiteScope;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * End-to-end lock-in for bill approval (DR Expense / CR AP) and the separate
 * externally accepted payment-run settlement (DR AP / CR Bank). Preparing or
 * downloading the bank instruction must never pay a bill or post GL.
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
    $creator = User::factory()->create(['organization_id' => 1]);
    $approver = User::factory()->create(['organization_id' => 1]);
    $run = FinPaymentRun::factory()->create([
        'organization_id' => 1,
        'status' => 'approved',
        'payment_date' => now()->subDay(),
        'total_amount' => '300.00',
        'item_count' => 1,
        'bank_account_id' => $bankAccount->id,
        'created_by' => $creator->id,
        'approved_by' => $approver->id,
        'approved_at' => now(),
    ]);
    FinPaymentRunItem::create([
        'payment_run_id' => $run->id,
        'site_id' => $site->id,
        'bill_id' => $bill->id,
        'settlement_bill_id' => $bill->id,
        'active_settlement_bill_id' => $bill->id,
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

it('processing prepares an immutable bank file without paying bills or posting GL', function () {
    Storage::fake('local');
    seedApJournalAccounts();
    $run = approvedPaymentRun();
    $user = apGlobalPaymentManager();

    $result = app(PaymentRunService::class)->processPaymentRun($run, $user);

    expect($result->status)->toBe('prepared')
        ->and($result->journal_id)->toBeNull()
        ->and(FinBill::query()->findOrFail($run->items()->value('bill_id'))->status)->toBe('approved')
        ->and(FinPaymentAllocation::query()->count())->toBe(0)
        ->and(FinJournal::where('source_type', FinExternalSettlement::class)->count())->toBe(0);
    Storage::disk('local')->assertExists($result->file_path);
});

it('settles one bank-accepted payment run with exact balanced GL and stable replay', function () {
    Storage::fake('local');
    seedApJournalAccounts();
    $run = approvedPaymentRun();
    $preparer = apGlobalPaymentManager();
    $checker = apGlobalPaymentManager();
    $service = app(PaymentRunService::class);
    $settlements = app(ExternalSettlementService::class);

    $service->processPaymentRun($run, $preparer);
    $settlements->markExported($run, ExternalSettlementService::PAYMENT_RUN, $preparer);
    $settlements->accept(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $checker,
        'accept-payment-run-1',
        'BANK-ACCEPTED-1',
        ['confirmation_digest' => hash('sha256', 'bank-confirmation-1')],
    );
    $result = $settlements->settlePaymentRun($run, $checker, 'settle-payment-run-1');

    expect($result->status)->toBe('settled')
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

    expect($settlements->settlePaymentRun($run->fresh(), $checker, 'settle-payment-run-1')->journal_id)
        ->toBe($journal->id)
        ->and(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(1);
});

it('re-preparing a payment run returns the same occurrence and posts no journal', function () {
    Storage::fake('local');
    seedApJournalAccounts();
    $run = approvedPaymentRun();
    $user = apGlobalPaymentManager();
    $service = app(PaymentRunService::class);

    $service->processPaymentRun($run, $user);

    $replay = $service->processPaymentRun($run->fresh(), $user);

    expect($replay->status)->toBe('prepared')
        ->and(FinExternalSettlement::query()->where('source_id', $run->id)->count())->toBe(1)
        ->and(FinJournal::where('source_type', FinExternalSettlement::class)->count())->toBe(0);
});

it('fails closed when a prepared or exported payment file is tampered with or missing', function () {
    Storage::fake('local');
    seedApJournalAccounts();
    $run = approvedPaymentRun();
    $preparer = apGlobalPaymentManager();
    $checker = apGlobalPaymentManager();
    $service = app(PaymentRunService::class);
    $settlements = app(ExternalSettlementService::class);
    $service->processPaymentRun($run, $preparer);
    $path = $run->fresh()->file_path;
    $original = Storage::disk('local')->get($path);
    $billId = $run->items()->value('bill_id');

    Storage::disk('local')->put($path, 'tampered bank instruction');
    expect(fn () => $service->processPaymentRun($run->fresh(), $preparer))
        ->toThrow(InvalidArgumentException::class);
    $this->actingAs($preparer)
        ->get(route('finance.payment-runs.download', $run))
        ->assertRedirect()
        ->assertSessionHasErrors('payment_run');

    expect($run->fresh()->status)->toBe('prepared')
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'exported')->count())->toBe(0)
        ->and(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(0)
        ->and(FinPaymentAllocation::query()->count())->toBe(0)
        ->and((string) FinBill::query()->findOrFail($billId)->amount_paid)->toBe('0.00');

    Storage::disk('local')->put($path, $original);
    $this->get(route('finance.payment-runs.download', $run))
        ->assertOk()
        ->assertDownload($run->run_number.'.csv');
    expect($run->fresh()->status)->toBe('exported')
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'exported')->count())->toBe(1);

    Storage::disk('local')->put($path, 'tampered after export');
    expect(fn () => $settlements->accept(
        $run->fresh(),
        ExternalSettlementService::PAYMENT_RUN,
        $checker,
        'accept-tampered-payment-file',
        'BANK-MUST-NOT-ACCEPT',
        ['digest' => hash('sha256', 'must-not-accept')],
    ))->toThrow(InvalidArgumentException::class);
    expect($run->fresh()->status)->toBe('exported')
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'accepted')->count())->toBe(0);

    Storage::disk('local')->delete($path);
    expect(fn () => $settlements->markExported($run->fresh(), ExternalSettlementService::PAYMENT_RUN, $preparer))
        ->toThrow(InvalidArgumentException::class);
    $this->get(route('finance.payment-runs.download', $run))
        ->assertRedirect()
        ->assertSessionHasErrors('payment_run');

    Storage::disk('local')->put($path, $original);
    DB::table('fin_external_settlements')
        ->where('source_id', $run->id)
        ->update(['artifact_path' => "finance/payment-runs/../{$run->run_number}.csv"]);
    $this->get(route('finance.payment-runs.download', $run))
        ->assertRedirect()
        ->assertSessionHasErrors('payment_run');

    expect($run->fresh()->status)->toBe('exported')
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'exported')->count())->toBe(1)
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'accepted')->count())->toBe(0)
        ->and(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(0)
        ->and(FinPaymentAllocation::query()->count())->toBe(0)
        ->and((string) FinBill::query()->findOrFail($billId)->amount_paid)->toBe('0.00');
});

it('rolls back payment-run preparation when bank-file storage fails', function () {
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

it('deletes a written bank file and rolls back DB effects when the preparation audit fails', function () {
    Storage::fake('local');
    seedApJournalAccounts();
    $run = approvedPaymentRun();
    $billId = $run->items()->value('bill_id');
    $path = "finance/payment-runs/{$run->run_number}.csv";
    $blockCompletionAudit = true;

    DB::listen(function ($query) use (&$blockCompletionAudit): void {
        if ($blockCompletionAudit
            && str_contains(strtolower($query->sql), 'insert into `audit_logs`')
            && in_array('finance.payment_run.prepared', $query->bindings, true)) {
            throw new RuntimeException('Forced payment-run preparation audit failure.');
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
        ->and(DB::table('audit_logs')->where('action', 'finance.payment_run.prepared')->count())->toBe(0);
});

it('conceals wrong-Site payment-run IDs and lifecycle transitions while explicit global authority passes', function () {
    Storage::fake('local');
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
    $scopeOnly = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $globalScope = Permission::query()->firstOrCreate(
        ['key' => PaymentSettlementSiteScope::GLOBAL_PERMISSION],
        ['description' => PaymentSettlementSiteScope::GLOBAL_PERMISSION],
    );
    $scopeOnly->permissionOverrides()->syncWithoutDetaching([
        $globalScope->id => ['allowed' => true],
    ]);

    $this->actingAs($scopeOnly)
        ->post(route('finance.payment-runs.process', $run))
        ->assertForbidden();
    expect(fn () => app(PaymentRunService::class)->processPaymentRun($run, $scopeOnly))
        ->toThrow(HttpException::class);

    foreach ([$run->items()->value('bill_id'), PHP_INT_MAX] as $concealedBillId) {
        $this->actingAs($siteUser)
            ->post(route('finance.payment-runs.store'), [
                'bank_account_id' => $run->bank_account_id,
                'payment_date' => now()->toDateString(),
                'bill_ids' => [$concealedBillId],
            ])
            ->assertNotFound();
    }
    expect(FinPaymentRun::query()->count())->toBe(1);
    $this->actingAs($siteUser)
        ->post(route('finance.payment-runs.process', $run))
        ->assertNotFound();
    $this->actingAs($siteUser)
        ->get(route('finance.payment-runs.download', $run))
        ->assertNotFound();

    expect($run->fresh()->status)->toBe('approved')
        ->and(FinPaymentAllocation::query()->count())->toBe(0);

    $preparer = apGlobalPaymentManager();
    $checker = apGlobalPaymentManager();
    $this->actingAs($preparer)
        ->post(route('finance.payment-runs.process', $run))
        ->assertRedirect()
        ->assertSessionHas('success');
    $this->get(route('finance.payment-runs.download', $run))
        ->assertOk();

    $evidence = [
        'idempotency_key' => 'wrong-site-settlement-transition',
        'reference' => 'BANK-WRONG-SITE-DENIED',
        'evidence' => ['digest' => hash('sha256', 'wrong-site-denied')],
    ];
    $externalSettlements = app(ExternalSettlementService::class);
    expect(fn () => $externalSettlements->accept(
        $run->fresh(),
        ExternalSettlementService::PAYMENT_RUN,
        $siteUser,
        $evidence['idempotency_key'],
        $evidence['reference'],
        $evidence['evidence'],
    ))->toThrow(NotFoundHttpException::class);
    expect(fn () => $externalSettlements->reject(
        $run->fresh(),
        ExternalSettlementService::PAYMENT_RUN,
        $siteUser,
        $evidence['idempotency_key'],
        $evidence['reference'],
        'Must be concealed before mutation.',
        $evidence['evidence'],
    ))->toThrow(NotFoundHttpException::class);
    expect(fn () => $externalSettlements->settlePaymentRun(
        $run->fresh(),
        $siteUser,
        'wrong-site-settle-denied',
    ))->toThrow(NotFoundHttpException::class);
    expect(fn () => $externalSettlements->reconcile(
        $run->fresh(),
        ExternalSettlementService::PAYMENT_RUN,
        1,
        $siteUser,
        $evidence['idempotency_key'],
        $evidence['reference'],
        $evidence['evidence'],
    ))->toThrow(NotFoundHttpException::class);
    $this->actingAs($siteUser)
        ->post(route('finance.payment-runs.accept', $run), $evidence)
        ->assertNotFound();
    $this->post(route('finance.payment-runs.reject', $run), [
        ...$evidence,
        'reason' => 'Must be concealed before mutation.',
    ])->assertNotFound();
    $this->post(route('finance.payment-runs.settle', $run), [
        'idempotency_key' => 'wrong-site-settle-denied',
    ])->assertNotFound();
    $this->post(route('finance.payment-runs.reconcile', $run), [
        ...$evidence,
        'bank_transaction_id' => 1,
    ])->assertNotFound();

    expect($run->fresh()->status)->toBe('exported')
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'accepted')->count())->toBe(0)
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'rejected')->count())->toBe(0)
        ->and(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(0)
        ->and(FinPaymentAllocation::query()->count())->toBe(0);

    $this->actingAs($checker)
        ->post(route('finance.payment-runs.accept', $run), [
            'idempotency_key' => 'global-settlement-accept',
            'reference' => 'BANK-GLOBAL-ACCEPTED',
            'evidence' => ['digest' => hash('sha256', 'global-accepted')],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');
    expect($run->fresh()->status)->toBe('accepted');
    expect(fn () => $externalSettlements->accept(
        $run->fresh(),
        ExternalSettlementService::PAYMENT_RUN,
        $siteUser,
        'global-settlement-accept',
        'BANK-GLOBAL-ACCEPTED',
        ['digest' => hash('sha256', 'global-accepted')],
    ))->toThrow(NotFoundHttpException::class);
    expect(fn () => $externalSettlements->accept(
        $run->fresh(),
        ExternalSettlementService::PAYMENT_RUN,
        $scopeOnly,
        'global-settlement-accept',
        'BANK-GLOBAL-ACCEPTED',
        ['digest' => hash('sha256', 'global-accepted')],
    ))->toThrow(HttpException::class);

    $runSite->update(['is_active' => false]);
    $this->actingAs($checker)
        ->get(route('finance.payment-runs.download', $run))
        ->assertNotFound();
});
