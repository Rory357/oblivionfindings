<?php

namespace Database\Seeders;

use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\MealRecipeIngredient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CateringSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = null;

        $tags = [
            // Dietary preferences
            ['key' => 'vegetarian', 'label' => 'Vegetarian', 'kind' => 'dietary', 'severity' => 'info', 'color' => '#16a34a'],
            ['key' => 'vegan', 'label' => 'Vegan', 'kind' => 'dietary', 'severity' => 'info', 'color' => '#15803d'],
            ['key' => 'gluten_free', 'label' => 'Gluten Free', 'kind' => 'dietary', 'severity' => 'info', 'color' => '#a16207'],
            ['key' => 'dairy_free', 'label' => 'Dairy Free', 'kind' => 'dietary', 'severity' => 'info', 'color' => '#0891b2'],
            ['key' => 'halal', 'label' => 'Halal', 'kind' => 'dietary', 'severity' => 'info', 'color' => '#15803d'],
            ['key' => 'kosher', 'label' => 'Kosher', 'kind' => 'dietary', 'severity' => 'info', 'color' => '#7c3aed'],
            ['key' => 'low_sodium', 'label' => 'Low Sodium', 'kind' => 'dietary', 'severity' => 'info', 'color' => '#3b82f6'],
            ['key' => 'diabetic', 'label' => 'Diabetic Friendly', 'kind' => 'dietary', 'severity' => 'warn', 'color' => '#ea580c'],
            ['key' => 'soft_diet', 'label' => 'Soft Diet', 'kind' => 'dietary', 'severity' => 'warn', 'color' => '#f59e0b'],
            ['key' => 'pureed', 'label' => 'Pureed', 'kind' => 'dietary', 'severity' => 'warn', 'color' => '#dc2626'],
            ['key' => 'thickened_fluids', 'label' => 'Thickened Fluids', 'kind' => 'dietary', 'severity' => 'critical', 'color' => '#991b1b'],

            // Allergens
            ['key' => 'allergen_peanut', 'label' => 'Peanuts', 'kind' => 'allergen', 'severity' => 'critical', 'color' => '#b91c1c'],
            ['key' => 'allergen_tree_nut', 'label' => 'Tree Nuts', 'kind' => 'allergen', 'severity' => 'critical', 'color' => '#b91c1c'],
            ['key' => 'allergen_shellfish', 'label' => 'Shellfish', 'kind' => 'allergen', 'severity' => 'critical', 'color' => '#b91c1c'],
            ['key' => 'allergen_fish', 'label' => 'Fish', 'kind' => 'allergen', 'severity' => 'critical', 'color' => '#b91c1c'],
            ['key' => 'allergen_egg', 'label' => 'Eggs', 'kind' => 'allergen', 'severity' => 'critical', 'color' => '#b91c1c'],
            ['key' => 'allergen_dairy', 'label' => 'Dairy', 'kind' => 'allergen', 'severity' => 'warn', 'color' => '#ea580c'],
            ['key' => 'allergen_soy', 'label' => 'Soy', 'kind' => 'allergen', 'severity' => 'warn', 'color' => '#ea580c'],
            ['key' => 'allergen_gluten', 'label' => 'Gluten', 'kind' => 'allergen', 'severity' => 'warn', 'color' => '#ea580c'],
            ['key' => 'allergen_sesame', 'label' => 'Sesame', 'kind' => 'allergen', 'severity' => 'warn', 'color' => '#ea580c'],
            ['key' => 'allergen_sulphite', 'label' => 'Sulphites', 'kind' => 'allergen', 'severity' => 'info', 'color' => '#a16207'],
            ['key' => 'allergen_mustard', 'label' => 'Mustard', 'kind' => 'allergen', 'severity' => 'info', 'color' => '#a16207'],
            ['key' => 'allergen_celery', 'label' => 'Celery', 'kind' => 'allergen', 'severity' => 'info', 'color' => '#a16207'],
            ['key' => 'allergen_lupin', 'label' => 'Lupin', 'kind' => 'allergen', 'severity' => 'info', 'color' => '#a16207'],
            ['key' => 'allergen_mollusc', 'label' => 'Molluscs', 'kind' => 'allergen', 'severity' => 'warn', 'color' => '#ea580c'],
        ];

        $tagMap = [];
        foreach ($tags as $row) {
            $tag = MealDietaryTag::firstOrCreate(
                ['tenant_id' => $tenantId, 'key' => $row['key']],
                $row + ['tenant_id' => $tenantId]
            );
            $tagMap[$row['key']] = $tag;
        }

        $products = [
            // Dairy
            ['name' => 'Milk (2L)', 'category' => 'dairy', 'default_unit' => 'each', 'pack_size' => 2, 'pack_unit' => 'L', 'tags' => ['allergen_dairy']],
            ['name' => 'Butter (500g)', 'category' => 'dairy', 'default_unit' => 'each', 'pack_size' => 500, 'pack_unit' => 'g', 'tags' => ['allergen_dairy', 'vegetarian']],
            ['name' => 'Cheese — Tasty Block (500g)', 'category' => 'dairy', 'default_unit' => 'each', 'pack_size' => 500, 'pack_unit' => 'g', 'tags' => ['allergen_dairy', 'vegetarian']],
            ['name' => 'Yoghurt — Natural (1kg)', 'category' => 'dairy', 'default_unit' => 'each', 'pack_size' => 1, 'pack_unit' => 'kg', 'tags' => ['allergen_dairy', 'vegetarian']],

            // Bakery
            ['name' => 'Bread — White Sandwich Loaf', 'category' => 'bakery', 'default_unit' => 'each', 'tags' => ['allergen_gluten', 'vegetarian']],
            ['name' => 'Bread — Wholemeal Loaf', 'category' => 'bakery', 'default_unit' => 'each', 'tags' => ['allergen_gluten', 'vegetarian']],
            ['name' => 'Wraps — Plain (8 pack)', 'category' => 'bakery', 'default_unit' => 'each', 'tags' => ['allergen_gluten', 'vegetarian']],

            // Proteins
            ['name' => 'Eggs (dozen)', 'category' => 'protein', 'default_unit' => 'each', 'tags' => ['allergen_egg', 'vegetarian']],
            ['name' => 'Chicken Breast (1kg)', 'category' => 'protein', 'default_unit' => 'kg', 'tags' => []],
            ['name' => 'Beef Mince (500g)', 'category' => 'protein', 'default_unit' => 'each', 'pack_size' => 500, 'pack_unit' => 'g', 'tags' => []],
            ['name' => 'Tinned Tuna (185g)', 'category' => 'protein', 'default_unit' => 'each', 'tags' => ['allergen_fish']],

            // Pantry staples
            ['name' => 'Rice — Long Grain (1kg)', 'category' => 'pantry', 'default_unit' => 'each', 'pack_size' => 1, 'pack_unit' => 'kg', 'tags' => ['vegetarian', 'vegan', 'gluten_free']],
            ['name' => 'Pasta — Penne (500g)', 'category' => 'pantry', 'default_unit' => 'each', 'pack_size' => 500, 'pack_unit' => 'g', 'tags' => ['allergen_gluten', 'vegetarian']],
            ['name' => 'Flour — Plain (1.5kg)', 'category' => 'pantry', 'default_unit' => 'each', 'pack_size' => 1.5, 'pack_unit' => 'kg', 'tags' => ['allergen_gluten', 'vegetarian']],
            ['name' => 'Sugar — White (1kg)', 'category' => 'pantry', 'default_unit' => 'each', 'pack_size' => 1, 'pack_unit' => 'kg', 'tags' => ['vegan']],
            ['name' => 'Salt — Iodised (500g)', 'category' => 'pantry', 'default_unit' => 'each', 'tags' => ['vegan']],
            ['name' => 'Olive Oil (750ml)', 'category' => 'pantry', 'default_unit' => 'each', 'pack_size' => 750, 'pack_unit' => 'ml', 'tags' => ['vegan']],
            ['name' => 'Tomato Pasta Sauce (500g)', 'category' => 'pantry', 'default_unit' => 'each', 'tags' => ['vegetarian']],
            ['name' => 'Baked Beans (420g)', 'category' => 'pantry', 'default_unit' => 'each', 'tags' => ['vegetarian']],

            // Beverages
            ['name' => 'Coffee — Ground (250g)', 'category' => 'beverage', 'default_unit' => 'each', 'pack_size' => 250, 'pack_unit' => 'g', 'tags' => []],
            ['name' => 'Tea Bags — Black (100 pack)', 'category' => 'beverage', 'default_unit' => 'each', 'tags' => []],
            ['name' => 'Tea Bags — Herbal (40 pack)', 'category' => 'beverage', 'default_unit' => 'each', 'tags' => []],
            ['name' => 'Long Life Milk (1L)', 'category' => 'beverage', 'default_unit' => 'each', 'pack_size' => 1, 'pack_unit' => 'L', 'tags' => ['allergen_dairy']],
            ['name' => 'Juice — Orange (2L)', 'category' => 'beverage', 'default_unit' => 'each', 'pack_size' => 2, 'pack_unit' => 'L', 'tags' => []],

            // Fresh produce
            ['name' => 'Bananas', 'category' => 'produce', 'default_unit' => 'kg', 'tags' => ['vegan', 'gluten_free']],
            ['name' => 'Apples', 'category' => 'produce', 'default_unit' => 'kg', 'tags' => ['vegan', 'gluten_free']],
            ['name' => 'Tomatoes', 'category' => 'produce', 'default_unit' => 'kg', 'tags' => ['vegan', 'gluten_free']],
            ['name' => 'Onions — Brown', 'category' => 'produce', 'default_unit' => 'kg', 'tags' => ['vegan', 'gluten_free']],
            ['name' => 'Potatoes — Washed', 'category' => 'produce', 'default_unit' => 'kg', 'tags' => ['vegan', 'gluten_free']],
            ['name' => 'Carrots', 'category' => 'produce', 'default_unit' => 'kg', 'tags' => ['vegan', 'gluten_free']],
            ['name' => 'Lettuce', 'category' => 'produce', 'default_unit' => 'each', 'tags' => ['vegan', 'gluten_free']],
            ['name' => 'Cucumber', 'category' => 'produce', 'default_unit' => 'each', 'tags' => ['vegan', 'gluten_free']],
            ['name' => 'Lemons', 'category' => 'produce', 'default_unit' => 'each', 'tags' => ['vegan', 'gluten_free']],

            // Cleaning / kitchen consumables
            ['name' => 'Paper Towels (4 pack)', 'category' => 'cleaning', 'default_unit' => 'each', 'tags' => []],
            ['name' => 'Dishwashing Liquid (900ml)', 'category' => 'cleaning', 'default_unit' => 'each', 'tags' => []],
            ['name' => 'Cling Film (30m)', 'category' => 'cleaning', 'default_unit' => 'each', 'tags' => []],
            ['name' => 'Tin Foil (30m)', 'category' => 'cleaning', 'default_unit' => 'each', 'tags' => []],
            ['name' => 'Rubbish Bags — Kitchen', 'category' => 'cleaning', 'default_unit' => 'each', 'tags' => []],
            ['name' => 'Tea Towels', 'category' => 'cleaning', 'default_unit' => 'each', 'tags' => []],
        ];

        $productMap = [];
        foreach ($products as $row) {
            $product = MealProduct::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $row['name']],
                [
                    'tenant_id' => $tenantId,
                    'category' => $row['category'],
                    'default_unit' => $row['default_unit'],
                    'pack_size' => $row['pack_size'] ?? null,
                    'pack_unit' => $row['pack_unit'] ?? null,
                    'is_active' => true,
                    'currency' => 'NZD',
                ]
            );
            $productMap[$row['name']] = $product;

            $tagIds = collect($row['tags'] ?? [])
                ->map(fn ($key) => $tagMap[$key]?->id ?? null)
                ->filter()
                ->all();
            if ($tagIds) {
                $product->tags()->syncWithoutDetaching($tagIds);
            }
        }

        $recipes = [
            [
                'name' => 'Classic Spaghetti Bolognese',
                'description' => 'Family-friendly bolognese with beef mince and pasta.',
                'serves_default' => 4,
                'prep_minutes' => 15,
                'cook_minutes' => 30,
                'instructions' => "1. Brown the mince with onion.\n2. Add tomato sauce, simmer 20 min.\n3. Boil pasta, drain, serve.",
                'tags' => ['allergen_gluten', 'allergen_dairy'],
                'ingredients' => [
                    ['product' => 'Beef Mince (500g)', 'quantity' => 1, 'unit' => 'each'],
                    ['product' => 'Pasta — Penne (500g)', 'quantity' => 1, 'unit' => 'each'],
                    ['product' => 'Tomato Pasta Sauce (500g)', 'quantity' => 1, 'unit' => 'each'],
                    ['product' => 'Onions — Brown', 'quantity' => 0.2, 'unit' => 'kg'],
                ],
            ],
            [
                'name' => 'Chicken & Rice Bowl',
                'description' => 'Simple grilled chicken on rice with carrots.',
                'serves_default' => 4,
                'prep_minutes' => 10,
                'cook_minutes' => 25,
                'instructions' => "1. Grill chicken 6 min each side.\n2. Cook rice.\n3. Steam carrots.\n4. Plate together.",
                'tags' => ['gluten_free', 'dairy_free'],
                'ingredients' => [
                    ['product' => 'Chicken Breast (1kg)', 'quantity' => 0.6, 'unit' => 'kg'],
                    ['product' => 'Rice — Long Grain (1kg)', 'quantity' => 1, 'unit' => 'each'],
                    ['product' => 'Carrots', 'quantity' => 0.3, 'unit' => 'kg'],
                    ['product' => 'Olive Oil (750ml)', 'quantity' => 30, 'unit' => 'ml'],
                ],
            ],
            [
                'name' => 'Tuna Sandwiches',
                'description' => 'Quick lunch option with tinned tuna.',
                'serves_default' => 4,
                'prep_minutes' => 10,
                'cook_minutes' => 0,
                'instructions' => "1. Drain tuna, mix with a little mayo.\n2. Spread on bread.\n3. Cut into halves.",
                'tags' => ['allergen_fish', 'allergen_gluten'],
                'ingredients' => [
                    ['product' => 'Tinned Tuna (185g)', 'quantity' => 2, 'unit' => 'each'],
                    ['product' => 'Bread — Wholemeal Loaf', 'quantity' => 1, 'unit' => 'each'],
                    ['product' => 'Lettuce', 'quantity' => 1, 'unit' => 'each'],
                ],
            ],
            [
                'name' => 'Vegetarian Pasta',
                'description' => 'Pasta with tomato sauce and cheese — no meat.',
                'serves_default' => 4,
                'prep_minutes' => 5,
                'cook_minutes' => 15,
                'instructions' => "1. Boil pasta.\n2. Heat sauce.\n3. Combine, top with grated cheese.",
                'tags' => ['vegetarian', 'allergen_gluten', 'allergen_dairy'],
                'ingredients' => [
                    ['product' => 'Pasta — Penne (500g)', 'quantity' => 1, 'unit' => 'each'],
                    ['product' => 'Tomato Pasta Sauce (500g)', 'quantity' => 1, 'unit' => 'each'],
                    ['product' => 'Cheese — Tasty Block (500g)', 'quantity' => 100, 'unit' => 'g'],
                ],
            ],
            [
                'name' => 'Baked Beans on Toast',
                'description' => 'Comfort breakfast or light meal.',
                'serves_default' => 4,
                'prep_minutes' => 2,
                'cook_minutes' => 8,
                'instructions' => "1. Heat beans gently.\n2. Toast bread.\n3. Ladle beans on toast, butter optional.",
                'tags' => ['vegetarian', 'allergen_gluten'],
                'ingredients' => [
                    ['product' => 'Baked Beans (420g)', 'quantity' => 2, 'unit' => 'each'],
                    ['product' => 'Bread — White Sandwich Loaf', 'quantity' => 1, 'unit' => 'each'],
                    ['product' => 'Butter (500g)', 'quantity' => 30, 'unit' => 'g'],
                ],
            ],
        ];

        foreach ($recipes as $row) {
            $recipe = MealRecipe::firstOrCreate(
                ['tenant_id' => $tenantId, 'slug' => Str::slug($row['name'])],
                [
                    'tenant_id' => $tenantId,
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'serves_default' => $row['serves_default'],
                    'prep_minutes' => $row['prep_minutes'],
                    'cook_minutes' => $row['cook_minutes'],
                    'instructions' => $row['instructions'],
                    'is_active' => false,
                ]
            );

            $tagIds = collect($row['tags'] ?? [])
                ->map(fn ($key) => $tagMap[$key]?->id ?? null)
                ->filter()
                ->all();
            if ($tagIds) {
                $recipe->tags()->syncWithoutDetaching($tagIds);
            }

            $existingNames = $recipe->ingredients()->pluck('product_id')->all();
            $order = 0;
            foreach ($row['ingredients'] as $ing) {
                $productId = $productMap[$ing['product']]?->id ?? null;
                if (! $productId) {
                    continue;
                }
                if (in_array($productId, $existingNames, true)) {
                    continue;
                }
                MealRecipeIngredient::create([
                    'recipe_id' => $recipe->id,
                    'product_id' => $productId,
                    'quantity' => $ing['quantity'],
                    'unit' => $ing['unit'],
                    'sort_order' => $order++,
                ]);
            }
        }

        $this->command?->info('Catering library seeded: ' . count($tags) . ' tags, ' . count($products) . ' products, ' . count($recipes) . ' recipes.');
    }
}
