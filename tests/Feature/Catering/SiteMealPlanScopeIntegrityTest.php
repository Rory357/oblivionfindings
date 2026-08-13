<?php

use App\Domain\Clinical\Models\ClientMealRestriction;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\MealDietaryTag;
use App\Models\MealRecipe;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteMealPlanEntry;
use App\Models\SiteMealWeekTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CateringPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-14 10:00:00');
    $this->seed(RbacSeeder::class);
    $this->seed(CateringPermissionsSeeder::class);

    $this->scopeSiteA = Site::factory()->create([
        'name' => 'Catering Scope Site A',
        'type' => 'house',
        'is_active' => true,
    ]);
    $this->scopeSiteB = Site::factory()->create([
        'name' => 'Catering Scope Site B',
        'type' => 'house',
        'is_active' => true,
    ]);
    $this->scopePlanner = caterScopePlanner($this->scopeSiteA);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function caterScopePlanner(Site $site, bool $allSites = false): User
{
    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $user->roles()->sync([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    if ($allSites) {
        $permission = Permission::query()->where('key', 'sites.viewAll')->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    return $user->fresh();
}

function caterScopeResident(Site $site, string $status = 'active'): Client
{
    return Client::factory()->create([
        'site_id' => $site->id,
        'status' => $status,
    ]);
}

function caterScopeAuthorise(Client $client, array $allergenTagIds = []): ClientMealRestriction
{
    $restriction = new ClientMealRestriction([
        'site_id' => $client->site_id,
        'client_id' => $client->id,
        'version' => 1,
        'status' => ClientMealRestriction::STATUS_AUTHORISED,
        'proposed_by' => User::factory()->create()->id,
        'proposed_at' => now(),
        'approved_by' => User::factory()->create()->id,
        'approved_at' => now(),
        'effective_from' => today()->subDay(),
        'review_due_at' => today()->addYear(),
        'allergen_tag_ids' => $allergenTagIds,
        'dietary_tag_ids' => [],
        'amendment_reason' => 'Clinically verified catering scope fixture.',
    ]);
    $restriction->content_hash = $restriction->calculateContentHash();
    $restriction->save();

    return $restriction;
}

function caterScopeRecipe(string $name, string $scope = 'shared', ?Site $site = null, array $tagIds = []): MealRecipe
{
    $recipe = MealRecipe::query()->create([
        'name' => $name,
        'scope' => $scope,
        'site_id' => $site?->id,
        'is_active' => true,
        'serves_default' => 4,
    ]);
    $recipe->tags()->sync($tagIds);

    return $recipe;
}

function caterScopePayload(int $recipeId, array $residentIds, array $overrides = []): array
{
    return [
        'plan_date' => '2026-08-17',
        'meal_slot' => 'lunch',
        'source_type' => 'recipe',
        'recipe_id' => $recipeId,
        'servings' => 4,
        'client_ids' => $residentIds,
        ...$overrides,
    ];
}

test('route Site authority conceals foreign and inactive residents and private recipes while preserving shared and local positives', function (): void {
    $activeA = caterScopeResident($this->scopeSiteA);
    $inactiveA = caterScopeResident($this->scopeSiteA, 'inactive');
    $dischargedA = caterScopeResident($this->scopeSiteA, 'discharged');
    $activeB = caterScopeResident($this->scopeSiteB);
    caterScopeAuthorise($activeA);
    caterScopeAuthorise($activeB);

    $shared = caterScopeRecipe('Shared canonical recipe');
    $privateA = caterScopeRecipe('Site A private recipe', 'house', $this->scopeSiteA);
    $privateB = caterScopeRecipe('Site B private recipe', 'house', $this->scopeSiteB);

    $bootstrap = $this->actingAs($this->scopePlanner)
        ->getJson("/sites/{$this->scopeSiteA->id}/meal-planner/bootstrap")
        ->assertOk();
    expect(collect($bootstrap->json('clients'))->pluck('id')->all())->toBe([$activeA->id])
        ->and(collect($bootstrap->json('recipes'))->pluck('id')->all())
        ->toEqualCanonicalizing([$shared->id, $privateA->id]);

    $residentErrors = [];
    $auditBeforeDenials = AuditLog::query()->count();
    foreach ([$activeB, $inactiveA, $dischargedA] as $resident) {
        $response = $this->actingAs($this->scopePlanner)
            ->postJson(
                "/sites/{$this->scopeSiteA->id}/meal-plan",
                caterScopePayload($shared->id, [$resident->id]),
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_ids.0');
        $residentErrors[] = $response->json('errors.client_ids.0.0');
    }
    expect(array_unique($residentErrors))->toHaveCount(1)
        ->and(SiteMealPlanEntry::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($auditBeforeDenials);

    $privateError = $this->actingAs($this->scopePlanner)
        ->postJson(
            "/sites/{$this->scopeSiteA->id}/meal-plan",
            caterScopePayload($privateB->id, [$activeA->id]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recipe_id')
        ->json('errors.recipe_id.0');
    $missingError = $this->actingAs($this->scopePlanner)
        ->postJson(
            "/sites/{$this->scopeSiteA->id}/meal-plan",
            caterScopePayload(999999999, [$activeA->id]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recipe_id')
        ->json('errors.recipe_id.0');
    expect($privateError)->toBe($missingError);

    $this->actingAs($this->scopePlanner)
        ->postJson(
            "/sites/{$this->scopeSiteA->id}/meal-planner/check-conflicts",
            ['recipe_id' => $privateB->id, 'client_ids' => [$activeA->id]],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recipe_id');

    foreach ([$shared, $privateA] as $recipe) {
        $this->actingAs($this->scopePlanner)
            ->postJson(
                "/sites/{$this->scopeSiteA->id}/meal-plan",
                caterScopePayload($recipe->id, [$activeA->id]),
            )
            ->assertOk();
    }
    expect(SiteMealPlanEntry::query()->where('site_id', $this->scopeSiteA->id)->count())->toBe(2);

    $entry = SiteMealPlanEntry::query()->where('site_id', $this->scopeSiteA->id)->firstOrFail();
    $beforeUpdate = $entry->only(['recipe_id', 'client_ids', 'version']);
    $auditBeforeBadUpdates = AuditLog::query()->count();
    foreach ([
        caterScopePayload($shared->id, [$activeB->id], ['expected_version' => 1]),
        caterScopePayload($privateB->id, [$activeA->id], ['expected_version' => 1]),
    ] as $payload) {
        $this->actingAs($this->scopePlanner)
            ->putJson("/sites/{$this->scopeSiteA->id}/meal-plan/{$entry->id}", $payload)
            ->assertUnprocessable();
    }
    expect($entry->fresh()->only(['recipe_id', 'client_ids', 'version']))->toBe($beforeUpdate)
        ->and(AuditLog::query()->count())->toBe($auditBeforeBadUpdates);

    $allSitesPlanner = caterScopePlanner($this->scopeSiteA, true);
    $this->actingAs($allSitesPlanner)
        ->postJson(
            "/sites/{$this->scopeSiteB->id}/meal-plan",
            caterScopePayload($shared->id, [$activeB->id]),
        )
        ->assertOk();
    $this->actingAs($allSitesPlanner)
        ->postJson(
            "/sites/{$this->scopeSiteB->id}/meal-plan",
            caterScopePayload($privateA->id, [$activeB->id]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recipe_id');
});

test('canonical conflict resolution and optimistic versioning reject unsafe stale concurrent and replayed updates without side effects', function (): void {
    $resident = caterScopeResident($this->scopeSiteA);
    $allergen = MealDietaryTag::query()->create([
        'key' => 'cater_scope_allergen',
        'label' => 'Catering scope allergen',
        'kind' => 'allergen',
        'severity' => 'critical',
    ]);
    caterScopeAuthorise($resident, [$allergen->id]);
    $safe = caterScopeRecipe('Canonical safe recipe');
    $unsafe = caterScopeRecipe('Canonical unsafe recipe', 'shared', null, [$allergen->id]);

    $this->actingAs($this->scopePlanner)
        ->postJson(
            "/sites/{$this->scopeSiteA->id}/meal-plan",
            caterScopePayload($safe->id, [$resident->id]),
        )
        ->assertOk();
    $entry = SiteMealPlanEntry::query()->firstOrFail();
    expect($entry->version)->toBe(1);

    $auditBeforeConflict = AuditLog::query()->count();
    $this->actingAs($this->scopePlanner)
        ->putJson(
            "/sites/{$this->scopeSiteA->id}/meal-plan/{$entry->id}",
            caterScopePayload($unsafe->id, [$resident->id], ['expected_version' => 1]),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('client_ids');
    expect($entry->fresh()->recipe_id)->toBe($safe->id)
        ->and($entry->fresh()->version)->toBe(1)
        ->and(AuditLog::query()->count())->toBe($auditBeforeConflict);

    $firstUpdate = caterScopePayload($safe->id, [$resident->id], [
        'expected_version' => 1,
        'notes' => 'First accepted update',
    ]);
    $this->actingAs($this->scopePlanner)
        ->putJson("/sites/{$this->scopeSiteA->id}/meal-plan/{$entry->id}", $firstUpdate)
        ->assertOk();
    expect($entry->fresh()->version)->toBe(2)
        ->and($entry->fresh()->notes)->toBe('First accepted update');

    $this->actingAs($this->scopePlanner)
        ->postJson("/sites/{$this->scopeSiteA->id}/meal-plan/{$entry->id}/serve")
        ->assertOk();
    expect($entry->fresh()->version)->toBe(3)
        ->and($entry->fresh()->served_at)->not->toBeNull();

    $auditAfterServe = AuditLog::query()->count();
    foreach ([
        $firstUpdate,
        [...$firstUpdate, 'expected_version' => 2, 'notes' => 'Concurrent writer payload'],
    ] as $stalePayload) {
        $this->actingAs($this->scopePlanner)
            ->putJson("/sites/{$this->scopeSiteA->id}/meal-plan/{$entry->id}", $stalePayload)
            ->assertStatus(409)
            ->assertJsonValidationErrors('expected_version');
    }
    expect($entry->fresh()->version)->toBe(3)
        ->and($entry->fresh()->notes)->toBe('First accepted update')
        ->and(AuditLog::query()->count())->toBe($auditAfterServe);

    $this->actingAs($this->scopePlanner)
        ->postJson("/sites/{$this->scopeSiteA->id}/meal-plan/{$entry->id}/unserve")
        ->assertOk();
    expect($entry->fresh()->version)->toBe(4)
        ->and($entry->fresh()->served_at)->toBeNull();

    $this->actingAs($this->scopePlanner)
        ->getJson("/sites/{$this->scopeSiteA->id}/meal-plan?week=2026-08-17")
        ->assertOk()
        ->assertJsonPath('entries.0.version', 4);
});

test('copy and template replacement re-resolve relationships before deleting destination meals', function (): void {
    $active = caterScopeResident($this->scopeSiteA);
    caterScopeResident($this->scopeSiteA, 'inactive');
    $foreign = caterScopeResident($this->scopeSiteB);
    caterScopeAuthorise($active);
    $shared = caterScopeRecipe('Replacement shared recipe');
    $privateB = caterScopeRecipe('Replacement private recipe', 'house', $this->scopeSiteB);

    $source = SiteMealPlanEntry::query()->create([
        'site_id' => $this->scopeSiteA->id,
        'plan_date' => '2026-08-17',
        'meal_slot' => 'lunch',
        'source_type' => 'recipe',
        'recipe_id' => $privateB->id,
        'servings' => 4,
        'client_ids' => [$active->id],
    ]);
    $destination = SiteMealPlanEntry::query()->create([
        'site_id' => $this->scopeSiteA->id,
        'plan_date' => '2026-08-24',
        'meal_slot' => 'breakfast',
        'source_type' => 'ad_hoc',
        'ad_hoc_name' => 'Destination must survive',
        'servings' => 1,
        'client_ids' => [],
    ]);

    $this->actingAs($this->scopePlanner)
        ->postJson("/sites/{$this->scopeSiteA->id}/meal-plan-week/copy", [
            'from_week' => '2026-08-17',
            'to_week' => '2026-08-24',
            'replace' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recipe_id');
    expect(SiteMealPlanEntry::query()->whereKey($destination->id)->exists())->toBeTrue();

    $source->forceFill([
        'recipe_id' => $shared->id,
        'client_ids' => [$foreign->id],
    ])->save();
    $this->actingAs($this->scopePlanner)
        ->postJson("/sites/{$this->scopeSiteA->id}/meal-plan-week/copy", [
            'from_week' => '2026-08-17',
            'to_week' => '2026-08-24',
            'replace' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('client_ids.0');
    expect(SiteMealPlanEntry::query()->whereKey($destination->id)->exists())->toBeTrue();

    $invalidTemplateCount = SiteMealWeekTemplate::query()->count();
    $this->actingAs($this->scopePlanner)
        ->postJson("/sites/{$this->scopeSiteA->id}/meal-templates", [
            'name' => 'Rejected private template',
            'meals' => [[
                'day' => 0,
                'slot' => 'dinner',
                'recipe_id' => $privateB->id,
                'servings' => 4,
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('meals.0.recipe_id');
    expect(SiteMealWeekTemplate::query()->count())->toBe($invalidTemplateCount);

    $this->actingAs($this->scopePlanner)
        ->postJson("/sites/{$this->scopeSiteA->id}/meal-templates", [
            'name' => 'Mutable shared template',
            'meals' => [[
                'day' => 0,
                'slot' => 'lunch',
                'recipe_id' => $shared->id,
                'servings' => 4,
            ]],
        ])
        ->assertOk();
    $mutableTemplate = SiteMealWeekTemplate::query()
        ->where('name', 'Mutable shared template')
        ->firstOrFail();
    $this->actingAs($this->scopePlanner)
        ->putJson("/sites/{$this->scopeSiteA->id}/meal-templates/{$mutableTemplate->id}", [
            'name' => 'Injected private template',
            'meals' => [[
                'day' => 0,
                'slot' => 'lunch',
                'recipe_id' => $privateB->id,
                'servings' => 4,
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('meals.0.recipe_id');
    expect($mutableTemplate->fresh()->name)->toBe('Mutable shared template')
        ->and($mutableTemplate->fresh()->meals[0]['recipe_id'])->toBe($shared->id);

    $invalidTemplate = SiteMealWeekTemplate::query()->create([
        'site_id' => $this->scopeSiteA->id,
        'name' => 'Private foreign replacement',
        'is_starter' => false,
        'meals' => [[
            'day' => 0,
            'slot' => 'dinner',
            'recipe_id' => $privateB->id,
            'servings' => 4,
        ]],
    ]);
    $this->actingAs($this->scopePlanner)
        ->postJson("/sites/{$this->scopeSiteA->id}/meal-templates/{$invalidTemplate->id}/apply", [
            'week' => '2026-08-24',
            'replace' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('template');
    expect(SiteMealPlanEntry::query()->whereKey($destination->id)->exists())->toBeTrue();

    $sharedTemplate = SiteMealWeekTemplate::query()->create([
        'site_id' => $this->scopeSiteA->id,
        'name' => 'Shared canonical replacement',
        'is_starter' => false,
        'meals' => [[
            'day' => 0,
            'slot' => 'dinner',
            'recipe_id' => $shared->id,
            'servings' => 4,
        ]],
    ]);
    $this->actingAs($this->scopePlanner)
        ->postJson("/sites/{$this->scopeSiteA->id}/meal-templates/{$sharedTemplate->id}/apply", [
            'week' => '2026-08-24',
            'replace' => true,
        ])
        ->assertOk();

    $applied = SiteMealPlanEntry::query()
        ->where('site_id', $this->scopeSiteA->id)
        ->whereDate('plan_date', '2026-08-24')
        ->sole();
    expect($applied->recipe_id)->toBe($shared->id)
        ->and($applied->client_ids)->toBe([$active->id])
        ->and($applied->client_ids)->not->toContain($foreign->id);
});

test('an injected create failure rolls back the meal and every observable side effect', function (): void {
    $resident = caterScopeResident($this->scopeSiteA);
    caterScopeAuthorise($resident);
    $recipe = caterScopeRecipe('Atomic failure recipe');

    // Boot model observers before appending the injected failure listener so
    // the audit insert happens first and is then proven to roll back with it.
    SiteMealPlanEntry::query()->count();
    $before = [
        'plans' => SiteMealPlanEntry::query()->count(),
        'audit' => AuditLog::query()->count(),
        'inventory' => DB::table('site_meal_inventory_movements')->count(),
        'timeline' => DB::table('timeline_events')->count(),
        'notifications' => DB::table('notifications')->count(),
    ];
    Event::listen('eloquent.created: '.SiteMealPlanEntry::class, function (): void {
        throw new RuntimeException('Injected meal-plan persistence failure.');
    });

    $this->actingAs($this->scopePlanner)
        ->postJson(
            "/sites/{$this->scopeSiteA->id}/meal-plan",
            caterScopePayload($recipe->id, [$resident->id]),
        )
        ->assertInternalServerError();

    expect(SiteMealPlanEntry::query()->count())->toBe($before['plans'])
        ->and(AuditLog::query()->count())->toBe($before['audit'])
        ->and(DB::table('site_meal_inventory_movements')->count())->toBe($before['inventory'])
        ->and(DB::table('timeline_events')->count())->toBe($before['timeline'])
        ->and(DB::table('notifications')->count())->toBe($before['notifications']);
});
