<?php

namespace App\Services\Catering;

use App\Models\Site;
use App\Models\SiteMealInventoryItem;
use App\Models\SiteMealInventoryMovement;
use App\Models\SiteMealPlanEntry;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class InventoryMovementRecorder
{
    public function __construct(private UnitConverter $units) {}

    /**
     * Single write-path for *all* inventory changes. Appends a movement
     * row + updates the materialised current_qty inside one transaction.
     *
     * $delta is signed and in $unit. Positive = added to stock,
     * negative = removed from stock.
     *
     * Returns the persisted movement.
     */
    public function record(
        Site $site,
        int $productId,
        float $delta,
        string $unit,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $performedBy = null,
        ?string $note = null,
    ): SiteMealInventoryMovement {
        return DB::transaction(function () use ($site, $productId, $delta, $unit, $reason, $referenceType, $referenceId, $performedBy, $note) {
            $item = SiteMealInventoryItem::withTrashed()->firstOrCreate(
                ['site_id' => $site->id, 'product_id' => $productId],
                [
                    'unit' => $unit,
                    'current_qty' => 0,
                ]
            );

            if ($item->trashed()) {
                throw new RuntimeException('The inventory item is archived.');
            }

            $item = SiteMealInventoryItem::query()
                ->whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->recordAgainstLockedItem(
                site: $site,
                item: $item,
                delta: $delta,
                unit: $unit,
                reason: $reason,
                referenceType: $referenceType,
                referenceId: $referenceId,
                performedBy: $performedBy,
                note: $note,
            );
        }, 3);
    }

    /**
     * Append a movement and advance an inventory row already locked by the
     * caller. Meal service uses this to keep every product mutation inside its
     * one enclosing transaction and deterministic multi-row lock order.
     *
     * @param  array<int, int>  $mealRecipeIngredientIds
     */
    public function recordAgainstLockedItem(
        Site $site,
        SiteMealInventoryItem $item,
        float $delta,
        string $unit,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $performedBy = null,
        ?string $note = null,
        ?string $mealServiceKey = null,
        ?string $mealServiceAction = null,
        ?int $mealRecipeId = null,
        array $mealRecipeIngredientIds = [],
        ?int $reversalOfId = null,
    ): SiteMealInventoryMovement {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A locked inventory movement requires an enclosing transaction.');
        }
        if ((int) $item->site_id !== (int) $site->id || ! $item->exists || $item->trashed()) {
            throw new LogicException('The locked inventory item does not belong to the supplied Site.');
        }
        if (($mealServiceKey === null) !== ($mealServiceAction === null)) {
            throw new LogicException('Meal service movement identity is incomplete.');
        }
        if ($mealServiceKey !== null) {
            if (! in_array($mealServiceAction, [
                SiteMealInventoryMovement::MEAL_SERVICE_ACTION_SERVE,
                SiteMealInventoryMovement::MEAL_SERVICE_ACTION_UNSERVE,
            ], true)) {
                throw new LogicException('Meal service movement action is invalid.');
            }
            if ($referenceType !== SiteMealPlanEntry::class || $referenceId === null || $mealRecipeId === null || $mealRecipeIngredientIds === []) {
                throw new LogicException('Meal service movement provenance is incomplete.');
            }
        } elseif ($mealRecipeId !== null || $mealRecipeIngredientIds !== [] || $reversalOfId !== null) {
            throw new LogicException('Meal service provenance requires an occurrence identity.');
        }
        if ($reversalOfId !== null && $mealServiceAction !== SiteMealInventoryMovement::MEAL_SERVICE_ACTION_UNSERVE) {
            throw new LogicException('Only an un-serve movement can reverse a serve movement.');
        }
        if ($mealServiceAction === SiteMealInventoryMovement::MEAL_SERVICE_ACTION_UNSERVE && $reversalOfId === null) {
            throw new LogicException('An un-serve movement must identify the exact serve movement it reverses.');
        }

        $deltaInItemUnit = $this->units->convert($delta, $unit, $item->unit);
        if ($deltaInItemUnit === null) {
            // Preserve the established fallback while making it visible in the journal.
            $deltaInItemUnit = $delta;
            $note = trim(($note ? $note.' ' : '')."[unit conversion failed: {$unit} → {$item->unit}]");
        }

        $movement = SiteMealInventoryMovement::query()->create([
            'site_id' => $site->id,
            'product_id' => $item->product_id,
            'delta' => $deltaInItemUnit,
            'unit' => $item->unit,
            'reason' => $reason,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'meal_service_key' => $mealServiceKey,
            'meal_service_action' => $mealServiceAction,
            'meal_recipe_id' => $mealRecipeId,
            'meal_recipe_ingredient_ids' => $mealRecipeIngredientIds ?: null,
            'reversal_of_id' => $reversalOfId,
            'note' => $note,
            'performed_by' => $performedBy ?? auth()->id(),
            'performed_at' => now(),
        ]);

        $item->current_qty = (float) $item->current_qty + $deltaInItemUnit;
        if ($reason === 'stocktake') {
            $item->last_counted_at = now();
        }
        $item->save();

        return $movement;
    }

    /**
     * Sets the absolute on-hand quantity (stocktake mode). Writes a
     * single movement representing the delta.
     */
    public function stocktake(
        Site $site,
        int $productId,
        float $newQty,
        string $unit,
        ?int $performedBy = null,
        ?string $note = null,
    ): SiteMealInventoryMovement {
        return DB::transaction(function () use ($site, $productId, $newQty, $unit, $performedBy, $note) {
            $item = SiteMealInventoryItem::withTrashed()->firstOrCreate(
                ['site_id' => $site->id, 'product_id' => $productId],
                [
                    'unit' => $unit,
                    'current_qty' => 0,
                ]
            );

            if ($item->trashed()) {
                throw new RuntimeException('The inventory item is archived.');
            }

            $item = SiteMealInventoryItem::query()
                ->whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();

            $targetInItemUnit = $this->units->convert($newQty, $unit, $item->unit);
            if ($targetInItemUnit === null) {
                $targetInItemUnit = $newQty;
            }
            $delta = $targetInItemUnit - (float) $item->current_qty;

            return $this->recordAgainstLockedItem(
                site: $site,
                item: $item,
                delta: $delta,
                unit: $item->unit,
                reason: 'stocktake',
                performedBy: $performedBy,
                note: $note,
            );
        }, 3);
    }
}
