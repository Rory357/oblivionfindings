<?php

use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\MealRecipeIngredient;
use App\Models\Site;
use App\Models\SiteMealPlanEntry;
use App\Models\SiteMealShoppingListItem;
use App\Services\Catering\ShoppingListGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function aRecipe(): MealRecipe
{
    $recipe = MealRecipe::create([
        'name' => 'Test recipe ' . uniqid(),
        'serves_default' => 4,
        'is_active' => true,
    ]);
    $bread = MealProduct::create(['name' => 'Bread ' . uniqid(), 'default_unit' => 'each', 'is_active' => true]);
    MealRecipeIngredient::create([
        'recipe_id' => $recipe->id,
        'product_id' => $bread->id,
        'quantity' => 1,
        'unit' => 'each',
        'sort_order' => 0,
    ]);
    return $recipe;
}

it('aggregates ingredients from planned meals into a draft list', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $recipe = aRecipe();

    SiteMealPlanEntry::create([
        'site_id' => $site->id,
        'plan_date' => '2026-05-18',
        'meal_slot' => 'lunch',
        'recipe_id' => $recipe->id,
        'servings' => 8, // 2x the recipe default → 2 bread
    ]);

    $list = app(ShoppingListGenerator::class)->generate(
        site: $site,
        from: CarbonImmutable::parse('2026-05-18'),
        to: CarbonImmutable::parse('2026-05-24'),
        includeRestockToPar: false,
    );

    expect($list->status)->toBe('draft');
    expect($list->items)->toHaveCount(1);
    expect((float) $list->items->first()->needed_qty)->toBe(2.0);
    expect($list->items->first()->source)->toBe('meal_plan');
});

it('preserves manual items across regeneration', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $recipe = aRecipe();

    SiteMealPlanEntry::create([
        'site_id' => $site->id, 'plan_date' => '2026-05-18', 'meal_slot' => 'lunch',
        'recipe_id' => $recipe->id, 'servings' => 4,
    ]);

    $list = app(ShoppingListGenerator::class)->generate(
        $site, CarbonImmutable::parse('2026-05-18'), CarbonImmutable::parse('2026-05-24'), false
    );

    // Add a manual item
    SiteMealShoppingListItem::create([
        'list_id' => $list->id,
        'free_text_name' => 'Manual tea bags',
        'needed_qty' => 1,
        'unit' => 'each',
        'source' => 'manual',
    ]);

    expect($list->fresh('items')->items)->toHaveCount(2);

    // Regenerate — manual item should survive
    $list2 = app(ShoppingListGenerator::class)->generate(
        $site, CarbonImmutable::parse('2026-05-18'), CarbonImmutable::parse('2026-05-24'), false
    );

    expect($list2->id)->toBe($list->id);
    expect($list2->items->where('source', 'manual')->count())->toBe(1);
    expect($list2->items->where('source', 'meal_plan')->count())->toBe(1);
});
