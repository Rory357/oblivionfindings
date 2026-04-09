<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinCostAllocation;
use App\Models\ClientLedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Client Ledger Service — personal financial view for a client.
 *
 * Aggregates two data sources:
 *   1. ClientLedgerEntry records (personal transactions: contributions, purchases, etc.)
 *   2. fin_cost_allocations with client_id (GL-backed operational costs attributed to client)
 *
 * Returns a unified chronological ledger with optional running balance.
 * Every entry is traceable back to either a ClientLedgerEntry or a FinJournal.
 */
class ClientLedgerService
{
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
        $runningBalance = '0';

        // If running balance is enabled, we need the opening balance (all prior inflows - outflows)
        if ($withRunningBalance) {
            $runningBalance = $this->getOpeningBalance($clientId, $from);
        }

        $result = [];
        foreach ($entries as $entry) {
            $signed = $entry['signed_amount'];

            if (bccomp($signed, '0', 2) >= 0) {
                $totalInflows = bcadd($totalInflows, $signed, 2);
            } else {
                $totalOutflows = bcadd($totalOutflows, $signed, 2); // Already negative
            }

            if ($withRunningBalance) {
                $runningBalance = bcadd($runningBalance, $signed, 2);
                $entry['running_balance'] = $runningBalance;
            }

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
            ],
            'running_balance_enabled' => $withRunningBalance,
        ];
    }

    /**
     * Get a summary of client financials (no individual entries).
     */
    public function summary(int $clientId, Carbon $from, Carbon $to): array
    {
        $ledgerEntries = ClientLedgerEntry::forClient($clientId)
            ->forPeriod($from, $to)
            ->select(
                'direction',
                'type',
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as count'),
            )
            ->groupBy('direction', 'type')
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
            'personal_transactions' => $ledgerEntries->map(fn ($r) => [
                'direction' => $r->direction,
                'type' => $r->type,
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
    /*  Private                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Build unified entry list from both sources.
     */
    private function buildEntries(int $clientId, Carbon $from, Carbon $to): Collection
    {
        $entries = collect();

        // Source 1: ClientLedgerEntry records
        $ledgerEntries = ClientLedgerEntry::forClient($clientId)
            ->forPeriod($from, $to)
            ->orderBy('entry_date')
            ->get();

        foreach ($ledgerEntries as $le) {
            $entries->push([
                'date' => $le->entry_date->toDateString(),
                'source' => 'client_ledger',
                'source_id' => $le->id,
                'type' => $le->type,
                'category' => $le->category,
                'direction' => $le->direction,
                'amount' => (string) $le->amount,
                'signed_amount' => $le->signedAmount(),
                'description' => $le->description,
                'reference' => $le->reference,
                'journal_id' => $le->journal_id,
                'is_gl_backed' => $le->journal_id !== null,
            ]);
        }

        // Source 2: fin_cost_allocations with client_id (operational costs)
        // Exclude entries that originated from ClientLedgerEntry (already included above)
        $allocations = FinCostAllocation::forClient($clientId)
            ->forPeriod($from, $to)
            ->with(['journal:id,description,journal_date,source_type,source_id', 'financialEvent:id,event_type,description,source_type'])
            ->orderBy('event_date')
            ->get();

        foreach ($allocations as $alloc) {
            // Skip if this allocation came from a ClientLedgerEntry — already shown above
            $event = $alloc->financialEvent;
            if ($event && $event->source_type === ClientLedgerEntry::class) {
                continue;
            }

            $entries->push([
                'date' => $alloc->event_date->toDateString(),
                'source' => 'cost_allocation',
                'source_id' => $alloc->id,
                'type' => $alloc->event_type,
                'category' => null,
                'direction' => 'outflow',
                'amount' => (string) $alloc->amount,
                'signed_amount' => '-' . (string) $alloc->amount,
                'description' => $alloc->journal?->description ?? $alloc->event_type,
                'reference' => $alloc->journal?->journal_number ?? null,
                'journal_id' => $alloc->journal_id,
                'is_gl_backed' => true,
            ]);
        }

        return $entries;
    }

    /**
     * Calculate opening balance as of a given date.
     *
     * Sum of all inflows minus outflows from ClientLedgerEntry before the date,
     * minus all cost allocations before the date.
     */
    private function getOpeningBalance(int $clientId, Carbon $asOf): string
    {
        // Personal transactions before period
        $ledgerInflows = ClientLedgerEntry::forClient($clientId)
            ->where('entry_date', '<', $asOf)
            ->inflows()
            ->sum('amount');

        $ledgerOutflows = ClientLedgerEntry::forClient($clientId)
            ->where('entry_date', '<', $asOf)
            ->outflows()
            ->sum('amount');

        // Operational cost allocations before period (these are always "outflows" from client perspective)
        $allocOutflows = FinCostAllocation::forClient($clientId)
            ->where('event_date', '<', $asOf)
            ->whereHas('financialEvent', function ($q) {
                $q->where('source_type', '!=', ClientLedgerEntry::class);
            })
            ->sum('amount');

        $balance = bcsub((string) $ledgerInflows, (string) $ledgerOutflows, 2);
        $balance = bcsub($balance, (string) $allocOutflows, 2);

        return $balance;
    }
}
