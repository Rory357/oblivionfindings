<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinMatchRule;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinPaymentMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PaymentMatchingService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly AccountsPayableService $accountsPayableService,
    ) {}

    /**
     * Find potential matches for a single bank transaction.
     */
    public function findMatches(?int $orgId, FinBankTransaction $transaction): Collection
    {
        $candidates = $this->getCandidates($orgId, $transaction);
        $scores = collect();

        foreach ($candidates as $candidate) {
            $score = $this->calculateMatchScore($transaction, $candidate);
            if ($score['total'] >= 30) {
                $scores->push([
                    'matchable_type' => get_class($candidate),
                    'matchable_id' => $candidate->id,
                    'confidence_score' => $score['total'],
                    'match_reasons' => $score['reasons'],
                ]);
            }
        }

        return $scores->sortByDesc('confidence_score')->values();
    }

    /**
     * Calculate a match score between a transaction and a candidate bill/invoice.
     */
    private function calculateMatchScore(FinBankTransaction $txn, $candidate): array
    {
        $score = 0;
        $reasons = [];

        // 1. Exact amount match (40 points)
        $candidateAmountDue = $this->amountDueFor($candidate);
        if (bccomp((string) abs((float) $txn->amount), (string) $candidateAmountDue, 2) === 0) {
            $score += 40;
            $reasons[] = 'Exact amount match';
        } elseif (abs(abs((float) $txn->amount) - (float) $candidateAmountDue) < 0.50) {
            $score += 25;
            $reasons[] = 'Amount within $0.50 tolerance';
        }

        // 2. Reference/invoice number match (30 points)
        $billNumber = $candidate->bill_number ?? $candidate->invoice_number ?? '';
        if ($billNumber && $txn->reference && str_contains(
            strtolower($txn->description.' '.$txn->reference),
            strtolower($billNumber)
        )) {
            $score += 30;
            $reasons[] = 'Reference number found in transaction';
        }

        // 3. Vendor/client name match (15 points) - fuzzy match
        $vendorName = $candidate->vendor?->name ?? $candidate->client_name ?? '';
        if ($vendorName && $this->fuzzyMatch($txn->description, $vendorName)) {
            $score += 15;
            $reasons[] = 'Vendor/client name matched in description';
        }

        // 4. Date proximity (10 points)
        $dueDate = $candidate->due_date ?? $candidate->bill_date;
        if ($dueDate && $txn->transaction_date) {
            $dateDiff = abs($txn->transaction_date->diffInDays($dueDate));
            if ($dateDiff <= 3) {
                $score += 10;
                $reasons[] = 'Transaction within 3 days of due date';
            } elseif ($dateDiff <= 7) {
                $score += 5;
                $reasons[] = 'Transaction within 7 days of due date';
            }
        }

        // 5. Historical pattern (5 points)
        $orgId = $txn->bank_account_id ? FinBankAccount::find($txn->bank_account_id)?->organization_id : null;
        $historicalMatch = FinPaymentMatch::where('organization_id', $orgId)
            ->where('matchable_type', get_class($candidate))
            ->where('status', 'confirmed')
            ->whereHas('bankTransaction', fn ($q) => $q->where('description', 'LIKE', '%'.Str::limit($txn->description, 20, '').'%'))
            ->exists();
        if ($historicalMatch) {
            $score += 5;
            $reasons[] = 'Similar transaction matched before';
        }

        return ['total' => min($score, 100), 'reasons' => $reasons];
    }

    /**
     * Fuzzy-match a needle (e.g. vendor name) in a haystack (e.g. transaction description).
     */
    private function fuzzyMatch(string $haystack, string $needle): bool
    {
        $haystack = strtolower($haystack);
        $needle = strtolower($needle);

        if (str_contains($haystack, $needle)) {
            return true;
        }

        // Check each word of vendor name
        $words = explode(' ', $needle);
        $matchedWords = 0;
        foreach ($words as $word) {
            if (strlen($word) > 2 && str_contains($haystack, $word)) {
                $matchedWords++;
            }
        }

        return count($words) > 0 && ($matchedWords / count($words)) >= 0.5;
    }

    /**
     * Get candidate bills/invoices that could match a transaction.
     */
    private function getCandidates(?int $orgId, FinBankTransaction $transaction): Collection
    {
        if (bccomp((string) $transaction->amount, '0', 2) < 0) {
            return FinBill::forOrganization($orgId)
                ->whereIn('status', ['approved', 'partially_paid'])
                ->whereColumn('amount_paid', '<', 'total_amount')
                ->with('vendor')
                ->get();
        }

        if (bccomp((string) $transaction->amount, '0', 2) > 0) {
            return FinInvoice::forOrganization($orgId)
                ->whereIn('status', ['sent', 'viewed', 'overdue'])
                ->get()
                ->filter(fn (FinInvoice $invoice) => bccomp($this->amountDueFor($invoice), '0', 2) > 0)
                ->values();
        }

        return collect();
    }

    /**
     * Run matching for all unmatched withdrawal transactions in an organisation.
     */
    public function matchUnmatchedTransactions(?int $orgId): array
    {
        $unmatched = FinBankTransaction::whereHas('bankAccount', fn ($q) => $q->forOrganization($orgId))
            ->whereDoesntHave('paymentMatches', fn ($q) => $q->whereIn('status', ['confirmed', 'auto_confirmed']))
            ->where('amount', '!=', 0)
            ->get();

        $results = ['matched' => 0, 'auto_confirmed' => 0, 'suggested' => 0];
        $rules = FinMatchRule::forOrganization($orgId)->active()->byPriority()->get();

        foreach ($unmatched as $txn) {
            $matches = $this->findMatches($orgId, $txn);

            foreach ($matches as $match) {
                $autoThreshold = $rules->max('auto_confirm_threshold') ?? 95;

                $pm = FinPaymentMatch::create([
                    'organization_id' => $orgId,
                    'bank_transaction_id' => $txn->id,
                    'matchable_type' => $match['matchable_type'],
                    'matchable_id' => $match['matchable_id'],
                    'confidence_score' => $match['confidence_score'],
                    'match_reasons' => $match['match_reasons'],
                    'status' => $match['confidence_score'] >= $autoThreshold ? 'auto_confirmed' : 'suggested',
                ]);

                if ($pm->status === 'auto_confirmed') {
                    $this->confirmAndPost($pm, null, 'auto_confirmed');
                    $results['auto_confirmed']++;
                } else {
                    $results['suggested']++;
                }
                $results['matched']++;
                break; // Only best match per transaction
            }
        }

        return $results;
    }

    /**
     * Confirm a suggested payment match.
     */
    public function confirmMatch(FinPaymentMatch $match, ?int $userId): FinPaymentMatch
    {
        return $this->confirmAndPost($match, $userId, 'confirmed');
    }

    /**
     * Reject a suggested payment match.
     */
    public function rejectMatch(FinPaymentMatch $match): FinPaymentMatch
    {
        $match->update(['status' => 'rejected']);

        return $match;
    }

    private function confirmAndPost(FinPaymentMatch $match, ?int $userId, string $status): FinPaymentMatch
    {
        return DB::transaction(function () use ($match, $userId, $status) {
            $match = FinPaymentMatch::query()
                ->with(['bankTransaction.bankAccount.glAccount', 'matchable'])
                ->lockForUpdate()
                ->findOrFail($match->id);

            if ($match->status === 'rejected') {
                throw new InvalidArgumentException('Rejected payment matches cannot be confirmed.');
            }

            $journal = $match->journal_id
                ? null
                : $this->postJournalForMatch($match, $userId);

            $updates = [
                'status' => $status,
                'confirmed_by' => $userId,
                'confirmed_at' => $match->confirmed_at ?? now(),
            ];

            if ($journal) {
                $updates['journal_id'] = $journal->id;
            }

            $match->forceFill($updates)->save();

            return $match->refresh();
        });
    }

    private function postJournalForMatch(FinPaymentMatch $match, ?int $userId)
    {
        $matchable = $match->matchable;

        if ($matchable instanceof FinBill) {
            return $this->postBillPaymentJournal($match, $matchable, $userId);
        }

        if ($matchable instanceof FinInvoice) {
            return $this->postInvoiceReceiptJournal($match, $matchable, $userId);
        }

        return null;
    }

    private function postBillPaymentJournal(FinPaymentMatch $match, FinBill $bill, ?int $userId)
    {
        $transaction = $match->bankTransaction;

        if (bccomp((string) $transaction->amount, '0', 2) >= 0) {
            throw new InvalidArgumentException('Bill payment matches must be linked to withdrawal bank transactions.');
        }

        $amount = $this->paymentAmount($transaction);
        $amountDue = $this->amountDueFor($bill);

        if (bccomp($amount, $amountDue, 2) > 0) {
            throw new InvalidArgumentException("Payment amount {$amount} exceeds bill amount due {$amountDue}.");
        }

        $bankAccount = $this->resolveBankGlAccount($transaction);
        $apAccount = $this->findAccountByCode($bill->organization_id, '2000');

        $journal = $this->journalPostingService->createAndPost($bill->organization_id, [
            'journal_date' => $transaction->transaction_date->toDateString(),
            'type' => 'standard',
            'reference' => $transaction->reference ?: $bill->bill_number,
            'description' => "Matched bank payment for bill {$bill->bill_number}",
            'source_type' => FinPaymentMatch::class,
            'source_id' => $match->id,
            'lines' => [
                [
                    'account_id' => $apAccount->id,
                    'description' => "Payment matched to bill {$bill->bill_number}",
                    'debit' => $amount,
                    'credit' => 0,
                ],
                [
                    'account_id' => $bankAccount->id,
                    'description' => "Bank payment for bill {$bill->bill_number}",
                    'debit' => 0,
                    'credit' => $amount,
                ],
            ],
        ]);

        FinPaymentAllocation::create([
            'organization_id' => $bill->organization_id,
            'type' => 'payable',
            'payment_date' => $transaction->transaction_date->toDateString(),
            'amount' => $amount,
            'allocatable_type' => FinBill::class,
            'allocatable_id' => $bill->id,
            'source_type' => FinPaymentMatch::class,
            'source_id' => $match->id,
            'journal_id' => $journal->id,
            'notes' => "Matched bank transaction #{$transaction->id}",
            'created_by' => $userId,
        ]);

        $this->accountsPayableService->recordPayment($bill, (float) $amount);

        return $journal;
    }

    private function postInvoiceReceiptJournal(FinPaymentMatch $match, FinInvoice $invoice, ?int $userId)
    {
        $transaction = $match->bankTransaction;

        if (bccomp((string) $transaction->amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Invoice receipt matches must be linked to deposit bank transactions.');
        }

        $amount = $this->paymentAmount($transaction);
        $amountDue = $this->amountDueFor($invoice);

        if (bccomp($amount, $amountDue, 2) > 0) {
            throw new InvalidArgumentException("Receipt amount {$amount} exceeds invoice amount due {$amountDue}.");
        }

        $bankAccount = $this->resolveBankGlAccount($transaction);
        $arAccount = $this->findAccountByCode($invoice->organization_id, '1100');

        $journal = $this->journalPostingService->createAndPost($invoice->organization_id, [
            'journal_date' => $transaction->transaction_date->toDateString(),
            'type' => 'standard',
            'reference' => $transaction->reference ?: $invoice->invoice_number,
            'description' => "Matched bank receipt for invoice {$invoice->invoice_number}",
            'source_type' => FinPaymentMatch::class,
            'source_id' => $match->id,
            'lines' => [
                [
                    'account_id' => $bankAccount->id,
                    'description' => "Bank receipt for invoice {$invoice->invoice_number}",
                    'debit' => $amount,
                    'credit' => 0,
                ],
                [
                    'account_id' => $arAccount->id,
                    'description' => "Receipt matched to invoice {$invoice->invoice_number}",
                    'debit' => 0,
                    'credit' => $amount,
                ],
            ],
        ]);

        FinPaymentAllocation::create([
            'organization_id' => $invoice->organization_id,
            'type' => 'receivable',
            'payment_date' => $transaction->transaction_date->toDateString(),
            'amount' => $amount,
            'allocatable_type' => FinInvoice::class,
            'allocatable_id' => $invoice->id,
            'source_type' => FinPaymentMatch::class,
            'source_id' => $match->id,
            'journal_id' => $journal->id,
            'notes' => "Matched bank transaction #{$transaction->id}",
            'created_by' => $userId,
        ]);

        $totalPaid = FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
            ->where('allocatable_id', $invoice->id)
            ->sum('amount');

        if (bccomp((string) $totalPaid, (string) $invoice->total_amount, 2) >= 0) {
            $invoice->forceFill([
                'status' => 'paid',
                'paid_at' => $transaction->transaction_date,
            ])->save();
        }

        return $journal;
    }

    private function amountDueFor($candidate): string
    {
        if ($candidate instanceof FinBill) {
            return bcsub((string) $candidate->total_amount, (string) $candidate->amount_paid, 2);
        }

        if ($candidate instanceof FinInvoice) {
            $paid = FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
                ->where('allocatable_id', $candidate->id)
                ->sum('amount');

            return bcsub((string) $candidate->total_amount, (string) $paid, 2);
        }

        return (string) (($candidate->total_amount ?? 0) - ($candidate->amount_paid ?? 0));
    }

    private function paymentAmount(FinBankTransaction $transaction): string
    {
        return number_format(abs((float) $transaction->amount), 2, '.', '');
    }

    private function resolveBankGlAccount(FinBankTransaction $transaction): FinAccount
    {
        if ($transaction->bankAccount?->glAccount?->is_active) {
            return $transaction->bankAccount->glAccount;
        }

        return $this->findAccountByCode($transaction->organization_id, '1000');
    }

    private function findAccountByCode(?int $orgId, string $code): FinAccount
    {
        return FinAccount::forOrganization($orgId)
            ->where('code', $code)
            ->where('is_active', true)
            ->firstOrFail();
    }
}
