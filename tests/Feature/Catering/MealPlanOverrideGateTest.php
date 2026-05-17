<?php

use App\Models\Client;
use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\MealRecipeIngredient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteMealPlanEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->seed(\Database\Seeders\CateringPermissionsSeeder::class);
});

function aPlannerUser(): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    $adminRole = Role::where('name', 'admin')->first();
    if ($adminRole) {
        $user->roles()->syncWithoutDetaching([$adminRole->id]);
    }
    // Direct attach of meal plan permission so canDo() returns true
    foreach (['sites.meals.view', 'sites.meals.plan'] as $key) {
        $perm = Permission::where('key', $key)->first();
        if ($perm && $adminRole && !$adminRole->permissions()->where('permissions.id', $perm->id)->exists()) {
            $adminRole->permissions()->attach($perm->id);
        }
    }
    return $user;
}

function aConflictingMeal(): array
{
    $site = Site::factory()->create(['type' => 'house']);
    $glutenTag = MealDietaryTag::create(['key' => 'allergen_gluten_' . uniqid(), 'label' => 'Gluten', 'kind' => 'allergen', 'severity' => 'critical']);
    $pasta = MealProduct::create(['name' => 'Pasta ' . uniqid(), 'default_unit' => 'each']);
    $pasta->tags()->attach($glutenTag->id);

    $recipe = MealRecipe::create(['name' => 'Pasta Bake', 'serves_default' => 4, 'is_active' => true]);
    MealRecipeIngredient::create(['recipe_id' => $recipe->id, 'product_id' => $pasta->id, 'quantity' => 1, 'unit' => 'each']);

    $client = Client::create(['first_name' => 'Mila', 'last_name' => 'Singh', 'site_id' => $site->id, 'status' => 'active']);
    $client->mealDietaryTags()->attach($glutenTag->id);

    return [$site, $recipe, $client];
}

it('rejects a save without an override reason when an allergen conflict exists', function () {
    [$site, $recipe, $client] = aConflictingMeal();
    $user = aPlannerUser();

    $this->actingAs($user)
        ->post("/sites/{$site->id}/meal-plan", [
            'plan_date' => '2026-05-20',
            'meal_slot' => 'lunch',
            'recipe_id' => $recipe->id,
            'servings' => 4,
            'client_ids' => [$client->id],
        ])
        ->assertSessionHasErrors('allergen_override_reason');

    expect(SiteMealPlanEntry::count())->toBe(0);
});

it('rejects an override reason that is too short', function () {
    [$site, $recipe, $client] = aConflictingMeal();
    $user = aPlannerUser();

    $this->actingAs($user)
        ->post("/sites/{$site->id}/meal-plan", [
            'plan_date' => '2026-05-20',
            'meal_slot' => 'lunch',
            'recipe_id' => $recipe->id,
            'servings' => 4,
            'client_ids' => [$client->id],
            'allergen_override_reason' => 'ok',
        ])
        ->assertSessionHasErrors('allergen_override_reason');

    expect(SiteMealPlanEntry::count())->toBe(0);
});

it('accepts a save with a valid override reason and stamps the audit fields', function () {
    [$site, $recipe, $client] = aConflictingMeal();
    $user = aPlannerUser();

    $this->actingAs($user)
        ->post("/sites/{$site->id}/meal-plan", [
            'plan_date' => '2026-05-20',
            'meal_slot' => 'lunch',
            'recipe_id' => $recipe->id,
            'servings' => 4,
            'client_ids' => [$client->id],
            'allergen_override_reason' => 'Cook prepared a separate gluten-free portion.',
        ])
        ->assertSessionHasNoErrors();

    $entry = SiteMealPlanEntry::first();
    expect($entry)->not->toBeNull();
    expect($entry->allergen_override_reason)->toBe('Cook prepared a separate gluten-free portion.');
    expect($entry->allergen_override_by)->toBe($user->id);
    expect($entry->allergen_override_at)->not->toBeNull();
});

it('saves without an override when no conflict exists', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $recipe = MealRecipe::create(['name' => 'Simple Salad', 'serves_default' => 4, 'is_active' => true]);
    $user = aPlannerUser();

    $this->actingAs($user)
        ->post("/sites/{$site->id}/meal-plan", [
            'plan_date' => '2026-05-20',
            'meal_slot' => 'lunch',
            'recipe_id' => $recipe->id,
            'servings' => 4,
            'client_ids' => [],
        ])
        ->assertSessionHasNoErrors();

    $entry = SiteMealPlanEntry::first();
    expect($entry->allergen_override_at)->toBeNull();
    expect($entry->allergen_override_reason)->toBeNull();
});
