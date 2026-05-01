<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinPaymentMatch;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\PaymentMatchingService;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMatchingJournalPostingTest extends TestCase
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
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'open',
        ]);
    }

    public function test_confirming_bill_payment_match_posts_ap_bank_journal_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $bankAccount = $this->bankAccount();
        $vendor = FinVendor::factory()->create(['organization_id' => $this->orgId]);
        $bill = FinBill::factory()->create([
            'organization_id' => $this->orgId,
            'vendor_id' => $vendor->id,
            'bill_number' => 'BILL-MATCH-001',
            'status' => 'approved',
            'total_amount' => 250,
            'amount_paid' => 0,
        ]);
        $transaction = FinBankTransaction::create([
            'organization_id' => $this->orgId,
            'bank_account_id' => $bankAccount->id,
            'transaction_date' => now()->toDateString(),
            'amount' => -250,
            'description' => 'Payment BILL-MATCH-001',
            'reference' => 'BANK-BILL-001',
            'source' => 'manual',
            'status' => 'unreconciled',
        ]);
        $match = FinPaymentMatch::create([
            'organization_id' => $this->orgId,
            'bank_transaction_id' => $transaction->id,
            'matchable_type' => FinBill::class,
            'matchable_id' => $bill->id,
            'confidence_score' => 99,
            'match_reasons' => ['Exact amount match'],
            'status' => 'suggested',
        ]);

        $confirmed = app(PaymentMatchingService::class)->confirmMatch($match, $user->id);

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertNotNull($confirmed->journal_id);

        $journal = FinJournal::with('lines.account')->findOrFail($confirmed->journal_id);
        $this->assertSame('posted', $journal->status);
        $this->assertSame(FinPaymentMatch::class, $journal->source_type);
        $this->assertSame($match->id, $journal->source_id);
        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account->code === '2000'
                && (string) $line->debit === '250.00'
                && (string) $line->credit === '0.00'
        ));
        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account->code === '1000'
                && (string) $line->debit === '0.00'
                && (string) $line->credit === '250.00'
        ));

        $this->assertSame('250.00', (string) $bill->refresh()->amount_paid);
        $this->assertSame('paid', $bill->status);
        $this->assertSame(1, FinPaymentAllocation::where('allocatable_type', FinBill::class)
            ->where('allocatable_id', $bill->id)
            ->where('journal_id', $journal->id)
            ->count());

        app(PaymentMatchingService::class)->confirmMatch($confirmed, $user->id);

        $this->assertSame(1, FinJournal::where('source_type', FinPaymentMatch::class)
            ->where('source_id', $match->id)
            ->count());
        $this->assertSame(1, FinPaymentAllocation::where('source_type', FinPaymentMatch::class)
            ->where('source_id', $match->id)
            ->count());
    }

    public function test_confirming_invoice_receipt_match_posts_bank_ar_journal_and_marks_paid(): void
    {
        $user = User::factory()->create();
        $bankAccount = $this->bankAccount();
        $invoice = FinInvoice::factory()->create([
            'organization_id' => $this->orgId,
            'invoice_number' => 'INV-MATCH-001',
            'status' => 'sent',
            'subtotal' => 100,
            'tax_amount' => 15,
            'total_amount' => 115,
        ]);
        $transaction = FinBankTransaction::create([
            'organization_id' => $this->orgId,
            'bank_account_id' => $bankAccount->id,
            'transaction_date' => now()->toDateString(),
            'amount' => 115,
            'description' => 'Receipt INV-MATCH-001',
            'reference' => 'BANK-INV-001',
            'source' => 'manual',
            'status' => 'unreconciled',
        ]);
        $match = FinPaymentMatch::create([
            'organization_id' => $this->orgId,
            'bank_transaction_id' => $transaction->id,
            'matchable_type' => FinInvoice::class,
            'matchable_id' => $invoice->id,
            'confidence_score' => 99,
            'match_reasons' => ['Exact amount match'],
            'status' => 'suggested',
        ]);

        $confirmed = app(PaymentMatchingService::class)->confirmMatch($match, $user->id);

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertNotNull($confirmed->journal_id);

        $journal = FinJournal::with('lines.account')->findOrFail($confirmed->journal_id);
        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account->code === '1000'
                && (string) $line->debit === '115.00'
                && (string) $line->credit === '0.00'
        ));
        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account->code === '1100'
                && (string) $line->debit === '0.00'
                && (string) $line->credit === '115.00'
        ));

        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame(1, FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
            ->where('allocatable_id', $invoice->id)
            ->where('journal_id', $journal->id)
            ->count());
    }

    private function bankAccount(): FinBankAccount
    {
        $account = FinAccount::forOrganization($this->orgId)
            ->where('code', '1000')
            ->firstOrFail();

        return FinBankAccount::factory()->create([
            'organization_id' => $this->orgId,
            'gl_account_id' => $account->id,
            'account_type' => 'cheque',
        ]);
    }
}
