<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Jobs\PostClientFundJournalJob;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Models\Client;
use App\Models\ClientFund;
use App\Models\Site;
use App\Observers\ClientFundTransactionObserver;
use Database\Seeders\FinanceSeeder;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientFundJournalDispatchTest extends TestCase
{
    use RefreshDatabase;

    private int $storageContextId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        app(FinanceSeeder::class)->run($this->storageContextId);

        FinFiscalPeriod::create([
            'organization_id' => $this->storageContextId,
            'name' => 'FY2026',
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'open',
        ]);
    }

    public function test_client_fund_credit_and_debit_transactions_post_journals_once(): void
    {
        $this->assertInstanceOf(
            ShouldHandleEventsAfterCommit::class,
            new ClientFundTransactionObserver,
        );

        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $fund = ClientFund::create([
            'client_id' => $client->id,
            'fund_name' => 'Resident Trust',
            'fund_type' => 'trust',
            'balance' => 0,
            'is_active' => true,
        ]);

        $credit = $fund->transactions()->create([
            'transaction_type' => 'credit',
            'amount' => 100,
            'running_balance' => 100,
            'description' => 'Cash deposit',
            'transaction_date' => now()->toDateString(),
        ]);

        // RefreshDatabase holds an outer transaction open, so an after-commit
        // observer cannot fire inside this test. Execute the canonical queued
        // job explicitly while separately asserting the observer contract.
        PostClientFundJournalJob::dispatchSync($credit);
        $credit->refresh();
        $this->assertNotNull($credit->journal_id);

        $creditJournal = FinJournal::with('lines.account')->findOrFail($credit->journal_id);
        $this->assertTrue($creditJournal->lines->contains(
            fn ($line) => $line->account->code === '1010'
                && (string) $line->debit === '100.00'
                && (string) $line->credit === '0.00'
        ));
        $this->assertTrue($creditJournal->lines->contains(
            fn ($line) => $line->account->code === '2500'
                && (string) $line->debit === '0.00'
                && (string) $line->credit === '100.00'
        ));

        $debit = $fund->transactions()->create([
            'transaction_type' => 'debit',
            'amount' => 40,
            'running_balance' => 60,
            'description' => 'Activity payment',
            'transaction_date' => now()->toDateString(),
        ]);

        PostClientFundJournalJob::dispatchSync($debit);
        $debit->refresh();
        $this->assertNotNull($debit->journal_id);

        $debitJournal = FinJournal::with('lines.account')->findOrFail($debit->journal_id);
        $this->assertTrue($debitJournal->lines->contains(
            fn ($line) => $line->account->code === '2500'
                && (string) $line->debit === '40.00'
                && (string) $line->credit === '0.00'
        ));
        $this->assertTrue($debitJournal->lines->contains(
            fn ($line) => $line->account->code === '1010'
                && (string) $line->debit === '0.00'
                && (string) $line->credit === '40.00'
        ));

        PostClientFundJournalJob::dispatchSync($debit->refresh());

        $this->assertSame(1, FinJournal::where('source_type', 'client_fund_transaction')
            ->where('source_id', $debit->id)
            ->count());
    }
}
