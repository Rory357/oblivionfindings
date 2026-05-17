<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientMealDislike;
use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\MealRecipeIngredient;
use App\Models\Site;
use App\Models\SiteMealPlanEntry;
use App\Models\SiteMealShoppingListItem;
use App\Models\User;
use App\Services\Catering\InventoryMovementRecorder;
use App\Services\Catering\ShoppingListGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo data for the Meal Planner. Idempotent — safe to run multiple times.
 *
 * Builds on CateringSeeder (which seeds the tags + 39 products + 5 inactive
 * recipes). Adds:
 *
 *  - Realistic per-unit costs on every seeded product
 *  - Activates the 5 starter recipes + adds ~10 more across all meal slots
 *  - Tags 4 residents with allergens so PlanEntry warnings actually fire
 *  - For each house: seeds 25–30 inventory items with par/reorder levels
 *    (mixing in-stock, low, and out-of-stock states), then writes 2 weeks
 *    of plan entries across all meal slots
 *  - Generates one draft shopping list per house
 */
class CateringDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Catering library guarantees products/recipes/tags exist.
        if (MealProduct::count() === 0) {
            $this->call(CateringSeeder::class);
        }

        $this->backfillProductCosts();
        $this->activateAndExtendRecipes();
        $this->tagResidentsWithAllergens();
        $this->seedResidentDislikes();

        // Target Kauri House + Harbour Respite — these are the two
        // "real" demo houses (the others are E2E fixtures or empty
        // facilities). Pick by name so IDs from migrate:fresh don't
        // matter.
        $houses = Site::query()
            ->whereIn('name', ['Kauri House', 'Harbour Respite'])
            ->get();

        foreach ($houses as $site) {
            $this->seedInventoryForSite($site);
            $this->seedPlanEntriesForSite($site);
            $this->seedGuaranteedConflictMeals($site);
            $this->seedOverriddenMeal($site);
            $this->seedTakeawayMeal($site);
            $this->generateShoppingListForSite($site);
        }

        $this->command?->info('Catering demo data seeded for ' . $houses->count() . ' site(s).');
    }

    private function backfillProductCosts(): void
    {
        $costs = [
            'Milk (2L)' => 510,
            'Butter (500g)' => 850,
            'Cheese — Tasty Block (500g)' => 1290,
            'Yoghurt — Natural (1kg)' => 690,
            'Bread — White Sandwich Loaf' => 320,
            'Bread — Wholemeal Loaf' => 380,
            'Wraps — Plain (8 pack)' => 450,
            'Eggs (dozen)' => 950,
            'Chicken Breast (1kg)' => 1690,
            'Beef Mince (500g)' => 1290,
            'Tinned Tuna (185g)' => 280,
            'Rice — Long Grain (1kg)' => 360,
            'Pasta — Penne (500g)' => 220,
            'Flour — Plain (1.5kg)' => 290,
            'Sugar — White (1kg)' => 250,
            'Salt — Iodised (500g)' => 180,
            'Olive Oil (750ml)' => 1490,
            'Tomato Pasta Sauce (500g)' => 240,
            'Baked Beans (420g)' => 220,
            'Coffee — Ground (250g)' => 1290,
            'Tea Bags — Black (100 pack)' => 540,
            'Tea Bags — Herbal (40 pack)' => 690,
            'Long Life Milk (1L)' => 320,
            'Juice — Orange (2L)' => 480,
            'Bananas' => 450, // per kg
            'Apples' => 490,
            'Tomatoes' => 690,
            'Onions — Brown' => 290,
            'Potatoes — Washed' => 320,
            'Carrots' => 280,
            'Lettuce' => 350,
            'Cucumber' => 220,
            'Lemons' => 110, // per each
            'Paper Towels (4 pack)' => 590,
            'Dishwashing Liquid (900ml)' => 480,
            'Cling Film (30m)' => 320,
            'Tin Foil (30m)' => 420,
            'Rubbish Bags — Kitchen' => 580,
            'Tea Towels' => 350,
        ];

        foreach ($costs as $name => $cents) {
            MealProduct::where('name', $name)->whereNull('cost_per_unit_cents')->update([
                'cost_per_unit_cents' => $cents,
            ]);
        }
    }

    private function activateAndExtendRecipes(): void
    {
        MealRecipe::query()->update(['is_active' => true]);

        $tags = MealDietaryTag::query()->get()->keyBy('key');
        $products = MealProduct::query()->get()->keyBy('name');

        $more = [
            [
                'name' => 'Porridge with Banana',
                'description' => 'Warm oats with sliced banana — classic breakfast.',
                'serves_default' => 4, 'prep_minutes' => 5, 'cook_minutes' => 10,
                'instructions' => "1. Heat 1 cup oats with 3 cups milk.\n2. Stir till thick.\n3. Top with banana.",
                'tags' => ['vegetarian', 'allergen_dairy'],
                'ingredients' => [
                    ['Milk (2L)', 0.5, 'L'],
                    ['Bananas', 0.3, 'kg'],
                    ['Sugar — White (1kg)', 30, 'g'],
                ],
            ],
            [
                'name' => 'Scrambled Eggs on Toast',
                'description' => 'Soft eggs with butter on wholemeal toast.',
                'serves_default' => 4, 'prep_minutes' => 5, 'cook_minutes' => 8,
                'tags' => ['vegetarian', 'allergen_egg', 'allergen_gluten', 'allergen_dairy'],
                'ingredients' => [
                    ['Eggs (dozen)', 0.5, 'each'],
                    ['Bread — Wholemeal Loaf', 0.5, 'each'],
                    ['Butter (500g)', 40, 'g'],
                ],
            ],
            [
                'name' => 'Cheese Toasties',
                'description' => 'Grilled cheese sandwiches — quick lunch.',
                'serves_default' => 4, 'prep_minutes' => 5, 'cook_minutes' => 8,
                'tags' => ['vegetarian', 'allergen_dairy', 'allergen_gluten'],
                'ingredients' => [
                    ['Bread — White Sandwich Loaf', 1, 'each'],
                    ['Cheese — Tasty Block (500g)', 200, 'g'],
                    ['Butter (500g)', 30, 'g'],
                ],
            ],
            [
                'name' => 'Garden Salad',
                'description' => 'Lettuce, tomato, cucumber side.',
                'serves_default' => 4, 'prep_minutes' => 10, 'cook_minutes' => 0,
                'tags' => ['vegan', 'gluten_free', 'dairy_free'],
                'ingredients' => [
                    ['Lettuce', 1, 'each'],
                    ['Tomatoes', 0.4, 'kg'],
                    ['Cucumber', 1, 'each'],
                    ['Olive Oil (750ml)', 30, 'ml'],
                ],
            ],
            [
                'name' => 'Roast Chicken & Veg',
                'description' => 'Sunday roast with potatoes and carrots.',
                'serves_default' => 6, 'prep_minutes' => 20, 'cook_minutes' => 75,
                'tags' => ['gluten_free', 'dairy_free'],
                'ingredients' => [
                    ['Chicken Breast (1kg)', 1.2, 'kg'],
                    ['Potatoes — Washed', 1.2, 'kg'],
                    ['Carrots', 0.6, 'kg'],
                    ['Onions — Brown', 0.3, 'kg'],
                    ['Olive Oil (750ml)', 60, 'ml'],
                ],
            ],
            [
                'name' => 'Shepherd\'s Pie',
                'description' => 'Beef mince with mashed potato topping.',
                'serves_default' => 6, 'prep_minutes' => 20, 'cook_minutes' => 35,
                'tags' => ['allergen_dairy'],
                'ingredients' => [
                    ['Beef Mince (500g)', 1, 'each'],
                    ['Potatoes — Washed', 1, 'kg'],
                    ['Carrots', 0.3, 'kg'],
                    ['Onions — Brown', 0.2, 'kg'],
                    ['Milk (2L)', 100, 'ml'],
                    ['Butter (500g)', 40, 'g'],
                ],
            ],
            [
                'name' => 'Chicken Wraps',
                'description' => 'Shredded chicken + salad in plain wraps.',
                'serves_default' => 4, 'prep_minutes' => 10, 'cook_minutes' => 15,
                'tags' => ['allergen_gluten'],
                'ingredients' => [
                    ['Wraps — Plain (8 pack)', 0.5, 'each'],
                    ['Chicken Breast (1kg)', 0.5, 'kg'],
                    ['Lettuce', 1, 'each'],
                    ['Tomatoes', 0.2, 'kg'],
                ],
            ],
            [
                'name' => 'Fruit Bowl',
                'description' => 'Apple + banana for morning tea.',
                'serves_default' => 6, 'prep_minutes' => 5, 'cook_minutes' => 0,
                'tags' => ['vegan', 'gluten_free'],
                'ingredients' => [
                    ['Apples', 0.6, 'kg'],
                    ['Bananas', 0.6, 'kg'],
                ],
            ],
            [
                'name' => 'Tomato Soup',
                'description' => 'Quick tomato soup served with bread.',
                'serves_default' => 4, 'prep_minutes' => 5, 'cook_minutes' => 15,
                'tags' => ['vegetarian', 'allergen_gluten'],
                'ingredients' => [
                    ['Tomato Pasta Sauce (500g)', 1, 'each'],
                    ['Onions — Brown', 0.2, 'kg'],
                    ['Bread — Wholemeal Loaf', 0.5, 'each'],
                ],
            ],
            [
                'name' => 'Yoghurt & Fruit',
                'description' => 'Bowl of yoghurt topped with chopped fruit.',
                'serves_default' => 4, 'prep_minutes' => 5, 'cook_minutes' => 0,
                'tags' => ['vegetarian', 'gluten_free', 'allergen_dairy'],
                'ingredients' => [
                    ['Yoghurt — Natural (1kg)', 0.5, 'kg'],
                    ['Bananas', 0.3, 'kg'],
                ],
            ],
        ];

        foreach ($more as $row) {
            $recipe = MealRecipe::firstOrCreate(
                ['slug' => Str::slug($row['name']), 'tenant_id' => null],
                [
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'serves_default' => $row['serves_default'],
                    'prep_minutes' => $row['prep_minutes'],
                    'cook_minutes' => $row['cook_minutes'],
                    'instructions' => $row['instructions'] ?? "Combine and serve.",
                    'is_active' => true,
                ]
            );

            $tagIds = collect($row['tags'] ?? [])->map(fn ($k) => $tags->get($k)?->id)->filter()->all();
            if ($tagIds) {
                $recipe->tags()->syncWithoutDetaching($tagIds);
            }

            if ($recipe->ingredients()->count() === 0) {
                $order = 0;
                foreach ($row['ingredients'] as [$productName, $qty, $unit]) {
                    $product = $products->get($productName);
                    if (! $product) continue;
                    MealRecipeIngredient::create([
                        'recipe_id' => $recipe->id,
                        'product_id' => $product->id,
                        'quantity' => $qty,
                        'unit' => $unit,
                        'sort_order' => $order++,
                    ]);
                }
            }
        }
    }

    private function tagResidentsWithAllergens(): void
    {
        $tags = MealDietaryTag::query()->get()->keyBy('key');
        $pairings = [
            ['Rosie', 'Ngata', ['allergen_peanut', 'allergen_tree_nut']],
            ['Wiremu', 'Tait', ['allergen_shellfish', 'allergen_fish']],
            ['Aroha', 'Kingi', ['allergen_dairy', 'thickened_fluids']],
            ['Mila', 'Singh', ['allergen_gluten', 'vegetarian']],
        ];

        foreach ($pairings as [$first, $last, $tagKeys]) {
            $client = Client::where('first_name', $first)->where('last_name', $last)->first();
            if (! $client) continue;
            $tagIds = collect($tagKeys)->map(fn ($k) => $tags->get($k)?->id)->filter()->all();
            if (! empty($tagIds)) {
                $client->mealDietaryTags()->syncWithoutDetaching($tagIds);
            }
        }
    }

    private function seedInventoryForSite(Site $site): void
    {
        $products = MealProduct::active()->get();

        // Stock state: ~70% on hand, ~15% low, ~15% out
        $i = 0;
        foreach ($products as $product) {
            // Skip half of cleaning to keep numbers reasonable
            if ($product->category === 'cleaning' && $i % 2 === 0) { $i++; continue; }

            $packSize = $product->pack_size ? (float) $product->pack_size : 1;
            $par = max(2, (int) round($packSize <= 1 ? 6 : 4));
            $reorder = max(1, (int) round($par * 0.4));

            $bucket = $i % 7;
            $current = match (true) {
                $bucket === 0 => 0.0,              // out
                $bucket === 1 => (float) $reorder, // low
                default => (float) $par * (0.6 + (($i % 4) * 0.15)),
            };

            DB::transaction(function () use ($site, $product, $current, $par, $reorder) {
                $item = \App\Models\SiteMealInventoryItem::firstOrCreate(
                    ['site_id' => $site->id, 'product_id' => $product->id],
                    [
                        'tenant_id' => $site->tenant_id,
                        'unit' => $product->default_unit,
                        'current_qty' => 0,
                        'par_level' => $par,
                        'reorder_level' => $reorder,
                    ]
                );
                $item->update(['par_level' => $par, 'reorder_level' => $reorder]);

                if ((float) $item->current_qty !== $current) {
                    app(InventoryMovementRecorder::class)->stocktake(
                        site: $site,
                        productId: $product->id,
                        newQty: $current,
                        unit: $product->default_unit,
                        note: 'Demo seed',
                    );
                }
            });
            $i++;
        }
    }

    private function seedPlanEntriesForSite(Site $site): void
    {
        $existing = SiteMealPlanEntry::where('site_id', $site->id)->exists();
        if ($existing) return;

        $recipesBySlot = [
            'breakfast' => MealRecipe::active()->where(function ($q) {
                $q->where('name', 'like', '%Porridge%')
                  ->orWhere('name', 'like', '%Eggs%')
                  ->orWhere('name', 'like', '%Yoghurt%')
                  ->orWhere('name', 'like', '%Baked Beans%');
            })->get(),
            'morning_tea' => MealRecipe::active()->where('name', 'like', '%Fruit%')->get(),
            'lunch' => MealRecipe::active()->where(function ($q) {
                $q->where('name', 'like', '%Toasties%')
                  ->orWhere('name', 'like', '%Wraps%')
                  ->orWhere('name', 'like', '%Tuna%')
                  ->orWhere('name', 'like', '%Soup%');
            })->get(),
            'afternoon_tea' => MealRecipe::active()->where('name', 'like', '%Yoghurt%')->get(),
            'dinner' => MealRecipe::active()->where(function ($q) {
                $q->where('name', 'like', '%Bolognese%')
                  ->orWhere('name', 'like', '%Chicken & Rice%')
                  ->orWhere('name', 'like', '%Vegetarian%')
                  ->orWhere('name', 'like', '%Roast%')
                  ->orWhere('name', 'like', '%Shepherd%');
            })->get(),
        ];

        $clients = Client::where('site_id', $site->id)->take(6)->pluck('id')->all();

        $slots = ['breakfast', 'morning_tea', 'lunch', 'afternoon_tea', 'dinner'];
        $start = CarbonImmutable::now()->startOfWeek();
        for ($day = -7; $day < 7; $day++) {
            $date = $start->addDays($day);
            foreach ($slots as $slotIndex => $slot) {
                $pool = $recipesBySlot[$slot] ?? collect();
                if ($pool->isEmpty()) continue;
                $key = $day + 7 + $slotIndex; // always non-negative
                if ($key % 5 === 0) {
                    continue;
                }
                $recipeIndex = $key % $pool->count();
                $recipe = $pool->values()->get($recipeIndex);
                if (!$recipe) continue;
                SiteMealPlanEntry::create([
                    'tenant_id' => $site->tenant_id,
                    'site_id' => $site->id,
                    'plan_date' => $date->toDateString(),
                    'meal_slot' => $slot,
                    'recipe_id' => $recipe->id,
                    'servings' => max(2, count($clients)),
                    'client_ids' => $clients,
                    'served_at' => $day < 0 ? $date->setTime(12, 0) : null,
                ]);
            }
        }
    }

    private function generateShoppingListForSite(Site $site): void
    {
        $hasDraft = \App\Models\SiteMealShoppingList::where('site_id', $site->id)
            ->where('status', 'draft')
            ->exists();
        if ($hasDraft) return;

        $from = CarbonImmutable::now()->startOfWeek();
        $to = $from->addDays(6);

        $list = app(ShoppingListGenerator::class)->generate(
            site: $site,
            from: $from,
            to: $to,
            includeRestockToPar: true,
        );

        // Add a manual item to demo the preservation behaviour
        SiteMealShoppingListItem::create([
            'list_id' => $list->id,
            'free_text_name' => 'Birthday cake for resident',
            'needed_qty' => 1,
            'unit' => 'each',
            'source' => 'manual',
            'notes' => 'Demo manual item — survives regeneration.',
        ]);
    }

    /**
     * Per-resident dislikes — mix of free-text and product-linked so the
     * substring matching and the direct product match both have demo
     * coverage in the Plan dialog warning flow.
     */
    private function seedResidentDislikes(): void
    {
        $beefMince = MealProduct::where('name', 'Beef Mince (500g)')->first();
        $tunaTin = MealProduct::where('name', 'Tinned Tuna (185g)')->first();

        $rows = [
            ['Rosie', 'Ngata', null, 'mushrooms', 'Strong dislike — substitute another vegetable.'],
            ['Wiremu', 'Tait', $beefMince?->id, null, 'Avoid beef where possible.'],
            ['Aroha', 'Kingi', null, 'tomatoes', "Doesn't enjoy raw tomato, soup OK."],
            ['Mila', 'Singh', $tunaTin?->id, null, 'Strong fish dislike.'],
        ];

        foreach ($rows as [$first, $last, $productId, $freeText, $note]) {
            $client = Client::where('first_name', $first)->where('last_name', $last)->first();
            if (!$client) continue;

            $exists = ClientMealDislike::query()
                ->where('client_id', $client->id)
                ->when($productId, fn ($q) => $q->where('product_id', $productId))
                ->when(!$productId && $freeText, fn ($q) => $q->whereNull('product_id')->where('free_text_name', $freeText))
                ->exists();
            if ($exists) continue;

            ClientMealDislike::create([
                'client_id' => $client->id,
                'product_id' => $productId,
                'free_text_name' => $freeText,
                'notes' => $note,
            ]);
        }
    }

    /**
     * Drop two carefully-chosen meals into the current week so the
     * Plan dialog warning UX is visible without manual setup:
     *
     *   - Wed lunch: Spaghetti Bolognese + Wiremu (beef dislike) → soft warning
     *   - Thu dinner: Vegetarian Pasta + Mila (gluten allergy) → hard block
     *
     * Both are upserted by (site, date, slot) so re-running is safe.
     */
    private function seedGuaranteedConflictMeals(Site $site): void
    {
        $clients = Client::query()
            ->where('site_id', $site->id)
            ->whereIn('first_name', ['Wiremu', 'Mila', 'Rosie', 'Aroha'])
            ->pluck('id', 'first_name');

        $weekStart = CarbonImmutable::now()->startOfWeek();

        // 1. Soft-warning scenario — Wiremu dislikes beef mince
        $bolognese = MealRecipe::where('slug', 'classic-spaghetti-bolognese')->first()
            ?? MealRecipe::where('name', 'like', '%Bolognese%')->first();
        $wiremu = $clients->get('Wiremu');
        if ($bolognese && $wiremu) {
            $this->upsertPlanEntry(
                site: $site,
                date: $weekStart->addDays(2), // Wed
                slot: 'lunch',
                recipe: $bolognese,
                clientIds: array_filter([$wiremu, $clients->get('Aroha'), $clients->get('Rosie')]),
            );
        }

        // 2. Hard-block scenario — Mila has gluten allergy
        $glutenRecipe = MealRecipe::where('slug', 'vegetarian-pasta')->first()
            ?? MealRecipe::where('name', 'like', '%Pasta%')->first();
        $mila = $clients->get('Mila');
        if ($glutenRecipe && $mila) {
            $this->upsertPlanEntry(
                site: $site,
                date: $weekStart->addDays(3), // Thu
                slot: 'dinner',
                recipe: $glutenRecipe,
                clientIds: array_filter([$mila, $clients->get('Aroha')]),
            );
        }
    }

    /**
     * Pre-save today's lunch with a recorded override so the calendar's
     * red-left-border + "Override on file" pill are visible immediately.
     */
    private function seedOverriddenMeal(Site $site): void
    {
        $today = CarbonImmutable::now()->startOfDay();
        $mila = Client::where('site_id', $site->id)->where('first_name', 'Mila')->first();
        $glutenRecipe = MealRecipe::where('slug', 'tuna-sandwiches')->first()
            ?? MealRecipe::where('name', 'like', '%Tuna%')->first();

        if (!$mila || !$glutenRecipe) return;

        $entry = SiteMealPlanEntry::firstOrNew([
            'site_id' => $site->id,
            'plan_date' => $today->toDateString(),
            'meal_slot' => 'lunch',
        ]);

        // If it already has an override on file, leave it alone — keeps re-runs idempotent
        if ($entry->exists && $entry->allergen_override_at !== null) {
            return;
        }

        $overrideUser = User::query()
            ->whereHas('roles.permissions', fn ($q) => $q->where('key', 'sites.meals.plan'))
            ->first()
            ?? User::first();

        $entry->fill([
            'tenant_id' => $site->tenant_id,
            'recipe_id' => $glutenRecipe->id,
            'servings' => 4,
            'client_ids' => array_filter([$mila->id]),
            'notes' => 'Demo — meal saved with allergen override.',
            'allergen_override_reason' => 'Cook prepared a gluten-free portion separately for Mila.',
            'allergen_override_by' => $overrideUser?->id,
            'allergen_override_at' => now(),
        ])->save();
    }

    /**
     * Drop one takeaway meal into the current week (Friday dinner) so
     * the bag icon, in-cell cost, and "cook + takeaway" KPI sub-label
     * are all visible immediately after seeding.
     */
    private function seedTakeawayMeal(Site $site): void
    {
        $weekStart = CarbonImmutable::now()->startOfWeek();
        $friday = $weekStart->addDays(4);

        $vendor = $site->name === 'Harbour Respite' ? 'Sushi Train' : 'Hell Pizza';
        $costCents = $site->name === 'Harbour Respite' ? 4830 : 5240; // $48.30 / $52.40

        $clientIds = Client::query()
            ->where('site_id', $site->id)
            ->take(4)
            ->pluck('id')
            ->all();

        $entry = SiteMealPlanEntry::firstOrNew([
            'site_id' => $site->id,
            'plan_date' => $friday->toDateString(),
            'meal_slot' => 'dinner',
        ]);

        // Don't clobber an already-customised dinner slot.
        if ($entry->exists && $entry->source_type === 'takeaway' && $entry->takeaway_vendor === $vendor) {
            return;
        }

        $entry->fill([
            'tenant_id' => $site->tenant_id,
            'source_type' => 'takeaway',
            'recipe_id' => null,
            'ad_hoc_name' => null,
            'takeaway_vendor' => $vendor,
            'takeaway_cost_cents' => $costCents,
            'takeaway_reference' => 'DEMO-' . strtoupper(substr($vendor, 0, 3)) . '-' . $friday->format('md'),
            'servings' => max(2, count($clientIds)),
            'client_ids' => $clientIds,
            'notes' => 'Demo takeaway — Friday treat.',
        ])->save();
    }

    private function upsertPlanEntry(Site $site, CarbonImmutable $date, string $slot, MealRecipe $recipe, array $clientIds): void
    {
        $entry = SiteMealPlanEntry::firstOrNew([
            'site_id' => $site->id,
            'plan_date' => $date->toDateString(),
            'meal_slot' => $slot,
        ]);
        $entry->fill([
            'tenant_id' => $site->tenant_id,
            'recipe_id' => $recipe->id,
            'servings' => max(2, count($clientIds)),
            'client_ids' => array_values($clientIds),
        ])->save();
    }
}
