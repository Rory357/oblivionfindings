<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinJournalLine;
use App\Models\ClientFund;
use App\Models\ClientFundTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClientFundReconciliationService
{
    private const APPLICATION_STORAGE_CONTEXT_ID = 1;

    /**
     * Reconcile one fund against both its applied subledger effects and the
     * matching client/fund dimension on the Client Trust Funds GL account.
     * Aggregate equality can therefore never mask a cross-client allocation.
     *
     * @return array<string, mixed>
     */
    public function reconcile(ClientFund $fund): array
    {
        return DB::transaction(function () use ($fund): array {
            $lockedFund = ClientFund::query()
                ->with('client:id,site_id')
                ->lockForUpdate()
                ->findOrFail($fund->id);

            $effects = ClientFundTransaction::query()
                ->where('client_fund_id', $lockedFund->id)
                ->whereNotNull('balance_effect_applied_at')
                ->get(['id', 'transaction_type', 'amount', 'status', 'journal_id']);

            $subledgerBalance = $this->sumSigned($effects);
            $unpostedApproved = $effects->filter(fn (ClientFundTransaction $transaction): bool => $transaction->status === 'approved' && $transaction->journal_id === null
            );
            $unpostedApprovedBalance = $this->sumSigned($unpostedApproved);
            $postedSubledgerBalance = bcsub($subledgerBalance, $unpostedApprovedBalance, 2);

            $glBalance = $this->dimensionBalance($lockedFund);
            $storedDifference = bcsub((string) $lockedFund->balance, $subledgerBalance, 2);
            $glDifference = bcsub($postedSubledgerBalance, $glBalance, 2);
            $hasNumericalMismatch = bccomp($storedDifference, '0.00', 2) !== 0
                || bccomp($glDifference, '0.00', 2) !== 0;
            $hasGovernanceReview = $lockedFund->governance_review_status === 'review_required'
                || $effects->contains(fn (ClientFundTransaction $transaction): bool => $transaction->status === 'review');
            $hasPostingReview = $unpostedApproved->isNotEmpty();

            $status = $hasNumericalMismatch
                ? 'mismatch'
                : (($hasGovernanceReview || $hasPostingReview) ? 'review' : 'clear');
            $difference = bccomp($storedDifference, '0.00', 2) !== 0
                ? $storedDifference
                : $glDifference;
            $details = [
                'client_id' => (int) $lockedFund->client_id,
                'client_fund_id' => (int) $lockedFund->id,
                'site_id' => $lockedFund->client?->site_id ? (int) $lockedFund->client->site_id : null,
                'currency_code' => (string) $lockedFund->currency_code,
                'stored_balance' => number_format((float) $lockedFund->balance, 2, '.', ''),
                'applied_subledger_balance' => $subledgerBalance,
                'approved_unposted_effect' => $unpostedApprovedBalance,
                'approved_unposted_count' => $unpostedApproved->count(),
                'posted_subledger_balance' => $postedSubledgerBalance,
                'dimensioned_gl_balance' => $glBalance,
                'stored_difference' => $storedDifference,
                'gl_difference' => $glDifference,
                'governance_review_required' => $hasGovernanceReview,
            ];

            $lockedFund->forceFill([
                'reconciliation_status' => $status,
                'reconciliation_difference' => $difference,
                'reconciliation_details' => $details,
                'reconciled_at' => now(),
            ])->save();

            return ['status' => $status, 'difference' => $difference, ...$details];
        }, 3);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function reconcileClient(int $clientId): Collection
    {
        return ClientFund::query()
            ->where('client_id', $clientId)
            ->orderBy('id')
            ->get()
            ->map(fn (ClientFund $fund): array => $this->reconcile($fund));
    }

    private function dimensionBalance(ClientFund $fund): string
    {
        $totals = FinJournalLine::query()
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_journals.status', 'posted')
            ->where('fin_journals.organization_id', self::APPLICATION_STORAGE_CONTEXT_ID)
            ->where('fin_accounts.organization_id', self::APPLICATION_STORAGE_CONTEXT_ID)
            ->where('fin_accounts.code', '2500')
            ->where('fin_journal_lines.client_id', $fund->client_id)
            ->where('fin_journal_lines.client_fund_id', $fund->id)
            ->where('fin_journal_lines.site_id', $fund->client?->site_id)
            ->selectRaw('COALESCE(SUM(fin_journal_lines.credit), 0) AS credits')
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit), 0) AS debits')
            ->first();

        return bcsub((string) ($totals?->credits ?? '0'), (string) ($totals?->debits ?? '0'), 2);
    }

    /** @param Collection<int, ClientFundTransaction> $transactions */
    private function sumSigned(Collection $transactions): string
    {
        return $transactions->reduce(function (string $total, ClientFundTransaction $transaction): string {
            $amount = (string) $transaction->amount;

            return $this->isCreditType((string) $transaction->transaction_type)
                ? bcadd($total, $amount, 2)
                : bcsub($total, $amount, 2);
        }, '0.00');
    }

    private function isCreditType(string $type): bool
    {
        return in_array($type, ['credit', 'deposit', 'inflow', 'transfer_credit', 'transfer_reversal'], true);
    }
}
