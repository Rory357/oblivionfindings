<?php

namespace App\Services\Catering;

use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\MealRecipeIngredient;
use App\Models\Site;
use App\Models\SiteMealInventoryItem;
use App\Models\SiteMealInventoryMovement;
use App\Models\SiteMealPlanEntry;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MealServiceCommand
{
    public const AUDIT_SERVED = 'catering.meal_service.served';

    public const AUDIT_UNSERVED = 'catering.meal_service.unserved';

    public function __construct(
        private readonly InventoryMovementRecorder $inventory,
        private readonly UnitConverter $units,
        private readonly DietaryConflictChecker $conflictChecker,
        private readonly SiteMealPlanAggregate $aggregate,
    ) {}

    public function serve(int $siteId, int $entryId, int $actorId): MealServiceResult
    {
        return DB::transaction(function () use ($siteId, $entryId, $actorId): MealServiceResult {
            [$site, $entry, $actor] = $this->lockSiteAndEntry($siteId, $entryId, $actorId);

            if ($entry->served_at !== null) {
                return new MealServiceResult('already_served');
            }

            $resolved = $this->aggregate->resolve(
                $site,
                $entry->recipe_id ? (int) $entry->recipe_id : null,
                $entry->client_ids ?? [],
                $entry->plan_date,
                $actor,
                true,
            );
            [$recipe, $ingredients, $products] = $this->lockRecipeOccurrence($entry, $resolved['recipe']);
            $resolved['recipe'] = $recipe;
            $resolved['report'] = $this->conflictChecker->checkMealAgainstResolvedClients(
                $recipe,
                $resolved['residents'],
                $entry->plan_date,
            );
            $this->aggregate->assertClinicallySafe($resolved);

            $items = $this->lockInventoryItems($site, $products);
            $sequence = (int) $entry->meal_service_sequence + 1;
            $serviceKey = $this->serviceKey($entry, $sequence);
            $movements = $this->recordServeMovements(
                $site,
                $entry,
                $recipe,
                $ingredients,
                $items,
                $serviceKey,
                $actorId,
            );

            $entry->forceFill([
                'served_at' => now(),
                'served_by' => $actorId,
                'meal_service_sequence' => $sequence,
                'meal_service_movement_count' => $movements->count(),
                'version' => (int) $entry->version + 1,
            ])->save();

            AuditLogger::logOrFail(self::AUDIT_SERVED, $entry, [
                'actor_id' => $actorId,
                'site_id' => $site->id,
                'meal_service_key' => $serviceKey,
                'recipe_id' => $recipe?->id,
                'movement_ids' => $movements->modelKeys(),
            ]);

            return new MealServiceResult('served', $movements->count());
        }, 3);
    }

    public function unserve(int $siteId, int $entryId, int $actorId): MealServiceResult
    {
        return DB::transaction(function () use ($siteId, $entryId, $actorId): MealServiceResult {
            [$site, $entry, $actor] = $this->lockSiteAndEntry($siteId, $entryId, $actorId);

            if ($entry->served_at === null) {
                return new MealServiceResult('not_served');
            }

            $this->aggregate->resolve(
                $site,
                $entry->recipe_id ? (int) $entry->recipe_id : null,
                $entry->client_ids ?? [],
                $entry->plan_date,
                $actor,
                true,
            );

            $sequence = (int) $entry->meal_service_sequence;
            if ($sequence < 1) {
                return $this->unserveLegacyStateOnlyEntry($site, $entry, $actorId);
            }

            $serviceKey = $this->serviceKey($entry, $sequence);
            $serveMovementSnapshot = SiteMealInventoryMovement::query()
                ->where('site_id', $site->id)
                ->where('reference_type', SiteMealPlanEntry::class)
                ->where('reference_id', $entry->id)
                ->where('meal_service_key', $serviceKey)
                ->where('meal_service_action', SiteMealInventoryMovement::MEAL_SERVICE_ACTION_SERVE)
                ->orderBy('product_id')
                ->orderBy('id')
                ->get();

            if ($serveMovementSnapshot->count() !== (int) $entry->meal_service_movement_count) {
                throw UnsafeMealServiceReversal::because('the correlated serve journal is incomplete.');
            }

            $items = $this->lockReversalOccurrence($site, $entry, $serveMovementSnapshot);
            $serveMovements = SiteMealInventoryMovement::query()
                ->whereIn('id', $serveMovementSnapshot->modelKeys())
                ->orderBy('product_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($serveMovements->modelKeys() !== $serveMovementSnapshot->modelKeys()) {
                throw UnsafeMealServiceReversal::because('the correlated serve journal changed while reversing.');
            }
            $reversals = new Collection;

            foreach ($serveMovements as $serveMovement) {
                $item = $items->get((int) $serveMovement->product_id);
                if (! $item instanceof SiteMealInventoryItem) {
                    throw UnsafeMealServiceReversal::because('a correlated inventory item is unavailable.');
                }

                $reversals->push($this->inventory->recordAgainstLockedItem(
                    site: $site,
                    item: $item,
                    delta: -((float) $serveMovement->delta),
                    unit: $serveMovement->unit,
                    reason: 'plan_consumption',
                    referenceType: SiteMealPlanEntry::class,
                    referenceId: $entry->id,
                    performedBy: $actorId,
                    note: 'Un-served: '.$entry->displayName(),
                    mealServiceKey: $serviceKey,
                    mealServiceAction: SiteMealInventoryMovement::MEAL_SERVICE_ACTION_UNSERVE,
                    mealRecipeId: $serveMovement->meal_recipe_id,
                    mealRecipeIngredientIds: $serveMovement->meal_recipe_ingredient_ids ?? [],
                    reversalOfId: $serveMovement->id,
                ));
            }

            $entry->forceFill([
                'served_at' => null,
                'served_by' => null,
                'version' => (int) $entry->version + 1,
            ])->save();

            AuditLogger::logOrFail(self::AUDIT_UNSERVED, $entry, [
                'actor_id' => $actorId,
                'site_id' => $site->id,
                'meal_service_key' => $serviceKey,
                'serve_movement_ids' => $serveMovements->modelKeys(),
                'reversal_movement_ids' => $reversals->modelKeys(),
            ]);

            return new MealServiceResult('unserved', $reversals->count());
        }, 3);
    }

    /** @return array{Site, SiteMealPlanEntry, User} */
    private function lockSiteAndEntry(int $siteId, int $entryId, int $actorId): array
    {
        $site = Site::query()
            ->whereKey($siteId)
            ->lockForUpdate()
            ->firstOrFail();

        $actor = User::query()->whereKey($actorId)->firstOrFail();
        Gate::forUser($actor)->authorize('view', $site);
        abort_unless($actor->canDo('sites.meals.plan'), 403);

        $entry = SiteMealPlanEntry::query()
            ->whereKey($entryId)
            ->where('site_id', $site->id)
            ->lockForUpdate()
            ->firstOrFail();

        return [$site, $entry, $actor];
    }

    /**
     * @return array{MealRecipe|null, Collection<int, MealRecipeIngredient>, Collection<int, MealProduct>}
     */
    private function lockRecipeOccurrence(
        SiteMealPlanEntry $entry,
        ?MealRecipe $resolvedRecipe,
    ): array
    {
        if ($entry->recipe_id === null) {
            return [null, new Collection, new Collection];
        }

        if (! $resolvedRecipe instanceof MealRecipe || (int) $resolvedRecipe->id !== (int) $entry->recipe_id) {
            throw (new ModelNotFoundException)->setModel(MealRecipe::class, [$entry->recipe_id]);
        }
        $recipe = $resolvedRecipe;

        $ingredients = MealRecipeIngredient::query()
            ->where('recipe_id', $recipe->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $productIds = $ingredients->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $products = MealProduct::withTrashed()
            ->with('tags')
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            throw (new ModelNotFoundException)->setModel(MealProduct::class, $productIds->all());
        }

        foreach ($ingredients as $ingredient) {
            if ($ingredient->product_id !== null) {
                $ingredient->setRelation('product', $products->get((int) $ingredient->product_id));
            }
        }
        $entry->setRelation('recipe', $recipe);
        $recipe->setRelation('ingredients', $ingredients);
        $recipe->loadMissing('tags');

        return [$recipe, $ingredients, $products];
    }

    /**
     * @param  Collection<int, MealProduct>  $products
     * @return Collection<int, SiteMealInventoryItem>
     */
    private function lockInventoryItems(Site $site, Collection $products): Collection
    {
        $productIds = $products->modelKeys();
        $existingItems = SiteMealInventoryItem::withTrashed()
            ->where('site_id', $site->id)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');

        if ($existingItems->contains(fn (SiteMealInventoryItem $item) => $item->trashed())) {
            throw UnsafeMealServiceReversal::because('an ingredient inventory item is archived.');
        }

        foreach ($products as $product) {
            if ($existingItems->has((int) $product->id)) {
                continue;
            }
            $item = SiteMealInventoryItem::withTrashed()->firstOrCreate(
                ['site_id' => $site->id, 'product_id' => $product->id],
                ['unit' => $product->default_unit, 'current_qty' => 0],
            );
            if ($item->trashed()) {
                throw UnsafeMealServiceReversal::because('an ingredient inventory item is archived.');
            }
        }

        $items = SiteMealInventoryItem::query()
            ->where('site_id', $site->id)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');

        if ($items->count() !== count($productIds)) {
            throw UnsafeMealServiceReversal::because('an ingredient inventory item is unavailable.');
        }

        return $items;
    }

    /**
     * @param  Collection<int, MealRecipeIngredient>  $ingredients
     * @param  Collection<int, SiteMealInventoryItem>  $items
     * @return Collection<int, SiteMealInventoryMovement>
     */
    private function recordServeMovements(
        Site $site,
        SiteMealPlanEntry $entry,
        ?MealRecipe $recipe,
        Collection $ingredients,
        Collection $items,
        string $serviceKey,
        int $actorId,
    ): Collection {
        if ($entry->source_type !== 'recipe' || $recipe === null) {
            return new Collection;
        }

        $baseServings = max(1, (int) $recipe->serves_default);
        $scale = ((int) ($entry->servings ?: $baseServings)) / $baseServings;
        $movements = new Collection;

        foreach ($ingredients->filter(fn (MealRecipeIngredient $ingredient) => $ingredient->product_id !== null)
            ->groupBy('product_id')->sortKeys() as $productId => $productIngredients) {
            $item = $items->get((int) $productId);
            if (! $item instanceof SiteMealInventoryItem) {
                throw UnsafeMealServiceReversal::because('an ingredient inventory item is unavailable.');
            }

            $delta = 0.0;
            $conversionWarnings = [];
            foreach ($productIngredients as $ingredient) {
                $ingredientDelta = -((float) $ingredient->quantity) * $scale;
                $converted = $this->units->convert($ingredientDelta, $ingredient->unit, $item->unit);
                if ($converted === null) {
                    $converted = $ingredientDelta;
                    $conversionWarnings[] = "{$ingredient->unit} → {$item->unit}";
                }
                $delta += $converted;
            }

            $delta = round($delta, 4);
            if ($delta === 0.0) {
                continue;
            }

            $note = 'Served: '.$entry->displayName();
            if ($conversionWarnings !== []) {
                $note .= ' [unit conversion failed: '.implode(', ', array_unique($conversionWarnings)).']';
            }

            $movements->push($this->inventory->recordAgainstLockedItem(
                site: $site,
                item: $item,
                delta: $delta,
                unit: $item->unit,
                reason: 'plan_consumption',
                referenceType: SiteMealPlanEntry::class,
                referenceId: $entry->id,
                performedBy: $actorId,
                note: $note,
                mealServiceKey: $serviceKey,
                mealServiceAction: SiteMealInventoryMovement::MEAL_SERVICE_ACTION_SERVE,
                mealRecipeId: $recipe->id,
                mealRecipeIngredientIds: $productIngredients->modelKeys(),
            ));
        }

        return $movements;
    }

    /**
     * @param  Collection<int, SiteMealInventoryMovement>  $serveMovements
     * @return Collection<int, SiteMealInventoryItem>
     */
    private function lockReversalOccurrence(
        Site $site,
        SiteMealPlanEntry $entry,
        Collection $serveMovements,
    ): Collection {
        if ($serveMovements->isEmpty()) {
            return new Collection;
        }

        $recipeIds = $serveMovements->pluck('meal_recipe_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($recipeIds->count() !== 1) {
            throw UnsafeMealServiceReversal::because('the serve recipe occurrence is inconsistent.');
        }

        $recipe = MealRecipe::withTrashed()
            ->whereKey($recipeIds->sole())
            ->lockForUpdate()
            ->first();
        if (! $recipe instanceof MealRecipe) {
            throw UnsafeMealServiceReversal::because('the served recipe occurrence is unavailable.');
        }
        $entry->setRelation('recipe', $recipe);

        $ingredientIds = $serveMovements
            ->flatMap(fn (SiteMealInventoryMovement $movement) => $movement->meal_recipe_ingredient_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();
        $lockedIngredients = MealRecipeIngredient::query()
            ->where('recipe_id', $recipe->id)
            ->whereIn('id', $ingredientIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'product_id'])
            ->keyBy('id');
        if ($lockedIngredients->count() !== $ingredientIds->count()) {
            throw UnsafeMealServiceReversal::because('the served ingredient occurrence has changed.');
        }
        foreach ($serveMovements as $serveMovement) {
            foreach ($serveMovement->meal_recipe_ingredient_ids ?? [] as $ingredientId) {
                if ((int) $lockedIngredients->get((int) $ingredientId)?->product_id !== (int) $serveMovement->product_id) {
                    throw UnsafeMealServiceReversal::because('the served ingredient product occurrence has changed.');
                }
            }
        }

        $productIds = $serveMovements->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $lockedProductCount = MealProduct::withTrashed()
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id'])
            ->count();
        if ($lockedProductCount !== $productIds->count()) {
            throw UnsafeMealServiceReversal::because('a served product occurrence is unavailable.');
        }

        $items = SiteMealInventoryItem::query()
            ->where('site_id', $site->id)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');
        if ($items->count() !== $productIds->count()) {
            throw UnsafeMealServiceReversal::because('a correlated inventory item is unavailable.');
        }

        foreach ($serveMovements as $serveMovement) {
            $item = $items->get((int) $serveMovement->product_id);
            if (! $item instanceof SiteMealInventoryItem || $item->unit !== $serveMovement->unit) {
                throw UnsafeMealServiceReversal::because('a correlated inventory unit has changed.');
            }
            if (SiteMealInventoryMovement::query()->where('reversal_of_id', $serveMovement->id)->exists()) {
                throw UnsafeMealServiceReversal::because('a serve movement already has a reversal.');
            }
            if (SiteMealInventoryMovement::query()
                ->where('site_id', $site->id)
                ->where('product_id', $serveMovement->product_id)
                ->where('id', '>', $serveMovement->id)
                ->where('reason', 'stocktake')
                ->exists()) {
                throw UnsafeMealServiceReversal::because('a later stocktake depends on the served quantity.');
            }
        }

        return $items;
    }

    private function unserveLegacyStateOnlyEntry(
        Site $site,
        SiteMealPlanEntry $entry,
        int $actorId,
    ): MealServiceResult {
        $hasUnlinkedStock = SiteMealInventoryMovement::query()
            ->where('site_id', $site->id)
            ->where('reference_type', SiteMealPlanEntry::class)
            ->where('reference_id', $entry->id)
            ->where('reason', 'plan_consumption')
            ->lockForUpdate()
            ->exists();
        if ($hasUnlinkedStock) {
            throw UnsafeMealServiceReversal::because('its existing stock movements are not exactly linked.');
        }

        $entry->forceFill([
            'served_at' => null,
            'served_by' => null,
            'version' => (int) $entry->version + 1,
        ])->save();
        AuditLogger::logOrFail(self::AUDIT_UNSERVED, $entry, [
            'actor_id' => $actorId,
            'site_id' => $site->id,
            'meal_service_key' => null,
            'serve_movement_ids' => [],
            'reversal_movement_ids' => [],
        ]);

        return new MealServiceResult('unserved');
    }

    private function serviceKey(SiteMealPlanEntry $entry, int $sequence): string
    {
        return "meal-plan:{$entry->id}:service:{$sequence}";
    }
}
