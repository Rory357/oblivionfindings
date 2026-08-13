<?php

namespace App\Domain\Finance\Services;

use App\Models\Client;
use App\Models\ServiceAgreement;
use Illuminate\Support\Carbon;

/**
 * Composes ClientLedgerService and ClientCostService into a unified
 * summary for client financial dashboards.
 *
 * Read-only. No financial mutations.
 */
class ClientFinancialSummaryService
{
    public function __construct(
        private readonly ClientLedgerService $ledgerService,
        private readonly ClientCostService $costService,
    ) {}

    /**
     * Full financial summary for a client over a period.
     */
    public function getSummary(int $clientId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $to = $to ?? Carbon::now();
        $from = $from ?? $to->copy()->subMonths(1)->startOfMonth();
        // Financial Insights never exposes a deleted Client as an interactive
        // object, including to users with global finance scope.
        $client = Client::query()->findOrFail($clientId);

        $ledger = $this->ledgerService->getLedger($clientId, $from, $to, withRunningBalance: true);
        $cost = $this->costService->calculate($clientId, $from, $to);

        // Funding data
        $funding = $this->getFundingSummary($clientId, $from, $to);

        // Weekly equivalent
        $periodDays = max($from->diffInDays($to) + 1, 1);
        $periodWeeks = bcdiv((string) $periodDays, '7', 2);
        $weeklyEquivalent = bccomp($periodWeeks, '0', 2) > 0
            ? bcdiv($cost['total_cost'], $periodWeeks, 2)
            : $cost['total_cost'];

        return [
            'client_id' => $clientId,
            'client_name' => $client->full_name,
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $periodDays,
            ],

            // Balance card
            'balance' => [
                'current' => $ledger['entries'] ? end($ledger['entries'])['running_balance'] ?? '0.00' : '0.00',
                'total_inflows' => $ledger['summary']['total_inflows'],
                'total_outflows' => $ledger['summary']['total_outflows'],
                'net' => $ledger['summary']['net'],
            ],

            // Personal transactions
            'personal' => [
                'contributions' => $this->sumByType($ledger['entries'], 'contribution'),
                'purchases' => $this->sumByType($ledger['entries'], 'purchase'),
                'reimbursements' => $this->sumByType($ledger['entries'], 'reimbursement'),
            ],

            // Cost of care
            'cost_of_care' => [
                'direct' => $cost['direct_costs'],
                'overheads' => $cost['allocated_overheads'],
                'total' => $cost['total_cost'],
                'weekly_equivalent' => $weeklyEquivalent,
                'resident_days' => $cost['resident_days'],
            ],

            // Funding comparison
            'funding' => $funding,

            // Cost vs funding gap
            'gap_analysis' => $this->calculateGap($cost['total_cost'], $funding, $periodWeeks),
        ];
    }

    /**
     * Compact card data for multi-client overview.
     */
    public function getClientCards(array $clientIds, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $to = $to ?? Carbon::now();
        $from = $from ?? $to->copy()->subMonths(1)->startOfMonth();
        $periodDays = max($from->diffInDays($to) + 1, 1);
        $periodWeeks = bcdiv((string) $periodDays, '7', 2);

        $cards = [];
        foreach ($clientIds as $clientId) {
            $client = Client::query()->find($clientId);
            if (! $client) {
                continue;
            }

            $cost = $this->costService->calculate($clientId, $from, $to);
            $funding = $this->getFundingSummary($clientId, $from, $to);

            $weeklyEquivalent = bccomp($periodWeeks, '0', 2) > 0
                ? bcdiv($cost['total_cost'], $periodWeeks, 2)
                : $cost['total_cost'];

            $cards[] = [
                'client_id' => $clientId,
                'client_name' => $client->full_name,
                'total_cost' => $cost['total_cost'],
                'weekly_cost' => $weeklyEquivalent,
                'total_funding' => $funding['period_allocation'],
                'gap' => bcsub($cost['total_cost'], $funding['period_allocation'], 2),
            ];
        }

        usort($cards, fn ($a, $b) => bccomp($b['total_cost'], $a['total_cost'], 2));

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'clients' => $cards,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Private */
    /* ------------------------------------------------------------------ */

    private function getFundingSummary(int $clientId, Carbon $from, Carbon $to): array
    {
        // Get active service agreements for this client in the period
        $agreements = ServiceAgreement::where('client_id', $clientId)
            ->where(function ($q) use ($from, $to) {
                $q->where('starts_at', '<=', $to)
                    ->where(function ($inner) use ($from) {
                        $inner->whereNull('ends_at')
                            ->orWhere('ends_at', '>=', $from);
                    });
            })
            ->get();

        $totalBudget = '0';
        $totalUsed = '0';

        foreach ($agreements as $agreement) {
            $totalBudget = bcadd($totalBudget, (string) $agreement->total_budget, 2);
            $totalUsed = bcadd($totalUsed, (string) $agreement->budget_used, 2);
        }

        // Pro-rate budget to the requested period if agreement spans wider
        $periodDays = max($from->diffInDays($to) + 1, 1);
        $periodAllocation = '0';

        foreach ($agreements as $agreement) {
            $agrStart = $agreement->starts_at ?? $from;
            $agrEnd = $agreement->ends_at ?? $to;
            $agrDays = max($agrStart->diffInDays($agrEnd) + 1, 1);

            // Fraction of agreement that falls in the requested period
            $overlapStart = $agrStart->gt($from) ? $agrStart : $from;
            $overlapEnd = $agrEnd->lt($to) ? $agrEnd : $to;
            $overlapDays = max($overlapStart->diffInDays($overlapEnd) + 1, 0);

            if ($overlapDays > 0 && $agrDays > 0) {
                $fraction = bcdiv((string) $overlapDays, (string) $agrDays, 6);
                $allocated = bcmul((string) $agreement->total_budget, $fraction, 2);
                $periodAllocation = bcadd($periodAllocation, $allocated, 2);
            }
        }

        return [
            'agreement_count' => $agreements->count(),
            'total_budget' => $totalBudget,
            'total_used' => $totalUsed,
            'remaining' => bcsub($totalBudget, $totalUsed, 2),
            'period_allocation' => $periodAllocation,
        ];
    }

    private function calculateGap(string $totalCost, array $funding, string $periodWeeks): array
    {
        $periodFunding = $funding['period_allocation'];
        $gap = bcsub($totalCost, $periodFunding, 2);
        $weeklyGap = bccomp($periodWeeks, '0', 2) > 0
            ? bcdiv($gap, $periodWeeks, 2)
            : $gap;

        return [
            'total_gap' => $gap,
            'weekly_gap' => $weeklyGap,
            'is_underfunded' => bccomp($gap, '0', 2) > 0,
            'funding_coverage_pct' => bccomp($totalCost, '0', 2) > 0
                ? bcmul(bcdiv($periodFunding, $totalCost, 4), '100', 1)
                : '100.0',
        ];
    }

    /**
     * Sum amounts from ledger entries by type (from the unified entries array).
     */
    private function sumByType(array $entries, string $type): string
    {
        $total = '0';
        foreach ($entries as $entry) {
            if (($entry['type'] ?? '') === $type) {
                // Use absolute amount (entries may be signed)
                $total = bcadd($total, (string) abs((float) ($entry['amount'] ?? 0)), 2);
            }
        }

        return $total;
    }
}
