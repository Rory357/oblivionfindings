<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Models\Client;
use App\Models\FundingClaim;
use App\Models\ServiceAgreement;
use App\Models\Site;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundingClaimJournalDispatchTest extends TestCase
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

    public function test_submitted_funding_claim_posts_journal_once(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $agreement = ServiceAgreement::factory()
            ->for($client)
            ->create([
                'status' => 'active',
                'funding_body' => 'private',
                'total_budget' => 1000,
            ]);

        $claim = FundingClaim::create([
            'service_agreement_id' => $agreement->id,
            'client_id' => $client->id,
            'claim_reference' => 'FC-TEST-001',
            'status' => 'draft',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'total_amount' => 125.50,
        ]);

        $claim->update(['status' => 'submitted', 'submitted_at' => now()]);

        $claim->refresh();

        $this->assertNotNull($claim->journal_id);
        $this->assertNotNull($claim->gl_posted_at);

        $journal = FinJournal::with('lines.account')->findOrFail($claim->journal_id);

        $this->assertSame('posted', $journal->status);
        $this->assertSame('billing', $journal->type);
        $this->assertSame('funding_claim', $journal->source_type);
        $this->assertSame($claim->id, $journal->source_id);
        $this->assertSame('125.50', (string) $journal->total_amount);
        $this->assertCount(2, $journal->lines);

        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account->code === '1100'
                && (string) $line->debit === '125.50'
                && (string) $line->credit === '0.00'
        ));
        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account->code === '4030'
                && (string) $line->debit === '0.00'
                && (string) $line->credit === '125.50'
        ));

        $claim->update(['status' => 'approved', 'approved_at' => now()]);

        $this->assertSame(1, FinJournal::where('source_type', 'funding_claim')
            ->where('source_id', $claim->id)
            ->count());
    }
}
