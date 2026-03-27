<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinEftposBatch;
use App\Domain\Finance\Models\FinEftposTerminal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EftposReconciliationService
{
    /**
     * Import a batch of EFTPOS transactions for a terminal.
     */
    public function importBatch(?int $orgId, int $terminalId, array $batchData): FinEftposBatch
    {
        $terminal = FinEftposTerminal::findOrFail($terminalId);

        return DB::transaction(function () use ($orgId, $terminal, $batchData) {
            $batch = FinEftposBatch::create([
                'organization_id' => $orgId,
                'terminal_id' => $terminal->id,
                'batch_number' => $batchData['batch_number'],
                'batch_date' => $batchData['batch_date'],
                'status' => 'closed',
                'created_by' => Auth::id(),
            ]);

            $totalAmount = '0';
            $totalRefunds = '0';
            $totalFees = '0';
            $count = 0;

            foreach ($batchData['transactions'] as $txn) {
                $batch->transactions()->create([
                    'transaction_reference' => $txn['reference'],
                    'transaction_date' => $txn['date'],
                    'card_type' => $txn['card_type'] ?? 'eftpos',
                    'transaction_type' => $txn['type'] ?? 'purchase',
                    'amount' => $txn['amount'],
                    'fee_amount' => $txn['fee'] ?? 0,
                    'auth_code' => $txn['auth_code'] ?? null,
                    'card_last_four' => $txn['card_last_four'] ?? null,
                    'status' => 'approved',
                ]);

                if (($txn['type'] ?? 'purchase') === 'refund') {
                    $totalRefunds = bcadd($totalRefunds, (string) abs($txn['amount']), 2);
                } else {
                    $totalAmount = bcadd($totalAmount, (string) $txn['amount'], 2);
                }
                $totalFees = bcadd($totalFees, (string) ($txn['fee'] ?? 0), 2);
                $count++;
            }

            $netAmount = bcsub($totalAmount, $totalRefunds, 2);
            $settlementAmount = bcsub($netAmount, $totalFees, 2);

            $batch->update([
                'total_transactions' => $count,
                'total_amount' => $totalAmount,
                'total_refunds' => $totalRefunds,
                'net_amount' => $netAmount,
                'fees' => $totalFees,
                'settlement_amount' => $settlementAmount,
            ]);

            return $batch->refresh();
        });
    }

    /**
     * Reconcile an EFTPOS batch against a bank transaction.
     * Auto-matches if no bank transaction ID is provided.
     */
    public function reconcileBatch(FinEftposBatch $batch, ?int $bankTransactionId = null): FinEftposBatch
    {
        // Auto-match: find bank transaction matching settlement amount and date
        if (! $bankTransactionId) {
            $bankAccount = $batch->terminal->bankAccount;
            if ($bankAccount) {
                $matchingTxn = FinBankTransaction::where('bank_account_id', $bankAccount->id)
                    ->where('amount', $batch->settlement_amount)
                    ->whereBetween('transaction_date', [
                        $batch->batch_date,
                        Carbon::parse($batch->batch_date)->addDays(3),
                    ])
                    ->whereNull('reconciliation_id')
                    ->first();
                $bankTransactionId = $matchingTxn?->id;
            }
        }

        $discrepancy = '0';
        $status = 'reconciled';

        if ($bankTransactionId) {
            $bankTxn = FinBankTransaction::findOrFail($bankTransactionId);
            $discrepancy = bcsub((string) $bankTxn->amount, (string) $batch->settlement_amount, 2);
            if (bccomp($discrepancy, '0', 2) !== 0) {
                $status = 'discrepancy';
            }
        }

        $batch->update([
            'bank_transaction_id' => $bankTransactionId,
            'status' => $status,
            'reconciled_at' => now(),
            'reconciled_by' => Auth::id(),
            'discrepancy_amount' => $discrepancy,
        ]);

        return $batch->refresh();
    }

    /**
     * Get all unreconciled EFTPOS batches for an organisation.
     */
    public function getUnreconciledBatches(?int $orgId): Collection
    {
        return FinEftposBatch::forOrganization($orgId)
            ->whereIn('status', ['closed', 'discrepancy'])
            ->with(['terminal', 'bankTransaction'])
            ->orderBy('batch_date')
            ->get();
    }
}
