<?php

use App\Domain\Clinical\Models\ClientMealRestriction;
use App\Models\Client;
use App\Models\ClientMealDislike;
use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\MealRecipeIngredient;
use App\Models\Site;
use App\Models\User;
use App\Services\Catering\DietaryConflictChecker;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function makeClient(string $first = 'Test', string $last = 'Resident'): Client
{
    return Client::create([
        'first_name' => $first,
        'last_name' => $last,
        'status' => 'active',
        'site_id' => Site::factory()->create(['type' => 'house'])->id,
    ]);
}

function authoriseCheckerRestriction(Client $client, array $allergenTagIds = []): ClientMealRestriction
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
        'allergen_tag_ids' => $allergenTagIds,
        'dietary_tag_ids' => [],
        'amendment_reason' => 'Clinically verified conflict-checker fixture.',
    ]);
    $restriction->content_hash = $restriction->calculateContentHash();
    $restriction->save();

    return $restriction;
}

function makeTag(string $key, string $kind = 'allergen', string $severity = 'critical'): MealDietaryTag
{
    return MealDietaryTag::create([
        'key' => $key.'_'.uniqid(),
        'label' => ucfirst($key),
        'kind' => $kind,
        'severity' => $severity,
    ]);
}

function makeRecipeWithIngredient(?MealProduct $product = null, ?MealDietaryTag $directTag = null): MealRecipe
{
    $recipe = MealRecipe::create([
        'name' => 'Test recipe '.uniqid(),
        'serves_default' => 4,
        'is_active' => true,
    ]);
    if ($directTag) {
        $recipe->tags()->attach($directTag->id);
    }
    if ($product) {
        MealRecipeIngredient::create([
            'recipe_id' => $recipe->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit' => 'each',
        ]);
    }

    return $recipe;
}

it('produces an empty report when no clients are attached', function () {
    $checker = app(DietaryConflictChecker::class);
    $recipe = makeRecipeWithIngredient();

    $report = $checker->checkRecipeAgainstClients($recipe, []);

    expect($report['has_hard_blocks'])->toBeFalse();
    expect($report['has_soft_warnings'])->toBeFalse();
    expect($report['hard_blocks'])->toBeEmpty();
});

it('flags an allergen tag carried by a recipe ingredient as a hard block', function () {
    $checker = app(DietaryConflictChecker::class);

    $glutenTag = makeTag('gluten', 'allergen', 'critical');
    $pasta = MealProduct::create(['name' => 'Pasta', 'default_unit' => 'each']);
    $pasta->tags()->attach($glutenTag->id);
    $recipe = makeRecipeWithIngredient($pasta);

    $client = makeClient('Mila', 'Singh');
    authoriseCheckerRestriction($client, [$glutenTag->id]);

    $report = $checker->checkRecipeAgainstClients($recipe, [$client->id]);

    expect($report['has_hard_blocks'])->toBeTrue();
    expect($report['hard_blocks'])->toHaveCount(1);
    expect($report['hard_blocks'][0]['client_name'])->toBe('Mila Singh');
    expect($report['hard_blocks'][0]['matches'][0]['severity'])->toBe('critical');
});

it('flags a recipe-level allergen tag (no ingredient required)', function () {
    $checker = app(DietaryConflictChecker::class);
    $dairyTag = makeTag('dairy', 'allergen', 'critical');
    $recipe = makeRecipeWithIngredient(null, $dairyTag);

    $client = makeClient();
    authoriseCheckerRestriction($client, [$dairyTag->id]);

    $report = $checker->checkRecipeAgainstClients($recipe, [$client->id]);

    expect($report['has_hard_blocks'])->toBeTrue();
    expect($report['hard_blocks'][0]['matches'][0]['source'])->toBe('recipe_tag');
});

it('flags a free-text dislike that matches an ingredient name (substring)', function () {
    $checker = app(DietaryConflictChecker::class);
    $beef = MealProduct::create(['name' => 'Beef Mince (500g)', 'default_unit' => 'each']);
    $recipe = makeRecipeWithIngredient($beef);
    $recipe->update(['name' => 'Spaghetti Bolognese']);

    $client = makeClient('Wiremu', 'Tait');
    authoriseCheckerRestriction($client);
    ClientMealDislike::create([
        'client_id' => $client->id,
        'free_text_name' => 'beef',
    ]);

    $report = $checker->checkRecipeAgainstClients($recipe, [$client->id]);

    expect($report['has_hard_blocks'])->toBeFalse();
    expect($report['has_soft_warnings'])->toBeTrue();
    expect($report['soft_warnings'][0]['matches'][0]['kind'])->toBe('dislike');
});

it('flags a product-linked dislike when the recipe uses that product', function () {
    $checker = app(DietaryConflictChecker::class);
    $tuna = MealProduct::create(['name' => 'Tinned Tuna', 'default_unit' => 'each']);
    $recipe = makeRecipeWithIngredient($tuna);

    $client = makeClient();
    authoriseCheckerRestriction($client);
    ClientMealDislike::create([
        'client_id' => $client->id,
        'product_id' => $tuna->id,
    ]);

    $report = $checker->checkRecipeAgainstClients($recipe, [$client->id]);

    expect($report['has_soft_warnings'])->toBeTrue();
    expect($report['soft_warnings'][0]['matches'][0]['kind'])->toBe('dislike');
});

it('does not match a substring miss', function () {
    $checker = app(DietaryConflictChecker::class);
    $apples = MealProduct::create(['name' => 'Apples', 'default_unit' => 'kg']);
    $recipe = makeRecipeWithIngredient($apples);
    $recipe->update(['name' => 'Apple crumble']);

    $client = makeClient();
    authoriseCheckerRestriction($client);
    ClientMealDislike::create([
        'client_id' => $client->id,
        'free_text_name' => 'mushrooms', // not anywhere in the recipe
    ]);

    $report = $checker->checkRecipeAgainstClients($recipe, [$client->id]);

    expect($report['has_soft_warnings'])->toBeFalse();
});

it('combines hard block + soft warning when both apply to different clients', function () {
    $checker = app(DietaryConflictChecker::class);

    $glutenTag = makeTag('gluten', 'allergen', 'critical');
    $beef = MealProduct::create(['name' => 'Beef Mince', 'default_unit' => 'each']);
    $pasta = MealProduct::create(['name' => 'Pasta', 'default_unit' => 'each']);
    $pasta->tags()->attach($glutenTag->id);

    $recipe = MealRecipe::create(['name' => 'Bolognese', 'serves_default' => 4, 'is_active' => true]);
    foreach ([$beef, $pasta] as $p) {
        MealRecipeIngredient::create(['recipe_id' => $recipe->id, 'product_id' => $p->id, 'quantity' => 1, 'unit' => 'each']);
    }

    $mila = makeClient('Mila', 'Singh');
    authoriseCheckerRestriction($mila, [$glutenTag->id]);
    $wiremu = makeClient('Wiremu', 'Tait');
    authoriseCheckerRestriction($wiremu);
    ClientMealDislike::create(['client_id' => $wiremu->id, 'free_text_name' => 'beef']);

    $report = $checker->checkRecipeAgainstClients($recipe, [$mila->id, $wiremu->id]);

    expect($report['has_hard_blocks'])->toBeTrue();
    expect($report['has_soft_warnings'])->toBeTrue();
    expect($report['hard_blocks'])->toHaveCount(1);
    expect($report['soft_warnings'])->toHaveCount(1);
});
