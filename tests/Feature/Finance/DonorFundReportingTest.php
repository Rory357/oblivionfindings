<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinBillLine;
use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Models\FinDonorFundReport;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Domain\Finance\Services\DonorFundService;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DonorFundReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(FinanceSeeder::class)->run(1);

        FinFiscalPeriod::create([
            'organization_id' => 1,
            'name' => 'FY2026',
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'open',
        ]);
    }

    public function test_receipt_expenditure_and_report_generation_use_posted_journals(): void
    {
        $user = User::factory()->create(['organization_id' => 1]);
        $site = ensureCanonicalHrStaffProfile($user);
        $this->actingAs($user);

        $stream = FinFundingStream::create([
            'organization_id' => 1,
            'code' => 'DONOR-REPORT',
            'name' => 'Donor Reporting',
            'funder_type' => 'other',
            'default_revenue_account_id' => $this->account('4220')->id,
            'is_active' => true,
        ]);
        $fund = FinDonorFund::factory()->create([
            'organization_id' => 1,
            'fund_code' => 'DONOR-001',
            'fund_name' => 'Community Grant Fund',
            'fund_type' => 'grant',
            'gl_account_id' => $this->account('2600')->id,
            'funding_stream_id' => $stream->id,
            'total_received' => 0,
            'total_spent' => 0,
            'total_committed' => 0,
            'available_balance' => 0,
            'status' => 'active',
            'is_restricted' => true,
            'created_by' => $user->id,
        ]);

        $bankAccount = FinBankAccount::factory()->create([
            'organization_id' => 1,
            'gl_account_id' => $this->account('1000')->id,
            'bank_name' => 'ASB',
            'account_type' => 'cheque',
        ]);

        $service = app(DonorFundService::class);
        $transactionDate = now()->toDateString();

        $receipt = $service->recordReceipt($fund, [
            'idempotency_key' => (string) Str::uuid(),
            'transaction_date' => $transactionDate,
            'description' => 'Grant receipt',
            'amount' => 500,
            'reference' => 'DON-REC-001',
            'bank_account_id' => $bankAccount->id,
        ]);

        $bill = $this->approvedBill($user, '125.00', $site->id);
        $expenditure = $service->recordExpenditure($fund->refresh(), [
            'idempotency_key' => (string) Str::uuid(),
            'transaction_date' => $transactionDate,
            'description' => 'Programme supplies',
            'amount' => 125,
            'reference' => 'DON-SPEND-001',
            'expense_account_id' => $this->account('6500')->id,
            'bill_id' => $bill->id,
        ]);

        $report = $service->generateReport($fund->refresh(), now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

        $this->assertNotNull($receipt->journal_id);
        $this->assertNotNull($expenditure->journal_id);

        $this->assertSame('500.00', (string) $fund->refresh()->total_received);
        $this->assertSame('125.00', (string) $fund->total_spent);
        $this->assertSame('375.00', (string) $fund->available_balance);

        $this->assertInstanceOf(FinDonorFundReport::class, $report);
        $this->assertSame('500.00', (string) $report->total_receipts);
        $this->assertSame('125.00', (string) $report->total_expenditure);
        $this->assertSame('375.00', (string) $report->closing_balance);
        $this->assertSame('draft', $report->status);
        $this->assertSame(
            ['transaction_date', 'type', 'description', 'amount'],
            array_keys($report->report_data['transactions'][0]),
        );

        $this->assertJournalHasBalancedLines($receipt->journal_id, [
            ['1000', '500.00', '0.00'],
            ['2600', '0.00', '500.00'],
        ]);
        $this->assertJournalHasBalancedLines($expenditure->journal_id, [
            ['2600', '125.00', '0.00'],
            ['4220', '0.00', '125.00'],
        ]);
    }

    private function approvedBill(User $user, string $amount, int $siteId): FinBill
    {
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
        FinBillLine::create([
            'bill_id' => $bill->id,
            'description' => 'Programme supplies',
            'quantity' => 1,
            'unit_price' => $amount,
            'gst_rate' => 0,
            'gst_amount' => '0.00',
            'line_total' => $amount,
            'account_id' => $this->account('6500')->id,
        ]);

        return app(AccountsPayableService::class)->approveBill($bill, $user->id);
    }

    private function account(string $code): FinAccount
    {
        return FinAccount::forOrganization(1)
            ->where('code', $code)
            ->firstOrFail();
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $expectedLines
     */
    private function assertJournalHasBalancedLines(int $journalId, array $expectedLines): void
    {
        $journal = FinJournal::with('lines.account')->findOrFail($journalId);

        $this->assertSame('posted', $journal->status);
        $this->assertSame('0.00', bcsub(
            (string) $journal->lines->sum('debit'),
            (string) $journal->lines->sum('credit'),
            2
        ));

        foreach ($expectedLines as [$code, $debit, $credit]) {
            $this->assertTrue($journal->lines->contains(
                fn ($line) => $line->account->code === $code
                    && (string) $line->debit === $debit
                    && (string) $line->credit === $credit
            ), "Missing journal line {$code} DR {$debit} CR {$credit}");
        }
    }
}
