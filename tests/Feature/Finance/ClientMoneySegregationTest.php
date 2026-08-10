<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Services\ClientLedgerService;
use App\Models\Client;
use App\Models\ClientFund;
use App\Models\ClientFundTransaction;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * A resident's personal trust balance was polluted by operational cost allocations
 * (the provider's cost of supporting them), so families saw a hugely-negative personal
 * balance. The ledger segregates: operational cost allocations are shown but never
 * move the personal running balance / totals. (C4: the personal source is the
 * canonical ClientFundTransaction trust store, not the dormant ClientLedgerEntry.)
 */
it('keeps operational cost allocations out of the personal running balance', function () {
    Queue::fake(); // suppress the trust-fund GL-posting job — this test only reads the ledger

    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $user = User::factory()->create();

    // Personal money in: a $100 deposit into the resident's trust fund (canonical store).
    $fund = ClientFund::create([
        'client_id' => $client->id, 'fund_name' => 'Resident Trust',
        'fund_type' => 'trust', 'balance' => 100, 'is_active' => true,
    ]);
    ClientFundTransaction::create([
        'client_fund_id' => $fund->id, 'transaction_type' => 'credit',
        'amount' => '100.00', 'running_balance' => '100.00', 'description' => 'Personal deposit',
        'transaction_date' => Carbon::parse('2026-02-01'), 'recorded_by' => $user->id,
    ]);

    // Operational service cost attributed to the client (NOT their personal money).
    $journal = FinJournal::factory()->create(['organization_id' => 1]);
    $account = FinAccount::factory()->create(['organization_id' => 1, 'type' => 'expense', 'is_active' => true]);
    $jline = FinJournalLine::create([
        'journal_id' => $journal->id, 'account_id' => $account->id,
        'debit' => '5000.00', 'credit' => 0, 'description' => 'Service cost',
    ]);
    FinCostAllocation::create([
        'journal_id' => $journal->id, 'journal_line_id' => $jline->id, 'client_id' => $client->id,
        'amount' => '5000.00', 'event_type' => 'service_cost', 'event_date' => Carbon::parse('2026-02-02'),
    ]);

    $ledger = app(ClientLedgerService::class)->getLedger(
        $client->id, Carbon::parse('2026-01-01'), Carbon::parse('2026-02-28'), withRunningBalance: true,
    );

    $personal = collect($ledger['entries'])->firstWhere('source', 'client_ledger');
    $operational = collect($ledger['entries'])->firstWhere('source', 'cost_allocation');

    // Personal balance is +100, NOT 100 - 5000.
    expect((float) $personal['running_balance'])->toBe(100.0)
        ->and($personal['affects_personal_balance'])->toBeTrue()
        ->and((float) $operational['running_balance'])->toBe(100.0)   // unchanged by the operational row
        ->and($operational['affects_personal_balance'])->toBeFalse();

    // Summary: personal net is +100; operational cost is reported separately.
    expect((float) $ledger['summary']['net'])->toBe(100.0)
        ->and((float) $ledger['summary']['total_outflows'])->toBe(0.0)
        ->and((float) $ledger['summary']['operational_outflows'])->toBe(-5000.0);
});
