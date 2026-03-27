<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Models\FinDonorFundReport;
use App\Domain\Finance\Models\FinDonorFundTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DonorFundService
{
    public function __construct(
        private readonly JournalPostingService $journalService,
    ) {}

    /**
     * Record a receipt (incoming funds) against a donor fund.
     */
    public function recordReceipt(FinDonorFund $fund, array $data): FinDonorFundTransaction
    {
        return DB::transaction(function () use ($fund, $data) {
            $txn = $fund->transactions()->create([
                'transaction_date' => $data['transaction_date'],
                'type' => 'receipt',
                'description' => $data['description'],
                'amount' => $data['amount'],
                'reference' => $data['reference'] ?? null,
                'created_by' => Auth::id(),
            ]);

            // Update fund balance
            $fund->increment('total_received', $data['amount']);
            $fund->update([
                'available_balance' => bcsub(
                    bcadd((string) $fund->total_received, '0', 2),
                    bcadd((string) $fund->total_spent, (string) $fund->total_committed, 2),
                    2
                ),
            ]);

            // Post journal if GL account configured
            if ($fund->gl_account_id) {
                $bankAccountId = $data['bank_account_id'] ?? null;
                $debitAccount = $bankAccountId
                    ? FinBankAccount::find($bankAccountId)?->gl_account_id
                    : FinAccount::forOrganization($fund->organization_id)->where('code', '1000')->first()?->id;

                if ($debitAccount) {
                    $journal = $this->journalService->createAndPost($fund->organization_id, [
                        'journal_date' => $data['transaction_date'],
                        'type' => 'standard',
                        'reference' => "FUND-{$fund->fund_code}",
                        'description' => "Fund receipt: {$fund->fund_name} - {$data['description']}",
                        'lines' => [
                            ['account_id' => $debitAccount, 'debit' => $data['amount'], 'credit' => 0, 'funding_stream_id' => $fund->funding_stream_id],
                            ['account_id' => $fund->gl_account_id, 'debit' => 0, 'credit' => $data['amount'], 'funding_stream_id' => $fund->funding_stream_id],
                        ],
                    ]);
                    $txn->update(['journal_id' => $journal->id]);
                }
            }

            return $txn;
        });
    }

    /**
     * Record an expenditure against a donor fund.
     */
    public function recordExpenditure(FinDonorFund $fund, array $data): FinDonorFundTransaction
    {
        // Check available balance for restricted funds
        if ($fund->is_restricted && $data['amount'] > $fund->available_balance) {
            throw new InvalidArgumentException(
                "Insufficient fund balance. Available: \${$fund->available_balance}, Requested: \${$data['amount']}"
            );
        }

        return DB::transaction(function () use ($fund, $data) {
            $txn = $fund->transactions()->create([
                'transaction_date' => $data['transaction_date'],
                'type' => 'expenditure',
                'description' => $data['description'],
                'amount' => $data['amount'],
                'reference' => $data['reference'] ?? null,
                'bill_id' => $data['bill_id'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $fund->increment('total_spent', $data['amount']);
            $fund->update([
                'available_balance' => bcsub(
                    (string) $fund->total_received,
                    bcadd((string) $fund->total_spent, (string) $fund->total_committed, 2),
                    2
                ),
                'status' => $fund->total_spent >= $fund->total_received ? 'fully_spent' : 'active',
            ]);

            // Post journal
            if ($fund->gl_account_id) {
                $expenseAccountId = $data['expense_account_id'] ?? null;
                if ($expenseAccountId) {
                    $journal = $this->journalService->createAndPost($fund->organization_id, [
                        'journal_date' => $data['transaction_date'],
                        'type' => 'standard',
                        'reference' => "FUND-{$fund->fund_code}",
                        'description' => "Fund expenditure: {$fund->fund_name} - {$data['description']}",
                        'lines' => [
                            ['account_id' => $expenseAccountId, 'debit' => $data['amount'], 'credit' => 0, 'funding_stream_id' => $fund->funding_stream_id],
                            ['account_id' => $fund->gl_account_id, 'debit' => 0, 'credit' => $data['amount'], 'funding_stream_id' => $fund->funding_stream_id],
                        ],
                    ]);
                    $txn->update(['journal_id' => $journal->id]);
                }
            }

            return $txn;
        });
    }

    /**
     * Generate a fund report for a given period.
     */
    public function generateReport(FinDonorFund $fund, string $periodFrom, string $periodTo): FinDonorFundReport
    {
        $transactions = $fund->transactions()
            ->whereBetween('transaction_date', [$periodFrom, $periodTo])
            ->orderBy('transaction_date')
            ->get();

        $openingBalance = $fund->transactions()
            ->where('transaction_date', '<', $periodFrom)
            ->selectRaw("SUM(CASE WHEN type = 'receipt' THEN amount ELSE 0 END) - SUM(CASE WHEN type = 'expenditure' THEN amount ELSE 0 END) as balance")
            ->value('balance') ?? 0;

        $totalReceipts = $transactions->where('type', 'receipt')->sum('amount');
        $totalExpenditure = $transactions->where('type', 'expenditure')->sum('amount');
        $closingBalance = $openingBalance + $totalReceipts - $totalExpenditure;

        return FinDonorFundReport::create([
            'fund_id' => $fund->id,
            'report_name' => "{$fund->fund_name} - Report " . now()->format('M Y'),
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'opening_balance' => $openingBalance,
            'total_receipts' => $totalReceipts,
            'total_expenditure' => $totalExpenditure,
            'closing_balance' => $closingBalance,
            'report_data' => [
                'transactions' => $transactions->toArray(),
                'summary_by_type' => $transactions->groupBy('type')->map->sum('amount'),
            ],
            'status' => 'draft',
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Get a summary of all active funds for an organisation.
     */
    public function getFundsSummary(?int $orgId): array
    {
        $funds = FinDonorFund::forOrganization($orgId)->active()->get();

        return [
            'total_funds' => $funds->count(),
            'total_received' => $funds->sum('total_received'),
            'total_spent' => $funds->sum('total_spent'),
            'total_available' => $funds->sum('available_balance'),
            'restricted_balance' => $funds->where('is_restricted', true)->sum('available_balance'),
            'unrestricted_balance' => $funds->where('is_restricted', false)->sum('available_balance'),
            'expiring_soon' => $funds->filter(fn ($f) => $f->end_date && Carbon::parse($f->end_date)->diffInDays(now()) <= 90)->count(),
        ];
    }
}
