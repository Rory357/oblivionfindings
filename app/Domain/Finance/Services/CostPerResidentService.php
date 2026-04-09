<?php

namespace App\Domain\Finance\Services;

use App\Models\Client;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Calculates the true cost per resident at a site using period-aware occupancy.
 *
 * Cost per resident = total site cost / average resident count over the period.
 *
 * Occupancy calculation priority:
 *   1. BEST: Resident-days calculation from Client records with service_start_date
 *      and deleted_at/status fields. This gives true weighted average occupancy.
 *   2. FALLBACK: Current active client count at the site (point-in-time snapshot).
 *   3. LAST RESORT: site.current_occupancy field.
 *
 * The method documents which calculation tier was used in the output.
 */
class CostPerResidentService
{
    public function __construct(
        private readonly SiteCostService $siteCostService,
    ) {}

    /**
     * @return array{
     *     site_id: int,
     *     site_name: string,
     *     period_from: string,
     *     period_to: string,
     *     total_cost: string,
     *     avg_residents: string,
     *     cost_per_resident: string,
     *     breakdown: array,
     *     occupancy_method: string,
     *     total_resident_days: int,
     *     period_days: int,
     * }
     */
    public function calculate(int $siteId, Carbon $from, Carbon $to): array
    {
        $site = Site::findOrFail($siteId);
        $breakdown = $this->siteCostService->breakdown($siteId, $from, $to);
        $totalCost = $breakdown['total'];

        [$avgResidents, $method, $totalResidentDays, $periodDays] = $this->getAverageOccupancy($siteId, $from, $to);

        $costPerResident = bccomp($avgResidents, '0', 2) > 0
            ? bcdiv($totalCost, $avgResidents, 2)
            : $totalCost;

        return [
            'site_id' => $siteId,
            'site_name' => $site->name,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'total_cost' => $totalCost,
            'avg_residents' => $avgResidents,
            'cost_per_resident' => $costPerResident,
            'breakdown' => $breakdown['categories'],
            'occupancy_method' => $method,
            'total_resident_days' => $totalResidentDays,
            'period_days' => $periodDays,
        ];
    }

    /**
     * Cost per resident across all active residential sites.
     *
     * @return array<int, array>
     */
    public function allSites(Carbon $from, Carbon $to, ?int $tenantId = null): array
    {
        $query = Site::query()
            ->active()
            ->whereIn('type', ['house', 'facility']);

        if ($tenantId) {
            $query->forTenant($tenantId);
        }

        $sites = $query->get();
        $results = [];

        foreach ($sites as $site) {
            $results[$site->id] = $this->calculate($site->id, $from, $to);
        }

        uasort($results, fn ($a, $b) => bccomp($b['cost_per_resident'], $a['cost_per_resident'], 2));

        return $results;
    }

    /**
     * Monthly cost-per-resident trend for a single site.
     *
     * @return array<string, array{total_cost: string, avg_residents: string, cost_per_resident: string, occupancy_method: string}>
     */
    public function monthlyTrend(int $siteId, Carbon $from, Carbon $to): array
    {
        $monthlyCosts = $this->siteCostService->monthlyTrend($siteId, $from, $to);
        $trend = [];

        foreach ($monthlyCosts as $month => $totalCost) {
            $monthStart = Carbon::parse($month . '-01')->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            [$avgResidents, $method] = $this->getAverageOccupancy($siteId, $monthStart, $monthEnd);

            $trend[$month] = [
                'total_cost' => $totalCost,
                'avg_residents' => $avgResidents,
                'cost_per_resident' => bccomp($avgResidents, '0', 2) > 0
                    ? bcdiv($totalCost, $avgResidents, 2)
                    : $totalCost,
                'occupancy_method' => $method,
            ];
        }

        return $trend;
    }

    /**
     * Calculate average occupancy for a site over a date range.
     *
     * TIER 1 (resident-days): For each client at the site, count the number of days
     * they were active during [from, to]. A client is "active" from their
     * service_start_date (or period start, whichever is later) to their deleted_at
     * or status change (or period end, whichever is earlier).
     *
     * Average = total_resident_days / period_days.
     *
     * TIER 2 (snapshot): Count clients currently active. Used when no clients have
     * service_start_date (legacy data).
     *
     * TIER 3 (counter): Use site.current_occupancy. Used when no clients at all.
     *
     * @return array{string, string, int, int} [avg_residents, method, total_resident_days, period_days]
     */
    private function getAverageOccupancy(int $siteId, Carbon $from, Carbon $to): array
    {
        $periodDays = max($from->diffInDays($to) + 1, 1); // +1 because both ends inclusive

        // Get all clients who were at this site during the period.
        // Include soft-deleted clients (they left during the period).
        $clients = Client::withTrashed()
            ->where('site_id', $siteId)
            ->where(function ($q) use ($from, $to) {
                // Client must have started service on or before the period end
                $q->where(function ($inner) use ($to) {
                    $inner->whereNull('service_start_date')
                        ->orWhere('service_start_date', '<=', $to);
                });
                // Client must not have been deleted before the period start
                $q->where(function ($inner) use ($from) {
                    $inner->whereNull('deleted_at')
                        ->orWhere('deleted_at', '>=', $from);
                });
            })
            ->get(['id', 'service_start_date', 'status', 'deleted_at']);

        // TIER 1: Resident-days calculation
        $clientsWithDates = $clients->filter(fn ($c) => $c->service_start_date !== null);

        if ($clientsWithDates->isNotEmpty()) {
            $totalDays = 0;

            foreach ($clientsWithDates as $client) {
                // Client's active window within the period
                $clientStart = $client->service_start_date->gt($from)
                    ? $client->service_start_date
                    : $from;

                $clientEnd = $client->deleted_at && $client->deleted_at->lt($to)
                    ? $client->deleted_at->startOfDay()
                    : $to;

                // Skip if client's window doesn't overlap the period
                if ($clientStart->gt($clientEnd)) {
                    continue;
                }

                $days = $clientStart->diffInDays($clientEnd) + 1;
                $totalDays += $days;
            }

            if ($totalDays > 0) {
                $avgResidents = bcdiv((string) $totalDays, (string) $periodDays, 2);

                return [$avgResidents, 'resident_days', $totalDays, $periodDays];
            }
        }

        // TIER 2: Snapshot count of currently active clients
        $activeCount = Client::where('site_id', $siteId)
            ->where(function ($q) {
                $q->where('status', 'active')
                    ->orWhereNull('status');
            })
            ->count();

        if ($activeCount > 0) {
            return [
                number_format($activeCount, 2, '.', ''),
                'snapshot',
                $activeCount * $periodDays,
                $periodDays,
            ];
        }

        // TIER 3: Site counter fallback
        $site = Site::find($siteId);
        $occupancy = max((int) ($site?->current_occupancy ?? 0), 0);

        return [
            number_format($occupancy, 2, '.', ''),
            'site_counter',
            $occupancy * $periodDays,
            $periodDays,
        ];
    }
}
