<?php

use App\Models\MealProduct;
use App\Models\MealRecipe;
use Database\Seeders\CateringDemoSeeder;
use Database\Seeders\CateringSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps the application catering seeders rerunnable without replacing archived recipes', function () {
    $recipeSnapshot = static fn (): array => MealRecipe::withTrashed()
        ->orderBy('slug')
        ->pluck('id', 'slug')
        ->all();
    $productSnapshot = static fn (): array => MealProduct::withTrashed()
        ->orderBy('name')
        ->pluck('id', 'name')
        ->all();

    $this->seed(CateringSeeder::class);

    $baseRecipeIds = $recipeSnapshot();
    $baseProductIds = $productSnapshot();
    $archivedRecipe = MealRecipe::query()->where('slug', 'classic-spaghetti-bolognese')->firstOrFail();
    $archivedRecipe->delete();

    $this->seed(CateringSeeder::class);

    expect($recipeSnapshot())->toBe($baseRecipeIds)
        ->and($productSnapshot())->toBe($baseProductIds)
        ->and(MealRecipe::withTrashed()->count())->toBe(count($baseRecipeIds))
        ->and(MealProduct::withTrashed()->count())->toBe(count($baseProductIds))
        ->and(MealRecipe::withTrashed()->findOrFail($archivedRecipe->id)->trashed())->toBeTrue();

    $this->seed(CateringDemoSeeder::class);

    $demoRecipeIds = $recipeSnapshot();
    $demoProductIds = $productSnapshot();

    $this->seed(CateringDemoSeeder::class);

    expect($recipeSnapshot())->toBe($demoRecipeIds)
        ->and($productSnapshot())->toBe($demoProductIds)
        ->and(MealRecipe::withTrashed()->count())->toBe(count($demoRecipeIds))
        ->and(MealProduct::withTrashed()->count())->toBe(count($demoProductIds))
        ->and(MealRecipe::withTrashed()->findOrFail($archivedRecipe->id)->trashed())->toBeTrue()
        ->and(MealRecipe::withTrashed()->where('slug', $archivedRecipe->slug)->count())->toBe(1);
});
