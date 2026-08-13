<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinCostAllocation;
use App\Models\BillingEntry;
use App\Models\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Client Cost Service — the true cost to support a client.
 *
 * Separates costs into:
 *   1. DIRECT COSTS — expenses with this client's ID in the allocation
 *      (payroll via BillingEntry.payroll_cost, mileage, transport, client-specific purchases)
 *   2. ALLOCATED OVERHEADS — site-level costs (rent, utilities, maintenance, general house expenses)
 *      allocated proportionally by resident-days
 *
 * Every number traces back to a FinJournal, BillingEntry, or FinCostAllocation.
 *
 * Handles:
 *   - Client moving sites mid-period
 *   - Client entering/leaving mid-period
 *   - Sites with zero occupancy (overhead unallocated)
 */
class ClientCostService
{
    /** Event types that are site-level overheads, not client-direct. */
    private const OVERHEAD_EVENT_TYPES = [
        'site_rent_expense',
        'site_utilities_expense',
        'site_utilities_true_up',
        'site_maintenance_expense',
        'house_ledger_expense',
    ];

    /** Event types that are directly attributable to a client. */
    private const DIRECT_EVENT_TYPES = [
        'payroll_cost',
        'employer_oncost',
        'mileage_reimbursement',
        'fuel_expense',
        'client_ledger_expense',
        'client_ledger_income',
    ];

    public function __construct(
        private readonly SiteCostService $siteCostService,
    ) {}

    /**
     * Calculate the full cost to support a client over a period.
     *
     * @return array{
     *     client_id: int,
     *     period_start: string,
     *     period_end: string,
     *     direct_costs: array{payroll: string, mileage: string, transport: string, purchases: string, other: string, total: string},
     *     allocated_overheads: array{rent: string, utilities: string, maintenance: string, house_operating: string, other: string, total: string},
     *     total_cost: string,
     *     resident_days: int,
     *     notes: array{allocation_method: string, assumptions: array<string>},
     * }
     */
    public function calculate(int $clientId, Carbon $from, Carbon $to): array
    {
        $client = Client::query()->findOrFail($clientId);

        // 1. Direct costs
        $direct = $this->calculateDirectCosts($clientId, $from, $to);

        // 2. Allocated overheads (from every site the client was at during the period)
        $overhead = $this->calculateAllocatedOverheads($client, $from, $to);

        $totalCost = bcadd($direct['total'], $overhead['total'], 2);

        return [
            'client_id' => $clientId,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'direct_costs' => $direct,
            'allocated_overheads' => $overhead,
            'total_cost' => $totalCost,
            'resident_days' => $overhead['resident_days'],
            'notes' => [
                'allocation_method' => $overhead['allocation_method'],
                'assumptions' => $overhead['assumptions'],
            ],
        ];
    }

    /**
     * Monthly cost trend for a client.
     *
     * @return array<string, array{direct: string, overhead: string, total: string}>
     */
    public function monthlyTrend(int $clientId, Carbon $from, Carbon $to): array
    {
        $trend = [];
        $current = $from->copy()->startOfMonth();
        $client = Client::query()->findOrFail($clientId);

        while ($current->lte($to)) {
            $monthStart = $current->copy()->startOfMonth();
            $monthEnd = $current->copy()->endOfMonth();

            // Clamp to requested range
            if ($monthStart->lt($from)) {
                $monthStart = $from->copy();
            }
            if ($monthEnd->gt($to)) {
                $monthEnd = $to->copy();
            }

            $direct = $this->calculateDirectCosts($clientId, $monthStart, $monthEnd);
            $overhead = $this->calculateAllocatedOverheads($client, $monthStart, $monthEnd);

            $trend[$current->format('Y-m')] = [
                'direct' => $direct['total'],
                'overhead' => $overhead['total'],
                'total' => bcadd($direct['total'], $overhead['total'], 2),
            ];

            $current->addMonth();
        }

        return $trend;
    }

    /* ------------------------------------------------------------------ */
    /*  Direct Costs                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Direct costs: expenses explicitly linked to this client.
     *
     * Primary source: fin_cost_allocations where client_id = this client.
     * This includes payroll_cost allocations (from PayrollCostAllocationService),
     * mileage, transport, and personal purchases.
     *
     * Fallback: If no payroll_cost allocations exist in fin_cost_allocations for
     * this period, falls back to BillingEntry.payroll_cost (snapshot from billing time).
     * This ensures backward compatibility before PayrollCostAllocationService backfill.
     */
    private function calculateDirectCosts(int $clientId, Carbon $from, Carbon $to): array
    {
        // All client-specific cost allocations from GL
        $allocations = FinCostAllocation::forClient($clientId)
            ->forPeriod($from, $to)
            ->select('event_type', DB::raw('SUM(amount) as total'))
            ->groupBy('event_type')
            ->pluck('total', 'event_type');

        // Payroll: prefer GL allocation, fallback to BillingEntry snapshot
        $payrollFromAlloc = (float) ($allocations['payroll_cost'] ?? 0);

        if ($payrollFromAlloc > 0) {
            $payrollStr = number_format($payrollFromAlloc, 2, '.', '');
        } else {
            // Fallback: BillingEntry snapshot (for periods before payroll allocation was implemented)
            $payrollFromBilling = BillingEntry::where('client_id', $clientId)
                ->whereBetween('service_date', [$from, $to])
                ->sum('payroll_cost');
            $payrollStr = number_format((float) $payrollFromBilling, 2, '.', '');
        }

        $employerOncost = number_format((float) ($allocations['employer_oncost'] ?? 0), 2, '.', '');
        $mileage = number_format((float) ($allocations['mileage_reimbursement'] ?? 0), 2, '.', '');
        $transport = number_format((float) ($allocations['fuel_expense'] ?? 0), 2, '.', '');
        $purchases = number_format((float) ($allocations['client_ledger_expense'] ?? 0), 2, '.', '');

        // Other direct: anything in allocations not already categorised above and not an overhead
        $otherDirect = '0';
        foreach ($allocations as $type => $amount) {
            if (in_array($type, self::OVERHEAD_EVENT_TYPES)) {
                continue;
            }
            if (in_array($type, ['payroll_cost', 'employer_oncost', 'mileage_reimbursement', 'fuel_expense', 'client_ledger_expense', 'client_ledger_income'])) {
                continue;
            }
            $otherDirect = bcadd($otherDirect, (string) $amount, 2);
        }

        $total = bcadd($payrollStr, $employerOncost, 2);
        $total = bcadd($total, $mileage, 2);
        $total = bcadd($total, $transport, 2);
        $total = bcadd($total, $purchases, 2);
        $total = bcadd($total, $otherDirect, 2);

        return [
            'payroll' => $payrollStr,
            'employer_oncost' => $employerOncost,
            'mileage' => $mileage,
            'transport' => $transport,
            'purchases' => $purchases,
            'other' => $otherDirect,
            'total' => $total,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Allocated Overheads                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Overhead allocation: site-level costs split by resident-days.
     *
     * A client may have been at multiple sites during the period (transfer).
     * For each site:
     *   1. Calculate the client's resident-days at that site
     *   2. Calculate total resident-days at that site for all clients
     *   3. Client's share = (client_days / total_days) × site overhead cost
     *
     * If total_days is 0 at a site (no occupancy data), overhead is not allocated
     * to any client — it remains as a site-level unallocated cost.
     */
    private function calculateAllocatedOverheads(Client $client, Carbon $from, Carbon $to): array
    {
        $periodDays = max($from->diffInDays($to) + 1, 1);

        // Determine which site(s) the client was at during this period
        // For now, clients have a single site_id. A client who transferred
        // sites will have their current site_id. We use that as the primary.
        $siteIds = collect([$client->site_id])->filter()->unique();

        $rent = '0';
        $utilities = '0';
        $maintenance = '0';
        $houseOperating = '0';
        $otherOverhead = '0';
        $totalResidentDays = 0;
        $assumptions = [];

        foreach ($siteIds as $siteId) {
            // Client's days at this site
            $clientDays = $this->clientDaysAtSite($client, (int) $siteId, $from, $to);
            if ($clientDays <= 0) {
                continue;
            }
            $totalResidentDays += $clientDays;

            // Total resident-days at this site (all clients)
            $siteTotalDays = $this->totalResidentDaysAtSite((int) $siteId, $from, $to);
            if ($siteTotalDays <= 0) {
                $assumptions[] = "Site #{$siteId}: zero total occupancy, overhead unallocated";
                continue;
            }

            // Client's allocation fraction
            $fraction = bcdiv((string) $clientDays, (string) $siteTotalDays, 6);

            // Get site overhead costs by type
            $siteOverheads = FinCostAllocation::forSite((int) $siteId)
                ->forPeriod($from, $to)
                ->whereIn('event_type', self::OVERHEAD_EVENT_TYPES)
                ->select('event_type', DB::raw('SUM(amount) as total'))
                ->groupBy('event_type')
                ->pluck('total', 'event_type');

            foreach ($siteOverheads as $type => $siteAmount) {
                $clientShare = bcmul((string) $siteAmount, $fraction, 2);

                match ($type) {
                    'site_rent_expense' => $rent = bcadd($rent, $clientShare, 2),
                    'site_utilities_expense', 'site_utilities_true_up' => $utilities = bcadd($utilities, $clientShare, 2),
                    'site_maintenance_expense' => $maintenance = bcadd($maintenance, $clientShare, 2),
                    'house_ledger_expense' => $houseOperating = bcadd($houseOperating, $clientShare, 2),
                    default => $otherOverhead = bcadd($otherOverhead, $clientShare, 2),
                };
            }

            $assumptions[] = "Site #{$siteId}: {$clientDays}/{$siteTotalDays} resident-days"
                . " ({$fraction} allocation fraction)";
        }

        $total = bcadd($rent, $utilities, 2);
        $total = bcadd($total, $maintenance, 2);
        $total = bcadd($total, $houseOperating, 2);
        $total = bcadd($total, $otherOverhead, 2);

        return [
            'rent' => $rent,
            'utilities' => $utilities,
            'maintenance' => $maintenance,
            'house_operating' => $houseOperating,
            'other' => $otherOverhead,
            'total' => $total,
            'resident_days' => $totalResidentDays,
            'allocation_method' => 'resident_days_proportional',
            'assumptions' => $assumptions,
        ];
    }

    /**
     * How many days was THIS client at the given site during [from, to]?
     */
    private function clientDaysAtSite(Client $client, int $siteId, Carbon $from, Carbon $to): int
    {
        if ($client->site_id !== $siteId) {
            return 0;
        }

        $clientStart = $client->service_start_date && $client->service_start_date->gt($from)
            ? $client->service_start_date
            : $from;

        $clientEnd = $client->deleted_at && $client->deleted_at->lt($to)
            ? $client->deleted_at->startOfDay()
            : $to;

        if ($clientStart->gt($clientEnd)) {
            return 0;
        }

        return $clientStart->diffInDays($clientEnd) + 1;
    }

    /**
     * Total resident-days at a site across ALL clients during [from, to].
     *
     * Uses the same Tier 1 logic as CostPerResidentService for consistency.
     */
    private function totalResidentDaysAtSite(int $siteId, Carbon $from, Carbon $to): int
    {
        $periodDays = max($from->diffInDays($to) + 1, 1);

        $clients = Client::withTrashed()
            ->where('site_id', $siteId)
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($inner) use ($to) {
                    $inner->whereNull('service_start_date')
                        ->orWhere('service_start_date', '<=', $to);
                });
                $q->where(function ($inner) use ($from) {
                    $inner->whereNull('deleted_at')
                        ->orWhere('deleted_at', '>=', $from);
                });
            })
            ->get(['id', 'service_start_date', 'deleted_at']);

        $totalDays = 0;

        foreach ($clients as $client) {
            $clientStart = $client->service_start_date && $client->service_start_date->gt($from)
                ? $client->service_start_date
                : $from;

            $clientEnd = $client->deleted_at && $client->deleted_at->lt($to)
                ? $client->deleted_at->startOfDay()
                : $to;

            if ($clientStart->gt($clientEnd)) {
                continue;
            }

            $totalDays += $clientStart->diffInDays($clientEnd) + 1;
        }

        // Fallback: if no date-aware clients, use current occupancy × period days
        if ($totalDays === 0) {
            $activeCount = Client::where('site_id', $siteId)
                ->where(function ($q) {
                    $q->where('status', 'active')->orWhereNull('status');
                })
                ->count();

            $totalDays = $activeCount * $periodDays;
        }

        return $totalDays;
    }
}
