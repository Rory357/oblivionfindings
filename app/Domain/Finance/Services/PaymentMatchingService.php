<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinMatchRule;
use App\Domain\Finance\Models\FinPaymentMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PaymentMatchingService
{
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
        $candidateAmountDue = $candidate->total_amount - $candidate->amount_paid;
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
            strtolower($txn->description . ' ' . $txn->reference),
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
            ->whereHas('bankTransaction', fn($q) => $q->where('description', 'LIKE', '%' . Str::limit($txn->description, 20, '') . '%'))
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
        // Get unpaid/partially paid bills
        $bills = FinBill::forOrganization($orgId)
            ->whereIn('status', ['approved', 'partial'])
            ->whereColumn('amount_paid', '<', 'total_amount')
            ->with('vendor')
            ->get();

        return $bills;
    }

    /**
     * Run matching for all unmatched withdrawal transactions in an organisation.
     */
    public function matchUnmatchedTransactions(?int $orgId): array
    {
        $unmatched = FinBankTransaction::whereHas('bankAccount', fn($q) => $q->forOrganization($orgId))
            ->whereDoesntHave('paymentMatches', fn($q) => $q->whereIn('status', ['confirmed', 'auto_confirmed']))
            ->where('amount', '<', 0) // withdrawals are negative amounts
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
                    $pm->update(['confirmed_at' => now()]);
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
    public function confirmMatch(FinPaymentMatch $match, int $userId): FinPaymentMatch
    {
        $match->update([
            'status' => 'confirmed',
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
        ]);

        return $match;
    }

    /**
     * Reject a suggested payment match.
     */
    public function rejectMatch(FinPaymentMatch $match): FinPaymentMatch
    {
        $match->update(['status' => 'rejected']);

        return $match;
    }
}
