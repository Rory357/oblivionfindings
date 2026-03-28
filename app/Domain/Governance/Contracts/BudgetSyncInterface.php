<?php

namespace App\Domain\Governance\Contracts;

interface BudgetSyncInterface
{
    /**
     * Get the approved budget envelope for a given fiscal period.
     */
    public function getBudgetEnvelope(int $fiscalPeriodId): array;

    /**
     * Get actuals spent against a budget category.
     */
    public function getActualsForCategory(int $budgetId, string $category): float;

    /**
     * Sync budget actuals from finance ledger to governance tracking.
     */
    public function syncBudgetActuals(int $budgetId): void;
}
