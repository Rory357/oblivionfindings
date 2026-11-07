<?php

use App\Domain\Clinical\Models\ClientMealRestriction;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\MealDietaryTag;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\MealRecipeIngredient;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteMealInventoryItem;
use App\Models\SiteMealPlanEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CateringPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/*
 * To-do #4: the side menu's Meal Planner (/catering) opens on every site the
 * person can plan for, not on the first house by name (Aroha Respite). A
 * ?site= link still opens that site's planner; one they can't open goes back
 * to the overview with a notice.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(CateringPermissionsSeeder::class);

    // Wed 4 Nov 2026 at the NZ wall clock: the week is Mon 2 Nov – Sun 8 Nov.
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'Pacific/Auckland')->utc());
});

/** Admin: every site (sites.viewAll), every type, the planner. */
function mealOverviewManager(): User
{
    $user = User::factory()->create(['name' => 'Overview Manager', 'approved_at' => now()]);
    $user->roles()->sync([Role::query()->where('name', 'admin')->firstOrFail()->id]);

    return $user->fresh();
}

/** Support worker placed at the given sites only (no sites.viewAll). */
function mealOverviewWorker(Site ...$sites): User
{
    $user = User::factory()->create([
        'name' => 'Overview Worker',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $user->roles()->sync([Role::query()->where('name', 'support_worker')->firstOrFail()->id]);

    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $sites[0]->id,
        'secondary_site_ids' => array_map(fn (Site $site): int => $site->id, array_slice($sites, 1)),
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $user->fresh(['roles', 'hrEmployeeProfile']);
}

function mealOverviewHouse(string $name, array $attributes = []): Site
{
    return Site::factory()->create([
        'name' => $name,
        'type' => 'house',
        'is_active' => true,
        ...$attributes,
    ]);
}

/** The background-loaded overview rows, fetched the way the page asks for them. */
function mealOverviewRows(TestCase $test, User $user, string $query = ''): array
{
    return $test->actingAs($user)
        ->get('/catering'.$query, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'catering/meal-planner-overview',
            'X-Inertia-Partial-Data' => 'overview',
        ])
        ->assertOk()
        ->json('props.overview.sites');
}

function mealOverviewAuthorise(Client $client, array $allergenTagIds): void
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
        'amendment_reason' => 'Clinically verified overview fixture.',
    ]);
    $restriction->content_hash = $restriction->calculateContentHash();
    $restriction->save();
}

function mealOverviewEntry(Site $site, array $attributes): SiteMealPlanEntry
{
    return SiteMealPlanEntry::query()->create([
        'site_id' => $site->id,
        'source_type' => 'ad_hoc',
        'ad_hoc_name' => 'Planned meal',
        'servings' => 4,
        'client_ids' => [],
        ...$attributes,
    ]);
}

test('the side menu opens the meal planner on all sites, not on one house', function (): void {
    mealOverviewHouse('Birch House');
    mealOverviewHouse('Aroha Respite');
    Site::factory()->create(['name' => 'Head Office', 'type' => 'head_office', 'is_active' => true]);
    mealOverviewHouse('Closed House', ['is_active' => false]);
    mealOverviewHouse('Archived House', ['archived' => true, 'archived_at' => now()]);

    $this->actingAs(mealOverviewManager())
        ->get('/catering')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('catering/meal-planner-overview')
            ->where('week.start', '2026-11-02')
            ->where('week.end', '2026-11-08')
            ->where('week.is_current', true)
            // The rows load in the background, after the header paints.
            ->missing('overview')
            ->missing('default_site_id')
            ->loadDeferredProps('overview', fn (AssertableInertia $reload) => $reload
                ->where('overview.week_start', '2026-11-02')
                ->where('overview.sites', fn ($sites) => $sites->pluck('name')->all() === [
                    'Aroha Respite',
                    'Birch House',
                    'Head Office',
                ])));
});

test('the overview shows only the sites the person can access', function (): void {
    $aroha = mealOverviewHouse('Aroha Respite');
    $birch = mealOverviewHouse('Birch House');
    $cedar = mealOverviewHouse('Cedar House');
    mealOverviewEntry($cedar, ['plan_date' => '2026-11-03', 'meal_slot' => 'lunch']);

    $rows = mealOverviewRows($this, mealOverviewWorker($birch, $aroha));

    expect(collect($rows)->pluck('id')->all())->toBe([$aroha->id, $birch->id])
        ->and(collect($rows)->pluck('name')->all())->not->toContain('Cedar House');
});

test('a person with access to one site sees only that site', function (): void {
    $birch = mealOverviewHouse('Birch House');
    mealOverviewHouse('Aroha Respite');

    $this->actingAs($worker = mealOverviewWorker($birch))
        ->get('/catering')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('catering/meal-planner-overview')
            ->loadDeferredProps('overview', fn (AssertableInertia $reload) => $reload
                ->has('overview.sites', 1)
                ->where('overview.sites.0.id', $birch->id)
                ->where('overview.sites.0.name', 'Birch House')));

    expect(collect(mealOverviewRows($this, $worker))->pluck('id')->all())->toBe([$birch->id]);
});

test('a person with no site access gets an empty overview, not someone else\'s house', function (): void {
    mealOverviewHouse('Aroha Respite');
    $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $user->roles()->sync([Role::query()->where('name', 'support_worker')->firstOrFail()->id]);

    expect(mealOverviewRows($this, $user))->toBe([]);
});

test('a ?site= link still opens that site\'s planner', function (): void {
    $aroha = mealOverviewHouse('Aroha Respite');
    $birch = mealOverviewHouse('Birch House');
    $worker = mealOverviewWorker($birch, $aroha);

    $this->actingAs($worker)
        ->get("/catering?site={$birch->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('catering/meal-planner')
            ->where('default_site_id', $birch->id)
            ->where('default_site_name', 'Birch House')
            ->where('default_week', null)
            ->missing('overview'));

    // A link for another week opens on that week (any day in it).
    $this->actingAs($worker)
        ->get("/catering?site={$aroha->id}&week=2026-11-12")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('catering/meal-planner')
            ->where('default_site_id', $aroha->id)
            ->where('default_week', '2026-11-09'));

    // An admin who can see every site can open any of them.
    $this->actingAs(mealOverviewManager())
        ->get("/catering?site={$aroha->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('catering/meal-planner')
            ->where('default_site_id', $aroha->id));
});

test('a ?site= the person can\'t open goes back to the all-sites overview', function (): void {
    $birch = mealOverviewHouse('Birch House');
    $restricted = mealOverviewHouse('Aroha Respite');
    $closed = mealOverviewHouse('Closed House', ['is_active' => false]);
    $worker = mealOverviewWorker($birch);

    // Another site's id, a closed site, an unknown id and a junk value all read
    // the same, so the notice never tells anyone which sites exist.
    foreach ([$restricted->id, $closed->id, 999999, 'abc'] as $site) {
        $this->actingAs($worker)
            ->get("/catering?site={$site}")
            ->assertRedirect(route('catering.meal-planner'))
            ->assertSessionHas('warning', "You can't open that site's meal planner, so here are the sites you can plan for.");
    }

    $this->actingAs($worker)
        ->followingRedirects()
        ->get("/catering?site={$restricted->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('catering/meal-planner-overview')
            ->missing('default_site_id'));
});

test('each site row carries the numbers its own planner shows for the week', function (): void {
    $manager = mealOverviewManager();
    $site = mealOverviewHouse('Aroha Respite', ['weekly_food_budget_cents' => 10000]);

    $peanut = MealDietaryTag::query()->create([
        'key' => 'peanut_overview',
        'label' => 'Peanut',
        'kind' => 'allergen',
        'severity' => 'critical',
    ]);
    $resident = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    mealOverviewAuthorise($resident, [$peanut->id]);
    $leaver = Client::factory()->create(['site_id' => $site->id, 'status' => 'inactive']);

    $sauce = MealProduct::query()->create([
        'name' => 'Overview satay sauce',
        'default_unit' => 'each',
        'cost_per_unit_cents' => 250,
        'currency' => 'NZD',
        'is_active' => true,
    ]);
    $satay = MealRecipe::query()->create([
        'name' => 'Overview satay chicken',
        'scope' => 'shared',
        'is_active' => true,
        'serves_default' => 4,
    ]);
    $satay->tags()->attach($peanut->id);
    MealRecipeIngredient::query()->create([
        'recipe_id' => $satay->id,
        'product_id' => $sauce->id,
        'quantity' => 2,
        'unit' => 'each',
        'sort_order' => 1,
    ]);

    // Mon lunch: peanut satay for a resident with a peanut allergy — a conflict.
    mealOverviewEntry($site, [
        'plan_date' => '2026-11-02', 'meal_slot' => 'lunch', 'source_type' => 'recipe',
        'ad_hoc_name' => null, 'recipe_id' => $satay->id, 'client_ids' => [$resident->id],
    ]);
    // Mon dinner: the same, with an override logged and served — not unresolved.
    mealOverviewEntry($site, [
        'plan_date' => '2026-11-02', 'meal_slot' => 'dinner', 'source_type' => 'recipe',
        'ad_hoc_name' => null, 'recipe_id' => $satay->id, 'client_ids' => [$resident->id],
        'allergen_override_reason' => 'Peanut-free sauce used for this resident.',
        'allergen_override_by' => $manager->id,
        'allergen_override_at' => now(),
        'served_at' => now(),
    ]);
    // Tue breakfast and Wed dinner (a takeaway at its paid cost).
    mealOverviewEntry($site, ['plan_date' => '2026-11-03', 'meal_slot' => 'breakfast']);
    mealOverviewEntry($site, [
        'plan_date' => '2026-11-04', 'meal_slot' => 'dinner', 'source_type' => 'takeaway',
        'ad_hoc_name' => null, 'takeaway_vendor' => 'Hell Pizza', 'takeaway_cost_cents' => 5240,
    ]);
    // Thu lunch for a resident who has left: the planner calendar hides it.
    mealOverviewEntry($site, ['plan_date' => '2026-11-05', 'meal_slot' => 'lunch', 'client_ids' => [$leaver->id]]);
    // Next week: not this week's numbers.
    mealOverviewEntry($site, ['plan_date' => '2026-11-09', 'meal_slot' => 'lunch']);

    foreach ([[0, null], [2, 5], [10, 5]] as $i => [$quantity, $reorder]) {
        $product = MealProduct::query()->create([
            'name' => "Overview pantry item {$i}",
            'default_unit' => 'each',
            'is_active' => true,
        ]);
        SiteMealInventoryItem::query()->create([
            'site_id' => $site->id,
            'product_id' => $product->id,
            'current_qty' => $quantity,
            'reorder_level' => $reorder,
            'unit' => 'each',
        ]);
    }

    $row = collect(mealOverviewRows($this, $manager))->firstWhere('id', $site->id);

    expect($row['is_house'])->toBeTrue()
        ->and($row['residents'])->toBe(1)
        ->and($row['week']['meals'])->toBe(4)
        ->and($row['week']['served'])->toBe(1)
        ->and($row['week']['slots_filled'])->toBe(4)
        ->and($row['week']['slots_total'])->toBe(21)
        ->and($row['week']['fill_percent'])->toBe(19)
        ->and(collect($row['week']['days'])->pluck('date')->all())->toBe([
            '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08',
        ])
        ->and(collect($row['week']['days'])->pluck('meals')->all())->toBe([2, 1, 1, 0, 0, 0, 0])
        ->and(collect($row['week']['days'])->pluck('slots')->all())->toBe([2, 1, 1, 0, 0, 0, 0])
        ->and($row['allergens'])->toBe(['unresolved' => 1, 'overrides' => 1])
        ->and($row['stock'])->toBe(['tracked' => 3, 'low' => 1, 'out' => 1, 'at_par_percent' => 33])
        ->and($row['spend'])->toBe(['total_cents' => 500 + 500 + 5240, 'budget_cents' => 10000]);

    // The same numbers the site's own planner reads for that week.
    $plan = $this->actingAs($manager)
        ->getJson("/sites/{$site->id}/meal-plan?week=2026-11-02")
        ->assertOk();
    $summary = $this->actingAs($manager)
        ->getJson("/sites/{$site->id}/meal-plan/week-summary?week=2026-11-02")
        ->assertOk();

    expect(count($plan->json('entries')))->toBe($row['week']['meals'])
        ->and($summary->json('total_cost_cents'))->toBe($row['spend']['total_cents']);

    // Another week's numbers come from ?week=.
    $nextWeek = collect(mealOverviewRows($this, $manager, '?week=2026-11-10'))->firstWhere('id', $site->id);
    expect($nextWeek['week']['meals'])->toBe(1)
        ->and($nextWeek['allergens']['unresolved'])->toBe(0);
});

test('the overview reads the same number of queries however many sites there are', function (): void {
    $manager = mealOverviewManager();
    $addSite = function (string $name): void {
        $site = mealOverviewHouse($name, ['weekly_food_budget_cents' => 20000]);
        Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        mealOverviewEntry($site, ['plan_date' => '2026-11-03', 'meal_slot' => 'lunch']);
        SiteMealInventoryItem::query()->create([
            'site_id' => $site->id,
            'product_id' => MealProduct::query()->create([
                'name' => "{$name} pantry item",
                'default_unit' => 'each',
                'is_active' => true,
            ])->id,
            'current_qty' => 1,
            'unit' => 'each',
        ]);
    };
    // Each measurement is a fresh request: the scoped site-access cache and
    // the user's loaded permissions start empty, as they do in the app.
    $queries = function () use ($manager): array {
        app()->forgetScopedInstances();
        $user = $manager->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = mealOverviewRows($this, $user);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$count, count($rows)];
    };

    $addSite('Site one');
    $queries(); // warm anything cached for the whole process
    [$oneSite, $oneRow] = $queries();

    foreach (['Site two', 'Site three', 'Site four', 'Site five'] as $name) {
        $addSite($name);
    }
    [$fiveSites, $fiveRows] = $queries();

    expect($oneRow)->toBe(1)
        ->and($fiveRows)->toBe(5)
        ->and($fiveSites - $oneSite)->toBeLessThanOrEqual(2);
});
