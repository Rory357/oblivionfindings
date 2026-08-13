<?php

use App\Domain\Clinical\Models\ClientMealRestriction;
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
use Database\Seeders\CateringPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(CateringPermissionsSeeder::class);
});

function aPlannerUser(): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    $adminRole = Role::where('name', 'admin')->firstOrFail();
    $user->roles()->syncWithoutDetaching([$adminRole->id]);

    return $user;
}

function overrideGateRestriction(Client $client, int $allergenTagId): void
{
    $author = User::factory()->create();
    $approver = User::factory()->create();
    $restriction = new ClientMealRestriction([
        'site_id' => $client->site_id,
        'client_id' => $client->id,
        'version' => 1,
        'status' => ClientMealRestriction::STATUS_AUTHORISED,
        'proposed_by' => $author->id,
        'proposed_at' => now(),
        'approved_by' => $approver->id,
        'approved_at' => now(),
        'effective_from' => today()->subDay(),
        'review_due_at' => today()->addYear(),
        'allergen_tag_ids' => [$allergenTagId],
        'dietary_tag_ids' => [],
        'amendment_reason' => 'Clinically verified allergen restriction fixture.',
    ]);
    $restriction->content_hash = $restriction->calculateContentHash();
    $restriction->save();
}

function aConflictingMeal(): array
{
    $site = Site::factory()->create(['type' => 'house']);
    $glutenTag = MealDietaryTag::create([
        'key' => 'allergen_gluten_'.uniqid(),
        'label' => 'Gluten',
        'kind' => 'allergen',
        'severity' => 'critical',
    ]);
    $pasta = MealProduct::create(['name' => 'Pasta '.uniqid(), 'default_unit' => 'each']);
    $pasta->tags()->attach($glutenTag->id);

    $recipe = MealRecipe::create(['name' => 'Pasta Bake', 'serves_default' => 4, 'is_active' => true]);
    MealRecipeIngredient::create([
        'recipe_id' => $recipe->id,
        'product_id' => $pasta->id,
        'quantity' => 1,
        'unit' => 'each',
    ]);

    $client = Client::create([
        'first_name' => 'Mila',
        'last_name' => 'Singh',
        'site_id' => $site->id,
        'status' => 'active',
    ]);
    overrideGateRestriction($client, $glutenTag->id);

    return [$site, $recipe, $client];
}

it('rejects an allergen conflict without creating a meal', function () {
    [$site, $recipe, $client] = aConflictingMeal();

    $this->actingAs(aPlannerUser())
        ->post("/sites/{$site->id}/meal-plan", [
            'plan_date' => today()->toDateString(),
            'meal_slot' => 'lunch',
            'recipe_id' => $recipe->id,
            'servings' => 4,
            'client_ids' => [$client->id],
        ])
        ->assertSessionHasErrors('client_ids');

    expect(SiteMealPlanEntry::count())->toBe(0);
});

it('does not honour the former allergen override permission or reason', function () {
    [$site, $recipe, $client] = aConflictingMeal();
    $user = aPlannerUser();
    $legacy = Permission::firstOrCreate(
        ['key' => 'sites.meals.allergen.override'],
        ['description' => 'Legacy allergen override'],
    );
    $user->permissionOverrides()->attach($legacy->id, ['allowed' => true]);

    $this->actingAs($user)
        ->post("/sites/{$site->id}/meal-plan", [
            'plan_date' => today()->toDateString(),
            'meal_slot' => 'lunch',
            'recipe_id' => $recipe->id,
            'servings' => 4,
            'client_ids' => [$client->id],
            'allergen_override_reason' => 'Cook prepared a separate gluten-free portion.',
        ])
        ->assertSessionHasErrors('client_ids');

    expect(SiteMealPlanEntry::count())->toBe(0);
});

it('saves a meal without residents and never stamps override fields', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $recipe = MealRecipe::create(['name' => 'Simple Salad', 'serves_default' => 4, 'is_active' => true]);

    $this->actingAs(aPlannerUser())
        ->post("/sites/{$site->id}/meal-plan", [
            'plan_date' => today()->toDateString(),
            'meal_slot' => 'lunch',
            'recipe_id' => $recipe->id,
            'servings' => 4,
            'client_ids' => [],
        ])
        ->assertSessionHasNoErrors();

    $entry = SiteMealPlanEntry::firstOrFail();
    expect($entry->allergen_override_at)->toBeNull()
        ->and($entry->allergen_override_reason)->toBeNull();
});
