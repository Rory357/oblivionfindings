<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankReconciliation;
use App\Domain\Finance\Models\FinBankReconciliationLine;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinJournalLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BankReconciliationService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /**
     * Parse CSV file and create FinBankTransaction records.
     * CSV format: Date,Amount,Description,Reference (first row headers).
     */
    public function importTransactions(?int $orgId, int $bankAccountId, string $filePath, string $format = 'csv'): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open import file.');
        }

        // Skip header row
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new \RuntimeException('Import file is empty.');
        }

        $imported = 0;
        $skipped = 0;

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 3) {
                    $skipped++;

                    continue;
                }

                $date = $this->parseDate($row[0] ?? '');
                $amount = $this->parseAmount($row[1] ?? '0');
                $description = trim($row[2] ?? '');
                $reference = trim($row[3] ?? '');

                if ($date === null) {
                    $skipped++;

                    continue;
                }

                FinBankTransaction::create([
                    'organization_id' => $orgId,
                    'bank_account_id' => $bankAccountId,
                    'transaction_date' => $date,
                    'amount' => $amount,
                    'description' => $description,
                    'reference' => $reference ?: null,
                    'source' => 'import',
                    'status' => 'unreconciled',
                ]);

                $imported++;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            fclose($handle);
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    /**
     * Start a new bank reconciliation.
     */
    public function startReconciliation(?int $orgId, int $bankAccountId, array $data): FinBankReconciliation
    {
        return FinBankReconciliation::create([
            'organization_id' => $orgId,
            'bank_account_id' => $bankAccountId,
            'statement_date' => $data['statement_date'],
            'statement_balance' => $data['statement_balance'],
            'status' => 'in_progress',
            'created_by' => $data['created_by'] ?? null,
        ]);
    }

    /**
     * Get unreconciled bank transactions and unmatched journal lines for the bank's GL account.
     */
    public function getUnreconciledItems(int $bankAccountId): array
    {
        $bankAccount = FinBankAccount::findOrFail($bankAccountId);

        $transactions = FinBankTransaction::where('bank_account_id', $bankAccountId)
            ->where('status', 'unreconciled')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        // Get journal lines for the bank's GL account that are not already matched
        $matchedJournalLineIds = FinBankReconciliationLine::whereNotNull('journal_line_id')
            ->pluck('journal_line_id')
            ->toArray();

        $journalLines = FinJournalLine::where('account_id', $bankAccount->gl_account_id)
            ->whereHas('journal', function ($q) {
                $q->where('status', 'posted');
            })
            ->when(! empty($matchedJournalLineIds), function ($q) use ($matchedJournalLineIds) {
                $q->whereNotIn('id', $matchedJournalLineIds);
            })
            ->with('journal:id,journal_number,journal_date,description')
            ->orderBy('id')
            ->get()
            ->sortBy(function ($line) {
                return $line->journal->journal_date;
            })
            ->values();

        return [
            'transactions' => $transactions,
            'journal_lines' => $journalLines,
        ];
    }

    /**
     * Auto-matching logic for suggesting matches between bank transactions and journal lines.
     */
    public function suggestMatches(int $reconciliationId): array
    {
        $reconciliation = FinBankReconciliation::findOrFail($reconciliationId);
        $bankAccount = FinBankAccount::findOrFail($reconciliation->bank_account_id);

        $items = $this->getUnreconciledItems($reconciliation->bank_account_id);
        $transactions = $items['transactions'];
        $journalLines = $items['journal_lines'];

        $suggestions = [];
        $usedJournalLineIds = [];

        foreach ($transactions as $transaction) {
            $bestMatch = null;
            $bestConfidence = null;

            foreach ($journalLines as $journalLine) {
                if (in_array($journalLine->id, $usedJournalLineIds)) {
                    continue;
                }

                $confidence = $this->calculateMatchConfidence($transaction, $journalLine);
                if ($confidence === null) {
                    continue;
                }

                if ($bestMatch === null || $this->confidenceRank($confidence) > $this->confidenceRank($bestConfidence)) {
                    $bestMatch = $journalLine;
                    $bestConfidence = $confidence;
                }
            }

            if ($bestMatch !== null) {
                $suggestions[] = [
                    'bank_transaction_id' => $transaction->id,
                    'journal_line_id' => $bestMatch->id,
                    'confidence' => $bestConfidence,
                ];
                $usedJournalLineIds[] = $bestMatch->id;
            }
        }

        return $suggestions;
    }

    /**
     * Create a reconciliation line matching a bank transaction to an optional journal line.
     */
    public function matchTransaction(int $reconciliationId, int $bankTransactionId, ?int $journalLineId, ?int $adjustmentAccountId = null): FinBankReconciliationLine
    {
        $transaction = FinBankTransaction::findOrFail($bankTransactionId);

        return DB::transaction(function () use ($reconciliationId, $bankTransactionId, $journalLineId, $adjustmentAccountId, $transaction) {
            // A statement line with no existing journal (bank fee, interest, etc.)
            // can be matched "as an adjustment": post a balanced journal against the
            // chosen account so the GL reflects it, then match the new bank-side line.
            if ($journalLineId === null && $adjustmentAccountId !== null) {
                $journalLineId = $this->postAdjustmentJournal($transaction, $adjustmentAccountId);
            }

            $line = FinBankReconciliationLine::create([
                'reconciliation_id' => $reconciliationId,
                'bank_transaction_id' => $bankTransactionId,
                'journal_line_id' => $journalLineId,
                'is_matched' => true,
            ]);

            $transaction->update([
                'status' => 'matched',
                'reconciliation_id' => $reconciliationId,
                'matched_journal_line_id' => $journalLineId,
            ]);

            return $line;
        });
    }

    /**
     * Post a balanced adjustment journal for an unmatched statement line and return
     * the bank-side journal line id (the GL movement the reconciliation matches).
     * Outflow (fee): DR adjustment account / CR bank. Inflow (interest): DR bank /
     * CR adjustment account. The bank GL is the account's gl_account_id, else 1000.
     */
    private function postAdjustmentJournal(FinBankTransaction $transaction, int $adjustmentAccountId): int
    {
        $bankAccount = FinBankAccount::findOrFail($transaction->bank_account_id);
        $orgId = $bankAccount->organization_id;

        $bankGlId = $bankAccount->gl_account_id
            ?: FinAccount::forOrganization($orgId)->where('code', '1000')->where('is_active', true)->value('id');

        if (! $bankGlId) {
            throw new \InvalidArgumentException('No bank GL account is configured for this bank account.');
        }

        $amount = number_format(abs((float) $transaction->amount), 2, '.', '');
        $isOutflow = (float) $transaction->amount < 0;

        $adjustmentLine = ['account_id' => $adjustmentAccountId, 'description' => $transaction->description ?: 'Bank adjustment'];
        $bankLine = ['account_id' => $bankGlId, 'description' => $transaction->description ?: 'Bank adjustment'];

        if ($isOutflow) {
            $adjustmentLine += ['debit' => $amount, 'credit' => 0];
            $bankLine += ['debit' => 0, 'credit' => $amount];
        } else {
            $bankLine += ['debit' => $amount, 'credit' => 0];
            $adjustmentLine += ['debit' => 0, 'credit' => $amount];
        }

        $journal = $this->journalPostingService->createAndPost($orgId, [
            'journal_date' => $transaction->transaction_date->toDateString(),
            'type' => 'standard',
            'reference' => $transaction->reference ?: "REC-{$transaction->id}",
            'description' => 'Bank reconciliation adjustment: '.($transaction->description ?: "transaction #{$transaction->id}"),
            'source_type' => FinBankTransaction::class,
            'source_id' => $transaction->id,
            'lines' => [$adjustmentLine, $bankLine],
        ])->load('lines');

        return $journal->lines->firstWhere('account_id', $bankGlId)->id;
    }

    /**
     * Remove a reconciliation line and reset the bank transaction status.
     */
    public function unmatchTransaction(FinBankReconciliationLine $line): void
    {
        $transaction = FinBankTransaction::find($line->bank_transaction_id);

        if ($transaction) {
            $transaction->update([
                'status' => 'unreconciled',
                'reconciliation_id' => null,
                'matched_journal_line_id' => null,
            ]);
        }

        $line->delete();
    }

    /**
     * Complete the reconciliation: calculate balance, check it matches, update statuses.
     */
    public function completeReconciliation(FinBankReconciliation $recon, int $userId): FinBankReconciliation
    {
        $bankAccount = FinBankAccount::findOrFail($recon->bank_account_id);

        // Calculate balance from matched transactions
        $matchedTransactions = FinBankTransaction::where('bank_account_id', $bankAccount->id)
            ->where('reconciliation_id', $recon->id)
            ->where('status', 'matched')
            ->get();

        $calculatedBalance = $matchedTransactions->sum(function ($txn) {
            return (float) $txn->amount;
        });

        // Add the opening balance to get the calculated bank balance
        $previousRecon = FinBankReconciliation::where('bank_account_id', $bankAccount->id)
            ->where('status', 'completed')
            ->orderByDesc('statement_date')
            ->first();

        $startingBalance = $previousRecon
            ? (float) $previousRecon->statement_balance
            : (float) $bankAccount->opening_balance;

        $calculatedBalance = $startingBalance + $calculatedBalance;

        $difference = abs($calculatedBalance - (float) $recon->statement_balance);

        if ($difference > 0.01) {
            throw new \InvalidArgumentException(
                "Reconciliation is not balanced. Statement balance: \${$recon->statement_balance}, Calculated balance: \${$calculatedBalance}, Difference: \${$difference}"
            );
        }

        DB::beginTransaction();
        try {
            $recon->update([
                'calculated_balance' => $calculatedBalance,
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $userId,
            ]);

            // Mark all matched transactions as reconciled
            FinBankTransaction::where('reconciliation_id', $recon->id)
                ->where('status', 'matched')
                ->update(['status' => 'reconciled']);

            // Update bank account current balance
            $bankAccount->update([
                'current_balance' => $calculatedBalance,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $recon->fresh();
    }

    /**
     * Calculate match confidence between a bank transaction and a journal line.
     */
    private function calculateMatchConfidence(FinBankTransaction $transaction, FinJournalLine $journalLine): ?string
    {
        $txnAmount = (float) $transaction->amount;
        $debit = (float) $journalLine->debit;
        $credit = (float) $journalLine->credit;

        // Amount match: deposit = debit on bank GL account, withdrawal = credit
        $amountMatches = false;
        if ($txnAmount > 0 && abs($txnAmount - $debit) < 0.01) {
            $amountMatches = true;
        } elseif ($txnAmount < 0 && abs(abs($txnAmount) - $credit) < 0.01) {
            $amountMatches = true;
        }

        if (! $amountMatches) {
            return null;
        }

        // Date match: within 3 days
        $journalDate = Carbon::parse($journalLine->journal->journal_date);
        $txnDate = Carbon::parse($transaction->transaction_date);
        $daysDiff = abs($journalDate->diffInDays($txnDate));

        if ($daysDiff > 3) {
            return null;
        }

        // Reference/description partial match
        $hasTextMatch = $this->hasPartialTextMatch(
            $transaction->reference.' '.$transaction->description,
            $journalLine->description.' '.($journalLine->journal->reference ?? '')
        );

        // Determine confidence
        if ($daysDiff === 0 && $hasTextMatch) {
            return 'high';
        }

        if ($daysDiff <= 1 || $hasTextMatch) {
            return 'medium';
        }

        return 'low';
    }

    private function hasPartialTextMatch(string $text1, string $text2): bool
    {
        $text1 = Str::lower(trim($text1));
        $text2 = Str::lower(trim($text2));

        if (empty($text1) || empty($text2)) {
            return false;
        }

        // Extract meaningful words (3+ chars)
        $words1 = array_filter(preg_split('/\s+/', $text1), fn ($w) => strlen($w) >= 3);
        $words2 = array_filter(preg_split('/\s+/', $text2), fn ($w) => strlen($w) >= 3);

        foreach ($words1 as $word) {
            foreach ($words2 as $other) {
                if (Str::contains($other, $word) || Str::contains($word, $other)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function confidenceRank(?string $confidence): int
    {
        return match ($confidence) {
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if (empty($value)) {
            return null;
        }

        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'];
        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseAmount(string $value): float
    {
        $value = trim($value);
        $value = str_replace(['$', ',', ' '], '', $value);

        return (float) $value;
    }
}
