<?php

use App\Http\Controllers\Catering\DashboardController;
use App\Http\Controllers\Catering\DietaryTagController;
use App\Http\Controllers\Catering\ProductController;
use App\Http\Controllers\Catering\RecipeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Catering (global library) routes
|--------------------------------------------------------------------------
|
| Reusable cross-site catering library used by the Sites Meal Planner:
| recipes, products and dietary/allergen tags. Per-site routes (planning,
| inventory, shopping lists) live in routes/sites.php.
|
*/

Route::middleware(['auth', 'verified'])->prefix('catering')->name('catering.')->group(function () {

    Route::get('/', [DashboardController::class, 'mealPlanner'])
        ->middleware('permission:sites.meals.view')
        ->name('meal-planner');
    Route::get('/overview', [DashboardController::class, 'index'])
        ->middleware('permission:sites.meals.view')
        ->name('dashboard');
    Route::get('/library-counts', [DashboardController::class, 'libraryCounts'])
        ->middleware('permission:sites.meals.view')
        ->name('library-counts');

    Route::middleware('permission:catering.recipes.view')->group(function () {
        Route::get('recipes', [RecipeController::class, 'index'])->name('recipes.index');
        Route::get('recipes/create', [RecipeController::class, 'create'])
            ->middleware('permission:catering.recipes.manage')
            ->name('recipes.create');
        Route::get('recipes/{recipe}', [RecipeController::class, 'show'])->name('recipes.show');
        Route::get('recipes/{recipe}/edit', [RecipeController::class, 'edit'])
            ->middleware('permission:catering.recipes.manage')
            ->name('recipes.edit');
        Route::post('recipes', [RecipeController::class, 'store'])
            ->middleware('permission:catering.recipes.manage')
            ->name('recipes.store');
        Route::put('recipes/{recipe}', [RecipeController::class, 'update'])
            ->middleware('permission:catering.recipes.manage')
            ->name('recipes.update');
        Route::delete('recipes/{recipe}', [RecipeController::class, 'destroy'])
            ->middleware('permission:catering.recipes.manage')
            ->name('recipes.destroy');
    });

    Route::middleware('permission:catering.products.view')->group(function () {
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::post('products', [ProductController::class, 'store'])
            ->middleware('permission:catering.products.manage')
            ->name('products.store');
        Route::put('products/{product}', [ProductController::class, 'update'])
            ->middleware('permission:catering.products.manage')
            ->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])
            ->middleware('permission:catering.products.manage')
            ->name('products.destroy');
    });

    Route::middleware('permission:catering.tags.view')->group(function () {
        Route::get('tags', [DietaryTagController::class, 'index'])->name('tags.index');
        Route::post('tags', [DietaryTagController::class, 'store'])
            ->middleware('permission:catering.tags.manage')
            ->name('tags.store');
        Route::put('tags/{tag}', [DietaryTagController::class, 'update'])
            ->middleware('permission:catering.tags.manage')
            ->name('tags.update');
        Route::delete('tags/{tag}', [DietaryTagController::class, 'destroy'])
            ->middleware('permission:catering.tags.manage')
            ->name('tags.destroy');
    });
});
