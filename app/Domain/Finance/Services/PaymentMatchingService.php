<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinPaymentMatch;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PaymentMatchingService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly AccountsPayableService $accountsPayableService,
        private readonly PaymentSettlementSiteScope $paymentSiteScope,
        private readonly PaymentSettlementRecorder $settlementRecorder,
    ) {}

    /**
     * Find potential matches for a single bank transaction.
     */
    public function findMatches(?int $orgId, FinBankTransaction $transaction, ?User $actor = null): Collection
    {
        if ((int) $transaction->organization_id !== (int) $orgId) {
            abort(404);
        }
        $transaction->loadMissing('bankAccount:id,organization_id');
        abort_unless(
            $transaction->bankAccount !== null
                && (int) $transaction->bankAccount->organization_id === (int) $orgId,
            404,
        );

        $candidates = $this->getCandidates($orgId, $transaction, $actor);
        $scores = collect();

        foreach ($candidates as $candidate) {
            $score = $this->calculateMatchScore($transaction, $candidate);
            if ($score['total'] >= 30) {
                $scores->push([
                    'matchable_type' => get_class($candidate),
                    'matchable_id' => $candidate->id,
                    'confidence_score' => $score['total'],
                    'match_reasons' => $score['reasons'],
                    'dimensions' => $score['dimensions'],
                ]);
            }
        }

        return $scores->sortByDesc('confidence_score')->values();
    }

    public function suggestForTransaction(?int $orgId, FinBankTransaction $transaction, User $actor): int
    {
        return DB::transaction(function () use ($orgId, $transaction, $actor): int {
            $transaction = FinBankTransaction::query()
                ->where('organization_id', $orgId)
                ->lockForUpdate()
                ->findOrFail($transaction->id);

            $created = 0;
            foreach ($this->findMatches($orgId, $transaction, $actor) as $match) {
                $siteId = $this->candidateSiteId($match['matchable_type'], (int) $match['matchable_id'], $orgId);
                $suggestionKey = $this->nextSuggestionKey(
                    $orgId,
                    $transaction->id,
                    $match['matchable_type'],
                    (int) $match['matchable_id'],
                );

                if ($suggestionKey === null) {
                    continue;
                }

                FinPaymentMatch::create([
                    'organization_id' => $orgId,
                    'site_id' => $siteId,
                    'bank_transaction_id' => $transaction->id,
                    'matchable_type' => $match['matchable_type'],
                    'matchable_id' => $match['matchable_id'],
                    'suggestion_key' => $suggestionKey,
                    'confidence_score' => $match['confidence_score'],
                    'match_reasons' => $match['match_reasons'],
                    'status' => 'suggested',
                ]);
                $created++;
            }

            return $created;
        });
    }

    public function scopeMatchesForActor(Builder $query, User $actor): Builder
    {
        return $this->paymentSiteScope->applyPaymentMatchScope($query, $actor);
    }

    /**
     * Calculate a match score between a transaction and a candidate bill/invoice.
     */
    private function calculateMatchScore(FinBankTransaction $txn, $candidate): array
    {
        $score = 0;
        $reasons = [];
        // Dimensions remain explanatory ranking evidence only. They never grant
        // authority to settle without an actor-confirmed Site-scoped command.
        $dimensions = [];

        // 1. Exact amount match (40 points)
        $candidateAmountDue = $this->amountDueFor($candidate);
        if (bccomp((string) abs((float) $txn->amount), (string) $candidateAmountDue, 2) === 0) {
            $score += 40;
            $reasons[] = 'Exact amount match';
            $dimensions[] = 'exact_amount';
        } elseif (abs(abs((float) $txn->amount) - (float) $candidateAmountDue) < 0.50) {
            $score += 25;
            $reasons[] = 'Amount within $0.50 tolerance';
            $dimensions[] = 'amount_tolerance';
        }

        // 2. Reference/invoice number match (30 points)
        $billNumber = $candidate->bill_number ?? $candidate->invoice_number ?? '';
        if ($billNumber && $txn->reference && str_contains(
            strtolower($txn->description.' '.$txn->reference),
            strtolower($billNumber)
        )) {
            $score += 30;
            $reasons[] = 'Reference number found in transaction';
            $dimensions[] = 'reference_match';
        }

        // 3. Vendor/client name match (15 points) - fuzzy match
        $vendorName = $candidate->vendor?->name ?? $candidate->client_name ?? '';
        if ($vendorName && $this->fuzzyMatch($txn->description, $vendorName)) {
            $score += 15;
            $reasons[] = 'Vendor/client name matched in description';
            $dimensions[] = 'vendor_pattern';
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
            $dimensions[] = 'recurring_pattern';
        }

        return ['total' => min($score, 100), 'reasons' => $reasons, 'dimensions' => $dimensions];
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
    private function getCandidates(?int $orgId, FinBankTransaction $transaction, ?User $actor): Collection
    {
        if (bccomp((string) $transaction->amount, '0', 2) < 0) {
            $query = FinBill::forOrganization($orgId)
                ->whereIn('status', ['approved', 'partially_paid'])
                ->whereColumn('amount_paid', '<', 'total_amount')
                ->with('vendor');

            return ($actor ? $this->paymentSiteScope->applyBillScope($query, $actor) : $query)->get();
        }

        if (bccomp((string) $transaction->amount, '0', 2) > 0) {
            $query = FinInvoice::forOrganization($orgId)
                ->whereIn('status', ['sent', 'viewed', 'overdue']);

            return ($actor ? $this->paymentSiteScope->applyInvoiceScope($query, $actor) : $query)
                ->get()
                ->filter(fn (FinInvoice $invoice) => bccomp($this->amountDueFor($invoice), '0', 2) > 0)
                ->values();
        }

        return collect();
    }

    /**
     * Run matching for all unmatched withdrawal transactions in an organisation.
     */
    public function matchUnmatchedTransactions(?int $orgId, ?User $actor = null): array
    {
        $unmatched = FinBankTransaction::whereHas('bankAccount', fn ($q) => $q->forOrganization($orgId))
            ->where('organization_id', $orgId)
            ->whereDoesntHave('paymentMatches', fn ($q) => $q->whereIn('status', ['confirmed', 'auto_confirmed']))
            ->where('amount', '!=', 0)
            ->orderBy('id')
            ->get();

        $results = ['matched' => 0, 'auto_confirmed' => 0, 'suggested' => 0];

        foreach ($unmatched as $txn) {
            $created = DB::transaction(function () use ($orgId, $actor, $txn): bool {
                $lockedTransaction = FinBankTransaction::query()
                    ->where('organization_id', $orgId)
                    ->lockForUpdate()
                    ->findOrFail($txn->id);

                if ($lockedTransaction->paymentMatches()
                    ->whereIn('status', ['suggested', 'confirmed', 'auto_confirmed'])
                    ->exists()) {
                    return false;
                }

                $match = $this->findMatches($orgId, $lockedTransaction, $actor)->first();
                if ($match === null) {
                    return false;
                }

                $siteId = $this->candidateSiteId($match['matchable_type'], (int) $match['matchable_id'], $orgId);
                $suggestionKey = $this->nextSuggestionKey(
                    $orgId,
                    $lockedTransaction->id,
                    $match['matchable_type'],
                    (int) $match['matchable_id'],
                );
                if ($suggestionKey === null) {
                    return false;
                }

                FinPaymentMatch::create([
                    'organization_id' => $orgId,
                    'site_id' => $siteId,
                    'bank_transaction_id' => $lockedTransaction->id,
                    'matchable_type' => $match['matchable_type'],
                    'matchable_id' => $match['matchable_id'],
                    'suggestion_key' => $suggestionKey,
                    'confidence_score' => $match['confidence_score'],
                    'match_reasons' => $match['match_reasons'],
                    'status' => 'suggested',
                ]);

                return true;
            });

            if ($created) {
                $results['suggested']++;
                $results['matched']++;
            }
        }

        return $results;
    }

    /**
     * Confirm a suggested payment match.
     */
    public function confirmMatch(FinPaymentMatch $match, User $actor): FinPaymentMatch
    {
        return $this->confirmAndPost($match, $actor);
    }

    /**
     * Reject a suggested payment match.
     */
    public function rejectMatch(
        FinPaymentMatch $match,
        User $actor,
        ?string $reason = null,
    ): FinPaymentMatch {
        $reason = $reason === null ? null : trim($reason);
        $reason = $reason === '' ? null : $reason;
        if ($reason !== null && mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('The rejection reason may not exceed 500 characters.');
        }

        return DB::transaction(function () use ($match, $actor, $reason): FinPaymentMatch {
            $match = FinPaymentMatch::query()
                ->with('matchable')
                ->lockForUpdate()
                ->findOrFail($match->id);

            abort_unless((int) $match->organization_id === (int) $actor->organization_id, 404);
            $this->paymentSiteScope->assertStoredMatchSiteIsCurrent($match);
            $this->assertActorCanAccessTarget($actor, $match->matchable);

            if ($match->status !== 'suggested' || $match->journal_id !== null) {
                throw new InvalidArgumentException('Only an unsettled suggested match can be rejected.');
            }

            $match->update([
                'status' => 'rejected',
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            AuditLogger::logOrFail('finance.payment_match.rejected', $match, [
                'actor_id' => $actor->id,
                'site_id' => $match->site_id,
                'bank_transaction_id' => $match->bank_transaction_id,
                'suggestion_key' => $match->suggestion_key,
                'reason' => $reason,
            ]);

            return $match->refresh();
        });
    }

    private function confirmAndPost(FinPaymentMatch $match, User $actor): FinPaymentMatch
    {
        $organizationId = (int) FinPaymentMatch::query()
            ->whereKey($match->id)
            ->firstOrFail(['organization_id'])
            ->organization_id;

        return DB::transaction(function () use ($match, $actor, $organizationId) {
            // Shared journal order: 000080 sequence before match, bank
            // transaction and canonical bill/invoice locks.
            $this->journalPostingService->lockJournalSequence($organizationId);
            $match = FinPaymentMatch::query()
                ->lockForUpdate()
                ->findOrFail($match->id);

            abort_unless((int) $match->organization_id === $organizationId, 404);
            abort_unless((int) $match->organization_id === (int) $actor->organization_id, 404);

            if (in_array($match->status, ['confirmed', 'auto_confirmed'], true)) {
                $match->load('matchable');
                $this->paymentSiteScope->assertStoredMatchSiteIsCurrent($match);
                $this->assertActorCanAccessTarget($actor, $match->matchable);
                $evidenceExists = $match->journal_id !== null
                    && $match->allocation()
                        ->where('integrity_state', FinPaymentAllocation::INTEGRITY_TRACEABLE)
                        ->exists();
                if (! $evidenceExists) {
                    throw new InvalidArgumentException('Confirmed payment match is missing canonical settlement evidence.');
                }

                return $match;
            }

            if ($match->status !== 'suggested') {
                throw new InvalidArgumentException('Only a suggested payment match can be confirmed.');
            }

            $transaction = FinBankTransaction::query()
                ->with('bankAccount.glAccount')
                ->where('organization_id', $match->organization_id)
                ->lockForUpdate()
                ->findOrFail($match->bank_transaction_id);

            abort_if(
                FinPaymentAllocation::query()
                    ->where('bank_transaction_id', $transaction->id)
                    ->exists(),
                409,
                'This bank transaction has already been settled.',
            );

            $target = $this->lockMatchable($match);
            $match->setRelation('bankTransaction', $transaction);
            $match->setRelation('matchable', $target);
            $this->paymentSiteScope->assertStoredMatchSiteIsCurrent($match);
            $this->assertActorCanAccessTarget($actor, $target);

            $journal = $this->postJournalForMatch($match, $actor);

            $updates = [
                'status' => 'confirmed',
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'journal_id' => $journal->id,
            ];

            $match->forceFill($updates)->save();

            return $match->refresh();
        });
    }

    private function postJournalForMatch(FinPaymentMatch $match, User $actor)
    {
        $matchable = $match->matchable;

        if ($matchable instanceof FinBill) {
            return $this->postBillPaymentJournal($match, $matchable, $actor);
        }

        if ($matchable instanceof FinInvoice) {
            return $this->postInvoiceReceiptJournal($match, $matchable, $actor);
        }

        abort(404);
    }

    private function postBillPaymentJournal(FinPaymentMatch $match, FinBill $bill, User $actor)
    {
        $transaction = $match->bankTransaction;

        $bill->loadMissing('vendor');
        abort_unless(
            $bill->vendor !== null
                && (int) $bill->vendor->organization_id === (int) $bill->organization_id,
            404,
        );

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
        $siteId = $this->paymentSiteScope->billSiteId($bill);

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
                    'site_id' => $siteId,
                ],
                [
                    'account_id' => $bankAccount->id,
                    'description' => "Bank payment for bill {$bill->bill_number}",
                    'debit' => 0,
                    'credit' => $amount,
                    'site_id' => $siteId,
                ],
            ],
        ]);

        $this->accountsPayableService->recordPayment($bill, $amount);

        $this->settlementRecorder->record(
            target: $bill,
            journal: $journal,
            source: $match,
            siteId: $siteId,
            amount: $amount,
            paymentDate: $transaction->transaction_date->toDateString(),
            actor: $actor,
            bankTransaction: $transaction,
            notes: "Matched bank transaction #{$transaction->id}",
        );

        return $journal;
    }

    private function postInvoiceReceiptJournal(FinPaymentMatch $match, FinInvoice $invoice, User $actor)
    {
        $transaction = $match->bankTransaction;

        if (! in_array($invoice->status, ['sent', 'viewed', 'overdue'], true)) {
            throw new InvalidArgumentException('The invoice is not in a receivable state.');
        }

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
        $siteId = $this->paymentSiteScope->invoiceSiteId($invoice);

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
                    'site_id' => $siteId,
                ],
                [
                    'account_id' => $arAccount->id,
                    'description' => "Receipt matched to invoice {$invoice->invoice_number}",
                    'debit' => 0,
                    'credit' => $amount,
                    'site_id' => $siteId,
                ],
            ],
        ]);

        $this->settlementRecorder->record(
            target: $invoice,
            journal: $journal,
            source: $match,
            siteId: $siteId,
            amount: $amount,
            paymentDate: $transaction->transaction_date->toDateString(),
            actor: $actor,
            bankTransaction: $transaction,
            notes: "Matched bank transaction #{$transaction->id}",
        );

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

    private function lockMatchable(FinPaymentMatch $match): FinBill|FinInvoice
    {
        $query = match ($match->matchable_type) {
            FinBill::class => FinBill::query(),
            FinInvoice::class => FinInvoice::query(),
            default => abort(404),
        };

        return $query
            ->where('organization_id', $match->organization_id)
            ->lockForUpdate()
            ->findOrFail($match->matchable_id);
    }

    private function assertActorCanAccessTarget(User $actor, FinBill|FinInvoice $target): void
    {
        if ($target instanceof FinBill) {
            $this->paymentSiteScope->assertCanAccessBill($actor, $target);

            return;
        }

        $this->paymentSiteScope->assertCanAccessInvoice($actor, $target);
    }

    private function candidateSiteId(string $type, int $id, ?int $orgId): int
    {
        $candidate = match ($type) {
            FinBill::class => FinBill::forOrganization($orgId)->findOrFail($id),
            FinInvoice::class => FinInvoice::forOrganization($orgId)->findOrFail($id),
            default => abort(404),
        };

        return $candidate instanceof FinBill
            ? $this->paymentSiteScope->billSiteId($candidate)
            : $this->paymentSiteScope->invoiceSiteId($candidate);
    }

    private function suggestionKey(int $transactionId, string $type, int $id): string
    {
        return $transactionId.':'.$type.':'.$id;
    }

    private function nextSuggestionKey(
        ?int $orgId,
        int $transactionId,
        string $matchableType,
        int $matchableId,
    ): ?string {
        $baseKey = $this->suggestionKey($transactionId, $matchableType, $matchableId);
        $proposals = FinPaymentMatch::query()
            ->where('organization_id', $orgId)
            ->where('bank_transaction_id', $transactionId)
            ->where('matchable_type', $matchableType)
            ->where('matchable_id', $matchableId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['status', 'suggestion_key']);

        if ($proposals->contains(fn (FinPaymentMatch $proposal): bool => $proposal->status !== 'rejected')) {
            return null;
        }
        if ($proposals->isEmpty()) {
            return $baseKey;
        }

        $latestVersion = $proposals->reduce(
            function (int $latest, FinPaymentMatch $proposal) use ($baseKey): int {
                if ($proposal->suggestion_key === $baseKey) {
                    return max($latest, 1);
                }
                if (preg_match('/^'.preg_quote($baseKey, '/').':v(\d+)$/', (string) $proposal->suggestion_key, $matches)) {
                    return max($latest, (int) $matches[1]);
                }

                return $latest;
            },
            1,
        );

        return $baseKey.':v'.($latestVersion + 1);
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
        abort_unless(
            $transaction->bankAccount !== null
                && (int) $transaction->bankAccount->organization_id === (int) $transaction->organization_id,
            404,
        );

        if ($transaction->bankAccount->glAccount?->is_active
            && (int) $transaction->bankAccount->glAccount->organization_id === (int) $transaction->organization_id) {
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
