<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Services\BudgetActualsService;
use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetLineItem;
use App\Models\User;

/**
 * Budget-vs-actuals read a denormalised actual_amount column that only refreshed
 * on a manual syncActuals() run — so the report was stale between syncs. It now
 * computes actuals LIVE from posted journal lines (the real GL).
 */
it('reports actuals live from the GL, not the stale denormalised column', function () {
    $user = User::factory()->create(['organization_id' => 1]);
    $budget = Budget::create([
        'fiscal_year' => '2026-2027', 'title' => 'FY27', 'status' => 'approved', 'total_budget' => '1000.00',
        'created_by' => $user->id,
    ]);
    // The stored actual_amount is deliberately stale (0) — the live GL has $750.
    BudgetLineItem::create([
        'budget_id' => $budget->id, 'category' => 'Operations', 'description' => 'Supplies',
        'account_code' => '6000', 'budget_amount' => '1000.00', 'actual_amount' => '0.00',
    ]);

    $account = FinAccount::factory()->create([
        'organization_id' => 1, 'code' => '6000', 'name' => 'Supplies', 'type' => 'expense', 'is_active' => true,
    ]);
    // $750 of posted spend in FY2026-2027 (1 Apr 2026 – 31 Mar 2027).
    $journal = FinJournal::factory()->create([
        'organization_id' => 1, 'status' => 'posted', 'journal_date' => '2026-06-15',
    ]);
    FinJournalLine::create([
        'journal_id' => $journal->id, 'account_id' => $account->id,
        'debit' => '750.00', 'credit' => 0, 'description' => 'Supplies spend',
    ]);

    $report = app(BudgetActualsService::class)->getBudgetVsActualsReport(1, $budget->id);

    $line = collect($report['categories'])->firstWhere('name', 'Operations')['line_items'][0];

    expect((float) $line['budget_amount'])->toBe(1000.0)
        ->and((float) $line['actual_amount'])->toBe(750.0)        // live GL, not the stale 0
        ->and((float) $line['variance_amount'])->toBe(-250.0)
        ->and((float) $report['totals']['actual_amount'])->toBe(750.0);
});
