<?php

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

function takeawayPlannerUser(): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    $admin = Role::where('name', 'admin')->first();
    if ($admin) {
        $user->roles()->syncWithoutDetaching([$admin->id]);
        foreach (['sites.meals.view', 'sites.meals.plan'] as $key) {
            $perm = Permission::where('key', $key)->first();
            if ($perm && !$admin->permissions()->where('permissions.id', $perm->id)->exists()) {
                $admin->permissions()->attach($perm->id);
            }
        }
    }
    return $user;
}

it('store accepts takeaway meals and converts dollars to cents', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $user = takeawayPlannerUser();

    $this->actingAs($user)
        ->post("/sites/{$site->id}/meal-plan", [
            'plan_date' => '2026-05-22',
            'meal_slot' => 'dinner',
            'source_type' => 'takeaway',
            'takeaway_vendor' => 'Hell Pizza',
            'takeaway_cost' => 52.40, // dollars
            'takeaway_reference' => 'HP-12345',
            'servings' => 4,
            'client_ids' => [],
        ])
        ->assertSessionHasNoErrors();

    $entry = SiteMealPlanEntry::first();
    expect($entry->source_type)->toBe('takeaway');
    expect($entry->takeaway_vendor)->toBe('Hell Pizza');
    expect($entry->takeaway_cost_cents)->toBe(5240);
    expect($entry->takeaway_reference)->toBe('HP-12345');
    expect($entry->recipe_id)->toBeNull();
    expect($entry->ad_hoc_name)->toBeNull();
});

it('rejects takeaway meals without a vendor', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $user = takeawayPlannerUser();

    $this->actingAs($user)
        ->post("/sites/{$site->id}/meal-plan", [
            'plan_date' => '2026-05-22',
            'meal_slot' => 'dinner',
            'source_type' => 'takeaway',
            'takeaway_cost' => 30.00,
            'servings' => 4,
            'client_ids' => [],
        ])
        ->assertSessionHasErrors('takeaway_vendor');

    expect(SiteMealPlanEntry::count())->toBe(0);
});

it('week summary prefers takeaway cost over recipe estimate', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $user = takeawayPlannerUser();

    // A recipe with one priced ingredient — gives the calculator a non-zero number to ignore.
    $product = MealProduct::create([
        'name' => 'Test Product',
        'default_unit' => 'each',
        'cost_per_unit_cents' => 1000, // $10
        'is_active' => true,
    ]);
    $recipe = MealRecipe::create(['name' => 'Bolognese', 'serves_default' => 4, 'is_active' => true]);
    MealRecipeIngredient::create([
        'recipe_id' => $recipe->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit' => 'each',
    ]);

    // Two meals on the same day: one recipe, one takeaway.
    SiteMealPlanEntry::create([
        'site_id' => $site->id,
        'plan_date' => '2026-05-18',
        'meal_slot' => 'lunch',
        'source_type' => 'recipe',
        'recipe_id' => $recipe->id,
        'servings' => 4,
    ]);
    SiteMealPlanEntry::create([
        'site_id' => $site->id,
        'plan_date' => '2026-05-18',
        'meal_slot' => 'dinner',
        'source_type' => 'takeaway',
        'takeaway_vendor' => 'Hell Pizza',
        'takeaway_cost_cents' => 5240,
        'servings' => 4,
    ]);

    $res = $this->actingAs($user)
        ->getJson("/sites/{$site->id}/meal-plan/week-summary?week=2026-05-18");
    $res->assertOk();
    $body = $res->json();

    // Recipe: 1 each * $10 * (4/4) = $10 = 1000 cents
    // Takeaway: stored as 5240 cents
    // Total should be 6240
    expect($body['total_cost_cents'])->toBe(6240);
    expect($body['cook_cost_cents'])->toBe(1000);
    expect($body['takeaway_cost_cents'])->toBe(5240);
});

it('takeaway vendors autocomplete returns past distinct vendors', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $user = takeawayPlannerUser();

    SiteMealPlanEntry::create([
        'site_id' => $site->id, 'plan_date' => '2026-05-10', 'meal_slot' => 'dinner',
        'source_type' => 'takeaway', 'takeaway_vendor' => 'Hell Pizza',
    ]);
    SiteMealPlanEntry::create([
        'site_id' => $site->id, 'plan_date' => '2026-05-12', 'meal_slot' => 'dinner',
        'source_type' => 'takeaway', 'takeaway_vendor' => 'Sushi Train',
    ]);
    SiteMealPlanEntry::create([
        'site_id' => $site->id, 'plan_date' => '2026-05-14', 'meal_slot' => 'dinner',
        'source_type' => 'takeaway', 'takeaway_vendor' => 'Hell Pizza', // duplicate
    ]);

    $res = $this->actingAs($user)
        ->getJson("/sites/{$site->id}/meal-planner/takeaway-vendors");
    $res->assertOk();
    expect($res->json('vendors'))->toEqualCanonicalizing(['Hell Pizza', 'Sushi Train']);
});
