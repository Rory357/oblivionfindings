<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinEftposBatch;
use App\Domain\Finance\Models\FinEftposTerminal;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\EftposReconciliationService;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EftposSettlementJournalPostingTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        app(FinanceSeeder::class)->run($this->orgId);

        FinFiscalPeriod::create([
            'organization_id' => $this->orgId,
            'name' => 'FY2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);
    }

    public function test_reconciled_eftpos_batch_posts_bank_card_clearing_journal_once(): void
    {
        $bankGlAccount = FinAccount::forOrganization($this->orgId)
            ->where('code', '1000')
            ->firstOrFail();
        $cardClearingAccount = FinAccount::forOrganization($this->orgId)
            ->where('code', '1180')
            ->firstOrFail();
        $bankAccount = FinBankAccount::factory()->create([
            'organization_id' => $this->orgId,
            'gl_account_id' => $bankGlAccount->id,
            'account_type' => 'cheque',
        ]);
        $terminal = FinEftposTerminal::create([
            'organization_id' => $this->orgId,
            'terminal_id' => 'TERM-001',
            'name' => 'Front Desk EFTPOS',
            'provider' => 'worldline',
            'merchant_id' => 'MERCHANT-001',
            'bank_account_id' => $bankAccount->id,
            'is_active' => true,
        ]);
        $batch = FinEftposBatch::create([
            'organization_id' => $this->orgId,
            'terminal_id' => $terminal->id,
            'batch_number' => 'BATCH-SETTLE-001',
            'batch_date' => '2026-05-01',
            'settlement_date' => '2026-05-02',
            'total_transactions' => 3,
            'total_amount' => '130.00',
            'total_refunds' => '5.00',
            'net_amount' => '125.00',
            'fees' => '1.55',
            'settlement_amount' => '123.45',
            'status' => 'closed',
        ]);
        $bankTransaction = FinBankTransaction::create([
            'organization_id' => $this->orgId,
            'bank_account_id' => $bankAccount->id,
            'transaction_date' => '2026-05-02',
            'amount' => '123.45',
            'description' => 'EFTPOS settlement BATCH-SETTLE-001',
            'reference' => 'BANK-EFTPOS-001',
            'source' => 'manual',
            'status' => 'unreconciled',
        ]);

        $reconciled = app(EftposReconciliationService::class)
            ->reconcileBatch($batch, $bankTransaction->id);

        $this->assertSame('reconciled', $reconciled->status);
        $this->assertNotNull($reconciled->journal_id);
        $this->assertNotNull($reconciled->gl_posted_at);

        $journal = FinJournal::with('lines.account')->findOrFail($reconciled->journal_id);
        $this->assertSame('posted', $journal->status);
        $this->assertSame(FinEftposBatch::class, $journal->source_type);
        $this->assertSame($batch->id, $journal->source_id);
        $this->assertSame('123.45', (string) $journal->total_amount);
        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account_id === $bankGlAccount->id
                && (string) $line->debit === '123.45'
                && (string) $line->credit === '0.00'
        ));
        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account_id === $cardClearingAccount->id
                && (string) $line->debit === '0.00'
                && (string) $line->credit === '123.45'
        ));

        $secondAttempt = app(EftposReconciliationService::class)
            ->reconcileBatch($reconciled, $bankTransaction->id);

        $this->assertSame($journal->id, $secondAttempt->journal_id);
        $this->assertSame(1, FinJournal::where('source_type', FinEftposBatch::class)
            ->where('source_id', $batch->id)
            ->count());
    }
}
