<?php

namespace App\Services\Catering;

use App\Models\MealRecipe;

class MealCostCalculator
{
    public function __construct(private UnitConverter $units) {}

    /**
     * Returns total recipe cost in cents for a given target servings count.
     * Scales linearly off the recipe's serves_default.
     */
    public function forRecipe(MealRecipe $recipe, int $targetServings): int
    {
        $recipe->loadMissing('ingredients.product');

        $servesBase = max(1, $recipe->serves_default);
        $scale = $targetServings / $servesBase;

        $totalCents = 0;
        foreach ($recipe->ingredients as $ingredient) {
            $product = $ingredient->product;
            if (!$product || $product->cost_per_unit_cents === null) {
                continue;
            }

            $scaledQty = (float) $ingredient->quantity * $scale;

            $qtyInProductUnit = $this->units->convert(
                $scaledQty,
                $ingredient->unit,
                $product->default_unit,
                $product->pack_size !== null ? (float) $product->pack_size : null,
                $product->pack_unit,
            );

            if ($qtyInProductUnit === null) {
                continue;
            }

            $totalCents += (int) round($qtyInProductUnit * $product->cost_per_unit_cents);
        }

        return $totalCents;
    }
}
