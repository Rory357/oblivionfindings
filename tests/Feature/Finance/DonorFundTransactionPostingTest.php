<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinBillLine;
use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Models\FinDonorFundTransaction;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Domain\Finance\Services\DonorFundApplicationSiteScope;
use App\Domain\Finance\Services\DonorFundService;
use App\Domain\Finance\Services\JournalPostingService;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Donor-fund applications recognise each accounting source once: a receipt is
 * DR Bank / CR restricted-fund liability; expenditure first exists as one
 * approved bill (DR Expense / CR AP), then the fund application releases DR
 * restricted-fund liability / CR funding-stream revenue without another DR
 * expense. Application rows and reversals are immutable and replay-safe.
 */
function seedDonorFundAccounts(): void
{
    foreach ([
        ['1000', 'Bank', 'asset'],
        ['2000', 'Accounts Payable', 'liability'],
        ['2600', 'Restricted Grant Liability', 'liability'],
        ['4220', 'Grant Release Income', 'revenue'],
        ['6000', 'Programme Costs', 'expense'],
    ] as [$code, $name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'is_active' => true,
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

/** @return array{0:string,1:string} */
function donorJournalTotals(FinJournal $journal): array
{
    $journal->loadMissing('lines.account');

    return [
        $journal->lines->reduce(fn (string $total, $line) => bcadd($total, (string) $line->debit, 2), '0.00'),
        $journal->lines->reduce(fn (string $total, $line) => bcadd($total, (string) $line->credit, 2), '0.00'),
    ];
}

function donorFundWithGl(string $received = '0.00', bool $restricted = true): FinDonorFund
{
    $liability = FinAccount::query()->where('code', '2600')->firstOrFail();
    $revenue = FinAccount::query()->where('code', '4220')->firstOrFail();
    $stream = FinFundingStream::query()->create([
        'organization_id' => 1,
        'code' => 'DONOR-GRANT',
        'name' => 'Donor Grant',
        'funder_type' => 'other',
        'default_revenue_account_id' => $revenue->id,
        'is_active' => true,
    ]);

    return FinDonorFund::factory()->create([
        'organization_id' => 1,
        'status' => 'active',
        'is_restricted' => $restricted,
        'gl_account_id' => $liability->id,
        'funding_stream_id' => $stream->id,
        'total_received' => $received,
        'total_spent' => '0.00',
        'total_committed' => '0.00',
        'available_balance' => $received,
    ]);
}

function donorFundActor(?Site $site = null, array $permissions = []): User
{
    $actor = User::factory()->create([
        'organization_id' => 1,
        'approved_at' => now(),
    ]);
    ensureCanonicalHrStaffProfile($actor, $site);

    foreach ($permissions as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'finance', 'module' => 'Finance'],
        );
        $actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    return $actor;
}

function approvedDonorBill(
    User $actor,
    string $amount = '250.00',
    ?Site $site = null,
): FinBill {
    $expense = FinAccount::query()->where('code', '6000')->firstOrFail();
    $actor->loadMissing('hrEmployeeProfile');
    $siteId = $site?->id ?? $actor->hrEmployeeProfile?->primary_site_id;
    $bill = FinBill::factory()->create([
        'organization_id' => 1,
        'site_id' => $siteId,
        'status' => 'draft',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addMonth()->toDateString(),
        'subtotal' => $amount,
        'gst_amount' => '0.00',
        'total_amount' => $amount,
        'amount_paid' => '0.00',
    ]);
    FinBillLine::query()->create([
        'bill_id' => $bill->id,
        'description' => 'Programme delivery',
        'quantity' => 1,
        'unit_price' => $amount,
        'gst_rate' => 0,
        'gst_amount' => '0.00',
        'line_total' => $amount,
        'account_id' => $expense->id,
    ]);

    return app(AccountsPayableService::class)->approveBill($bill, $actor->id);
}

function donorRequestKey(): string
{
    return (string) Str::uuid();
}

it('records a receipt once and posts DR Bank / CR restricted-fund liability', function (): void {
    seedDonorFundAccounts();
    $fund = donorFundWithGl(restricted: true);
    $actor = donorFundActor();
    $this->actingAs($actor);
    $key = donorRequestKey();

    $payload = [
        'idempotency_key' => $key,
        'transaction_date' => now()->toDateString(),
        'description' => 'Q1 grant instalment',
        'amount' => '1000.00',
        'reference' => 'GRANT-1',
        'bank_account_id' => null,
    ];
    $service = app(DonorFundService::class);
    $first = $service->recordReceipt($fund, $payload);
    $replay = $service->recordReceipt($fund->fresh(), $payload);
    $otherActor = donorFundActor();

    expect($replay->id)->toBe($first->id)
        ->and($first->site_id)->toBeNull()
        ->and((int) $first->funding_stream_id)->toBe((int) $fund->funding_stream_id)
        ->and(FinDonorFundTransaction::query()->count())->toBe(1)
        ->and(FinJournal::query()->where('source_type', FinDonorFundTransaction::class)->count())->toBe(1)
        ->and((string) $fund->fresh()->total_received)->toBe('1000.00')
        ->and((string) $fund->fresh()->available_balance)->toBe('1000.00');

    $journal = FinJournal::query()->findOrFail($first->journal_id);
    [$debits, $credits] = donorJournalTotals($journal);
    expect($debits)->toBe('1000.00')
        ->and($credits)->toBe('1000.00')
        ->and($journal->lines->firstWhere('debit', '1000.00')->account->code)->toBe('1000')
        ->and($journal->lines->firstWhere('credit', '1000.00')->account->code)->toBe('2600');

    expect(fn () => $service->recordReceipt($fund->fresh(), [
        ...$payload,
        'description' => 'Changed replay',
    ]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->recordReceipt($fund->fresh(), [
        ...$payload,
        'actor_id' => $otherActor->id,
    ]))->toThrow(InvalidArgumentException::class, 'different application');
    expect(fn () => $fund->fresh()->forceDelete())->toThrow(QueryException::class);
});

it('requires one posted approved bill and reclassifies the fund release without duplicating bill expense', function (): void {
    seedDonorFundAccounts();
    $fund = donorFundWithGl('1000.00');
    $actor = donorFundActor();
    $this->actingAs($actor);
    $bill = approvedDonorBill($actor, '250.00');
    $expense = FinAccount::query()->where('code', '6000')->firstOrFail();
    $service = app(DonorFundService::class);
    $payload = [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'description' => 'Apply approved programme bill',
        'amount' => '250.00',
        'reference' => $bill->bill_number,
        'expense_account_id' => $expense->id,
        'bill_id' => $bill->id,
    ];

    $application = $service->recordExpenditure($fund, $payload);
    $sourceReplay = $service->recordExpenditure($fund->fresh(), [
        ...$payload,
        'idempotency_key' => donorRequestKey(),
    ]);

    expect($sourceReplay->id)->toBe($application->id)
        ->and((int) $application->site_id)->toBe((int) $actor->hrEmployeeProfile->primary_site_id)
        ->and((int) $application->funding_stream_id)->toBe((int) $fund->funding_stream_id)
        ->and(FinDonorFundTransaction::query()->where('bill_id', $bill->id)->count())->toBe(1)
        ->and((string) $fund->fresh()->total_spent)->toBe('250.00')
        ->and((string) $fund->fresh()->available_balance)->toBe('750.00');

    $journal = FinJournal::query()->findOrFail($application->journal_id);
    [$debits, $credits] = donorJournalTotals($journal);
    expect($debits)->toBe('250.00')
        ->and($credits)->toBe('250.00')
        ->and($journal->lines->firstWhere('debit', '250.00')->account->code)->toBe('2600')
        ->and($journal->lines->firstWhere('credit', '250.00')->account->code)->toBe('4220')
        ->and($journal->lines->every(
            fn ($line) => (int) $line->site_id === (int) $application->site_id
                && (int) $line->funding_stream_id === (int) $application->funding_stream_id,
        ))->toBeTrue()
        ->and($journal->lines->contains(fn ($line) => $line->account->code === '6000'))->toBeFalse();

    expect(fn () => $service->recordExpenditure($fund->fresh(), [
        ...$payload,
        'idempotency_key' => donorRequestKey(),
        'amount' => '200.00',
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects manual expenditure and restricted overspend before any donor effect', function (): void {
    seedDonorFundAccounts();
    $fund = donorFundWithGl('100.00');
    $actor = donorFundActor();
    $this->actingAs($actor);
    $service = app(DonorFundService::class);

    expect(fn () => $service->recordExpenditure($fund, [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'description' => 'Unsupported manual expense',
        'amount' => '10.00',
        'bill_id' => null,
    ]))->toThrow(InvalidArgumentException::class, 'posted approved bill');

    $bill = approvedDonorBill($actor, '150.00');
    expect(fn () => $service->recordExpenditure($fund->fresh(), [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'description' => 'Over available balance',
        'amount' => '150.00',
        'bill_id' => $bill->id,
    ]))->toThrow(InvalidArgumentException::class, 'Insufficient fund balance');

    expect(FinDonorFundTransaction::query()->count())->toBe(0)
        ->and((string) $fund->fresh()->total_spent)->toBe('0.00')
        ->and((string) $fund->fresh()->available_balance)->toBe('100.00');
});

it('rolls back the application, journal, and aggregate when posting fails', function (): void {
    seedDonorFundAccounts();
    $fund = donorFundWithGl('500.00');
    $actor = donorFundActor();
    $this->actingAs($actor);
    $bill = approvedDonorBill($actor, '125.00');
    FinFiscalPeriod::query()->update(['status' => 'closed']);

    expect(fn () => app(DonorFundService::class)->recordExpenditure($fund, [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'description' => 'Posting must roll back',
        'amount' => '125.00',
        'bill_id' => $bill->id,
    ]))->toThrow(InvalidArgumentException::class, "expected 'open'");

    expect(FinDonorFundTransaction::query()->count())->toBe(0)
        ->and(FinJournal::query()->where('source_type', FinDonorFundTransaction::class)->count())->toBe(0)
        ->and((string) $fund->fresh()->total_spent)->toBe('0.00')
        ->and((string) $fund->fresh()->available_balance)->toBe('500.00');
});

it('records one immutable reversal and reverses the journal and roll-forward exactly once', function (): void {
    seedDonorFundAccounts();
    $fund = donorFundWithGl('1000.00');
    $actor = donorFundActor(permissions: ['finance.admin']);
    $this->actingAs($actor);
    $bill = approvedDonorBill($actor, '250.00');
    $service = app(DonorFundService::class);
    $application = $service->recordExpenditure($fund, [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'description' => 'Programme delivery',
        'amount' => '250.00',
        'bill_id' => $bill->id,
    ]);
    $reversalPayload = [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'reason' => 'Bill allocation corrected',
        'reference' => 'REV-DONOR-1',
    ];

    $reversal = $service->reverseTransaction($application, $reversalPayload);
    $replay = $service->reverseTransaction($application->fresh(), $reversalPayload);
    $this->post(
        route('finance.donor-funds.transactions.reverse', [$fund, $application]),
        $reversalPayload,
    )->assertRedirect(route('finance.donor-funds.show', $fund));
    expect(fn () => $service->reverseTransaction($application->fresh(), [
        ...$reversalPayload,
        'reason' => 'Changed reversal request',
    ]))->toThrow(InvalidArgumentException::class, 'different request');

    expect($replay->id)->toBe($reversal->id)
        ->and((int) $reversal->reversal_of_transaction_id)->toBe($application->id)
        ->and((int) $reversal->site_id)->toBe((int) $application->site_id)
        ->and((int) $reversal->funding_stream_id)->toBe((int) $application->funding_stream_id)
        ->and((string) $reversal->amount)->toBe('-250.00')
        ->and((string) $fund->fresh()->total_spent)->toBe('0.00')
        ->and((string) $fund->fresh()->available_balance)->toBe('1000.00')
        ->and(FinDonorFundTransaction::query()->where('reversal_of_transaction_id', $application->id)->count())->toBe(1);

    $reversalJournal = FinJournal::query()->findOrFail($reversal->journal_id);
    $reversalJournal->load('lines.account');
    expect($reversalJournal->lines->firstWhere('debit', '250.00')->account->code)->toBe('4220')
        ->and($reversalJournal->lines->firstWhere('credit', '250.00')->account->code)->toBe('2600')
        ->and($reversalJournal->source_type)->toBe(FinDonorFundTransaction::class)
        ->and((int) $reversalJournal->source_id)->toBe($reversal->id)
        ->and((int) $reversalJournal->reversal_of_journal_id)->toBe($application->journal_id)
        ->and((int) $application->journal->fresh()->reversed_by_journal_id)->toBe($reversalJournal->id);

    expect(fn () => $application->update(['description' => 'Mutated']))
        ->toThrow(RuntimeException::class, 'immutable');
    $application->refresh();
    expect(fn () => $application->forceFill(['journal_id' => $reversalJournal->id])->save())
        ->toThrow(RuntimeException::class, 'immutable');
    expect(fn () => $application->delete())
        ->toThrow(RuntimeException::class, 'cannot be deleted');
});

it('rejects a bill whose posted journal no longer owns the source before any donor effect', function (): void {
    seedDonorFundAccounts();
    $fund = donorFundWithGl('500.00');
    $actor = donorFundActor();
    $this->actingAs($actor);
    $bill = approvedDonorBill($actor, '125.00');
    $bill->journal()->update(['source_id' => $bill->id + 1]);

    expect(fn () => app(DonorFundService::class)->recordExpenditure($fund, [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'description' => 'Broken source lineage',
        'amount' => '125.00',
        'bill_id' => $bill->id,
    ]))->toThrow(InvalidArgumentException::class, 'posted approved bill');

    expect(FinDonorFundTransaction::query()->count())->toBe(0)
        ->and(FinJournal::query()->where('source_type', FinDonorFundTransaction::class)->count())->toBe(0)
        ->and((string) $fund->fresh()->total_spent)->toBe('0.00')
        ->and((string) $fund->fresh()->available_balance)->toBe('500.00');
});

it('rejects an explicitly conflicting bill funding-stream classification before any donor effect', function (): void {
    seedDonorFundAccounts();
    $fund = donorFundWithGl('500.00');
    $actor = donorFundActor();
    $this->actingAs($actor);
    $bill = approvedDonorBill($actor, '125.00');
    $otherStream = FinFundingStream::query()->create([
        'organization_id' => 1,
        'code' => 'OTHER-PROGRAMME',
        'name' => 'Other Programme',
        'funder_type' => 'other',
        'default_revenue_account_id' => FinAccount::query()->where('code', '4220')->value('id'),
        'is_active' => true,
    ]);
    $bill->lines()->update(['funding_stream_id' => $otherStream->id]);

    expect(fn () => app(DonorFundService::class)->recordExpenditure($fund, [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'description' => 'Conflicting programme bill',
        'amount' => '125.00',
        'bill_id' => $bill->id,
    ]))->toThrow(InvalidArgumentException::class, 'conflicts with this donor fund');

    expect(FinDonorFundTransaction::query()->count())->toBe(0)
        ->and((string) $fund->fresh()->total_spent)->toBe('0.00')
        ->and((string) $fund->fresh()->available_balance)->toBe('500.00');
});

it('requires finance review if the source bill Site changes before reversal', function (): void {
    seedDonorFundAccounts();
    $fund = donorFundWithGl('500.00');
    $actor = donorFundActor();
    $this->actingAs($actor);
    $bill = approvedDonorBill($actor, '125.00');
    $application = app(DonorFundService::class)->recordExpenditure($fund, [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'description' => 'Site-bound programme bill',
        'amount' => '125.00',
        'bill_id' => $bill->id,
    ]);
    $bill->update(['site_id' => Site::factory()->create()->id]);

    expect(fn () => app(DonorFundService::class)->reverseTransaction($application, [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'reason' => 'Attempt after Site reassignment',
    ]))->toThrow(InvalidArgumentException::class, 'Site lineage changed');

    expect(FinDonorFundTransaction::query()->where('reversal_of_transaction_id', $application->id)->exists())->toBeFalse()
        ->and((string) $fund->fresh()->total_spent)->toBe('125.00')
        ->and((string) $fund->fresh()->available_balance)->toBe('375.00');
});

it('requires finance review instead of partially reversing an unjournaled legacy application', function (): void {
    seedDonorFundAccounts();
    $fund = donorFundWithGl('100.00');
    $actor = donorFundActor();
    $this->actingAs($actor);
    $legacy = $fund->transactions()->create([
        'transaction_date' => now()->toDateString(),
        'type' => 'receipt',
        'description' => 'Legacy unjournaled receipt',
        'amount' => '100.00',
        'created_by' => $actor->id,
    ]);

    expect(fn () => app(DonorFundService::class)->reverseTransaction($legacy, [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'reason' => 'Unsafe legacy reversal',
    ]))->toThrow(InvalidArgumentException::class, 'requires finance review');

    expect(FinDonorFundTransaction::query()->count())->toBe(1)
        ->and(FinJournal::query()->where('source_type', FinDonorFundTransaction::class)->count())->toBe(0)
        ->and((string) $fund->fresh()->total_received)->toBe('100.00')
        ->and((string) $fund->fresh()->available_balance)->toBe('100.00');
});

it('rolls back donor reversal when the source journal was reversed outside donor lineage', function (): void {
    seedDonorFundAccounts();
    $fund = donorFundWithGl();
    $actor = donorFundActor();
    $this->actingAs($actor);
    $service = app(DonorFundService::class);
    $receipt = $service->recordReceipt($fund, [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'description' => 'Restricted receipt',
        'amount' => '100.00',
    ]);
    app(JournalPostingService::class)->reverse($receipt->journal, 'External adjustment');

    expect(fn () => $service->reverseTransaction($receipt->fresh(), [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'reason' => 'Duplicate reversal path',
    ]))->toThrow(InvalidArgumentException::class, 'outside this application lineage');

    expect(FinDonorFundTransaction::query()->count())->toBe(1)
        ->and(FinDonorFundTransaction::query()->whereNotNull('reversal_of_transaction_id')->count())->toBe(0)
        ->and((string) $fund->fresh()->total_received)->toBe('100.00')
        ->and((string) $fund->fresh()->available_balance)->toBe('100.00');
});

it('blocks receipt reversal while restricted applications depend on the receipt', function (): void {
    seedDonorFundAccounts();
    $fund = donorFundWithGl();
    $actor = donorFundActor();
    $this->actingAs($actor);
    $service = app(DonorFundService::class);
    $receipt = $service->recordReceipt($fund, [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'description' => 'Restricted grant',
        'amount' => '500.00',
    ]);
    $bill = approvedDonorBill($actor, '100.00');
    $service->recordExpenditure($fund->fresh(), [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'description' => 'Programme bill',
        'amount' => '100.00',
        'bill_id' => $bill->id,
    ]);

    expect(fn () => $service->reverseTransaction($receipt, [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'reason' => 'Attempted return',
    ]))->toThrow(InvalidArgumentException::class, 'restricted funds are applied');

    expect((string) $fund->fresh()->total_received)->toBe('500.00')
        ->and((string) $fund->fresh()->total_spent)->toBe('100.00')
        ->and(FinDonorFundTransaction::query()->where('reversal_of_transaction_id', $receipt->id)->count())->toBe(0);
});

it('conceals a foreign-Site bill and keeps all-Sites scope separate from finance action authority', function (): void {
    seedDonorFundAccounts();
    $assignedSite = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    $scopedActor = donorFundActor($assignedSite, ['finance.admin']);
    $globalActor = donorFundActor($assignedSite, [
        'finance.admin',
        DonorFundApplicationSiteScope::GLOBAL_PERMISSION,
    ]);
    $scopeOnlyActor = donorFundActor($assignedSite, [
        DonorFundApplicationSiteScope::GLOBAL_PERMISSION,
    ]);
    $fund = donorFundWithGl('500.00');
    $bill = approvedDonorBill($scopedActor, '125.00', $foreignSite);
    $payload = [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'description' => 'Foreign Site programme bill',
        'amount' => '125.00',
        'bill_id' => $bill->id,
    ];

    $this->actingAs($scopedActor)
        ->post(route('finance.donor-funds.expenditure', $fund), $payload)
        ->assertNotFound();

    expect(FinDonorFundTransaction::query()->count())->toBe(0)
        ->and(FinJournal::query()->where('source_type', FinDonorFundTransaction::class)->count())->toBe(0)
        ->and((string) $fund->fresh()->total_spent)->toBe('0.00')
        ->and((string) $fund->fresh()->available_balance)->toBe('500.00');

    $this->actingAs($scopeOnlyActor)
        ->post(route('finance.donor-funds.expenditure', $fund), $payload)
        ->assertForbidden();

    $this->actingAs($globalActor)
        ->post(route('finance.donor-funds.expenditure', $fund), $payload)
        ->assertRedirect(route('finance.donor-funds.show', $fund));

    expect(FinDonorFundTransaction::query()->where('bill_id', $bill->id)->count())->toBe(1)
        ->and((string) $fund->fresh()->total_spent)->toBe('125.00')
        ->and((string) $fund->fresh()->available_balance)->toBe('375.00');
});

it('conceals a foreign-organisation donor fund before validating a direct mutation', function (): void {
    $actor = donorFundActor(permissions: ['finance.admin']);
    $foreignFund = FinDonorFund::factory()->create(['organization_id' => 2]);

    $this->actingAs($actor)
        ->post(route('finance.donor-funds.receipt', $foreignFund), [])
        ->assertNotFound();

    expect(FinDonorFundTransaction::query()->where('fund_id', $foreignFund->id)->exists())->toBeFalse();
});

it('serializes concurrent exact receipt replay into one application and one journal on MySQL', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');
    seedDonorFundAccounts();
    $fund = donorFundWithGl();
    $actor = donorFundActor();
    $payload = [
        'idempotency_key' => donorRequestKey(),
        'transaction_date' => now()->toDateString(),
        'description' => 'Concurrent grant receipt',
        'amount' => '325.00',
        'actor_id' => $actor->id,
    ];
    $database = $connection->getDatabaseName();

    $connection->commit();
    try {
        $ids = donorFundConcurrentReceiptRound($connection, $database, $fund->id, $payload);

        expect(array_unique($ids))->toHaveCount(1)
            ->and(FinDonorFundTransaction::query()->where('fund_id', $fund->id)->count())->toBe(1)
            ->and(FinJournal::query()->where('source_type', FinDonorFundTransaction::class)->count())->toBe(1)
            ->and((string) $fund->fresh()->total_received)->toBe('325.00')
            ->and((string) $fund->fresh()->available_balance)->toBe('325.00');
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        DB::table('fin_donor_fund_transactions')->delete();
        DB::table('fin_donor_funds')->delete();
        DB::table('fin_journal_lines')->delete();
        DB::table('fin_journals')->delete();
        DB::table('fin_journal_sequences')->where('organization_id', 1)->delete();
        DB::table('fin_fiscal_periods')->delete();
        DB::table('fin_funding_streams')->delete();
        DB::table('audit_logs')->delete();
        DB::table('fin_accounts')->delete();
        DB::table('users')->where('id', $actor->id)->delete();
        $connection->beginTransaction();
    }
});

/**
 * @param  array<string, int|string|null>  $payload
 * @return array<int, int>
 */
function donorFundConcurrentReceiptRound(
    ConnectionInterface $connection,
    string $database,
    int $fundId,
    array $payload,
): array {
    $token = (string) Str::uuid();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."donor-fund-release-{$token}";
    $readyPaths = [];
    $attemptPaths = [];
    $processes = [];

    $connection->beginTransaction();
    app(JournalPostingService::class)->lockJournalSequence(1);

    try {
        foreach ([0, 1] as $index) {
            $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."donor-fund-ready-{$index}-{$token}";
            $attemptPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."donor-fund-attempt-{$index}-{$token}";
            $processes[] = donorFundStartReceiptWorker(
                $database,
                $fundId,
                $payload,
                $readyPaths[$index],
                $attemptPaths[$index],
                $releasePath,
            );
        }

        donorFundWaitForFiles($readyPaths, 'Concurrent donor-fund workers did not become ready.');
        touch($releasePath);
        donorFundWaitForFiles($attemptPaths, 'Concurrent donor-fund workers did not reach the application command.');
        usleep(250_000);
        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A donor-fund worker exited before lock release.');
            }
        }

        $connection->commit();
        $ids = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A donor-fund concurrency worker failed.');
            }
            $ids[] = (int) json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR)['transaction_id'];
        }

        return $ids;
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

/** @param array<string, int|string|null> $payload */
function donorFundStartReceiptWorker(
    string $database,
    int $fundId,
    array $payload,
    string $readyPath,
    string $attemptPath,
    string $releasePath,
): Process {
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$fund = App\Domain\Finance\Models\FinDonorFund::query()->findOrFail((int) $argv[2]);
$payload = json_decode(base64_decode($argv[3]), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[6])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the donor-fund release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[5], 'attempting');
$transaction = $app->make(App\Domain\Finance\Services\DonorFundService::class)
    ->recordReceipt($fund, $payload);
echo json_encode(['transaction_id' => $transaction->id], JSON_THROW_ON_ERROR);
PHP;

    $process = new Process([
        PHP_BINARY,
        '-r',
        $worker,
        base_path(),
        (string) $fundId,
        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
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

/** @param array<int, string> $paths */
function donorFundWaitForFiles(array $paths, string $message): void
{
    $deadline = microtime(true) + 15;
    while (collect($paths)->contains(fn (string $path) => ! is_file($path))) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }
        usleep(10_000);
    }
}
