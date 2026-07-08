<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinCostAllocation;
use App\Models\ClientFundTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Client Ledger Service — personal financial view for a client.
 *
 * Aggregates two data sources:
 *   1. ClientFundTransaction records — the resident's TRUST-FUND movements
 *      (deposits/withdrawals held for them). This is the canonical client-money
 *      store (posts balanced GL to trust accounts 1010/2500 via
 *      ClientFundJournalService). [C4: repointed from the dormant, never-written
 *      ClientLedgerEntry to this working store — Chane's canonical-store decision.]
 *   2. fin_cost_allocations with client_id (GL-backed operational costs attributed
 *      to the client — the org's cost of support).
 *
 * Returns a unified chronological ledger with optional running balance. The personal
 * running balance moves ONLY on the resident's own trust transactions; operational
 * cost allocations are shown for transparency but NEVER reduce the personal balance
 * (client money is segregated — trust liability 2500, never netted vs operational).
 * Every entry is traceable back to a ClientFundTransaction or a FinJournal.
 */
class ClientLedgerService
{
    /** transaction_type values that represent money coming IN to the resident's trust fund. */
    private const INFLOW_TYPES = ['credit', 'deposit', 'inflow'];

    /**
     * Get the full client ledger for a date range.
     *
     * @return array{
     *     client_id: int,
     *     period_from: string,
     *     period_to: string,
     *     entries: array<int, array>,
     *     summary: array{total_inflows: string, total_outflows: string, net: string},
     *     running_balance_enabled: bool,
     * }
     */
    public function getLedger(int $clientId, Carbon $from, Carbon $to, bool $withRunningBalance = false): array
    {
        $entries = $this->buildEntries($clientId, $from, $to);

        // Sort chronologically
        $entries = $entries->sortBy('date')->values();

        $totalInflows = '0';
        $totalOutflows = '0';
        $operationalOutflows = '0';
        $runningBalance = '0';

        // The running balance is the PERSONAL trust balance — opening personal
        // balance, then only personal entries move it.
        if ($withRunningBalance) {
            $runningBalance = $this->getOpeningBalance($clientId, $from);
        }

        $result = [];
        foreach ($entries as $entry) {
            $signed = $entry['signed_amount'];
            $isPersonal = ($entry['source'] ?? null) === 'client_ledger';

            if ($isPersonal) {
                if (bccomp($signed, '0', 2) >= 0) {
                    $totalInflows = bcadd($totalInflows, $signed, 2);
                } else {
                    $totalOutflows = bcadd($totalOutflows, $signed, 2); // Already negative
                }

                if ($withRunningBalance) {
                    $runningBalance = bcadd($runningBalance, $signed, 2);
                }
            } else {
                // Operational cost allocation — informational only; the org's cost of
                // support is never deducted from the resident's personal balance.
                $operationalOutflows = bcadd($operationalOutflows, $signed, 2);
            }

            if ($withRunningBalance) {
                // Personal balance, unchanged by operational rows.
                $entry['running_balance'] = $runningBalance;
            }
            $entry['affects_personal_balance'] = $isPersonal;

            $result[] = $entry;
        }

        return [
            'client_id' => $clientId,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'entries' => $result,
            'summary' => [
                'total_inflows' => $totalInflows,
                'total_outflows' => $totalOutflows,
                'net' => bcadd($totalInflows, $totalOutflows, 2),
                // Org cost-of-support attributed to the client; segregated from the
                // personal balance for transparency.
                'operational_outflows' => $operationalOutflows,
            ],
            'running_balance_enabled' => $withRunningBalance,
        ];
    }

    /**
     * Get a summary of client financials (no individual entries).
     */
    public function summary(int $clientId, Carbon $from, Carbon $to): array
    {
        $txns = $this->clientFundTransactions($clientId)
            ->whereBetween('transaction_date', [$from, $to])
            ->select(
                'transaction_type',
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as count'),
            )
            ->groupBy('transaction_type')
            ->get();

        $glAllocations = FinCostAllocation::forClient($clientId)
            ->forPeriod($from, $to)
            ->select(
                'event_type',
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as count'),
            )
            ->groupBy('event_type')
            ->get();

        return [
            'client_id' => $clientId,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'personal_transactions' => $txns->map(fn ($r) => [
                'direction' => $this->isInflow($r->transaction_type) ? 'inflow' : 'outflow',
                'type' => $r->transaction_type,
                'total' => number_format((float) $r->total, 2, '.', ''),
                'count' => (int) $r->count,
            ])->toArray(),
            'operational_costs' => $glAllocations->map(fn ($r) => [
                'event_type' => $r->event_type,
                'total' => number_format((float) $r->total, 2, '.', ''),
                'count' => (int) $r->count,
            ])->toArray(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Private */
    /* ------------------------------------------------------------------ */

    /**
     * Base query: every trust-fund transaction belonging to any of the client's funds.
     */
    private function clientFundTransactions(int $clientId)
    {
        return ClientFundTransaction::query()
            ->whereHas('fund', fn ($q) => $q->where('client_id', $clientId));
    }

    private function isInflow(?string $transactionType): bool
    {
        return in_array(strtolower((string) $transactionType), self::INFLOW_TYPES, true);
    }

    /**
     * Build unified entry list from both sources.
     */
    private function buildEntries(int $clientId, Carbon $from, Carbon $to): Collection
    {
        $entries = collect();

        // Source 1: the resident's trust-fund transactions (canonical client money).
        $txns = $this->clientFundTransactions($clientId)
            ->whereBetween('transaction_date', [$from, $to])
            ->orderBy('transaction_date')
            ->get();

        foreach ($txns as $txn) {
            $isInflow = $this->isInflow($txn->transaction_type);
            $amount = (string) $txn->amount;
            $signed = $isInflow ? $amount : '-'.ltrim($amount, '-');

            $entries->push([
                'date' => $txn->transaction_date->toDateString(),
                'source' => 'client_ledger', // personal (moves the personal running balance)
                'source_id' => $txn->id,
                'type' => $txn->transaction_type,
                'category' => $txn->category,
                'direction' => $isInflow ? 'inflow' : 'outflow',
                'amount' => $amount,
                'signed_amount' => $signed,
                'description' => $txn->description,
                'reference' => $txn->reference,
                'journal_id' => $txn->journal_id,
                'is_gl_backed' => $txn->journal_id !== null,
            ]);
        }

        // Source 2: fin_cost_allocations with client_id (operational costs — the org's
        // cost of support). Informational only; never move the personal balance.
        $allocations = FinCostAllocation::forClient($clientId)
            ->forPeriod($from, $to)
            ->with(['journal:id,description,journal_date,source_type,source_id', 'financialEvent:id,event_type,description,source_type'])
            ->orderBy('event_date')
            ->get();

        foreach ($allocations as $alloc) {
            $entries->push([
                'date' => $alloc->event_date->toDateString(),
                'source' => 'cost_allocation',
                'source_id' => $alloc->id,
                'type' => $alloc->event_type,
                'category' => null,
                'direction' => 'outflow',
                'amount' => (string) $alloc->amount,
                'signed_amount' => '-'.(string) $alloc->amount,
                'description' => $alloc->journal?->description ?? $alloc->event_type,
                'reference' => $alloc->journal?->journal_number ?? null,
                'journal_id' => $alloc->journal_id,
                'is_gl_backed' => true,
            ]);
        }

        return $entries;
    }

    /**
     * Calculate the PERSONAL opening balance as of a given date.
     *
     * Personal inflows minus outflows from the resident's trust transactions only.
     * Operational cost allocations (the org's cost of supporting the client) are
     * deliberately NOT subtracted — they are not deductions from the resident's
     * personal trust money, so they must never reduce the personal balance.
     */
    private function getOpeningBalance(int $clientId, Carbon $asOf): string
    {
        $priorTxns = $this->clientFundTransactions($clientId)
            ->where('transaction_date', '<', $asOf)
            ->get(['transaction_type', 'amount']);

        $inflows = '0';
        $outflows = '0';
        foreach ($priorTxns as $txn) {
            $amount = ltrim((string) $txn->amount, '-');
            if ($this->isInflow($txn->transaction_type)) {
                $inflows = bcadd($inflows, $amount, 2);
            } else {
                $outflows = bcadd($outflows, $amount, 2);
            }
        }

        return bcsub($inflows, $outflows, 2);
    }
}
