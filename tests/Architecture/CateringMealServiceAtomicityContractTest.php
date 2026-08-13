<?php

test('meal service remains one transactional domain owner with immutable exact reversal identity', function (): void {
    $root = dirname(__DIR__, 2);
    $controller = file_get_contents($root.'/app/Http/Controllers/Sites/SiteMealPlanController.php');
    $command = file_get_contents($root.'/app/Services/Catering/MealServiceCommand.php');
    $movement = file_get_contents($root.'/app/Models/SiteMealInventoryMovement.php');
    $movementBuilder = file_get_contents($root.'/app/Services/Catering/ImmutableInventoryMovementBuilder.php');
    $migration = file_get_contents($root.'/database/migrations/2026_08_14_000052_add_meal_service_occurrence_integrity.php');

    expect($controller)
        ->toContain('$this->mealService->serve(', '$this->mealService->unserve(')
        ->not->toContain('applyServeStock(', 'InventoryMovementRecorder $inventory')
        ->and($command)
        ->toContain(
            'return DB::transaction(',
            'private function lockSiteAndEntry(',
            '->lockForUpdate()',
            'SiteMealPlanAggregate $aggregate',
            '$this->aggregate->resolve(',
            '\'version\' => (int) $entry->version + 1',
            'recordAgainstLockedItem(',
            "where('reversal_of_id'",
            "where('reason', 'stocktake')",
            'AuditLogger::logOrFail(',
        )
        ->and($movement)
        ->toContain(
            "static::updating(fn () => throw new LogicException('Inventory movements are immutable.'))",
            "static::deleting(fn () => throw new LogicException('Inventory movements are immutable.'))",
            'newEloquentBuilder(',
            'ImmutableInventoryMovementBuilder',
        )
        ->and($movementBuilder)
        ->toContain('public function update(', 'public function delete(): mixed')
        ->and($migration)
        ->toContain(
            "['meal_service_key', 'product_id', 'meal_service_action']",
            "->unique('reversal_of_id', 'smim_reversal_unique')",
            "->foreign('reversal_of_id', 'smim_reversal_fk')",
            "->foreign('site_id', 'site_meal_inventory_movements_site_id_foreign')",
            "->foreign('product_id', 'site_meal_inventory_movements_product_id_foreign')",
            '->restrictOnDelete()',
        );
});
