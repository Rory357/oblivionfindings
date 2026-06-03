<?php

use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->seed(\Database\Seeders\CateringPermissionsSeeder::class);
});

function aCateringLibraryManager(): User
{
    $user = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now()]);
    $adminRole = Role::where('name', 'admin')->first();
    if ($adminRole) {
        $user->roles()->syncWithoutDetaching([$adminRole->id]);
        foreach (['catering.products.view', 'catering.products.manage', 'catering.tags.view', 'catering.tags.manage'] as $key) {
            $perm = Permission::where('key', $key)->first();
            if ($perm && ! $adminRole->permissions()->where('permissions.id', $perm->id)->exists()) {
                $adminRole->permissions()->attach($perm->id);
            }
        }
    }

    return $user;
}

it('returns the product catalogue as json for the in-planner manager', function () {
    $tag = MealDietaryTag::create(['key' => 'vegan_x', 'label' => 'Vegan', 'kind' => 'dietary', 'severity' => 'info']);
    $product = MealProduct::create(['name' => 'Tofu', 'category' => 'protein', 'default_unit' => 'each', 'currency' => 'NZD', 'is_active' => true]);
    $product->tags()->attach($tag->id);

    $this->actingAs(aCateringLibraryManager())
        ->getJson('/catering/products')
        ->assertOk()
        ->assertJsonStructure(['products' => [['id', 'name', 'tags']], 'categories', 'tags']);
});

it('creates a product with tags via json', function () {
    $tag = MealDietaryTag::create(['key' => 'gf_x', 'label' => 'Gluten Free', 'kind' => 'dietary', 'severity' => 'info']);

    $this->actingAs(aCateringLibraryManager())
        ->postJson('/catering/products', [
            'name' => 'Rice Noodles',
            'category' => 'pantry',
            'default_unit' => 'each',
            'pack_size' => 400,
            'pack_unit' => 'g',
            'cost_per_unit_cents' => 350,
            'currency' => 'NZD',
            'is_active' => true,
            'tag_ids' => [$tag->id],
        ])
        ->assertOk();

    $product = MealProduct::where('name', 'Rice Noodles')->first();
    expect($product)->not->toBeNull();
    expect($product->cost_per_unit_cents)->toBe(350);
    expect($product->tags()->count())->toBe(1);
});

it('updates a product via json', function () {
    $product = MealProduct::create(['name' => 'Old Product', 'default_unit' => 'each', 'currency' => 'NZD', 'is_active' => true]);

    $this->actingAs(aCateringLibraryManager())
        ->putJson("/catering/products/{$product->id}", [
            'name' => 'Renamed Product',
            'default_unit' => 'kg',
            'is_active' => false,
        ])
        ->assertOk();

    $product->refresh();
    expect($product->name)->toBe('Renamed Product');
    expect($product->default_unit)->toBe('kg');
    expect($product->is_active)->toBeFalse();
});

it('archives a product via json', function () {
    $product = MealProduct::create(['name' => 'Archive Me', 'default_unit' => 'each', 'currency' => 'NZD', 'is_active' => true]);

    $this->actingAs(aCateringLibraryManager())
        ->deleteJson("/catering/products/{$product->id}")
        ->assertOk()
        ->assertJson(['deleted' => true]);

    expect(MealProduct::withTrashed()->find($product->id)->trashed())->toBeTrue();
});

it('returns dietary tags as json and supports create / update / delete', function () {
    $this->actingAs(aCateringLibraryManager())
        ->getJson('/catering/tags')
        ->assertOk()
        ->assertJsonStructure(['tags']);

    $this->actingAs(aCateringLibraryManager())
        ->postJson('/catering/tags', [
            'label' => 'Coeliac Safe',
            'kind' => 'dietary',
            'severity' => 'warn',
            'color' => '#a16207',
        ])
        ->assertOk();

    $tag = MealDietaryTag::where('label', 'Coeliac Safe')->first();
    expect($tag)->not->toBeNull();
    expect($tag->key)->toBe('coeliac_safe'); // auto-slugged from the label

    $this->actingAs(aCateringLibraryManager())
        ->putJson("/catering/tags/{$tag->id}", [
            'label' => 'Coeliac Safe',
            'kind' => 'allergen',
            'severity' => 'critical',
        ])
        ->assertOk();
    expect($tag->fresh()->kind)->toBe('allergen');

    $this->actingAs(aCateringLibraryManager())
        ->deleteJson("/catering/tags/{$tag->id}")
        ->assertOk()
        ->assertJson(['deleted' => true]);
    expect(MealDietaryTag::find($tag->id))->toBeNull();
});

it('redirects the legacy product and tag library pages into the meal planner', function () {
    $manager = aCateringLibraryManager();

    $this->actingAs($manager)->get('/catering/products')->assertRedirect(route('catering.meal-planner'));
    $this->actingAs($manager)->get('/catering/tags')->assertRedirect(route('catering.meal-planner'));
});
