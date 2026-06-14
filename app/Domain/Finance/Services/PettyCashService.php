<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinPettyCashFund;
use App\Domain\Finance\Models\FinPettyCashTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PettyCashService
{
    public function __construct(
        private JournalPostingService $journalPostingService,
    ) {}

    /**
     * Create a new petty cash fund.
     */
    public function createFund(?int $orgId, array $data): FinPettyCashFund
    {
        return FinPettyCashFund::create([
            'organization_id' => $orgId,
            'name' => $data['name'],
            'gl_account_id' => $data['gl_account_id'],
            'float_amount' => $data['float_amount'],
            'current_balance' => $data['float_amount'],
            'custodian_user_id' => $data['custodian_user_id'] ?? null,
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Add a transaction to a petty cash fund.
     */
    public function addTransaction(FinPettyCashFund $fund, array $data): FinPettyCashTransaction
    {
        return DB::transaction(function () use ($fund, $data) {
            $type = $data['type'];
            $amount = (float) $data['amount'];
            $journalId = null;

            // Update fund balance
            if ($type === 'top_up') {
                $fund->increment('current_balance', $amount);

                // Book the funding draw: DR Petty Cash (fund GL) / CR Bank. Without
                // a top-up journal the bank + petty-cash GL balances drifted from the
                // fund's recorded cash. Degrades gracefully if the GL/bank accounts
                // aren't configured (balance-only, as before).
                $bankAccount = FinAccount::forOrganization($fund->organization_id)
                    ->where('code', '1000')
                    ->where('is_active', true)
                    ->first();

                if ($fund->gl_account_id && $bankAccount) {
                    $journal = $this->journalPostingService->createAndPost(
                        $fund->organization_id,
                        [
                            'journal_date' => $data['transaction_date'],
                            'type' => 'standard',
                            'reference' => "PC-{$fund->id}",
                            'description' => 'Petty cash top-up: '.($data['description'] ?? $fund->name),
                            'lines' => [
                                [
                                    'account_id' => $fund->gl_account_id,
                                    'description' => $data['description'] ?? 'Petty cash top-up',
                                    'debit' => $amount,
                                    'credit' => 0,
                                ],
                                [
                                    'account_id' => $bankAccount->id,
                                    'description' => $data['description'] ?? 'Petty cash top-up',
                                    'debit' => 0,
                                    'credit' => $amount,
                                ],
                            ],
                        ]
                    );
                    $journalId = $journal->id;
                }
            } elseif ($type === 'expense') {
                $fund->decrement('current_balance', $amount);

                // Create GL journal if expense account provided
                if (! empty($data['account_id']) && $fund->gl_account_id) {
                    $journal = $this->journalPostingService->createAndPost(
                        $fund->organization_id,
                        [
                            'journal_date' => $data['transaction_date'],
                            'type' => 'standard',
                            'reference' => "PC-{$fund->id}",
                            'description' => 'Petty cash expense: '.($data['description'] ?? ''),
                            'lines' => [
                                [
                                    'account_id' => $data['account_id'],
                                    'description' => $data['description'] ?? 'Petty cash expense',
                                    'debit' => $amount,
                                    'credit' => 0,
                                ],
                                [
                                    'account_id' => $fund->gl_account_id,
                                    'description' => $data['description'] ?? 'Petty cash expense',
                                    'debit' => 0,
                                    'credit' => $amount,
                                ],
                            ],
                        ]
                    );
                    $journalId = $journal->id;
                }
            } elseif ($type === 'adjustment') {
                // Adjustments can be positive or negative
                $fund->increment('current_balance', $amount);
            }

            $transaction = FinPettyCashTransaction::create([
                'petty_cash_fund_id' => $fund->id,
                'transaction_date' => $data['transaction_date'],
                'type' => $type,
                'amount' => abs($amount),
                // description is NOT NULL but the request validates it nullable —
                // coalesce to '' so a description-less transaction doesn't 500.
                'description' => $data['description'] ?? '',
                'receipt_path' => $data['receipt_path'] ?? null,
                'account_id' => $data['account_id'] ?? null,
                'journal_id' => $journalId,
                'created_by' => Auth::id(),
            ]);

            return $transaction;
        });
    }

    /**
     * Get fund summary with recent transactions.
     */
    public function getFundSummary(FinPettyCashFund $fund): array
    {
        $fund->load('custodian:id,name', 'glAccount:id,code,name');

        $transactions = $fund->transactions()
            ->with('account:id,code,name', 'createdBy:id,name')
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // Calculate running balance for display
        $allTransactions = $fund->transactions()
            ->orderBy('transaction_date')
            ->orderBy('created_at')
            ->get();

        $runningBalance = (float) $fund->float_amount;
        $balanceMap = [];
        foreach ($allTransactions as $txn) {
            if ($txn->type === 'top_up') {
                $runningBalance += (float) $txn->amount;
            } elseif ($txn->type === 'expense') {
                $runningBalance -= (float) $txn->amount;
            } elseif ($txn->type === 'adjustment') {
                $runningBalance += (float) $txn->amount;
            }
            $balanceMap[$txn->id] = round($runningBalance, 2);
        }

        $transactionRows = $transactions->map(function ($txn) use ($balanceMap) {
            return [
                'id' => $txn->id,
                'transaction_date' => $txn->transaction_date->toDateString(),
                'type' => $txn->type,
                'description' => $txn->description,
                'amount' => (float) $txn->amount,
                'account_name' => $txn->account ? $txn->account->code.' - '.$txn->account->name : null,
                'receipt_path' => $txn->receipt_path,
                'created_by' => $txn->createdBy->name ?? null,
                'running_balance' => $balanceMap[$txn->id] ?? null,
            ];
        })->toArray();

        return [
            'fund' => [
                'id' => $fund->id,
                'name' => $fund->name,
                'float_amount' => (float) $fund->float_amount,
                'current_balance' => (float) $fund->current_balance,
                'custodian_name' => $fund->custodian->name ?? null,
                'gl_account_name' => $fund->glAccount ? $fund->glAccount->code.' - '.$fund->glAccount->name : null,
                'is_active' => $fund->is_active,
                'variance' => round((float) $fund->current_balance - (float) $fund->float_amount, 2),
            ],
            'transactions' => $transactionRows,
        ];
    }
}
