<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCreditNote;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinTaxRate;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Domain\Finance\Services\AccountsReceivableService;
use App\Domain\Finance\Services\FinInvoiceJournalService;
use App\Domain\Finance\Services\GstReturnService;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('prepares complete invoice-basis evidence for mixed rates, bills, invoices, and signed credits', function (): void {
    $orgId = 48101;
    $rates = gstRates($orgId);
    $revenue = gstAccount($orgId, 'GST-REV-1', 'revenue');
    $expense = gstAccount($orgId, 'GST-EXP-1', 'expense');

    gstInvoice($orgId, '2026-06-10', $revenue, [
        ['description' => 'Standard sale', 'net' => '100.00', 'gst' => '15.00', 'rate' => $rates['gst']],
        ['description' => 'Zero-rated sale', 'net' => '50.00', 'gst' => '0.00', 'rate' => $rates['zero']],
        ['description' => 'Exempt sale', 'net' => '25.00', 'gst' => '0.00', 'rate' => $rates['exempt']],
    ]);
    gstBill($orgId, '2026-06-11', $expense, [
        ['description' => 'Standard purchase', 'net' => '40.00', 'gst' => '6.00', 'rate' => $rates['gst']],
        ['description' => 'Zero-rated purchase', 'net' => '10.00', 'gst' => '0.00', 'rate' => $rates['zero']],
    ]);
    gstCredit($orgId, 'receivable', '2026-06-12', $revenue, '20.00', '3.00', $rates['gst']);
    gstCredit($orgId, 'payable', '2026-06-13', $expense, '5.00', '0.75', $rates['gst']);

    $service = app(GstReturnService::class);
    $return = $service->prepareReturn($orgId, gstRequest('2026-06-01', '2026-06-30', 'invoice'));
    $replay = $service->prepareReturn($orgId, gstRequest('2026-06-01', '2026-06-30', 'invoice'));

    expect($replay->id)->toBe($return->id)
        ->and(FinGstReturn::where('organization_id', $orgId)->count())->toBe(1)
        ->and((string) $return->total_sales)->toBe('155.00')
        ->and((string) $return->total_gst_collected)->toBe('12.00')
        ->and((string) $return->total_purchases)->toBe('45.00')
        ->and((string) $return->total_gst_paid)->toBe('5.25')
        ->and((string) $return->gst_payable)->toBe('6.75')
        ->and($return->lines)->toHaveCount(7)
        ->and($return->lines->pluck('source_key')->unique())->toHaveCount(7)
        ->and($return->lines->pluck('tax_rate_id')->unique()->sort()->values()->all())
        ->toBe(collect($rates)->pluck('id')->sort()->values()->all());
});

it('recognises cumulative partial settlements on payments basis and applies hybrid timing by side', function (): void {
    $paymentsOrg = 48102;
    $paymentsRates = gstRates($paymentsOrg);
    $paymentsRevenue = gstAccount($paymentsOrg, 'GST-REV-2', 'revenue');
    $paymentsExpense = gstAccount($paymentsOrg, 'GST-EXP-2', 'expense');
    $invoice = gstInvoice($paymentsOrg, '2026-05-10', $paymentsRevenue, [
        ['description' => 'Paid sale', 'net' => '100.00', 'gst' => '15.00', 'rate' => $paymentsRates['gst']],
    ]);
    $bill = gstBill($paymentsOrg, '2026-05-11', $paymentsExpense, [
        ['description' => 'Paid purchase', 'net' => '40.00', 'gst' => '6.00', 'rate' => $paymentsRates['gst']],
    ]);
    gstAllocation($paymentsOrg, $invoice, 'receivable', '2026-06-10', '57.50');
    gstAllocation($paymentsOrg, $invoice, 'receivable', '2026-07-10', '57.50');
    gstAllocation($paymentsOrg, $bill, 'payable', '2026-06-11', '23.00');
    gstAllocation($paymentsOrg, $bill, 'payable', '2026-07-11', '23.00');

    $service = app(GstReturnService::class);
    $june = $service->prepareReturn(
        $paymentsOrg,
        gstRequest('2026-06-01', '2026-06-30', 'payments'),
    );
    $july = $service->prepareReturn(
        $paymentsOrg,
        gstRequest('2026-07-01', '2026-07-31', 'payments'),
    );

    expect((string) $june->total_sales)->toBe('50.00')
        ->and((string) $june->total_gst_collected)->toBe('7.50')
        ->and((string) $june->total_purchases)->toBe('20.00')
        ->and((string) $june->total_gst_paid)->toBe('3.00')
        ->and((string) $july->total_sales)->toBe('50.00')
        ->and((string) $july->total_gst_collected)->toBe('7.50')
        ->and((string) $july->total_purchases)->toBe('20.00')
        ->and((string) $july->total_gst_paid)->toBe('3.00');

    $hybridOrg = 48103;
    $hybridRates = gstRates($hybridOrg);
    $hybridRevenue = gstAccount($hybridOrg, 'GST-REV-3', 'revenue');
    $hybridExpense = gstAccount($hybridOrg, 'GST-EXP-3', 'expense');
    $hybridInvoice = gstInvoice($hybridOrg, '2026-06-05', $hybridRevenue, [
        ['description' => 'Hybrid sale', 'net' => '100.00', 'gst' => '15.00', 'rate' => $hybridRates['gst']],
    ]);
    $hybridBill = gstBill($hybridOrg, '2026-05-05', $hybridExpense, [
        ['description' => 'Hybrid purchase', 'net' => '40.00', 'gst' => '6.00', 'rate' => $hybridRates['gst']],
    ]);
    gstAllocation($hybridOrg, $hybridInvoice, 'receivable', '2026-07-05', '115.00');
    gstAllocation($hybridOrg, $hybridBill, 'payable', '2026-06-15', '23.00');

    $hybrid = $service->prepareReturn(
        $hybridOrg,
        gstRequest('2026-06-01', '2026-06-30', 'hybrid'),
    );

    expect((string) $hybrid->total_sales)->toBe('100.00')
        ->and((string) $hybrid->total_gst_collected)->toBe('15.00')
        ->and((string) $hybrid->total_purchases)->toBe('20.00')
        ->and((string) $hybrid->total_gst_paid)->toBe('3.00');
});

it('rejects a stale draft until its changed source evidence is prepared and reviewed again', function (): void {
    $orgId = 48108;
    $rates = gstRates($orgId);
    $revenue = gstAccount($orgId, 'GST-REV-8', 'revenue');
    gstInvoice($orgId, '2026-06-01', $revenue, [
        ['description' => 'Reviewed sale', 'net' => '100.00', 'gst' => '15.00', 'rate' => $rates['gst']],
    ]);

    $service = app(GstReturnService::class);
    $user = User::factory()->create();
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'finance.tax.manage'],
        ['description' => 'Manage GST returns'],
    );
    $user->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    $return = $service->prepareReturn(
        $orgId,
        gstRequest('2026-06-01', '2026-06-30', 'invoice'),
    );
    $reviewedDigest = $return->source_digest;

    gstInvoice($orgId, '2026-06-20', $revenue, [
        ['description' => 'Source added after review', 'net' => '20.00', 'gst' => '3.00', 'rate' => $rates['gst']],
    ]);

    $this->actingAs($user)
        ->post(route('finance.gst-returns.file', $return))
        ->assertSessionHasErrors([
            'status' => 'GST source evidence changed after preparation. Prepare and review the draft again before filing.',
        ]);

    $staleDraft = $return->fresh();
    expect($staleDraft->status)->toBe('draft')
        ->and($staleDraft->source_digest)->toBe($reviewedDigest)
        ->and($staleDraft->lines)->toHaveCount(1)
        ->and($staleDraft->filed_at)->toBeNull()
        ->and($staleDraft->filed_by)->toBeNull();

    $refreshedDraft = $service->prepareReturn(
        $orgId,
        gstRequest('2026-06-01', '2026-06-30', 'invoice'),
    );
    expect($refreshedDraft->id)->toBe($return->id)
        ->and($refreshedDraft->source_digest)->not->toBe($reviewedDigest)
        ->and($refreshedDraft->lines)->toHaveCount(2)
        ->and((string) $refreshedDraft->total_sales)->toBe('120.00')
        ->and((string) $refreshedDraft->total_gst_collected)->toBe('18.00');

    $this->actingAs($user)
        ->post(route('finance.gst-returns.file', $refreshedDraft))
        ->assertRedirect(route('finance.gst-returns.show', $refreshedDraft));

    expect($refreshedDraft->fresh()->status)->toBe('filed');
});

it('keeps filed evidence immutable and files one explicit amendment revision', function (): void {
    $orgId = 48104;
    $rates = gstRates($orgId);
    $revenue = gstAccount($orgId, 'GST-REV-4', 'revenue');
    gstInvoice($orgId, '2026-06-01', $revenue, [
        ['description' => 'Original sale', 'net' => '100.00', 'gst' => '15.00', 'rate' => $rates['gst']],
    ]);

    $service = app(GstReturnService::class);
    $user = User::factory()->create();
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'finance.tax.manage'],
        ['description' => 'Manage GST returns'],
    );
    $user->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    $original = $service->prepareReturn(
        $orgId,
        gstRequest('2026-06-01', '2026-06-30', 'invoice'),
    );
    $service->fileReturn($original, $user->id);
    $originalDigest = $original->fresh()->source_digest;

    gstInvoice($orgId, '2026-06-20', $revenue, [
        ['description' => 'Late source', 'net' => '20.00', 'gst' => '3.00', 'rate' => $rates['gst']],
    ]);

    expect(fn () => $service->prepareReturn(
        $orgId,
        gstRequest('2026-06-01', '2026-06-30', 'invoice'),
    ))->toThrow(InvalidArgumentException::class, 'explicit amendment');

    $this->actingAs($user)
        ->post(route('finance.gst-returns.amend', $original))
        ->assertRedirect();
    $amendment = FinGstReturn::query()
        ->where('supersedes_gst_return_id', $original->id)
        ->firstOrFail();
    $this->actingAs($user)
        ->post(route('finance.gst-returns.amend', $original))
        ->assertRedirect(route('finance.gst-returns.show', $amendment));
    $amendmentReplay = FinGstReturn::query()
        ->where('supersedes_gst_return_id', $original->id)
        ->firstOrFail();
    $service->fileReturn($amendment, $user->id);

    expect($amendmentReplay->id)->toBe($amendment->id)
        ->and($amendment->revision)->toBe(2)
        ->and($amendment->supersedes_gst_return_id)->toBe($original->id)
        ->and((string) $amendment->total_sales)->toBe('120.00')
        ->and($original->fresh()->status)->toBe('amended')
        ->and($original->fresh()->source_digest)->toBe($originalDigest)
        ->and($amendment->fresh()->status)->toBe('filed');
});

it('uses fractional rates and preserves one source tax component on balanced invoice and bill journals', function (): void {
    $orgId = 48105;
    $rates = gstRates($orgId);
    gstAccount($orgId, '1100', 'asset');
    gstAccount($orgId, '2200', 'liability');
    $revenue = gstAccount($orgId, '4030', 'revenue');
    gstAccount($orgId, '2000', 'liability');
    gstAccount($orgId, '2210', 'asset');
    $expense = gstAccount($orgId, '6000', 'expense');
    FinFiscalPeriod::create([
        'organization_id' => $orgId,
        'name' => 'GST source period',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);

    $invoice = app(AccountsReceivableService::class)->createInvoice($orgId, [
        'invoice_date' => '2026-06-10',
        'lines' => [[
            'description' => 'Explicit GST line',
            'unit_price' => '100.00',
            'tax_rate_id' => $rates['gst']->id,
            'account_id' => $revenue->id,
        ]],
    ]);
    $invoiceJournal = app(FinInvoiceJournalService::class)->postInvoiceJournal($invoice);
    $invoiceJournal->load('lines');
    $invoiceSourceLine = $invoiceJournal->lines->firstWhere('account_id', $revenue->id);
    $invoiceControlLine = $invoiceJournal->lines->firstWhere('account_id', FinAccount::where([
        'organization_id' => $orgId,
        'code' => '2200',
    ])->value('id'));

    $vendor = FinVendor::factory()->create(['organization_id' => $orgId]);
    $bill = app(AccountsPayableService::class)->createBill($orgId, [
        'vendor_id' => $vendor->id,
        'bill_number' => 'GST-BILL-METADATA',
        'bill_date' => '2026-06-11',
        'due_date' => '2026-07-11',
        'lines' => [[
            'description' => 'Explicit purchase GST line',
            'quantity' => 1,
            'unit_price' => '100.00',
            'gst_rate' => '15',
            'tax_rate_id' => $rates['gst']->id,
            'account_id' => $expense->id,
        ]],
    ]);
    $user = User::factory()->create(['organization_id' => $orgId]);
    $bill = app(AccountsPayableService::class)->approveBill($bill, $user->id);
    $bill->journal->load('lines');
    $billSourceLine = $bill->journal->lines->firstWhere('account_id', $expense->id);
    $billGstControlLine = $bill->journal->lines->firstWhere('account_id', FinAccount::where([
        'organization_id' => $orgId,
        'code' => '2210',
    ])->value('id'));
    $billPayableLine = $bill->journal->lines->firstWhere('account_id', FinAccount::where([
        'organization_id' => $orgId,
        'code' => '2000',
    ])->value('id'));

    expect((string) $invoice->tax_amount)->toBe('15.00')
        ->and((int) $invoice->lines->first()->tax_rate_id)->toBe($rates['gst']->id)
        ->and((int) $invoiceSourceLine->tax_rate_id)->toBe($rates['gst']->id)
        ->and((string) $invoiceSourceLine->tax_amount)->toBe('15.00')
        ->and($invoiceControlLine->tax_rate_id)->toBeNull()
        ->and((int) $billSourceLine->tax_rate_id)->toBe($rates['gst']->id)
        ->and((string) $billSourceLine->tax_amount)->toBe('15.00')
        ->and((string) $billSourceLine->debit)->toBe('100.00')
        ->and($billGstControlLine->tax_rate_id)->toBeNull()
        ->and((string) $billGstControlLine->debit)->toBe('15.00')
        ->and((string) $billPayableLine->credit)->toBe('115.00');
});

it('converges concurrent first preparation on one return and one set of source keys on MySQL', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    $orgId = 48106;
    $rates = gstRates($orgId);
    $revenue = gstAccount($orgId, 'GST-REV-6', 'revenue');
    gstInvoice($orgId, '2026-06-10', $revenue, [
        ['description' => 'Concurrent sale', 'net' => '100.00', 'gst' => '15.00', 'rate' => $rates['gst']],
    ]);

    $database = $connection->getDatabaseName();
    $connection->commit();

    try {
        $ids = concurrentGstPreparations($database, $orgId);

        expect(array_unique($ids))->toHaveCount(1)
            ->and(FinGstReturn::where('organization_id', $orgId)->count())->toBe(1)
            ->and(FinGstReturn::where('organization_id', $orgId)->firstOrFail()->lines)
            ->toHaveCount(1);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        DB::table('fin_gst_return_lines')->whereIn(
            'gst_return_id',
            FinGstReturn::where('organization_id', $orgId)->pluck('id'),
        )->delete();
        FinGstReturn::where('organization_id', $orgId)->delete();
        DB::table('fin_invoice_lines')->whereIn(
            'invoice_id',
            FinInvoice::where('organization_id', $orgId)->pluck('id'),
        )->delete();
        FinInvoice::where('organization_id', $orgId)->forceDelete();
        DB::table('fin_journal_lines')->whereIn(
            'journal_id',
            FinJournal::where('organization_id', $orgId)->pluck('id'),
        )->delete();
        FinJournal::where('organization_id', $orgId)->delete();
        FinTaxRate::where('organization_id', $orgId)->delete();
        FinAccount::where('organization_id', $orgId)->delete();
        $connection->beginTransaction();
    }
});

it('uses one ascending period-chain lock for a forced file and amend interleaving on MySQL', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    $orgId = 48107;
    $rates = gstRates($orgId);
    $revenue = gstAccount($orgId, 'GST-REV-7', 'revenue');
    $user = User::factory()->create();
    gstInvoice($orgId, '2026-06-10', $revenue, [
        ['description' => 'Original concurrent sale', 'net' => '100.00', 'gst' => '15.00', 'rate' => $rates['gst']],
    ]);

    $service = app(GstReturnService::class);
    $original = $service->prepareReturn(
        $orgId,
        gstRequest('2026-06-01', '2026-06-30', 'invoice'),
    );
    $service->fileReturn($original, $user->id);

    gstInvoice($orgId, '2026-06-20', $revenue, [
        ['description' => 'Late concurrent sale', 'net' => '20.00', 'gst' => '3.00', 'rate' => $rates['gst']],
    ]);
    $amendment = $service->prepareAmendment($original->fresh());

    $database = $connection->getDatabaseName();
    $connection->commit();

    try {
        $results = concurrentGstFileAndAmend(
            $database,
            $original->id,
            $amendment->id,
            $user->id,
        );
        $file = collect($results)->firstWhere('operation', 'file');
        $amend = collect($results)->firstWhere('operation', 'amend');

        expect(collect($results)->pluck('lock_queries')->all())->toBe([1, 1])
            ->and(strtolower((string) $file['first_lock_sql']))
            ->toContain('order by `revision` asc, `id` asc')
            ->and(strtolower((string) $amend['first_lock_sql']))
            ->toContain('order by `revision` asc, `id` asc')
            ->and($file['outcome'])->toBe('filed')
            ->and($amend['outcome'])->toBeIn(['existing_amendment', 'conflict_after_file'])
            ->and($original->fresh()->status)->toBe('amended')
            ->and($amendment->fresh()->status)->toBe('filed')
            ->and(FinGstReturn::where('organization_id', $orgId)->count())->toBe(2);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        cleanupCommittedGstFixture($orgId, $user->id);
        $connection->beginTransaction();
    }
});

/** @return array{gst:FinTaxRate,zero:FinTaxRate,exempt:FinTaxRate} */
function gstRates(int $orgId): array
{
    return [
        'gst' => FinTaxRate::create([
            'organization_id' => $orgId,
            'name' => 'GST 15%',
            'code' => "GST15-{$orgId}",
            'rate' => '0.1500',
            'type' => 'gst',
            'is_default' => true,
            'is_active' => true,
        ]),
        'zero' => FinTaxRate::create([
            'organization_id' => $orgId,
            'name' => 'Zero rated',
            'code' => "GST0-{$orgId}",
            'rate' => '0.0000',
            'type' => 'zero_rated',
            'is_default' => false,
            'is_active' => true,
        ]),
        'exempt' => FinTaxRate::create([
            'organization_id' => $orgId,
            'name' => 'Exempt',
            'code' => "EXEMPT-{$orgId}",
            'rate' => '0.0000',
            'type' => 'exempt',
            'is_default' => false,
            'is_active' => true,
        ]),
    ];
}

function gstAccount(int $orgId, string $code, string $type): FinAccount
{
    return FinAccount::factory()->create([
        'organization_id' => $orgId,
        'code' => $code,
        'name' => $code,
        'type' => $type,
        'is_active' => true,
    ]);
}

/** @param list<array{description:string,net:string,gst:string,rate:FinTaxRate}> $lines */
function gstInvoice(int $orgId, string $date, FinAccount $account, array $lines): FinInvoice
{
    $subtotal = collect($lines)->reduce(
        fn (string $sum, array $line) => bcadd($sum, $line['net'], 2),
        '0.00',
    );
    $tax = collect($lines)->reduce(
        fn (string $sum, array $line) => bcadd($sum, $line['gst'], 2),
        '0.00',
    );
    $invoice = FinInvoice::create([
        'organization_id' => $orgId,
        'invoice_number' => 'GST-INV-'.Str::uuid(),
        'invoice_date' => $date,
        'due_date' => $date,
        'client_name' => 'GST test customer',
        'subtotal' => $subtotal,
        'tax_amount' => $tax,
        'total_amount' => bcadd($subtotal, $tax, 2),
        'status' => 'sent',
    ]);

    foreach ($lines as $index => $line) {
        $invoice->lines()->create([
            'description' => $line['description'],
            'quantity' => '1.00',
            'unit_price' => $line['net'],
            'tax_rate_id' => $line['rate']->id,
            'tax_amount' => $line['gst'],
            'line_total' => bcadd($line['net'], $line['gst'], 2),
            'sort_order' => $index,
            'account_id' => $account->id,
        ]);
    }

    $journal = gstJournal($orgId, $date, $invoice);
    $invoice->update(['journal_id' => $journal->id, 'gl_posted_at' => now()]);

    return $invoice->refresh()->load('lines');
}

/** @param list<array{description:string,net:string,gst:string,rate:FinTaxRate}> $lines */
function gstBill(int $orgId, string $date, FinAccount $account, array $lines): FinBill
{
    $vendor = FinVendor::factory()->create(['organization_id' => $orgId]);
    $subtotal = collect($lines)->reduce(
        fn (string $sum, array $line) => bcadd($sum, $line['net'], 2),
        '0.00',
    );
    $tax = collect($lines)->reduce(
        fn (string $sum, array $line) => bcadd($sum, $line['gst'], 2),
        '0.00',
    );
    $bill = FinBill::create([
        'organization_id' => $orgId,
        'vendor_id' => $vendor->id,
        'bill_number' => 'GST-BILL-'.Str::uuid(),
        'status' => 'approved',
        'bill_date' => $date,
        'due_date' => $date,
        'subtotal' => $subtotal,
        'gst_amount' => $tax,
        'total_amount' => bcadd($subtotal, $tax, 2),
        'amount_paid' => '0.00',
    ]);

    foreach ($lines as $line) {
        $bill->lines()->create([
            'description' => $line['description'],
            'quantity' => '1.00',
            'unit_price' => $line['net'],
            'gst_rate' => $line['rate']->rate,
            'tax_rate_id' => $line['rate']->id,
            'gst_amount' => $line['gst'],
            'line_total' => bcadd($line['net'], $line['gst'], 2),
            'account_id' => $account->id,
        ]);
    }

    $journal = gstJournal($orgId, $date, $bill);
    $bill->update(['journal_id' => $journal->id]);

    return $bill->refresh()->load('lines');
}

function gstCredit(
    int $orgId,
    string $type,
    string $date,
    FinAccount $account,
    string $net,
    string $gst,
    FinTaxRate $rate,
): FinCreditNote {
    $credit = FinCreditNote::create([
        'organization_id' => $orgId,
        'credit_note_number' => 'GST-CN-'.Str::uuid(),
        'type' => $type,
        'status' => 'approved',
        'credit_date' => $date,
        'subtotal' => $net,
        'gst_amount' => $gst,
        'total_amount' => bcadd($net, $gst, 2),
    ]);
    $credit->lines()->create([
        'description' => 'GST credit',
        'quantity' => '1.00',
        'unit_price' => $net,
        'gst_rate' => $rate->rate,
        'tax_rate_id' => $rate->id,
        'gst_amount' => $gst,
        'line_total' => bcadd($net, $gst, 2),
        'account_id' => $account->id,
    ]);
    $journal = gstJournal($orgId, $date, $credit);
    $credit->update(['journal_id' => $journal->id]);

    return $credit->refresh()->load('lines');
}

function gstJournal(int $orgId, string $date, object $source): FinJournal
{
    return FinJournal::create([
        'organization_id' => $orgId,
        'journal_number' => 'GST-JNL-'.Str::uuid(),
        'journal_date' => $date,
        'type' => 'standard',
        'source_type' => $source->getMorphClass(),
        'source_id' => $source->getKey(),
        'status' => 'posted',
        'posted_at' => now(),
        'total_amount' => '0.00',
    ]);
}

function gstAllocation(
    int $orgId,
    FinInvoice|FinBill $document,
    string $type,
    string $date,
    string $amount,
): FinPaymentAllocation {
    $journal = gstJournal($orgId, $date, $document);
    $sourceKey = (string) Str::uuid();

    return FinPaymentAllocation::create([
        'organization_id' => $orgId,
        'type' => $type,
        'payment_date' => $date,
        'amount' => $amount,
        'allocatable_type' => $document->getMorphClass(),
        'allocatable_id' => $document->id,
        'source_type' => $journal->getMorphClass(),
        'source_id' => $journal->id,
        'settlement_source_key' => $sourceKey,
        'integrity_state' => FinPaymentAllocation::INTEGRITY_TRACEABLE,
        'journal_id' => $journal->id,
        'settlement_journal_id' => $journal->id,
    ]);
}

/** @return array{period_start:string,period_end:string,filing_frequency:string,basis:string} */
function gstRequest(string $start, string $end, string $basis): array
{
    return [
        'period_start' => $start,
        'period_end' => $end,
        'filing_frequency' => 'monthly',
        'basis' => $basis,
    ];
}

/** @return list<int> */
function concurrentGstPreparations(string $database, int $orgId): array
{
    $token = (string) Str::uuid();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."gst-release-{$token}";
    $readyPaths = [];
    $processes = [];

    try {
        foreach ([0, 1] as $index) {
            $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."gst-ready-{$index}-{$token}";
            $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
file_put_contents($argv[3], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[4])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for GST release barrier.');
    }
    usleep(10_000);
}
$return = $app->make(App\Domain\Finance\Services\GstReturnService::class)->prepareReturn(
    (int) $argv[2],
    [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'filing_frequency' => 'monthly',
        'basis' => 'invoice',
    ],
);
echo json_encode(['id' => $return->id], JSON_THROW_ON_ERROR);
PHP;
            $processes[] = new Process([
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                (string) $orgId,
                $readyPaths[$index],
                $releasePath,
            ], base_path(), [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => $database,
            ]);
            $processes[$index]->setTimeout(30);
            $processes[$index]->start();
        }

        $deadline = microtime(true) + 15;
        while (collect($readyPaths)->contains(fn (string $path) => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Concurrent GST preparation workers did not become ready.');
            }
            usleep(10_000);
        }
        touch($releasePath);

        return collect($processes)->map(function (Process $process): int {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A GST worker failed.');
            }

            return (int) json_decode(
                trim($process->getOutput()),
                true,
                flags: JSON_THROW_ON_ERROR,
            )['id'];
        })->all();
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ([...$readyPaths, $releasePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

/**
 * Release file and amend workers immediately before their first return-row
 * lock. The production contract must satisfy both paths with one identical
 * oldest-to-newest period-chain query; opposite per-row locks would issue
 * multiple lock queries and recreate the historical deadlock cycle.
 *
 * @return list<array{operation:string,outcome:string,lock_queries:int,first_lock_sql:string}>
 */
function concurrentGstFileAndAmend(
    string $database,
    int $originalId,
    int $amendmentId,
    int $userId,
): array {
    $token = (string) Str::uuid();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."gst-chain-release-{$token}";
    $readyPaths = [];
    $processes = [];

    try {
        foreach (['file', 'amend'] as $index => $operation) {
            $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."gst-chain-ready-{$index}-{$token}";
            $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = Illuminate\Support\Facades\DB::connection();
$firstLock = true;
$lockQueries = 0;
$firstLockSql = '';
$connection->beforeExecuting(function (string $query) use (
    &$firstLock,
    &$lockQueries,
    &$firstLockSql,
    $argv,
): void {
    $normalized = strtolower($query);
    if (! str_contains($normalized, 'fin_gst_returns')
        || ! str_contains($normalized, 'for update')) {
        return;
    }

    $lockQueries++;
    if (! $firstLock) {
        return;
    }

    $firstLock = false;
    $firstLockSql = $query;
    file_put_contents($argv[6], 'ready');
    $deadline = microtime(true) + 15;
    while (! is_file($argv[7])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the GST chain-lock release barrier.');
        }
        usleep(10_000);
    }
});

$service = $app->make(App\Domain\Finance\Services\GstReturnService::class);
$outcome = '';
try {
    if ($argv[2] === 'file') {
        $return = App\Domain\Finance\Models\FinGstReturn::findOrFail((int) $argv[4]);
        $service->fileReturn($return, (int) $argv[5]);
        $outcome = 'filed';
    } else {
        $return = App\Domain\Finance\Models\FinGstReturn::findOrFail((int) $argv[3]);
        $service->prepareAmendment($return);
        $outcome = 'existing_amendment';
    }
} catch (InvalidArgumentException $exception) {
    if ($argv[2] !== 'amend'
        || ! str_contains($exception->getMessage(), 'Only a filed GST return can be amended')) {
        throw $exception;
    }
    $outcome = 'conflict_after_file';
}

echo json_encode([
    'operation' => $argv[2],
    'outcome' => $outcome,
    'lock_queries' => $lockQueries,
    'first_lock_sql' => $firstLockSql,
], JSON_THROW_ON_ERROR);
PHP;
            $processes[] = new Process([
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                $operation,
                (string) $originalId,
                (string) $amendmentId,
                (string) $userId,
                $readyPaths[$index],
                $releasePath,
            ], base_path(), [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => $database,
            ]);
            $processes[$index]->setTimeout(30);
            $processes[$index]->start();
        }

        $deadline = microtime(true) + 15;
        while (collect($readyPaths)->contains(fn (string $path) => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('GST chain-lock workers did not reach the release barrier.');
            }
            usleep(10_000);
        }
        touch($releasePath);

        return collect($processes)->map(function (Process $process): array {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A GST chain-lock worker failed.');
            }

            return json_decode(
                trim($process->getOutput()),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        })->all();
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ([...$readyPaths, $releasePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

function cleanupCommittedGstFixture(int $orgId, ?int $userId = null): void
{
    DB::table('fin_gst_return_lines')->whereIn(
        'gst_return_id',
        FinGstReturn::where('organization_id', $orgId)->pluck('id'),
    )->delete();
    FinGstReturn::where('organization_id', $orgId)
        ->orderByDesc('revision')
        ->orderByDesc('id')
        ->get()
        ->each(fn (FinGstReturn $return) => $return->delete());
    DB::table('fin_invoice_lines')->whereIn(
        'invoice_id',
        FinInvoice::where('organization_id', $orgId)->pluck('id'),
    )->delete();
    FinInvoice::where('organization_id', $orgId)->forceDelete();
    DB::table('fin_journal_lines')->whereIn(
        'journal_id',
        FinJournal::where('organization_id', $orgId)->pluck('id'),
    )->delete();
    FinJournal::where('organization_id', $orgId)->delete();
    FinTaxRate::where('organization_id', $orgId)->delete();
    FinAccount::where('organization_id', $orgId)->delete();

    if ($userId !== null) {
        User::whereKey($userId)->delete();
    }
}
