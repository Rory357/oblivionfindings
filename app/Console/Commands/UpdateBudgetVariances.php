<?php

namespace App\Console\Commands;

use App\Domain\Finance\Services\BudgetActualsService;
use App\Domain\Governance\Models\Budget;
use Illuminate\Console\Command;

class UpdateBudgetVariances extends Command
{
    protected $signature = 'governance:update-budget-variances';

    protected $description = 'Update budget variance calculations from actuals';

    public function handle(BudgetActualsService $budgetActualsService): int
    {
        $this->info('Updating budget variances...');

        $budgetActualsService->syncActuals(null);

        $budgets = Budget::approved()->get();

        foreach ($budgets as $budget) {
            foreach ($budget->lineItems as $item) {
                $item->calculateVariance();
                $item->save();
            }

            $this->info("Updated variances for budget: {$budget->fiscal_year}");
        }

        $this->info('Budget variance updates complete.');

        return self::SUCCESS;
    }
}
