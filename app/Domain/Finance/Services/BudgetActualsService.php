<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetLineItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BudgetActualsService
{
    /**
     * Sync actual amounts from posted journal lines into budget line items.
     *
     * @return array{updated: int, total_budget: float, total_actual: float, variance: float}
     */
    public function syncActuals(?int $orgId): array
    {
        $budgets = Budget::approved()
            ->with('lineItems')
            ->get();

        $updated = 0;
        $totalBudget = 0;
        $totalActual = 0;

        foreach ($budgets as $budget) {
            [$startDate, $endDate] = $this->parseFiscalYear($budget->fiscal_year);

            foreach ($budget->lineItems as $lineItem) {
                $account = $this->mapAccountToLineItem($lineItem, $orgId);

                if (! $account) {
                    continue;
                }

                $actual = $this->sumPostedJournalLines($account, $orgId, $startDate, $endDate);

                $lineItem->actual_amount = $actual;
                // The model's saving event calls calculateVariance() automatically
                $lineItem->save();

                $totalBudget += (float) $lineItem->budget_amount;
                $totalActual += (float) $lineItem->actual_amount;
                $updated++;
            }
        }

        $variance = $totalBudget != 0
            ? (($totalActual - $totalBudget) / $totalBudget) * 100
            : 0;

        return [
            'updated' => $updated,
            'total_budget' => round($totalBudget, 2),
            'total_actual' => round($totalActual, 2),
            'variance' => round($variance, 2),
        ];
    }

    /**
     * Get formatted budget-vs-actuals report data for the frontend.
     *
     * @return array{budget: array, categories: array, totals: array}
     */
    public function getBudgetVsActualsReport(?int $orgId, ?int $budgetId = null): array
    {
        $budget = $budgetId
            ? Budget::with('lineItems')->findOrFail($budgetId)
            : Budget::approved()->with('lineItems')->latest('fiscal_year')->first();

        if (! $budget) {
            return [
                'budget' => null,
                'categories' => [],
                'totals' => [
                    'budget_amount' => 0,
                    'actual_amount' => 0,
                    'variance_amount' => 0,
                    'variance_pct' => 0,
                    'utilization_pct' => 0,
                ],
            ];
        }

        $grouped = $budget->lineItems
            ->groupBy('category')
            ->sortKeys();

        $categories = [];
        $grandBudget = 0;
        $grandActual = 0;

        foreach ($grouped as $category => $items) {
            $categoryBudget = 0;
            $categoryActual = 0;
            $lineItems = [];

            foreach ($items as $item) {
                $budgetAmt = (float) $item->budget_amount;
                $actualAmt = (float) $item->actual_amount;
                $varianceAmt = (float) $item->variance_amount;
                $variancePct = (float) $item->variance_pct;

                $lineItems[] = [
                    'id' => $item->id,
                    'description' => $item->description,
                    'subcategory' => $item->subcategory,
                    'account_code' => $item->account_code,
                    'budget_amount' => $budgetAmt,
                    'actual_amount' => $actualAmt,
                    'variance_amount' => $varianceAmt,
                    'variance_pct' => $variancePct,
                    'variance_color' => $this->getVarianceColor($variancePct),
                    'variance_explained' => (bool) $item->variance_explained,
                    'variance_explanation' => $item->variance_explanation,
                ];

                $categoryBudget += $budgetAmt;
                $categoryActual += $actualAmt;
            }

            $categoryVariance = $categoryActual - $categoryBudget;
            $categoryVariancePct = $categoryBudget != 0
                ? ($categoryVariance / $categoryBudget) * 100
                : 0;

            $categories[] = [
                'name' => $category,
                'line_items' => $lineItems,
                'subtotals' => [
                    'budget_amount' => round($categoryBudget, 2),
                    'actual_amount' => round($categoryActual, 2),
                    'variance_amount' => round($categoryVariance, 2),
                    'variance_pct' => round($categoryVariancePct, 2),
                    'variance_color' => $this->getVarianceColor($categoryVariancePct),
                    'utilization_pct' => $categoryBudget != 0
                        ? round(($categoryActual / $categoryBudget) * 100, 1)
                        : 0,
                ],
            ];

            $grandBudget += $categoryBudget;
            $grandActual += $categoryActual;
        }

        $grandVariance = $grandActual - $grandBudget;
        $grandVariancePct = $grandBudget != 0
            ? ($grandVariance / $grandBudget) * 100
            : 0;

        return [
            'budget' => [
                'id' => $budget->id,
                'fiscal_year' => $budget->fiscal_year,
                'title' => $budget->title,
                'status' => $budget->status,
                'currency' => $budget->currency ?? 'NZD',
                'approved_at' => $budget->approved_by_board_at?->toIso8601String(),
            ],
            'categories' => $categories,
            'totals' => [
                'budget_amount' => round($grandBudget, 2),
                'actual_amount' => round($grandActual, 2),
                'variance_amount' => round($grandVariance, 2),
                'variance_pct' => round($grandVariancePct, 2),
                'utilization_pct' => $grandBudget != 0
                    ? round(($grandActual / $grandBudget) * 100, 1)
                    : 0,
            ],
        ];
    }

    /**
     * Resolve the GL account for a budget line item.
     * Uses gl_account_id first, then falls back to account_code lookup.
     */
    public function mapAccountToLineItem(BudgetLineItem $lineItem, ?int $orgId = null): ?FinAccount
    {
        // Priority 1: direct gl_account_id foreign key
        if ($lineItem->gl_account_id) {
            return FinAccount::find($lineItem->gl_account_id);
        }

        // Priority 2: match by account_code
        if ($lineItem->account_code) {
            $query = FinAccount::where('code', $lineItem->account_code);

            if ($orgId) {
                $query->where('organization_id', $orgId);
            }

            return $query->first();
        }

        return null;
    }

    /**
     * Sum posted journal lines for a given account within a date range.
     * For expense accounts: actual = debits - credits
     * For revenue accounts: actual = credits - debits
     */
    private function sumPostedJournalLines(FinAccount $account, ?int $orgId, Carbon $startDate, Carbon $endDate): float
    {
        $totals = FinJournalLine::where('account_id', $account->id)
            ->whereHas('journal', function ($q) use ($orgId, $startDate, $endDate) {
                $q->where('organization_id', $orgId)
                    ->where('status', 'posted')
                    ->whereBetween('journal_date', [$startDate, $endDate]);
            })
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debits, COALESCE(SUM(credit), 0) as total_credits')
            ->first();

        $debits = (float) $totals->total_debits;
        $credits = (float) $totals->total_credits;

        // Expense/asset accounts: normal debit balance
        if (in_array($account->type, ['expense', 'asset'])) {
            return $debits - $credits;
        }

        // Revenue/liability/equity accounts: normal credit balance
        return $credits - $debits;
    }

    /**
     * Parse a fiscal year string like "2025-2026" into start/end dates.
     * NZ fiscal year: 1 April to 31 March.
     */
    private function parseFiscalYear(string $fiscalYear): array
    {
        $parts = explode('-', $fiscalYear);
        $startYear = (int) $parts[0];
        $endYear = count($parts) > 1 ? (int) $parts[1] : $startYear + 1;

        return [
            Carbon::create($startYear, 4, 1)->startOfDay(),
            Carbon::create($endYear, 3, 31)->endOfDay(),
        ];
    }

    /**
     * Determine variance colour based on absolute percentage.
     */
    private function getVarianceColor(float $variancePct): string
    {
        $abs = abs($variancePct);

        return match (true) {
            $abs >= 10 => 'red',
            $abs >= 5 => 'yellow',
            default => 'green',
        };
    }
}
