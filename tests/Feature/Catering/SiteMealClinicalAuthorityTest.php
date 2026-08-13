<?php

use App\Domain\Clinical\Models\ClientMealRestriction;
use App\Domain\Clinical\Models\ClientMealRestrictionDiscrepancy;
use App\Domain\Clinical\Services\ClientMealRestrictionProjection;
use App\Domain\Clinical\Services\ClientMealRestrictionService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMealDislike;
use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\MealRecipeIngredient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteMealInventoryItem;
use App\Models\SiteMealInventoryMovement;
use App\Models\SiteMealPlanEntry;
use App\Models\SiteMealWeekTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CateringPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-14 10:00:00');
    $this->seed(RbacSeeder::class);
    $this->seed(CateringPermissionsSeeder::class);

    $this->siteA = Site::factory()->create([
        'name' => 'Clinical Meal Site A',
        'type' => 'house',
        'is_active' => true,
    ]);
    $this->siteB = Site::factory()->create([
        'name' => 'Clinical Meal Site B',
        'type' => 'house',
        'is_active' => true,
    ]);
    $this->clientA = Client::factory()->create(['site_id' => $this->siteA->id]);
    $this->clientB = Client::factory()->create(['site_id' => $this->siteB->id]);
    $this->planner = mealClinicalUserAtSite($this->siteA);
    $this->author = mealClinicalUserAtSite($this->siteA, ['clinical.mealRestrictions.author']);
    $this->approver = mealClinicalUserAtSite($this->siteA, ['clinical.mealRestrictions.approve']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function mealClinicalUserAtSite(Site $site, array $extraPermissions = []): User
{
    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $user->roles()->sync([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);

    foreach ($extraPermissions as $key) {
        $permission = Permission::query()->where('key', $key)->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $user->fresh();
}

function mealClinicalAuthorisedRestriction(
    Client $client,
    User $author,
    User $approver,
    array $overrides = [],
): ClientMealRestriction {
    $service = app(ClientMealRestrictionService::class);
    $latest = ClientMealRestriction::query()
        ->where('client_id', $client->id)
        ->where('status', ClientMealRestriction::STATUS_AUTHORISED)
        ->orderByDesc('version')
        ->first();
    $data = [
        'expected_current_id' => $latest?->id,
        'effective_from' => today()->subDay()->toDateString(),
        'effective_until' => null,
        'review_due_at' => today()->addYear()->toDateString(),
        'iddsi_food_level' => null,
        'fluid_iddsi_level' => null,
        'allergen_tag_ids' => [],
        'dietary_tag_ids' => [],
        'clinical_notes' => null,
        'amendment_reason' => 'Initial clinically verified meal safety record.',
        ...$overrides,
    ];

    $pending = $service->propose($client, $author, $data);

    return $service->approve($pending, $approver, (string) Str::uuid());
}

function mealClinicalRecipe(string $name, array $tagIds = [], ?int $iddsiLevel = null): MealRecipe
{
    $recipe = MealRecipe::query()->create([
        'name' => $name,
        'scope' => 'shared',
        'is_active' => true,
        'serves_default' => 4,
        'iddsi_food_level' => $iddsiLevel,
    ]);
    $recipe->tags()->sync($tagIds);

    return $recipe;
}

test('Site A catering receives an authorised read-only projection and cannot rewrite clinical restrictions', function (): void {
    $allergen = MealDietaryTag::query()->create([
        'key' => 'clin_peanut',
        'label' => 'Peanuts',
        'kind' => 'allergen',
        'severity' => 'critical',
    ]);
    $restriction = mealClinicalAuthorisedRestriction($this->clientA, $this->author, $this->approver, [
        'iddsi_food_level' => 5,
        'fluid_iddsi_level' => 2,
        'allergen_tag_ids' => [$allergen->id],
    ]);

    $bootstrap = $this->actingAs($this->planner)
        ->getJson("/sites/{$this->siteA->id}/meal-planner/bootstrap")
        ->assertOk();

    expect(collect($bootstrap->json('clients'))->pluck('id')->all())->toBe([$this->clientA->id]);
    $bootstrap->assertJsonPath('clients.0.allergens.0', 'Peanuts')
        ->assertJsonPath('clients.0.texture.level', 5)
        ->assertJsonPath('clients.0.fluids', 'Mildly thick')
        ->assertJsonPath('clients.0.restriction_authority.status', 'authorised')
        ->assertJsonPath('clients.0.restriction_authority.restriction_id', $restriction->id);

    $this->actingAs($this->planner)
        ->getJson("/sites/{$this->siteB->id}/meal-planner/bootstrap")
        ->assertForbidden();

    $this->actingAs($this->planner)
        ->putJson("/sites/{$this->siteA->id}/meal-planner/residents/{$this->clientA->id}", [
            'tag_ids' => [$allergen->id],
            'iddsi_level' => 7,
            'iddsi_label' => 'Regular',
            'fluids' => 'Thin',
            'dislikes' => ['mushrooms'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tag_ids', 'iddsi_level', 'iddsi_label', 'fluids']);

    expect(ClientMealDislike::query()->where('client_id', $this->clientA->id)->count())->toBe(0)
        ->and($restriction->fresh()->content_hash)->toBe($restriction->content_hash);

    $this->actingAs($this->planner)
        ->putJson("/sites/{$this->siteA->id}/meal-planner/residents/{$this->clientA->id}", [
            'dislikes' => ['mushrooms'],
        ])
        ->assertOk();
    expect(ClientMealDislike::query()->where('client_id', $this->clientA->id)->count())->toBe(1);

    $this->actingAs($this->planner)
        ->putJson("/sites/{$this->siteA->id}/meal-planner/residents/{$this->clientB->id}", [
            'dislikes' => ['forged'],
        ])
        ->assertNotFound();

    $this->actingAs($this->planner)
        ->putJson("/clients/{$this->clientA->id}/meal-preferences/tags", [
            'tag_ids' => [],
        ])
        ->assertConflict();
    expect($this->clientA->mealDietaryTags()->count())->toBe(0);
});

test('wrong-Site and unqualified clinical mutations are denied before side effects', function (): void {
    $payload = [
        'expected_current_id' => null,
        'effective_from' => today()->toDateString(),
        'effective_until' => null,
        'review_due_at' => today()->addMonths(6)->toDateString(),
        'allergen_tag_ids' => [],
        'dietary_tag_ids' => [],
        'amendment_reason' => 'Clinically assessed initial restriction record.',
    ];

    $this->actingAs($this->planner)
        ->postJson("/sites/{$this->siteA->id}/clinical-meal-restrictions/residents/{$this->clientA->id}", $payload)
        ->assertForbidden();

    $this->actingAs($this->author)
        ->postJson("/sites/{$this->siteA->id}/clinical-meal-restrictions/residents/{$this->clientB->id}", $payload)
        ->assertNotFound();

    $this->actingAs($this->author)
        ->postJson("/sites/{$this->siteB->id}/clinical-meal-restrictions/residents/{$this->clientB->id}", $payload)
        ->assertForbidden();

    expect(ClientMealRestriction::query()->count())->toBe(0);
});

test('qualified independent approval is mandatory, immutable and replay safe', function (): void {
    $authorAndApprover = mealClinicalUserAtSite($this->siteA, [
        'clinical.mealRestrictions.author',
        'clinical.mealRestrictions.approve',
    ]);
    $payload = [
        'expected_current_id' => null,
        'effective_from' => today()->toDateString(),
        'effective_until' => null,
        'review_due_at' => today()->addMonths(6)->toDateString(),
        'iddsi_food_level' => 6,
        'fluid_iddsi_level' => 1,
        'allergen_tag_ids' => [],
        'dietary_tag_ids' => [],
        'clinical_notes' => 'SLT plan checked against signed instructions.',
        'amendment_reason' => 'Initial restriction entered from the signed clinical plan.',
    ];

    $created = $this->actingAs($authorAndApprover)
        ->postJson("/sites/{$this->siteA->id}/clinical-meal-restrictions/residents/{$this->clientA->id}", $payload)
        ->assertCreated()
        ->assertJsonPath('restriction.status', 'pending');
    $restrictionId = $created->json('restriction.id');
    $selfKey = (string) Str::uuid();

    $this->actingAs($authorAndApprover)
        ->postJson("/sites/{$this->siteA->id}/clinical-meal-restrictions/residents/{$this->clientA->id}/{$restrictionId}/approve", [
            'idempotency_key' => $selfKey,
        ])
        ->assertUnprocessable();
    expect(ClientMealRestriction::query()->findOrFail($restrictionId)->status)->toBe('pending');

    $replayKey = (string) Str::uuid();
    $this->actingAs($this->approver)
        ->postJson("/sites/{$this->siteA->id}/clinical-meal-restrictions/residents/{$this->clientA->id}/{$restrictionId}/approve", [
            'idempotency_key' => $replayKey,
        ])
        ->assertOk()
        ->assertJsonPath('restriction.status', 'authorised')
        ->assertJsonPath('restriction.approved_by.id', $this->approver->id);

    $this->actingAs($this->approver)
        ->postJson("/sites/{$this->siteA->id}/clinical-meal-restrictions/residents/{$this->clientA->id}/{$restrictionId}/approve", [
            'idempotency_key' => $replayKey,
        ])
        ->assertOk();
    expect(ClientMealRestriction::query()->count())->toBe(1);

    $this->actingAs($this->approver)
        ->postJson("/sites/{$this->siteA->id}/clinical-meal-restrictions/residents/{$this->clientA->id}/{$restrictionId}/approve", [
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertConflict();

    $restriction = ClientMealRestriction::query()->findOrFail($restrictionId);
    expect(fn () => $restriction->update(['clinical_notes' => 'silently changed']))
        ->toThrow(LogicException::class);
});

test('pending amendment concurrency is rejected against the same authorised version', function (): void {
    $current = mealClinicalAuthorisedRestriction($this->clientA, $this->author, $this->approver);
    $payload = [
        'expected_current_id' => $current->id,
        'effective_from' => today()->addDay()->toDateString(),
        'effective_until' => null,
        'review_due_at' => today()->addMonths(6)->toDateString(),
        'allergen_tag_ids' => [],
        'dietary_tag_ids' => [],
        'amendment_reason' => 'First concurrent amendment request for clinical review.',
    ];

    $this->actingAs($this->author)
        ->postJson("/sites/{$this->siteA->id}/clinical-meal-restrictions/residents/{$this->clientA->id}", $payload)
        ->assertCreated();
    $this->actingAs($this->author)
        ->postJson("/sites/{$this->siteA->id}/clinical-meal-restrictions/residents/{$this->clientA->id}", $payload)
        ->assertConflict();

    expect(ClientMealRestriction::query()
        ->where('client_id', $this->clientA->id)
        ->where('status', 'pending')
        ->count())->toBe(1);
});

test('effective future expired and stale restrictions fail closed on the meal date', function (): void {
    $futureClient = $this->clientA;
    $expiredClient = Client::factory()->create(['site_id' => $this->siteA->id]);
    $staleClient = Client::factory()->create(['site_id' => $this->siteA->id]);
    mealClinicalAuthorisedRestriction($futureClient, $this->author, $this->approver, [
        'effective_from' => today()->addDay()->toDateString(),
        'review_due_at' => today()->addMonths(6)->toDateString(),
    ]);
    mealClinicalAuthorisedRestriction($expiredClient, $this->author, $this->approver, [
        'effective_from' => today()->subMonths(2)->toDateString(),
        'review_due_at' => today()->addMonths(6)->toDateString(),
        'amendment_reason' => 'Previous restriction superseded by a later amendment.',
    ]);
    mealClinicalAuthorisedRestriction($expiredClient, $this->author, $this->approver, [
        'effective_from' => today()->subMonth()->toDateString(),
        'effective_until' => today()->subDay()->toDateString(),
        'review_due_at' => today()->subDay()->toDateString(),
    ]);
    mealClinicalAuthorisedRestriction($staleClient, $this->author, $this->approver, [
        'effective_from' => today()->subMonth()->toDateString(),
        'review_due_at' => today()->subDay()->toDateString(),
    ]);
    $recipe = mealClinicalRecipe('Lifecycle-safe regular meal');

    foreach ([$futureClient, $expiredClient, $staleClient] as $client) {
        $this->actingAs($this->planner)
            ->postJson("/sites/{$this->siteA->id}/meal-plan", [
                'plan_date' => today()->toDateString(),
                'meal_slot' => 'lunch',
                'source_type' => 'recipe',
                'recipe_id' => $recipe->id,
                'servings' => 4,
                'client_ids' => [$client->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_ids');
    }

    $this->actingAs($this->planner)
        ->postJson("/sites/{$this->siteA->id}/meal-plan", [
            'plan_date' => today()->addDay()->toDateString(),
            'meal_slot' => 'lunch',
            'source_type' => 'recipe',
            'recipe_id' => $recipe->id,
            'servings' => 4,
            'client_ids' => [$futureClient->id],
        ])
        ->assertOk();

    $projection = app(ClientMealRestrictionProjection::class);
    expect($projection->forClient($futureClient)['authority_status'])->toBe('not_effective')
        ->and($projection->forClient($expiredClient)['authority_status'])->toBe('expired')
        ->and($projection->forClient($staleClient)['authority_status'])->toBe('stale');
});

test('allergy dietary and IDDSI conflicts cannot be overridden by operational permissions', function (): void {
    $gluten = MealDietaryTag::query()->create([
        'key' => 'clin_gluten',
        'label' => 'Gluten',
        'kind' => 'allergen',
        'severity' => 'critical',
    ]);
    $vegetarian = MealDietaryTag::query()->create([
        'key' => 'clin_vegetarian',
        'label' => 'Vegetarian',
        'kind' => 'dietary',
        'severity' => 'warn',
    ]);
    mealClinicalAuthorisedRestriction($this->clientA, $this->author, $this->approver, [
        'iddsi_food_level' => 5,
        'allergen_tag_ids' => [$gluten->id],
        'dietary_tag_ids' => [$vegetarian->id],
    ]);

    $safe = mealClinicalRecipe('Safe IDDSI 5 vegetarian meal', [$vegetarian->id], 5);
    $allergenConflict = mealClinicalRecipe('Gluten IDDSI 5 meal', [$gluten->id, $vegetarian->id], 5);
    $iddsiConflict = mealClinicalRecipe('Wrong IDDSI vegetarian meal', [$vegetarian->id], 6);
    $dietConflict = mealClinicalRecipe('Missing vegetarian classification', [], 5);

    $formerOverride = Permission::query()->firstOrCreate([
        'key' => 'sites.meals.allergen.override',
    ], [
        'description' => 'Legacy override permission',
        'group' => 'sites',
        'module' => 'Sites',
    ]);
    $this->planner->permissionOverrides()->syncWithoutDetaching([
        $formerOverride->id => ['allowed' => true],
    ]);

    $base = [
        'plan_date' => today()->toDateString(),
        'meal_slot' => 'lunch',
        'source_type' => 'recipe',
        'servings' => 4,
        'client_ids' => [$this->clientA->id],
        'allergen_override_reason' => 'A legacy manager supplied an override reason.',
    ];

    $this->actingAs($this->planner)
        ->postJson("/sites/{$this->siteA->id}/meal-plan", [...$base, 'recipe_id' => $safe->id])
        ->assertOk();

    foreach ([$allergenConflict, $iddsiConflict, $dietConflict] as $recipe) {
        $count = SiteMealPlanEntry::query()->count();
        $this->actingAs($this->planner)
            ->postJson("/sites/{$this->siteA->id}/meal-plan", [...$base, 'recipe_id' => $recipe->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_ids');
        expect(SiteMealPlanEntry::query()->count())->toBe($count);
    }

    expect(SiteMealPlanEntry::query()->firstOrFail()->allergen_override_at)->toBeNull();
});

test('discrepancy reporting is Site-scoped and idempotent without changing authority', function (): void {
    $restriction = mealClinicalAuthorisedRestriction($this->clientA, $this->author, $this->approver);
    $key = (string) Str::uuid();
    $details = 'Kitchen copy shows different fluid instructions from the authorised record.';

    $this->actingAs($this->planner)
        ->postJson("/sites/{$this->siteA->id}/meal-planner/residents/{$this->clientA->id}/restriction-discrepancies", [
            'details' => $details,
            'idempotency_key' => $key,
        ])
        ->assertCreated();
    $this->actingAs($this->planner)
        ->postJson("/sites/{$this->siteA->id}/meal-planner/residents/{$this->clientA->id}/restriction-discrepancies", [
            'details' => $details,
            'idempotency_key' => $key,
        ])
        ->assertOk();

    expect(ClientMealRestrictionDiscrepancy::query()->count())->toBe(1)
        ->and(ClientMealRestrictionDiscrepancy::query()->firstOrFail()->restriction_id)->toBe($restriction->id)
        ->and($restriction->fresh()->content_hash)->toBe($restriction->content_hash);

    $this->actingAs($this->planner)
        ->postJson("/sites/{$this->siteA->id}/meal-planner/residents/{$this->clientA->id}/restriction-discrepancies", [
            'details' => 'Different details with a replayed key.',
            'idempotency_key' => $key,
        ])
        ->assertConflict();
    $this->actingAs($this->planner)
        ->postJson("/sites/{$this->siteA->id}/meal-planner/residents/{$this->clientB->id}/restriction-discrepancies", [
            'details' => $details,
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertNotFound();
    $this->actingAs($this->planner)
        ->postJson("/sites/{$this->siteB->id}/meal-planner/residents/{$this->clientB->id}/restriction-discrepancies", [
            'details' => $details,
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertForbidden();
    expect(ClientMealRestrictionDiscrepancy::query()->count())->toBe(1);
});

test('serve and template replacement leave no partial side effects when authority is unsafe', function (): void {
    mealClinicalAuthorisedRestriction($this->clientA, $this->author, $this->approver, [
        'effective_from' => today()->subMonth()->toDateString(),
        'review_due_at' => today()->subDay()->toDateString(),
    ]);
    $product = MealProduct::query()->create([
        'name' => 'Atomic stock product',
        'default_unit' => 'each',
        'is_active' => true,
    ]);
    $recipe = mealClinicalRecipe('Atomic serve recipe');
    MealRecipeIngredient::query()->create([
        'recipe_id' => $recipe->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit' => 'each',
        'sort_order' => 0,
    ]);
    $inventory = SiteMealInventoryItem::query()->create([
        'site_id' => $this->siteA->id,
        'product_id' => $product->id,
        'current_qty' => 10,
        'unit' => 'each',
    ]);
    $entry = SiteMealPlanEntry::query()->create([
        'site_id' => $this->siteA->id,
        'plan_date' => today()->toDateString(),
        'meal_slot' => 'lunch',
        'source_type' => 'recipe',
        'recipe_id' => $recipe->id,
        'servings' => 4,
        'client_ids' => [$this->clientA->id],
    ]);

    $this->actingAs($this->planner)
        ->postJson("/sites/{$this->siteA->id}/meal-plan/{$entry->id}/serve")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('client_ids');
    expect($entry->fresh()->served_at)->toBeNull()
        ->and((float) $inventory->fresh()->current_qty)->toBe(10.0)
        ->and(SiteMealInventoryMovement::query()->count())->toBe(0);

    $existingDestination = SiteMealPlanEntry::query()->create([
        'site_id' => $this->siteA->id,
        'plan_date' => '2026-08-17',
        'meal_slot' => 'breakfast',
        'source_type' => 'ad_hoc',
        'ad_hoc_name' => 'Keep this meal',
        'servings' => 1,
        'client_ids' => [],
    ]);
    $template = SiteMealWeekTemplate::query()->create([
        'site_id' => $this->siteA->id,
        'name' => 'Unsafe replacement template',
        'is_starter' => false,
        'meals' => [[
            'day' => 0,
            'slot' => 'dinner',
            'recipe_id' => $recipe->id,
            'servings' => 4,
        ]],
    ]);

    $this->actingAs($this->planner)
        ->postJson("/sites/{$this->siteA->id}/meal-templates/{$template->id}/apply", [
            'week' => '2026-08-17',
            'replace' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('template');

    expect(SiteMealPlanEntry::query()->whereKey($existingDestination->id)->exists())->toBeTrue()
        ->and(SiteMealPlanEntry::query()
            ->where('site_id', $this->siteA->id)
            ->whereDate('plan_date', '2026-08-17')
            ->count())->toBe(1);
});
