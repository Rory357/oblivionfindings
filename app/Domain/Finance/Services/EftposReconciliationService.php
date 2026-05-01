<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinEftposBatch;
use App\Domain\Finance\Models\FinEftposTerminal;
use App\Domain\Finance\Models\FinJournal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class EftposReconciliationService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
    ) {}

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
        return DB::transaction(function () use ($batch, $bankTransactionId) {
            $batch = FinEftposBatch::query()
                ->with(['terminal.bankAccount.glAccount', 'terminal.glAccount'])
                ->lockForUpdate()
                ->findOrFail($batch->id);

            if ($batch->journal_id !== null) {
                if ($batch->gl_posted_at === null) {
                    $batch->forceFill(['gl_posted_at' => now()])->save();
                }

                return $batch->refresh();
            }

            $bankTxn = null;

            // Auto-match: find bank transaction matching settlement amount and date
            if (! $bankTransactionId) {
                $bankAccount = $batch->terminal?->bankAccount;
                if ($bankAccount) {
                    $bankTxn = FinBankTransaction::where('bank_account_id', $bankAccount->id)
                        ->where('amount', $batch->settlement_amount)
                        ->whereBetween('transaction_date', [
                            $batch->batch_date,
                            Carbon::parse($batch->batch_date)->addDays(3),
                        ])
                        ->whereNull('reconciliation_id')
                        ->first();
                    $bankTransactionId = $bankTxn?->id;
                }
            } else {
                $bankTxn = FinBankTransaction::findOrFail($bankTransactionId);
            }

            $discrepancy = '0';
            $status = 'reconciled';

            if ($bankTxn) {
                $discrepancy = bcsub((string) $bankTxn->amount, (string) $batch->settlement_amount, 2);
                if (bccomp($discrepancy, '0', 2) !== 0) {
                    $status = 'discrepancy';
                }
            }

            $batch->forceFill([
                'bank_transaction_id' => $bankTransactionId,
                'status' => $status,
                'reconciled_at' => now(),
                'reconciled_by' => Auth::id(),
                'discrepancy_amount' => $discrepancy,
            ])->save();

            if ($status === 'reconciled') {
                $journal = $this->findExistingSettlementJournal($batch)
                    ?? $this->postSettlementJournal($batch->refresh()->load(['terminal.bankAccount.glAccount', 'terminal.glAccount']), $bankTxn);

                $batch->forceFill([
                    'journal_id' => $journal->id,
                    'gl_posted_at' => $journal->posted_at ?? now(),
                ])->save();
            }

            return $batch->refresh();
        });
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

    private function postSettlementJournal(FinEftposBatch $batch, ?FinBankTransaction $bankTxn): FinJournal
    {
        $settledAmount = number_format(abs((float) $batch->settlement_amount), 2, '.', '');

        if (bccomp($settledAmount, '0', 2) <= 0) {
            throw new InvalidArgumentException("EFTPOS batch {$batch->batch_number} has no settlement amount to post.");
        }

        $bankAccount = $this->resolveSettlementBankAccount($batch);
        $clearingAccount = $this->findAccountByCode(
            $batch->organization_id,
            config('finance.eftpos_settlement_accounts.card_clearing', '1180'),
        );
        $isDeposit = bccomp((string) $batch->settlement_amount, '0', 2) >= 0;

        $bankLine = [
            'account_id' => $bankAccount->id,
            'description' => "EFTPOS settlement deposited for batch {$batch->batch_number}",
            'debit' => $isDeposit ? $settledAmount : 0,
            'credit' => $isDeposit ? 0 : $settledAmount,
        ];

        $clearingLine = [
            'account_id' => $clearingAccount->id,
            'description' => "Card clearing settled for batch {$batch->batch_number}",
            'debit' => $isDeposit ? 0 : $settledAmount,
            'credit' => $isDeposit ? $settledAmount : 0,
        ];

        return $this->journalPostingService->createAndPost($batch->organization_id, [
            'journal_date' => $this->settlementJournalDate($batch, $bankTxn),
            'type' => 'standard',
            'reference' => $bankTxn?->reference ?: $batch->batch_number,
            'description' => "EFTPOS settlement for batch {$batch->batch_number}",
            'source_type' => FinEftposBatch::class,
            'source_id' => $batch->id,
            'lines' => [
                $bankLine,
                $clearingLine,
            ],
        ]);
    }

    private function resolveSettlementBankAccount(FinEftposBatch $batch): FinAccount
    {
        foreach ([
            $batch->terminal?->bankAccount?->glAccount,
            $batch->terminal?->glAccount,
        ] as $account) {
            if ($account instanceof FinAccount && $account->is_active && (int) $account->organization_id === (int) $batch->organization_id) {
                return $account;
            }
        }

        return $this->findAccountByCode(
            $batch->organization_id,
            config('finance.eftpos_settlement_accounts.bank', '1000'),
        );
    }

    private function settlementJournalDate(FinEftposBatch $batch, ?FinBankTransaction $bankTxn): string
    {
        return ($batch->settlement_date ?? $bankTxn?->transaction_date ?? $batch->batch_date)->toDateString();
    }

    private function findExistingSettlementJournal(FinEftposBatch $batch): ?FinJournal
    {
        return FinJournal::query()
            ->where('organization_id', $batch->organization_id)
            ->where('source_type', FinEftposBatch::class)
            ->where('source_id', $batch->id)
            ->where('status', 'posted')
            ->first();
    }

    private function findAccountByCode(?int $orgId, string $code): FinAccount
    {
        $account = FinAccount::forOrganization($orgId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new RuntimeException(
                "GL account with code '{$code}' not found (or inactive) for organisation #{$orgId}."
            );
        }

        return $account;
    }
}
