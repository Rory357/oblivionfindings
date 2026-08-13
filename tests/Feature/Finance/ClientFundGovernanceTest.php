<?php

use App\Domain\Finance\Jobs\PostClientFundJournalJob;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\ClientFundJournalService;
use App\Domain\Finance\Services\ClientFundReconciliationService;
use App\Domain\Finance\Services\ClientFundTransactionService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientFund;
use App\Models\ClientFundTransaction;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function clientMoneyGovernanceUser(Site $site, array $permissions): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-MONEY-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Client Money Custodian',
        'position_role' => 'finance',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    foreach ($permissions as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'finance', 'module' => 'Finance'],
        );
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    return $user;
}

function clientMoneyGovernanceFund(
    Site $site,
    string $balance = '100.00',
    ?Client $client = null,
    string $name = 'Client trust',
): ClientFund {
    $client ??= Client::factory()->create(['site_id' => $site->id]);

    return ClientFund::query()->create([
        'client_id' => $client->id,
        'fund_name' => $name,
        'fund_type' => 'trust',
        'currency_code' => 'NZD',
        'balance' => $balance,
        'available_balance' => $balance,
        'is_active' => true,
    ]);
}

function clientMoneyPayload(string $type, string $amount, array $overrides = []): array
{
    return [
        'type' => $type,
        'amount' => $amount,
        'description' => 'Governed client-money movement',
        'reference' => 'CM-'.Str::upper(Str::random(8)),
        'idempotency_key' => Str::uuid()->toString(),
        ...$overrides,
    ];
}

function seedClientMoneyGl(): void
{
    app(FinanceSeeder::class)->run(1);
    FinFiscalPeriod::query()->create([
        'organization_id' => 1,
        'name' => 'Current client-money period',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);
}

beforeEach(function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    Queue::fake([PostClientFundJournalJob::class]);
});

it('does not apply a debit beyond available cleared balance', function () {
    $site = Site::factory()->create();
    $maker = clientMoneyGovernanceUser($site, ['client_funds.manage']);
    $checker = clientMoneyGovernanceUser($site, ['client_funds.approve']);
    $fund = clientMoneyGovernanceFund($site, '100.00');
    $fund->forceFill(['available_balance' => '40.00'])->save();

    $transaction = app(ClientFundTransactionService::class)->record(
        $fund,
        $maker,
        clientMoneyPayload('debit', '40.01'),
    );

    expect($transaction->status)->toBe('pending')
        ->and($transaction->balance_effect_applied_at)->toBeNull();

    expect(fn () => app(ClientFundTransactionService::class)->approve(
        $transaction,
        $checker,
        'Receipt and available balance checked.',
    ))->toThrow(ValidationException::class);

    expect((string) $fund->fresh()->balance)->toBe('100.00')
        ->and((string) $fund->fresh()->available_balance)->toBe('40.00')
        ->and($transaction->fresh()->status)->toBe('pending');
});

it('honours only a fully evidenced product-authorized overdraft policy state', function () {
    $site = Site::factory()->create();
    $maker = clientMoneyGovernanceUser($site, ['client_funds.manage']);
    $checker = clientMoneyGovernanceUser($site, ['client_funds.approve']);
    $governedFund = clientMoneyGovernanceFund($site, '10.00');
    $incompleteFund = clientMoneyGovernanceFund($site, '10.00');
    $governedFund->forceFill([
        'overdraft_policy_state' => 'authorized',
        'overdraft_limit' => '20.00',
        'overdraft_authorized_by' => $checker->id,
        'overdraft_authorized_at' => now(),
        'overdraft_authorization_reason' => 'Documented client-money committee authority CM-2026-01.',
    ])->save();
    $incompleteFund->forceFill([
        'overdraft_policy_state' => 'authorized',
        'overdraft_limit' => '20.00',
    ])->save();
    $service = app(ClientFundTransactionService::class);

    $governedDebit = $service->record($governedFund, $maker, clientMoneyPayload('debit', '25.00'));
    $service->approve($governedDebit, $checker, 'Exceptional authority and limit checked.');
    $incompleteDebit = $service->record($incompleteFund, $maker, clientMoneyPayload('debit', '25.00'));

    expect((string) $governedFund->fresh()->available_balance)->toBe('-15.00')
        ->and(fn () => $service->approve($incompleteDebit, $checker, 'Incomplete policy state.'))
        ->toThrow(ValidationException::class)
        ->and((string) $incompleteFund->fresh()->available_balance)->toBe('10.00');
});

it('requires an independent authorized checker and applies an approved debit once', function () {
    $site = Site::factory()->create();
    $maker = clientMoneyGovernanceUser($site, ['client_funds.manage', 'client_funds.approve']);
    $checker = clientMoneyGovernanceUser($site, ['client_funds.approve']);
    $fund = clientMoneyGovernanceFund($site);
    $service = app(ClientFundTransactionService::class);

    $transaction = $service->record($fund, $maker, clientMoneyPayload('debit', '25.00'));

    expect(fn () => $service->approve($transaction, $maker, 'Self approval.'))
        ->toThrow(ValidationException::class);

    $approved = $service->approve($transaction, $checker, 'Invoice and receipt independently checked.');
    $retried = $service->approve($transaction, $checker, 'Retry after response loss.');

    expect($approved->status)->toBe('approved')
        ->and($approved->approved_by)->toBe($checker->id)
        ->and($retried->id)->toBe($approved->id)
        ->and((string) $fund->fresh()->available_balance)->toBe('75.00')
        ->and($fund->transactions()->whereNotNull('balance_effect_applied_at')->count())->toBe(1)
        ->and(fn () => $service->approve($transaction, $maker, 'Self-approval retry.'))
        ->toThrow(ValidationException::class);
});

it('denies a checker outside the current Site and permits only the explicit all-Sites capability', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $maker = clientMoneyGovernanceUser($site, ['client_funds.manage']);
    $foreignChecker = clientMoneyGovernanceUser($otherSite, ['client_funds.approve']);
    $globalChecker = clientMoneyGovernanceUser($otherSite, [
        'client_funds.approve',
        'client_funds.viewAllSites',
    ]);
    $fund = clientMoneyGovernanceFund($site);
    $transaction = app(ClientFundTransactionService::class)->record(
        $fund,
        $maker,
        clientMoneyPayload('debit', '10.00'),
    );

    expect(fn () => app(ClientFundTransactionService::class)->approve(
        $transaction,
        $foreignChecker,
        'Foreign Site decision.',
    ))->toThrow(ModelNotFoundException::class);

    app(ClientFundTransactionService::class)->approve(
        $transaction,
        $globalChecker,
        'Central delegated client-money review.',
    );

    expect((string) $fund->fresh()->balance)->toBe('90.00')
        ->and($transaction->fresh()->approved_by)->toBe($globalChecker->id);
});

it('replays a request once and requires approval for threshold credits', function () {
    config()->set('finance.client_funds.approval_threshold', '100.00');
    $site = Site::factory()->create();
    $maker = clientMoneyGovernanceUser($site, ['client_funds.manage']);
    $fund = clientMoneyGovernanceFund($site, '0.00');
    $payload = clientMoneyPayload('credit', '100.00');
    $service = app(ClientFundTransactionService::class);

    $first = $service->record($fund, $maker, $payload);
    $retry = $service->record($fund, $maker, $payload);

    expect($first->id)->toBe($retry->id)
        ->and($first->status)->toBe('pending')
        ->and($fund->transactions()->count())->toBe(1)
        ->and((string) $fund->fresh()->balance)->toBe('0.00');
});

it('moves money only between funds owned by the same Client', function () {
    seedClientMoneyGl();
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $maker = clientMoneyGovernanceUser($site, ['client_funds.manage']);
    $checker = clientMoneyGovernanceUser($site, ['client_funds.approve']);
    $source = clientMoneyGovernanceFund($site, '100.00', $client, 'Daily money');
    $destination = clientMoneyGovernanceFund($site, '20.00', $client, 'Activities');
    $foreign = clientMoneyGovernanceFund($site, '10.00');
    $service = app(ClientFundTransactionService::class);

    expect(fn () => $service->record($source, $maker, clientMoneyPayload('transfer', '30.00', [
        'destination_fund_id' => $foreign->id,
    ])))->toThrow(ModelNotFoundException::class);

    $transfer = $service->record($source, $maker, clientMoneyPayload('transfer', '30.00', [
        'destination_fund_id' => $destination->id,
    ]));
    $service->approve($transfer, $checker, 'Both fund purposes and balance checked.');
    $journal = app(ClientFundJournalService::class)->postClientFundJournal($transfer->fresh());
    $dimensionedLines = DB::table('fin_journal_lines')
        ->where('journal_id', $journal->id)
        ->orderBy('id')
        ->get();

    expect((string) $source->fresh()->balance)->toBe('70.00')
        ->and((string) $destination->fresh()->balance)->toBe('50.00')
        ->and($transfer->fresh()->counterpart_transaction_id)->not->toBeNull()
        ->and($destination->transactions()->where('source_type', 'client_fund_transfer_counterpart')->count())->toBe(1)
        ->and($dimensionedLines)->toHaveCount(2)
        ->and($dimensionedLines->pluck('client_fund_id')->sort()->values()->all())
        ->toBe(collect([$source->id, $destination->id])->sort()->values()->all());
});

it('creates one linked equal-and-opposite reversal with retained GL provenance', function () {
    seedClientMoneyGl();
    $site = Site::factory()->create();
    $maker = clientMoneyGovernanceUser($site, ['client_funds.manage']);
    $checker = clientMoneyGovernanceUser($site, ['client_funds.approve']);
    $fund = clientMoneyGovernanceFund($site, '0.00');
    $service = app(ClientFundTransactionService::class);
    $journalService = app(ClientFundJournalService::class);

    $credit = $service->record($fund, $maker, clientMoneyPayload('credit', '75.00'));
    $journalService->postClientFundJournal($credit);
    $reversalData = [
        'idempotency_key' => Str::uuid()->toString(),
        'reason' => 'Deposit was attributed to the wrong receipt.',
        'reference' => 'REV-CM-001',
    ];
    $reversal = $service->reverse($credit->fresh(), $maker, $reversalData);
    $sameReversal = $service->reverse($credit->fresh(), $maker, $reversalData);
    $service->approve($reversal, $checker, 'Original receipt attribution independently verified.');
    $reversalJournal = $journalService->postClientFundJournal($reversal->fresh());

    expect($sameReversal->id)->toBe($reversal->id)
        ->and($reversal->fresh()->reversal_of_id)->toBe($credit->id)
        ->and((string) $reversal->fresh()->amount)->toBe((string) $credit->amount)
        ->and((string) $fund->fresh()->balance)->toBe('0.00')
        ->and($credit->fresh()->status)->toBe('reversed')
        ->and($credit->fresh()->journal->reversed_by_journal_id)->toBe($reversalJournal->id)
        ->and(ClientFundTransaction::query()->where('reversal_of_id', $credit->id)->count())->toBe(1);
});

it('retains an approved effect for recovery when GL posting fails without partial journals', function () {
    app(FinanceSeeder::class)->run(1);
    $site = Site::factory()->create();
    $maker = clientMoneyGovernanceUser($site, ['client_funds.manage']);
    $fund = clientMoneyGovernanceFund($site, '0.00');
    $transaction = app(ClientFundTransactionService::class)->record(
        $fund,
        $maker,
        clientMoneyPayload('credit', '25.00'),
    );
    $job = new PostClientFundJournalJob($transaction);

    expect(fn () => $job->handle(app(ClientFundJournalService::class)))
        ->toThrow(InvalidArgumentException::class);

    expect($transaction->fresh()->status)->toBe('approved')
        ->and($transaction->fresh()->journal_id)->toBeNull()
        ->and($transaction->fresh()->posting_failed_at)->not->toBeNull()
        ->and((string) $fund->fresh()->balance)->toBe('25.00')
        ->and(FinJournal::query()->count())->toBe(0);

    FinFiscalPeriod::query()->create([
        'organization_id' => 1,
        'name' => 'Recovered period',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);
    $job->handle(app(ClientFundJournalService::class));
    $job->handle(app(ClientFundJournalService::class));

    expect($transaction->fresh()->status)->toBe('posted')
        ->and($transaction->fresh()->posting_failed_at)->toBeNull()
        ->and(FinJournal::query()->where('source_type', 'client_fund_transaction')->count())->toBe(1);
});

it('detects per-Client GL dimension mismatches hidden by correct aggregate totals', function () {
    seedClientMoneyGl();
    $site = Site::factory()->create();
    $maker = clientMoneyGovernanceUser($site, ['client_funds.manage']);
    $fundA = clientMoneyGovernanceFund($site, '0.00', name: 'Client A trust');
    $fundB = clientMoneyGovernanceFund($site, '0.00', name: 'Client B trust');
    $service = app(ClientFundTransactionService::class);
    $journalService = app(ClientFundJournalService::class);

    $transactionA = $service->record($fundA, $maker, clientMoneyPayload('credit', '40.00'));
    $transactionB = $service->record($fundB, $maker, clientMoneyPayload('credit', '60.00'));
    $journalService->postClientFundJournal($transactionA);
    $journalService->postClientFundJournal($transactionB);

    $lineA = DB::table('fin_journal_lines')
        ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
        ->where('fin_journal_lines.journal_id', $transactionA->fresh()->journal_id)
        ->where('fin_accounts.code', '2500')
        ->select('fin_journal_lines.id')
        ->first();
    $lineB = DB::table('fin_journal_lines')
        ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
        ->where('fin_journal_lines.journal_id', $transactionB->fresh()->journal_id)
        ->where('fin_accounts.code', '2500')
        ->select('fin_journal_lines.id')
        ->first();
    DB::table('fin_journal_lines')->where('id', $lineA->id)->update([
        'client_id' => $fundB->client_id,
        'client_fund_id' => $fundB->id,
    ]);
    DB::table('fin_journal_lines')->where('id', $lineB->id)->update([
        'client_id' => $fundA->client_id,
        'client_fund_id' => $fundA->id,
    ]);

    $aggregateGl = DB::table('fin_journal_lines')
        ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
        ->where('fin_accounts.code', '2500')
        ->selectRaw('SUM(credit) - SUM(debit) AS balance')
        ->value('balance');
    $reconciler = app(ClientFundReconciliationService::class);
    $resultA = $reconciler->reconcile($fundA);
    $resultB = $reconciler->reconcile($fundB);

    expect((string) $aggregateGl)->toBe('100.00')
        ->and($resultA['status'])->toBe('mismatch')
        ->and($resultB['status'])->toBe('mismatch')
        ->and($fundA->fresh()->reconciliation_status)->toBe('mismatch')
        ->and($fundB->fresh()->reconciliation_status)->toBe('mismatch');
});
