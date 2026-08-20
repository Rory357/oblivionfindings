<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinPaymentMatch;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPaymentRunItem;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Domain\Finance\Services\AccountsReceivableService;
use App\Domain\Finance\Services\PaymentMatchingService;
use App\Domain\Finance\Services\PaymentRunService;
use App\Domain\Finance\Services\PaymentSettlementSiteScope;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    foreach ([['1000', 'Bank - Operating'], ['1100', 'Accounts Receivable']] as [$code, $name]) {
        FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => $code,
            'name' => $name,
            'type' => 'asset',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
    }
    FinAccount::factory()->create([
        'organization_id' => 1,
        'code' => '2000',
        'name' => 'Accounts Payable',
        'type' => 'liability',
        'opening_balance' => 0,
        'is_active' => true,
    ]);

    FinFiscalPeriod::create([
        'organization_id' => 1,
        'name' => 'Payment allocation integrity period',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);
});

it('retires the forgeable generic allocation POST without any allocation bill or journal effect', function (): void {
    $site = Site::factory()->create();
    $actor = paymentAllocationActor($site, ['finance.ar.manage']);
    $bill = paymentAllocationBill($site, '100.00');

    expect(Route::has('finance.payment-allocations.store'))->toBeFalse();

    $this->actingAs($actor)
        ->post('/finance/payment-allocations', [
            'type' => 'payable',
            'amount' => '100.00',
            'payment_date' => now()->toDateString(),
            'allocatable_type' => 'bill',
            'allocatable_id' => $bill->id,
            'notes' => 'forged settlement',
        ])
        ->assertStatus(405);

    expect(FinPaymentAllocation::query()->count())->toBe(0)
        ->and(FinJournal::query()->count())->toBe(0)
        ->and((string) $bill->fresh()->amount_paid)->toBe('0.00')
        ->and($bill->status)->toBe('approved');
});

it('posts one balanced journal-backed partial AR receipt and rolls an excess second receipt back', function (): void {
    $site = Site::factory()->create();
    $actor = paymentAllocationActor($site, ['finance.ar.manage']);
    $invoice = paymentAllocationInvoice($site, '100.00');

    $this->actingAs($actor)
        ->post(route('finance.receivables.allocate'), [
            'invoice_id' => $invoice->id,
            'amount' => '60.00',
            'payment_date' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('idempotency_key');

    expect(FinPaymentAllocation::query()->count())->toBe(0)
        ->and(FinJournal::query()->count())->toBe(0)
        ->and(DB::table('fin_manual_receipt_idempotencies')->count())->toBe(0);

    $this->actingAs($actor)
        ->post(route('finance.receivables.allocate'), [
            'invoice_id' => $invoice->id,
            'amount' => '60.00',
            'payment_date' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'notes' => 'Part receipt',
        ])
        ->assertRedirect();

    $allocation = FinPaymentAllocation::query()->sole();
    $journal = FinJournal::query()->findOrFail($allocation->journal_id)->load('lines');
    $debits = $journal->lines->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->debit, 2), '0');
    $credits = $journal->lines->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->credit, 2), '0');

    expect($allocation->allocatable_type)->toBe(FinInvoice::class)
        ->and($allocation->allocatable_id)->toBe($invoice->id)
        ->and($allocation->requiresLegacyReview())->toBeFalse()
        ->and($journal->status)->toBe('posted')
        ->and($debits)->toBe('60.00')
        ->and($credits)->toBe('60.00')
        ->and($journal->lines->pluck('site_id')->unique()->all())->toBe([$site->id])
        ->and($invoice->fresh()->status)->toBe('sent');

    $this->actingAs($actor)
        ->from(route('finance.receivables.index'))
        ->post(route('finance.receivables.allocate'), [
            'invoice_id' => $invoice->id,
            'amount' => '50.00',
            'payment_date' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertRedirect(route('finance.receivables.index'))
        ->assertSessionHasErrors('amount');

    expect(FinPaymentAllocation::query()->count())->toBe(1)
        ->and(FinJournal::query()->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe('sent');
});

it('returns the original partial AR receipt on sequential replay while different keys remain legitimate', function (): void {
    $site = Site::factory()->create();
    $actor = paymentAllocationActor($site, ['finance.ar.manage']);
    $invoice = paymentAllocationInvoice($site, '100.00');
    $service = app(AccountsReceivableService::class);
    $paymentDate = now()->toDateString();
    $firstKey = (string) Str::uuid();
    $payload = [
        'invoice_id' => $invoice->id,
        'amount' => '25.00',
        'payment_date' => $paymentDate,
        'idempotency_key' => $firstKey,
        'notes' => 'Same receipt request',
    ];

    $this->actingAs($actor)
        ->post(route('finance.receivables.allocate'), $payload)
        ->assertRedirect();
    $first = FinPaymentAllocation::query()->sole();

    $this->actingAs($actor)
        ->post(route('finance.receivables.allocate'), $payload)
        ->assertRedirect();
    $replay = $service->allocatePayment(1, $actor, $payload);

    $otherActor = paymentAllocationActor($site, ['finance.ar.manage']);
    $this->actingAs($otherActor)
        ->from(route('finance.receivables.index'))
        ->post(route('finance.receivables.allocate'), $payload)
        ->assertRedirect(route('finance.receivables.index'))
        ->assertSessionHasErrors('amount');
    expect(FinPaymentAllocation::query()->count())->toBe(1)
        ->and(FinJournal::query()->count())->toBe(1)
        ->and(DB::table('fin_manual_receipt_idempotencies')->count())->toBe(1);

    expect(fn () => $service->allocatePayment(1, $actor, [
        ...$payload,
        'amount' => '20.00',
    ]))->toThrow(InvalidArgumentException::class);
    $differentKey = $service->allocatePayment(1, $actor, [
        ...$payload,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    expect($replay->id)->toBe($first->id)
        ->and($differentKey->id)->not->toBe($first->id)
        ->and(FinPaymentAllocation::query()->count())->toBe(2)
        ->and(FinJournal::query()->count())->toBe(2)
        ->and(DB::table('fin_manual_receipt_idempotencies')->count())->toBe(2)
        ->and(number_format((float) FinPaymentAllocation::query()->sum('amount'), 2, '.', ''))->toBe('50.00')
        ->and($invoice->fresh()->status)->toBe('sent');
});

it('returns the original fully settling AR receipt after the invoice becomes paid', function (): void {
    $site = Site::factory()->create();
    $actor = paymentAllocationActor($site, ['finance.ar.manage']);
    $invoice = paymentAllocationInvoice($site, '100.00');
    $service = app(AccountsReceivableService::class);
    $payload = [
        'invoice_id' => $invoice->id,
        'amount' => '100.00',
        'payment_date' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
        'notes' => 'Full receipt replay',
    ];

    $first = $service->allocatePayment(1, $actor, $payload);
    expect($invoice->fresh()->status)->toBe('paid');

    $replay = $service->allocatePayment(1, $actor, $payload);

    expect($replay->id)->toBe($first->id)
        ->and(FinPaymentAllocation::query()->count())->toBe(1)
        ->and(FinJournal::query()->count())->toBe(1)
        ->and(DB::table('fin_manual_receipt_idempotencies')->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe('paid');
});

it('conceals a wrong-Site AR target and permits the same canonical target only with explicit all-Sites authority', function (): void {
    $assignedSite = Site::factory()->create();
    $targetSite = Site::factory()->create();
    $siteActor = paymentAllocationActor($assignedSite, ['finance.ar.manage']);
    $invoice = paymentAllocationInvoice($targetSite, '75.00');
    $payload = [
        'invoice_id' => $invoice->id,
        'amount' => '25.00',
        'payment_date' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
    ];

    $this->actingAs($siteActor)
        ->post(route('finance.receivables.allocate'), $payload)
        ->assertNotFound();

    expect(FinPaymentAllocation::query()->count())->toBe(0)
        ->and(FinJournal::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe('sent');

    $globalActor = paymentAllocationActor(null, [
        'finance.ar.manage',
        PaymentSettlementSiteScope::GLOBAL_PERMISSION,
    ]);

    $this->actingAs($globalActor)
        ->post(route('finance.receivables.allocate'), $payload)
        ->assertRedirect();

    expect(FinPaymentAllocation::query()->count())->toBe(1)
        ->and(FinJournal::query()->count())->toBe(1);
});

it('reports source-less and source-only journal-less allocations for review without rewriting history', function (): void {
    $site = Site::factory()->create();
    $invoice = paymentAllocationInvoice($site, '100.00');
    $legacy = FinPaymentAllocation::query()->create([
        'organization_id' => 1,
        'type' => 'receivable',
        'payment_date' => now()->subDay()->toDateString(),
        'amount' => '30.00',
        'allocatable_type' => FinInvoice::class,
        'allocatable_id' => $invoice->id,
        'notes' => 'Historical row',
    ]);
    $sourceOnly = FinPaymentAllocation::query()->create([
        'organization_id' => 1,
        'type' => 'receivable',
        'payment_date' => now()->subDays(2)->toDateString(),
        'amount' => '20.00',
        'allocatable_type' => FinInvoice::class,
        'allocatable_id' => $invoice->id,
        'source_type' => FinInvoice::class,
        'source_id' => $invoice->id,
    ]);
    $actor = paymentAllocationActor(null, [
        'finance.ar.view',
        'finance.ar.manage',
        PaymentSettlementSiteScope::GLOBAL_PERMISSION,
        PaymentSettlementSiteScope::GLOBAL_VIEW_PERMISSION,
    ]);
    $traceable = app(AccountsReceivableService::class)->allocatePayment(1, $actor, [
        'invoice_id' => $invoice->id,
        'amount' => '10.00',
        'payment_date' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $this->actingAs($actor)
        ->get(route('finance.payment-allocations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('legacyReview.state', 'review_required')
            ->where('legacyReview.count', 2)
            ->where('legacyReview.total_amount', 50)
            ->where('legacyReview.correction_policy', 'journal_backed_correction_only')
            ->where('allocations.data.0.id', $traceable->id)
            ->where('allocations.data.0.review_state', 'traceable')
            ->where('allocations.data.1.id', $legacy->id)
            ->where('allocations.data.1.review_state', 'review_required')
            ->where('allocations.data.2.id', $sourceOnly->id)
            ->where('allocations.data.2.review_state', 'review_required'));

    expect($legacy->fresh()->journal_id)->toBeNull()
        ->and($legacy->source_type)->toBeNull()
        ->and($legacy->notes)->toBe('Historical row')
        ->and($sourceOnly->fresh()->journal_id)->toBeNull();

    expect(fn () => $legacy->fresh()->update(['notes' => 'silently rewritten']))
        ->toThrow(LogicException::class);
    expect($legacy->fresh()->notes)->toBe('Historical row');
});

it('Site-scopes allocation history while preserving the explicit all-Sites read exception', function (): void {
    $assignedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $assignedInvoice = paymentAllocationInvoice($assignedSite, '100.00');
    $otherInvoice = paymentAllocationInvoice($otherSite, '100.00');
    $writer = paymentAllocationActor(null, [
        'finance.ar.manage',
        PaymentSettlementSiteScope::GLOBAL_PERMISSION,
    ]);
    $assignedAllocation = app(AccountsReceivableService::class)->allocatePayment(1, $writer, [
        'invoice_id' => $assignedInvoice->id,
        'payment_date' => now()->toDateString(),
        'amount' => '10.00',
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $otherAllocation = app(AccountsReceivableService::class)->allocatePayment(1, $writer, [
        'invoice_id' => $otherInvoice->id,
        'payment_date' => now()->subDay()->toDateString(),
        'amount' => '20.00',
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $siteViewer = paymentAllocationActor($assignedSite, ['finance.ar.view']);

    $this->actingAs($siteViewer)
        ->get(route('finance.payment-allocations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('allocations.total', 1)
            ->where('allocations.data.0.id', $assignedAllocation->id)
            ->where('legacyReview.count', 0));

    $globalViewer = paymentAllocationActor(null, [
        'finance.ar.view',
        PaymentSettlementSiteScope::GLOBAL_VIEW_PERMISSION,
    ]);
    $this->actingAs($globalViewer)
        ->get(route('finance.payment-allocations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('allocations.total', 2)
            ->where('allocations.data.0.id', $assignedAllocation->id)
            ->where('allocations.data.1.id', $otherAllocation->id));
});

it('serializes aggregate settlement races across matching manual receipts match-all and payment runs on MySQL', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    $site = Site::factory()->create();
    $actor = paymentAllocationActor(null, [
        'finance.ar.manage',
        'finance.bank.manage',
        'finance.ap.manage',
        PaymentSettlementSiteScope::GLOBAL_PERMISSION,
    ]);
    $bankAccount = FinBankAccount::factory()->create([
        'organization_id' => 1,
        'gl_account_id' => FinAccount::where('code', '1000')->value('id'),
        'is_active' => true,
    ]);

    $firstInvoice = paymentAllocationInvoice($site, '100.00');
    $secondInvoice = paymentAllocationInvoice($site, '100.00');
    $sharedTransaction = paymentAllocationBankTransaction($bankAccount, '100.00', 'shared match race');
    $firstMatch = paymentAllocationMatch($site, $sharedTransaction, $firstInvoice, 'race-a');
    $secondMatch = paymentAllocationMatch($site, $sharedTransaction, $secondInvoice, 'race-b');

    $manualInvoice = paymentAllocationInvoice($site, '80.00');
    $manualTransaction = paymentAllocationBankTransaction($bankAccount, '80.00', 'manual race');
    $manualMatch = paymentAllocationMatch($site, $manualTransaction, $manualInvoice, 'manual-race');

    $matchAllInvoice = paymentAllocationInvoice($site, '55.00');
    $matchAllInvoice->forceFill(['invoice_number' => 'INV-CONCURRENT-MATCH-ALL'])->save();
    $matchAllTransaction = paymentAllocationBankTransaction(
        $bankAccount,
        '55.00',
        'INV-CONCURRENT-MATCH-ALL',
    );

    $rejectInvoice = paymentAllocationInvoice($site, '35.00');
    $rejectTransaction = paymentAllocationBankTransaction($bankAccount, '35.00', 'reject race');
    $rejectMatch = paymentAllocationMatch($site, $rejectTransaction, $rejectInvoice, 'reject-race');

    $replayInvoice = paymentAllocationInvoice($site, '100.00');
    $replayKey = (string) Str::uuid();

    $collisionInvoiceA = paymentAllocationInvoice($site, '100.00');
    $collisionInvoiceB = paymentAllocationInvoice($site, '100.00');
    $collisionKey = (string) Str::uuid();

    $bill = paymentAllocationBill($site, '70.00');
    $database = $connection->getDatabaseName();
    $actorId = $actor->id;
    $permissionIds = $actor->permissionOverrides()->pluck('permissions.id')->all();
    $connection->commit();

    try {
        expect(concurrentSettlementActions($database, [
            ['action' => 'confirm_match', 'match_id' => $firstMatch->id, 'actor_id' => $actorId],
            ['action' => 'confirm_match', 'match_id' => $secondMatch->id, 'actor_id' => $actorId],
        ]))->toBe(['ok', 'rejected']);
        expect(FinPaymentAllocation::where('bank_transaction_id', $sharedTransaction->id)->count())->toBe(1)
            ->and(FinJournal::where('source_type', FinPaymentMatch::class)
                ->whereIn('source_id', [$firstMatch->id, $secondMatch->id])->count())->toBe(1);

        expect(concurrentSettlementActions($database, [
            ['action' => 'confirm_match', 'match_id' => $manualMatch->id, 'actor_id' => $actorId],
            [
                'action' => 'allocate_invoice',
                'invoice_id' => $manualInvoice->id,
                'actor_id' => $actorId,
                'amount' => '80.00',
                'idempotency_key' => (string) Str::uuid(),
            ],
        ]))->toBe(['ok', 'rejected']);
        expect(FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
            ->where('allocatable_id', $manualInvoice->id)->count())->toBe(1);

        expect(concurrentSettlementActions($database, [
            [
                'action' => 'allocate_invoice',
                'invoice_id' => $replayInvoice->id,
                'actor_id' => $actorId,
                'amount' => '25.00',
                'idempotency_key' => $replayKey,
            ],
            [
                'action' => 'allocate_invoice',
                'invoice_id' => $replayInvoice->id,
                'actor_id' => $actorId,
                'amount' => '25.00',
                'idempotency_key' => $replayKey,
            ],
        ]))->toBe(['ok', 'ok']);
        expect(FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
            ->where('allocatable_id', $replayInvoice->id)->count())->toBe(1)
            ->and(FinJournal::where('source_type', FinInvoice::class)
                ->where('source_id', $replayInvoice->id)->count())->toBe(1)
            ->and(DB::table('fin_manual_receipt_idempotencies')
                ->where('idempotency_key', $replayKey)->count())->toBe(1);

        expect(concurrentSettlementActions($database, [
            [
                'action' => 'allocate_invoice_classified',
                'invoice_id' => $collisionInvoiceA->id,
                'actor_id' => $actorId,
                'amount' => '25.00',
                'idempotency_key' => $collisionKey,
            ],
            [
                'action' => 'allocate_invoice_classified',
                'invoice_id' => $collisionInvoiceB->id,
                'actor_id' => $actorId,
                'amount' => '25.00',
                'idempotency_key' => $collisionKey,
            ],
        ]))->toBe(['domain_conflict', 'ok']);
        expect(FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
            ->whereIn('allocatable_id', [$collisionInvoiceA->id, $collisionInvoiceB->id])
            ->count())->toBe(1)
            ->and(FinJournal::where('source_type', FinInvoice::class)
                ->whereIn('source_id', [$collisionInvoiceA->id, $collisionInvoiceB->id])
                ->count())->toBe(1)
            ->and(DB::table('fin_manual_receipt_idempotencies')
                ->where('idempotency_key', $collisionKey)->count())->toBe(1);

        expect(concurrentSettlementActions($database, [
            ['action' => 'match_all', 'actor_id' => $actorId],
            ['action' => 'match_all', 'actor_id' => $actorId],
        ]))->toBe(['ok', 'ok']);
        expect(FinPaymentMatch::where('bank_transaction_id', $matchAllTransaction->id)->count())->toBe(1)
            ->and(FinPaymentAllocation::where('allocatable_id', $matchAllInvoice->id)->count())->toBe(0);

        expect(concurrentSettlementActions($database, [
            ['action' => 'reject_match', 'match_id' => $rejectMatch->id, 'actor_id' => $actorId],
            ['action' => 'reject_match', 'match_id' => $rejectMatch->id, 'actor_id' => $actorId],
        ]))->toBe(['ok', 'rejected']);
        $rejectedMatch = $rejectMatch->fresh();
        expect($rejectedMatch->status)->toBe('rejected')
            ->and($rejectedMatch->rejected_by)->toBe($actorId)
            ->and($rejectedMatch->rejected_at)->not->toBeNull()
            ->and(DB::table('audit_logs')
                ->where('action', 'finance.payment_match.rejected')
                ->where('auditable_id', $rejectMatch->id)
                ->count())->toBe(1)
            ->and(FinJournal::where('source_type', FinPaymentMatch::class)
                ->where('source_id', $rejectMatch->id)->count())->toBe(0)
            ->and(FinPaymentAllocation::where('source_type', FinPaymentMatch::class)
                ->where('source_id', $rejectMatch->id)->count())->toBe(0)
            ->and($rejectInvoice->fresh()->status)->toBe('sent');

        expect(concurrentSettlementActions($database, [
            [
                'action' => 'create_run',
                'actor_id' => $actorId,
                'bank_account_id' => $bankAccount->id,
                'bill_id' => $bill->id,
            ],
            [
                'action' => 'create_run',
                'actor_id' => $actorId,
                'bank_account_id' => $bankAccount->id,
                'bill_id' => $bill->id,
            ],
        ]))->toBe(['ok', 'rejected']);
        expect(FinPaymentRunItem::where('settlement_bill_id', $bill->id)->count())->toBe(1);
    } finally {
        cleanupCommittedSettlementRaceRows($actorId, $permissionIds);
        $connection->beginTransaction();
    }
});

it('rolls back receipt balance journal allocation and audit evidence when any integrity write fails', function (
    string $blockedTable,
    string $blockedOperation,
): void {
    $site = Site::factory()->create();
    $actor = paymentAllocationActor(null, [
        'finance.ar.manage',
        PaymentSettlementSiteScope::GLOBAL_PERMISSION,
    ]);
    $invoice = paymentAllocationInvoice($site, '45.00');
    $auditCountBefore = DB::table('audit_logs')->count();
    $activeBlock = $blockedTable;

    DB::listen(function ($query) use (&$activeBlock, $blockedOperation): void {
        $blockedSql = $blockedOperation.' `'.strtolower((string) $activeBlock).'`';
        if ($activeBlock !== null && str_contains(strtolower($query->sql), $blockedSql)) {
            throw new RuntimeException("Forced {$activeBlock} write failure.");
        }
    });

    try {
        expect(fn () => app(AccountsReceivableService::class)->allocatePayment(1, $actor, [
            'invoice_id' => $invoice->id,
            'amount' => '45.00',
            'payment_date' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]))->toThrow(RuntimeException::class);
    } finally {
        $activeBlock = null;
    }

    expect(FinPaymentAllocation::query()->count())->toBe(0)
        ->and(FinJournal::query()->count())->toBe(0)
        ->and(DB::table('fin_manual_receipt_idempotencies')->count())->toBe(0)
        ->and(DB::table('audit_logs')->count())->toBe($auditCountBefore)
        ->and($invoice->fresh()->status)->toBe('sent')
        ->and($invoice->paid_at)->toBeNull();
})->with([
    'idempotency reservation' => ['fin_manual_receipt_idempotencies', 'insert into'],
    'idempotency allocation link' => ['fin_manual_receipt_idempotencies', 'update'],
    'journal' => ['fin_journals', 'insert into'],
    'allocation' => ['fin_payment_allocations', 'insert into'],
    'audit' => ['audit_logs', 'insert into'],
]);

it('denies settlement at inactive or archived Sites even with explicit all-Sites authority', function (array $siteState): void {
    $site = Site::factory()->create($siteState);
    $actor = paymentAllocationActor(null, [
        'finance.ar.manage',
        PaymentSettlementSiteScope::GLOBAL_PERMISSION,
    ]);
    $invoice = paymentAllocationInvoice($site, '25.00');

    expect(fn () => app(AccountsReceivableService::class)->allocatePayment(1, $actor, [
        'invoice_id' => $invoice->id,
        'amount' => '25.00',
        'payment_date' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
    ]))->toThrow(NotFoundHttpException::class);

    expect(FinPaymentAllocation::query()->count())->toBe(0)
        ->and(FinJournal::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe('sent');
})->with([
    'inactive' => [['is_active' => false]],
    'archived' => [['archived' => true, 'archived_at' => now()]],
]);

it('keeps allocation history bound to the immutable settlement Site after a client transfer', function (): void {
    $settlementSite = Site::factory()->create();
    $newSite = Site::factory()->create();
    $invoice = paymentAllocationInvoice($settlementSite, '40.00');
    $writer = paymentAllocationActor(null, [
        'finance.ar.manage',
        PaymentSettlementSiteScope::GLOBAL_PERMISSION,
    ]);
    $allocation = app(AccountsReceivableService::class)->allocatePayment(1, $writer, [
        'invoice_id' => $invoice->id,
        'amount' => '10.00',
        'payment_date' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $invoice->client->update(['site_id' => $newSite->id]);

    $originalViewer = paymentAllocationActor($settlementSite, ['finance.ar.view']);
    $newViewer = paymentAllocationActor($newSite, ['finance.ar.view']);

    $this->actingAs($originalViewer)
        ->get(route('finance.payment-allocations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('allocations.total', 1)
            ->where('allocations.data.0.id', $allocation->id));
    $this->actingAs($newViewer)
        ->get(route('finance.payment-allocations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('allocations.total', 0));
});

it('allows AP-only history access without exposing receivable rows from the mixed ledger', function (): void {
    $site = Site::factory()->create();
    $invoice = paymentAllocationInvoice($site, '20.00');
    $bill = paymentAllocationBill($site, '20.00');
    $writer = paymentAllocationActor(null, [
        'finance.ar.manage',
        PaymentSettlementSiteScope::GLOBAL_PERMISSION,
    ]);
    app(AccountsReceivableService::class)->allocatePayment(1, $writer, [
        'invoice_id' => $invoice->id,
        'amount' => '5.00',
        'payment_date' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $journal = FinJournal::factory()->create([
        'organization_id' => 1,
        'status' => 'posted',
        'source_type' => FinBill::class,
        'source_id' => $bill->id,
    ]);
    $payable = FinPaymentAllocation::query()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'type' => 'payable',
        'payment_date' => now()->toDateString(),
        'amount' => '5.00',
        'allocatable_type' => FinBill::class,
        'allocatable_id' => $bill->id,
        'source_type' => FinJournal::class,
        'source_id' => $journal->id,
        'settlement_source_key' => FinJournal::class.':'.$journal->id,
        'integrity_state' => FinPaymentAllocation::INTEGRITY_TRACEABLE,
        'journal_id' => $journal->id,
        'settlement_journal_id' => $journal->id,
    ]);
    $viewer = paymentAllocationActor($site, ['finance.ap.view']);

    $this->actingAs($viewer)
        ->get(route('finance.payment-allocations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('allocations.total', 1)
            ->where('allocations.data.0.id', $payable->id)
            ->where('allocations.data.0.type', 'payable'));
});

it('fails closed on canonical organisation client bill and target mismatches', function (): void {
    $site = Site::factory()->create();
    $actor = paymentAllocationActor(null, [PaymentSettlementSiteScope::GLOBAL_PERMISSION]);
    $bankAccount = FinBankAccount::factory()->create([
        'organization_id' => 1,
        'gl_account_id' => FinAccount::where('code', '1000')->value('id'),
        'is_active' => true,
    ]);

    $orphanInvoice = FinInvoice::factory()->create([
        'organization_id' => 1,
        'client_id' => null,
        'status' => 'sent',
        'total_amount' => '15.00',
    ]);
    expect(fn () => app(AccountsReceivableService::class)->allocatePayment(1, $actor, [
        'invoice_id' => $orphanInvoice->id,
        'amount' => '15.00',
        'payment_date' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
    ]))->toThrow(NotFoundHttpException::class);

    $invoice = paymentAllocationInvoice($site, '20.00');
    $foreignTransaction = FinBankTransaction::query()->create([
        'organization_id' => 2,
        'bank_account_id' => $bankAccount->id,
        'transaction_date' => now()->toDateString(),
        'amount' => '20.00',
        'description' => 'Foreign organisation transaction',
        'status' => 'unreconciled',
    ]);
    $match = paymentAllocationMatch($site, $foreignTransaction, $invoice, 'foreign-org');
    expect(fn () => app(PaymentMatchingService::class)
        ->confirmMatch($match, $actor))
        ->toThrow(ModelNotFoundException::class);

    $bill = paymentAllocationBill($site, '30.00');
    $otherBill = paymentAllocationBill($site, '30.00');
    $run = FinPaymentRun::factory()->create([
        'organization_id' => 1,
        'bank_account_id' => $bankAccount->id,
        'status' => 'approved',
        'payment_date' => now(),
        'item_count' => 1,
        'total_amount' => '30.00',
    ]);
    FinPaymentRunItem::query()->create([
        'payment_run_id' => $run->id,
        'site_id' => $site->id,
        'bill_id' => $bill->id,
        'settlement_bill_id' => $otherBill->id,
        'vendor_id' => $bill->vendor_id,
        'amount' => '30.00',
        'status' => 'pending',
    ]);
    expect(fn () => app(PaymentRunService::class)
        ->processPaymentRun($run, $actor))
        ->toThrow(NotFoundHttpException::class);

    expect(FinPaymentAllocation::query()->count())->toBe(0)
        ->and(FinJournal::query()->count())->toBe(0)
        ->and((string) $bill->fresh()->amount_paid)->toBe('0.00')
        ->and($run->fresh()->status)->toBe('approved');
});

it('migrates settlement constraints down and back up with foreign keys removed before unique indexes', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');
    $connection->commit();
    $path = database_path('migrations/2026_08_14_000064_add_finance_payment_global_site_permission.php');

    try {
        /** @var Migration $migration */
        $migration = require $path;
        $migration->down();

        expect(Schema::hasTable('fin_manual_receipt_idempotencies'))->toBeFalse()
            ->and(Schema::hasColumn('fin_payment_allocations', 'settlement_source_key'))->toBeFalse()
            ->and(Schema::hasColumn('fin_payment_matches', 'suggestion_key'))->toBeFalse()
            ->and(Schema::hasColumn('fin_payment_matches', 'rejected_by'))->toBeFalse()
            ->and(Schema::hasColumn('fin_payment_matches', 'rejected_at'))->toBeFalse()
            ->and(Schema::hasColumn('fin_payment_matches', 'rejection_reason'))->toBeFalse()
            ->and(Schema::hasColumn('fin_payment_run_items', 'settlement_bill_id'))->toBeFalse();

        $migration = require $path;
        $migration->up();
        expect(Schema::hasTable('fin_manual_receipt_idempotencies'))->toBeTrue()
            ->and(Schema::hasIndex(
                'fin_manual_receipt_idempotencies',
                'fin_manual_receipt_key_unique',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'fin_payment_allocations',
                'fin_payment_allocations_settlement_source_unique',
            ))->toBeTrue()
            ->and(Schema::hasColumn('fin_payment_matches', 'rejected_by'))->toBeTrue()
            ->and(Schema::hasColumn('fin_payment_matches', 'rejected_at'))->toBeTrue()
            ->and(Schema::hasColumn('fin_payment_matches', 'rejection_reason'))->toBeTrue()
            ->and(Schema::hasIndex(
                'fin_payment_allocations',
                'fin_payment_allocations_bank_transaction_unique',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'fin_payment_matches',
                'fin_payment_matches_suggestion_unique',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'fin_payment_run_items',
                'fin_payment_run_items_settlement_bill_unique',
            ))->toBeTrue();

        $migration = require $path;
        $migration->down();
        $migration = require $path;
        $migration->up();
    } finally {
        if (! Schema::hasColumn('fin_payment_allocations', 'settlement_source_key')) {
            /** @var Migration $restore */
            $restore = require $path;
            $restore->up();
        }
        DB::table('audit_logs')->delete();
        DB::table('fin_fiscal_periods')->delete();
        DB::table('fin_accounts')->delete();
        $connection->beginTransaction();
    }
});

it('rejects non-positive non-payable and above-balance AP mutations without changing the bill', function (): void {
    $site = Site::factory()->create();
    $service = app(AccountsPayableService::class);
    $bill = paymentAllocationBill($site, '100.00');
    $bill->forceFill(['amount_paid' => '40.00', 'status' => 'partially_paid'])->save();

    foreach ([-1.00, 0.00, 60.01] as $amount) {
        expect(fn () => $service->recordPayment($bill->fresh(), $amount))
            ->toThrow(InvalidArgumentException::class);
        expect((string) $bill->fresh()->amount_paid)->toBe('40.00')
            ->and($bill->status)->toBe('partially_paid');
    }

    $bill->forceFill(['status' => 'draft'])->save();
    expect(fn () => $service->recordPayment($bill->fresh(), 10.00))
        ->toThrow(InvalidArgumentException::class);
    expect((string) $bill->fresh()->amount_paid)->toBe('40.00')
        ->and($bill->status)->toBe('draft');

    $bill->forceFill(['status' => 'partially_paid'])->save();
    $settled = $service->recordPayment($bill->fresh(), 60.00);
    expect((string) $settled->amount_paid)->toBe('100.00')
        ->and($settled->status)->toBe('paid');
});

it('serializes concurrent and replayed AP payment mutation to one locked bill effect on MySQL', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    $site = Site::factory()->create();
    $bill = paymentAllocationBill($site, '100.00');
    $database = $connection->getDatabaseName();
    $connection->commit();

    try {
        $statuses = concurrentBillPaymentRound($connection, $database, $bill->id, '100.00');
        $bill->refresh();

        expect($statuses)->toBe(['paid', 'rejected'])
            ->and((string) $bill->amount_paid)->toBe('100.00')
            ->and($bill->status)->toBe('paid');

        expect(fn () => app(AccountsPayableService::class)->recordPayment($bill->fresh(), 100.00))
            ->toThrow(InvalidArgumentException::class);

        $bill->refresh();
        expect((string) $bill->amount_paid)->toBe('100.00')
            ->and($bill->status)->toBe('paid');
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        DB::table('audit_logs')->delete();
        DB::table('fin_bills')->delete();
        DB::table('fin_vendors')->delete();
        DB::table('fin_fiscal_periods')->delete();
        DB::table('fin_accounts')->delete();
        DB::table('sites')->delete();
        $connection->beginTransaction();
    }
});

function paymentAllocationActor(?Site $site, array $permissionKeys): User
{
    $actor = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    foreach ($permissionKeys as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key]);
        $actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    if ($site) {
        ensureCanonicalHrStaffProfile($actor, $site);
    }

    return $actor;
}

function paymentAllocationInvoice(Site $site, string $amount): FinInvoice
{
    $client = Client::factory()->create(['organization_id' => 1, 'site_id' => $site->id]);

    return FinInvoice::factory()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'status' => 'sent',
        'total_amount' => $amount,
        'invoice_date' => now()->subDay(),
        'due_date' => now()->addMonth(),
    ]);
}

function paymentAllocationBill(Site $site, string $amount): FinBill
{
    $vendor = FinVendor::factory()->create(['organization_id' => 1]);

    return FinBill::factory()->create([
        'organization_id' => 1,
        'vendor_id' => $vendor->id,
        'site_id' => $site->id,
        'status' => 'approved',
        'total_amount' => $amount,
        'amount_paid' => '0.00',
    ]);
}

function paymentAllocationBankTransaction(
    FinBankAccount $bankAccount,
    string $amount,
    string $description,
): FinBankTransaction {
    return FinBankTransaction::query()->create([
        'organization_id' => 1,
        'bank_account_id' => $bankAccount->id,
        'transaction_date' => now()->toDateString(),
        'amount' => $amount,
        'description' => $description,
        'reference' => $description,
        'source' => 'manual',
        'status' => 'unreconciled',
    ]);
}

function paymentAllocationMatch(
    Site $site,
    FinBankTransaction $transaction,
    FinInvoice $invoice,
    string $keySuffix,
): FinPaymentMatch {
    return FinPaymentMatch::query()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'bank_transaction_id' => $transaction->id,
        'matchable_type' => FinInvoice::class,
        'matchable_id' => $invoice->id,
        'suggestion_key' => "{$transaction->id}:{$keySuffix}:{$invoice->id}",
        'confidence_score' => 99,
        'match_reasons' => ['Concurrent settlement test'],
        'status' => 'suggested',
    ]);
}

/** @param list<array<string, int|string>> $actions */
function concurrentSettlementActions(string $database, array $actions): array
{
    $token = (string) Str::uuid();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."fin-settlement-release-{$token}";
    $readyPaths = [];
    $processes = [];

    try {
        foreach ($actions as $index => $action) {
            $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."fin-settlement-ready-{$index}-{$token}";
            $processes[] = startConcurrentSettlementWorker(
                $database,
                $action,
                $readyPaths[$index],
                $releasePath,
            );
        }

        waitForPaymentWorkerFiles($readyPaths, 'Concurrent settlement workers did not become ready.');
        touch($releasePath);

        $statuses = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A settlement worker failed.');
            }
            $statuses[] = json_decode(
                trim($process->getOutput()),
                true,
                flags: JSON_THROW_ON_ERROR,
            )['status'];
        }
        sort($statuses);

        return $statuses;
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

/** @param array<string, int|string> $action */
function startConcurrentSettlementWorker(
    string $database,
    array $action,
    string $readyPath,
    string $releasePath,
): Process {
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($argv[3], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[4])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the settlement release barrier.');
    }
    usleep(10_000);
}
try {
    $actor = App\Models\User::query()->findOrFail((int) $payload['actor_id']);
    switch ($payload['action']) {
        case 'confirm_match':
            $match = App\Domain\Finance\Models\FinPaymentMatch::query()
                ->findOrFail((int) $payload['match_id']);
            $app->make(App\Domain\Finance\Services\PaymentMatchingService::class)
                ->confirmMatch($match, $actor);
            break;
        case 'reject_match':
            $match = App\Domain\Finance\Models\FinPaymentMatch::query()
                ->findOrFail((int) $payload['match_id']);
            $app->make(App\Domain\Finance\Services\PaymentMatchingService::class)
                ->rejectMatch($match, $actor, 'Concurrent rejection');
            break;
        case 'allocate_invoice':
        case 'allocate_invoice_classified':
            $app->make(App\Domain\Finance\Services\AccountsReceivableService::class)
                ->allocatePayment(1, $actor, [
                    'invoice_id' => (int) $payload['invoice_id'],
                    'amount' => $payload['amount'],
                    'payment_date' => date('Y-m-d'),
                    'idempotency_key' => $payload['idempotency_key'],
                ]);
            break;
        case 'match_all':
            $app->make(App\Domain\Finance\Services\PaymentMatchingService::class)
                ->matchUnmatchedTransactions(1, $actor);
            break;
        case 'create_run':
            $app->make(App\Domain\Finance\Services\PaymentRunService::class)
                ->createPaymentRun(1, $actor, [
                    'bank_account_id' => (int) $payload['bank_account_id'],
                    'payment_date' => date('Y-m-d'),
                    'bill_ids' => [(int) $payload['bill_id']],
                ]);
            break;
        default:
            throw new RuntimeException('Unknown settlement concurrency action.');
    }
    $status = 'ok';
} catch (InvalidArgumentException) {
    $status = $payload['action'] === 'allocate_invoice_classified'
        ? 'domain_conflict'
        : 'rejected';
} catch (Throwable $exception) {
    $status = $payload['action'] === 'allocate_invoice_classified'
        ? 'unexpected:'.$exception::class
        : 'rejected';
}
echo json_encode(['status' => $status], JSON_THROW_ON_ERROR);
PHP;

    $process = new Process([
        PHP_BINARY,
        '-r',
        $worker,
        base_path(),
        base64_encode(json_encode($action, JSON_THROW_ON_ERROR)),
        $readyPath,
        $releasePath,
    ], base_path(), [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => $database,
    ]);
    $process->setTimeout(30);
    $process->start();

    return $process;
}

/** @param list<int> $permissionIds */
function cleanupCommittedSettlementRaceRows(int $actorId, array $permissionIds): void
{
    DB::table('audit_logs')->delete();
    DB::table('fin_manual_receipt_idempotencies')->delete();
    DB::table('fin_payment_allocations')->delete();
    DB::table('fin_payment_matches')->delete();
    DB::table('fin_payment_run_items')->delete();
    DB::table('fin_payment_runs')->delete();
    DB::table('fin_journal_lines')->delete();
    DB::table('fin_bank_transactions')->delete();
    DB::table('fin_journals')->delete();
    DB::table('fin_bank_accounts')->delete();
    DB::table('fin_invoices')->delete();
    DB::table('clients')->delete();
    DB::table('fin_bills')->delete();
    DB::table('fin_vendors')->delete();
    DB::table('permission_user')
        ->where('user_id', $actorId)
        ->whereIn('permission_id', $permissionIds)
        ->delete();
    DB::table('users')->where('id', $actorId)->delete();
    DB::table('fin_fiscal_periods')->delete();
    DB::table('fin_accounts')->delete();
    DB::table('sites')->delete();
}

function concurrentBillPaymentRound(
    ConnectionInterface $connection,
    string $database,
    int $billId,
    string $amount,
): array {
    $token = (string) Str::uuid();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."fin-payment-release-{$token}";
    $readyPaths = [];
    $attemptPaths = [];
    $processes = [];

    $connection->beginTransaction();
    FinBill::query()->whereKey($billId)->lockForUpdate()->firstOrFail();

    try {
        foreach ([0, 1] as $index) {
            $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."fin-payment-ready-{$index}-{$token}";
            $attemptPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."fin-payment-attempt-{$index}-{$token}";
            $processes[] = startConcurrentBillPaymentWorker(
                $database,
                $billId,
                $amount,
                $readyPaths[$index],
                $attemptPaths[$index],
                $releasePath,
            );
        }

        waitForPaymentWorkerFiles($readyPaths, 'Concurrent payment workers did not become ready.');
        touch($releasePath);
        waitForPaymentWorkerFiles($attemptPaths, 'Concurrent payment workers did not reach the bill lock.');
        usleep(250_000);

        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A payment worker exited before lock release.');
            }
        }

        $connection->commit();
        $statuses = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A payment concurrency worker failed.');
            }
            $statuses[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR)['status'];
        }
        sort($statuses);

        return $statuses;
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ([...$readyPaths, ...$attemptPaths, $releasePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

function startConcurrentBillPaymentWorker(
    string $database,
    int $billId,
    string $amount,
    string $readyPath,
    string $attemptPath,
    string $releasePath,
): Process {
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[6])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the payment release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[5], 'attempting');
try {
    $bill = App\Domain\Finance\Models\FinBill::query()->findOrFail((int) $argv[2]);
    $app->make(App\Domain\Finance\Services\AccountsPayableService::class)
        ->recordPayment($bill, (float) $argv[3]);
    $status = 'paid';
} catch (InvalidArgumentException) {
    $status = 'rejected';
}
echo json_encode(['status' => $status], JSON_THROW_ON_ERROR);
PHP;

    $process = new Process([
        PHP_BINARY,
        '-r',
        $worker,
        base_path(),
        (string) $billId,
        $amount,
        $readyPath,
        $attemptPath,
        $releasePath,
    ], base_path(), [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => $database,
    ]);
    $process->setTimeout(30);
    $process->start();

    return $process;
}

/** @param list<string> $paths */
function waitForPaymentWorkerFiles(array $paths, string $message): void
{
    $deadline = microtime(true) + 15;
    while (collect($paths)->contains(fn (string $path): bool => ! is_file($path))) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }
        usleep(10_000);
    }
}
