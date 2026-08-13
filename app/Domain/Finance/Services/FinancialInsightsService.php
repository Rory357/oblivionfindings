<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\SiteBudgetLine;
use App\Models\Client;
use App\Models\ServiceAgreement;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Generates actionable financial insights from real data.
 *
 * Each insight has:
 *   - type: cost_increase, underfunded_client, utility_trend, over_budget, approaching_budget, forecast_overrun
 *   - severity: info, warning, critical
 *   - message: human-readable description
 *   - data: structured payload for UI rendering
 *
 * Thresholds are configurable via config('finance.insight_thresholds').
 */
class FinancialInsightsService
{
    public function __construct(
        private readonly SiteCostService $siteCostService,
        private readonly CostPerResidentService $costPerResidentService,
        private readonly ClientCostService $clientCostService,
        private readonly BudgetVarianceService $budgetVarianceService,
        private readonly FinancialForecastService $forecastService,
        private readonly StaffingCostService $staffingCostService,
    ) {}

    /**
     * Generate all insights for an organisation.
     *
     * @return array<int, array{type: string, severity: string, message: string, data: array}>
     */
    public function generate(array $siteIds, array $clientIds): array
    {
        $now = Carbon::now();
        $currentFrom = $now->copy()->subMonth()->startOfMonth();
        $currentTo = $now->copy()->subMonth()->endOfMonth();
        $currentPeriod = $currentFrom->format('Y-m');
        $previousFrom = $currentFrom->copy()->subMonth();
        $previousTo = $currentFrom->copy()->subDay();

        $insights = [];

        $insights = array_merge($insights, $this->siteCostInsights($siteIds, $currentFrom, $currentTo, $previousFrom, $previousTo));
        $insights = array_merge($insights, $this->clientFundingInsights($clientIds, $currentFrom, $currentTo));
        $insights = array_merge($insights, $this->utilityCostInsights($siteIds, $currentFrom, $currentTo, $previousFrom, $previousTo));
        $insights = array_merge($insights, $this->budgetVarianceInsights($siteIds, $currentPeriod));
        $insights = array_merge($insights, $this->forecastOverrunInsights($siteIds));
        $insights = array_merge($insights, $this->staffingCostInsights($siteIds, $currentFrom, $currentTo));

        // Sort by severity: critical first, then warning, then info
        $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($insights, fn ($a, $b) => ($severityOrder[$a['severity']] ?? 3) <=> ($severityOrder[$b['severity']] ?? 3));

        return $insights;
    }

    /* ------------------------------------------------------------------ */
    /*  Site Cost Insights */
    /* ------------------------------------------------------------------ */

    private function siteCostInsights(array $siteIds, Carbon $currentFrom, Carbon $currentTo, Carbon $previousFrom, Carbon $previousTo): array
    {
        $thresholds = config('finance.insight_thresholds', []);
        $costIncreasePct = (float) ($thresholds['site_cost_increase_warning_pct'] ?? 15);
        $costIncreaseCriticalPct = (float) ($thresholds['site_cost_increase_critical_pct'] ?? 30);

        $sites = $this->sites($siteIds)->get();

        $insights = [];

        foreach ($sites as $site) {
            $currentCost = (float) $this->siteCostService->totalCost($site->id, $currentFrom, $currentTo);
            $previousCost = (float) $this->siteCostService->totalCost($site->id, $previousFrom, $previousTo);

            if ($previousCost <= 0 || $currentCost <= 0) {
                continue;
            }

            $changePct = (($currentCost - $previousCost) / $previousCost) * 100;

            if ($changePct >= $costIncreaseCriticalPct) {
                $insights[] = [
                    'type' => 'site_cost_increase',
                    'severity' => 'critical',
                    'message' => "{$site->name} cost increased by ".round($changePct, 1).'% this month',
                    'data' => [
                        'site_id' => $site->id,
                        'site_name' => $site->name,
                        'current_cost' => number_format($currentCost, 2, '.', ''),
                        'previous_cost' => number_format($previousCost, 2, '.', ''),
                        'change_pct' => round($changePct, 1),
                    ],
                ];
            } elseif ($changePct >= $costIncreasePct) {
                $insights[] = [
                    'type' => 'site_cost_increase',
                    'severity' => 'warning',
                    'message' => "{$site->name} cost increased by ".round($changePct, 1).'% this month',
                    'data' => [
                        'site_id' => $site->id,
                        'site_name' => $site->name,
                        'current_cost' => number_format($currentCost, 2, '.', ''),
                        'previous_cost' => number_format($previousCost, 2, '.', ''),
                        'change_pct' => round($changePct, 1),
                    ],
                ];
            }
        }

        return $insights;
    }

    /* ------------------------------------------------------------------ */
    /*  Client Funding Insights */
    /* ------------------------------------------------------------------ */

    private function clientFundingInsights(array $clientIds, Carbon $from, Carbon $to): array
    {
        $thresholds = config('finance.insight_thresholds', []);
        $gapWarningWeekly = (float) ($thresholds['client_funding_gap_warning_weekly'] ?? 200);
        $gapCriticalWeekly = (float) ($thresholds['client_funding_gap_critical_weekly'] ?? 500);

        $clients = Client::query()
            ->whereIn('id', $clientIds)
            ->where(function ($query) {
                $query->where('status', 'active')->orWhereNull('status');
            })
            ->limit(100)
            ->get();

        $periodDays = max($from->diffInDays($to) + 1, 1);
        $periodWeeks = max($periodDays / 7, 1);

        $insights = [];

        foreach ($clients as $client) {
            $cost = $this->clientCostService->calculate($client->id, $from, $to);
            $totalCost = (float) $cost['total_cost'];
            $funding = (float) $this->getClientFunding($client->id, $from, $to);

            $gap = $totalCost - $funding;
            if ($gap <= 0) {
                continue;
            }

            $weeklyGap = $gap / $periodWeeks;

            if ($weeklyGap >= $gapCriticalWeekly) {
                $insights[] = [
                    'type' => 'underfunded_client',
                    'severity' => 'critical',
                    'message' => "{$client->full_name} is costing \$".round($weeklyGap).'/week more than funding',
                    'data' => [
                        'client_id' => $client->id,
                        'client_name' => $client->full_name,
                        'total_cost' => number_format($totalCost, 2, '.', ''),
                        'total_funding' => number_format($funding, 2, '.', ''),
                        'weekly_gap' => number_format($weeklyGap, 2, '.', ''),
                    ],
                ];
            } elseif ($weeklyGap >= $gapWarningWeekly) {
                $insights[] = [
                    'type' => 'underfunded_client',
                    'severity' => 'warning',
                    'message' => "{$client->full_name} is costing \$".round($weeklyGap).'/week more than funding',
                    'data' => [
                        'client_id' => $client->id,
                        'client_name' => $client->full_name,
                        'total_cost' => number_format($totalCost, 2, '.', ''),
                        'total_funding' => number_format($funding, 2, '.', ''),
                        'weekly_gap' => number_format($weeklyGap, 2, '.', ''),
                    ],
                ];
            }
        }

        return $insights;
    }

    /* ------------------------------------------------------------------ */
    /*  Utility Trend Insights */
    /* ------------------------------------------------------------------ */

    private function utilityCostInsights(array $siteIds, Carbon $currentFrom, Carbon $currentTo, Carbon $previousFrom, Carbon $previousTo): array
    {
        $thresholds = config('finance.insight_thresholds', []);
        $utilityIncreasePct = (float) ($thresholds['utility_increase_warning_pct'] ?? 20);

        $sites = $this->sites($siteIds)->get();

        $insights = [];

        foreach ($sites as $site) {
            $currentBreakdown = $this->siteCostService->breakdown($site->id, $currentFrom, $currentTo);
            $previousBreakdown = $this->siteCostService->breakdown($site->id, $previousFrom, $previousTo);

            $currentUtility = (float) ($currentBreakdown['categories']['site_utilities_expense']['amount'] ?? 0);
            $previousUtility = (float) ($previousBreakdown['categories']['site_utilities_expense']['amount'] ?? 0);

            if ($previousUtility <= 0 || $currentUtility <= 0) {
                continue;
            }

            $changePct = (($currentUtility - $previousUtility) / $previousUtility) * 100;

            if ($changePct >= $utilityIncreasePct) {
                $insights[] = [
                    'type' => 'utility_cost_increase',
                    'severity' => 'warning',
                    'message' => "Utilities for {$site->name} increased by ".round($changePct, 1).'%',
                    'data' => [
                        'site_id' => $site->id,
                        'site_name' => $site->name,
                        'current_amount' => number_format($currentUtility, 2, '.', ''),
                        'previous_amount' => number_format($previousUtility, 2, '.', ''),
                        'change_pct' => round($changePct, 1),
                    ],
                ];
            }
        }

        return $insights;
    }

    /* ------------------------------------------------------------------ */
    /*  Budget Variance Insights (NEW — PR7) */
    /* ------------------------------------------------------------------ */

    private function budgetVarianceInsights(array $siteIds, string $period): array
    {
        $thresholds = config('finance.insight_thresholds', []);
        $approachingPct = (float) ($thresholds['budget_approaching_pct'] ?? 85);
        $overBudgetPct = (float) ($thresholds['budget_over_pct'] ?? 100);

        $sites = $this->sites($siteIds)->get();

        $insights = [];

        foreach ($sites as $site) {
            // Check if this site has any budget lines for this period
            $hasBudget = SiteBudgetLine::forSite($site->id)->forPeriod($period)->exists();
            if (! $hasBudget) {
                continue;
            }

            $variance = $this->budgetVarianceService->siteVariance($site->id, $period, $period);

            foreach ($variance['lines'] as $line) {
                $planned = (float) $line['planned'];
                $actual = (float) $line['actual'];

                if ($planned <= 0) {
                    continue;
                }

                $usagePct = ($actual / $planned) * 100;

                if ($usagePct >= $overBudgetPct) {
                    $insights[] = [
                        'type' => 'over_budget',
                        'severity' => 'critical',
                        'message' => "{$site->name}: {$line['label']} is over budget by \$".number_format(abs((float) $line['variance']), 0),
                        'data' => [
                            'site_id' => $site->id,
                            'site_name' => $site->name,
                            'category' => $line['category'],
                            'label' => $line['label'],
                            'planned' => $line['planned'],
                            'actual' => $line['actual'],
                            'variance' => $line['variance'],
                            'variance_pct' => $line['variance_pct'],
                            'period' => $period,
                        ],
                    ];
                } elseif ($usagePct >= $approachingPct) {
                    $insights[] = [
                        'type' => 'approaching_budget',
                        'severity' => 'warning',
                        'message' => "{$site->name}: {$line['label']} is at ".round($usagePct).'% of budget',
                        'data' => [
                            'site_id' => $site->id,
                            'site_name' => $site->name,
                            'category' => $line['category'],
                            'label' => $line['label'],
                            'planned' => $line['planned'],
                            'actual' => $line['actual'],
                            'usage_pct' => round($usagePct, 1),
                            'period' => $period,
                        ],
                    ];
                }
            }
        }

        return $insights;
    }

    /* ------------------------------------------------------------------ */
    /*  Forecast Overrun Insights (NEW — PR7) */
    /* ------------------------------------------------------------------ */

    private function forecastOverrunInsights(array $siteIds): array
    {
        $sites = $this->sites($siteIds)->get();

        $insights = [];

        foreach ($sites as $site) {
            $forecast = $this->forecastService->siteForecast($site->id, forecastMonths: 3);

            if (! $forecast['budget_comparison']) {
                continue;
            }

            foreach ($forecast['budget_comparison'] as $overrun) {
                $insights[] = [
                    'type' => 'forecast_overrun',
                    'severity' => 'warning',
                    'message' => "{$site->name}: {$overrun['label']} forecast to exceed budget by \$"
                        .number_format((float) $overrun['variance'], 0)." in {$overrun['month']}",
                    'data' => [
                        'site_id' => $site->id,
                        'site_name' => $site->name,
                        'month' => $overrun['month'],
                        'category' => $overrun['category'],
                        'label' => $overrun['label'],
                        'planned' => $overrun['planned'],
                        'projected' => $overrun['projected'],
                        'variance' => $overrun['variance'],
                    ],
                ];
            }
        }

        return $insights;
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /* ------------------------------------------------------------------ */
    /*  Staffing Cost Insights (NEW — PR10) */
    /* ------------------------------------------------------------------ */

    private function staffingCostInsights(array $siteIds, Carbon $from, Carbon $to): array
    {
        $thresholds = config('finance.insight_thresholds', []);
        $highOncostPct = (float) ($thresholds['employer_oncost_high_pct'] ?? 12);
        $highStaffingPct = (float) ($thresholds['staffing_pct_of_total_warning'] ?? 75);

        $siteIds = $this->sites($siteIds)
            ->pluck('id')
            ->map(fn ($siteId): int => (int) $siteId)
            ->all();

        if (empty($siteIds)) {
            return [];
        }

        $staffingBySite = $this->staffingCostService->perSiteComparison($siteIds, $from, $to);
        $siteNames = Site::whereIn('id', $siteIds)->pluck('name', 'id');

        $insights = [];

        foreach ($staffingBySite as $siteId => $staffing) {
            $siteName = $siteNames[$siteId] ?? "Site #{$siteId}";

            // Employer on-cost % unusually high
            $oncostPct = (float) $staffing['oncost_pct'];
            if ($oncostPct >= $highOncostPct && bccomp($staffing['wages'], '0', 2) > 0) {
                $insights[] = [
                    'type' => 'high_employer_oncost',
                    'severity' => 'info',
                    'message' => "{$siteName}: employer on-costs are {$oncostPct}% of wages",
                    'data' => [
                        'site_id' => $siteId,
                        'site_name' => $siteName,
                        'wages' => $staffing['wages'],
                        'employer_oncost' => $staffing['employer_oncost'],
                        'oncost_pct' => $oncostPct,
                    ],
                ];
            }

            // Staffing as % of total site cost
            $totalSiteCost = (float) $this->siteCostService->totalCost($siteId, $from, $to);
            if ($totalSiteCost > 0) {
                $staffingPct = ((float) $staffing['total'] / $totalSiteCost) * 100;

                if ($staffingPct >= $highStaffingPct) {
                    $insights[] = [
                        'type' => 'high_staffing_ratio',
                        'severity' => 'warning',
                        'message' => "{$siteName}: staffing is ".round($staffingPct).'% of total cost',
                        'data' => [
                            'site_id' => $siteId,
                            'site_name' => $siteName,
                            'staffing_cost' => $staffing['total'],
                            'total_cost' => number_format($totalSiteCost, 2, '.', ''),
                            'staffing_pct' => round($staffingPct, 1),
                        ],
                    ];
                }
            }
        }

        return $insights;
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function getClientFunding(int $clientId, Carbon $from, Carbon $to): string
    {
        $agreements = ServiceAgreement::where('client_id', $clientId)
            ->where('starts_at', '<=', $to)
            ->where(function ($q) use ($from) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $from);
            })
            ->get();

        $total = '0';
        foreach ($agreements as $agreement) {
            $agrStart = $agreement->starts_at ?? $from;
            $agrEnd = $agreement->ends_at ?? $to;
            $agrDays = max($agrStart->diffInDays($agrEnd) + 1, 1);

            $overlapStart = $agrStart->gt($from) ? $agrStart : $from;
            $overlapEnd = $agrEnd->lt($to) ? $agrEnd : $to;
            $overlapDays = max($overlapStart->diffInDays($overlapEnd) + 1, 0);

            if ($overlapDays > 0 && $agrDays > 0) {
                $fraction = bcdiv((string) $overlapDays, (string) $agrDays, 6);
                $total = bcadd($total, bcmul((string) $agreement->total_budget, $fraction, 2), 2);
            }
        }

        return $total;
    }

    private function sites(array $siteIds): Builder
    {
        return Site::query()
            ->whereIn('id', $siteIds)
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereIn('type', ['house', 'facility']);
    }
}
