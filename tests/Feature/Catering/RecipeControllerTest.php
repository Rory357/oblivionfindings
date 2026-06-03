<?php

use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->seed(\Database\Seeders\CateringPermissionsSeeder::class);
});

function aRecipeManager(): User
{
    $user = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now()]);
    $adminRole = Role::where('name', 'admin')->first();
    if ($adminRole) {
        $user->roles()->syncWithoutDetaching([$adminRole->id]);
        foreach (['catering.recipes.view', 'catering.recipes.manage'] as $key) {
            $perm = Permission::where('key', $key)->first();
            if ($perm && ! $adminRole->permissions()->where('permissions.id', $perm->id)->exists()) {
                $adminRole->permissions()->attach($perm->id);
            }
        }
    }

    return $user;
}

it('stores a house-scoped recipe with category, site_id and ingredients', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $product = MealProduct::create(['name' => 'Chicken Breast', 'default_unit' => 'kg']);

    $this->actingAs(aRecipeManager())
        ->postJson('/catering/recipes', [
            'name' => 'House Roast Dinner',
            'category' => 'Mains',
            'scope' => 'house',
            'site_id' => $site->id,
            'serves_default' => 6,
            'prep_minutes' => 10,
            'cook_minutes' => 40,
            'instructions' => 'Roast it.',
            'is_active' => true,
            'tag_ids' => [],
            'ingredients' => [
                ['product_id' => $product->id, 'free_text_name' => null, 'quantity' => 1.5, 'unit' => 'kg'],
            ],
        ])
        ->assertOk();

    $recipe = MealRecipe::where('name', 'House Roast Dinner')->first();
    expect($recipe)->not->toBeNull();
    expect($recipe->category)->toBe('Mains');
    expect($recipe->scope)->toBe('house');
    expect((int) $recipe->site_id)->toBe($site->id);
    expect($recipe->is_active)->toBeTrue();
    expect($recipe->ingredients()->count())->toBe(1);
});

it('forces site_id to null for a shared recipe even when one is sent', function () {
    $site = Site::factory()->create(['type' => 'house']);

    $this->actingAs(aRecipeManager())
        ->postJson('/catering/recipes', [
            'name' => 'Shared Pumpkin Soup',
            'category' => 'Soups',
            'scope' => 'shared',
            'site_id' => $site->id, // must be ignored for shared scope
            'serves_default' => 4,
            'ingredients' => [
                ['product_id' => null, 'free_text_name' => 'Pumpkin', 'quantity' => 1, 'unit' => 'each'],
            ],
        ])
        ->assertOk();

    $recipe = MealRecipe::where('name', 'Shared Pumpkin Soup')->first();
    expect($recipe->scope)->toBe('shared');
    expect($recipe->site_id)->toBeNull();
});

it('updates category and scope from the planner without wiping the description', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $recipe = MealRecipe::create([
        'name' => 'Old Name',
        'description' => 'Keep this description',
        'serves_default' => 4,
        'is_active' => true,
        'scope' => 'shared',
    ]);

    $this->actingAs(aRecipeManager())
        ->putJson("/catering/recipes/{$recipe->id}", [
            'name' => 'New Name',
            'category' => 'Baking',
            'scope' => 'house',
            'site_id' => $site->id,
            'serves_default' => 4,
            'is_active' => true,
            'ingredients' => [
                ['product_id' => null, 'free_text_name' => 'Flour', 'quantity' => 1, 'unit' => 'kg'],
            ],
            // intentionally NO 'description' key — the planner dialog never sends it
        ])
        ->assertOk();

    $recipe->refresh();
    expect($recipe->name)->toBe('New Name');
    expect($recipe->category)->toBe('Baking');
    expect($recipe->scope)->toBe('house');
    expect((int) $recipe->site_id)->toBe($site->id);
    expect($recipe->description)->toBe('Keep this description');
});

it('preserves category and scope when the legacy form omits them', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $recipe = MealRecipe::create([
        'name' => 'House Pie',
        'description' => 'Old description',
        'category' => 'Mains',
        'serves_default' => 4,
        'is_active' => true,
        'scope' => 'house',
        'site_id' => $site->id,
    ]);

    $this->actingAs(aRecipeManager())
        ->putJson("/catering/recipes/{$recipe->id}", [
            'name' => 'House Pie',
            'description' => 'New description',
            'serves_default' => 4,
            'is_active' => true,
            'ingredients' => [],
            // legacy library page sends no category / scope / site_id
        ])
        ->assertOk();

    $recipe->refresh();
    expect($recipe->description)->toBe('New description');
    expect($recipe->category)->toBe('Mains');
    expect($recipe->scope)->toBe('house');
    expect((int) $recipe->site_id)->toBe($site->id);
});

it('soft-deletes a recipe and returns json for the axios dialog', function () {
    $recipe = MealRecipe::create(['name' => 'Trash Me', 'serves_default' => 1, 'is_active' => true]);

    $this->actingAs(aRecipeManager())
        ->deleteJson("/catering/recipes/{$recipe->id}")
        ->assertOk()
        ->assertJson(['deleted' => true]);

    expect(MealRecipe::withTrashed()->find($recipe->id)->trashed())->toBeTrue();
});

it('redirects the legacy recipe library pages into the meal planner', function () {
    $recipe = MealRecipe::create(['name' => 'Legacy Page Recipe', 'serves_default' => 4, 'is_active' => true]);
    $manager = aRecipeManager();

    $this->actingAs($manager)->get('/catering/recipes')->assertRedirect(route('catering.meal-planner'));
    $this->actingAs($manager)->get('/catering/recipes/create')->assertRedirect(route('catering.meal-planner'));
    $this->actingAs($manager)->get("/catering/recipes/{$recipe->id}")->assertRedirect(route('catering.meal-planner'));
    $this->actingAs($manager)->get("/catering/recipes/{$recipe->id}/edit")->assertRedirect(route('catering.meal-planner'));
});

it('still serves the editable recipe payload as json for the in-planner editor', function () {
    $recipe = MealRecipe::create(['name' => 'JSON Recipe', 'serves_default' => 4, 'is_active' => true]);

    $this->actingAs(aRecipeManager())
        ->getJson("/catering/recipes/{$recipe->id}/edit")
        ->assertOk()
        ->assertJsonStructure(['recipe' => ['id', 'name']]);
});
