<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Services\ClientLedgerService;
use App\Models\Client;
use App\Models\ClientLedgerEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A resident's personal trust balance was polluted by operational cost allocations
 * (the org's cost of supporting them), so families saw a hugely-negative personal
 * balance. The ledger now segregates: operational cost allocations are shown but
 * never move the personal running balance / totals.
 */
it('keeps operational cost allocations out of the personal running balance', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    $user = User::factory()->create(['organization_id' => 1]);

    // Personal money in: a $100 deposit (informational entry — skip the GL observer).
    ClientLedgerEntry::create([
        'tenant_id' => 1, 'client_id' => $client->id, 'type' => 'contribution', 'direction' => 'inflow',
        'amount' => '100.00', 'description' => 'Personal deposit', 'entry_date' => Carbon::parse('2026-02-01'),
        'posts_to_gl' => false, 'recorded_by' => $user->id,
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
