<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\MealProduct;
use App\Models\MealRecipe;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteMealInventoryItem;
use App\Models\SiteMealInventoryMovement;
use App\Models\SiteMealPlanEntry;
use App\Models\SiteMealWeekTemplate;
use App\Models\User;
use Database\Seeders\CateringPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(CateringPermissionsSeeder::class);

    $this->visibleMealSite = Site::factory()->create([
        'name' => 'Approved Meal Site',
        'type' => 'house',
        'is_active' => true,
    ]);
    $this->hiddenMealSite = Site::factory()->create([
        'name' => 'Restricted Meal Site',
        'type' => 'house',
        'is_active' => true,
    ]);
    $this->mealViewer = mealPlannerScopedWorker($this->visibleMealSite);
});

function mealPlannerScopedWorker(Site $site): User
{
    $user = User::factory()->create([
        'name' => 'Scoped Meal Worker',
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

    return $user->fresh(['roles', 'hrEmployeeProfile']);
}

function mealPlannerEntry(Site $site, string $date, string $name): SiteMealPlanEntry
{
    return SiteMealPlanEntry::query()->create([
        'site_id' => $site->id,
        'plan_date' => $date,
        'meal_slot' => 'lunch',
        'source_type' => 'ad_hoc',
        'ad_hoc_name' => $name,
        'servings' => 4,
    ]);
}

test('meal planning lists relationships and copy actions honour canonical Site access', function (): void {
    $visibleClient = Client::factory()->create(['site_id' => $this->visibleMealSite->id, 'status' => 'active']);
    $hiddenClient = Client::factory()->create(['site_id' => $this->hiddenMealSite->id, 'status' => 'active']);
    $sharedRecipe = MealRecipe::query()->create([
        'name' => 'Shared meal recipe',
        'scope' => 'shared',
        'is_active' => true,
        'serves_default' => 4,
    ]);
    $hiddenRecipe = MealRecipe::query()->create([
        'name' => 'Restricted house recipe',
        'scope' => 'house',
        'site_id' => $this->hiddenMealSite->id,
        'is_active' => true,
        'serves_default' => 4,
    ]);
    $visibleEntry = mealPlannerEntry($this->visibleMealSite, '2026-05-18', 'Visible lunch');
    $hiddenEntry = mealPlannerEntry($this->hiddenMealSite, '2026-05-18', 'Restricted lunch');

    $bootstrap = $this->actingAs($this->mealViewer)
        ->getJson("/sites/{$this->visibleMealSite->id}/meal-planner/bootstrap")
        ->assertOk();
    expect(collect($bootstrap->json('sites'))->pluck('id')->all())->toBe([$this->visibleMealSite->id])
        ->and(collect($bootstrap->json('clients'))->pluck('id')->all())->toBe([$visibleClient->id]);

    $plan = $this->actingAs($this->mealViewer)
        ->getJson("/sites/{$this->visibleMealSite->id}/meal-plan?week=2026-05-18")
        ->assertOk();
    expect(collect($plan->json('entries'))->pluck('id')->all())->toBe([$visibleEntry->id]);

    foreach ([
        'meal-planner/bootstrap',
        'meal-plan',
        'meal-plan/week-summary',
        'meal-planner/takeaway-vendors',
    ] as $path) {
        $this->actingAs($this->mealViewer)
            ->getJson("/sites/{$this->hiddenMealSite->id}/{$path}")
            ->assertForbidden();
    }

    $this->actingAs($this->mealViewer)
        ->putJson("/sites/{$this->visibleMealSite->id}/meal-plan/{$hiddenEntry->id}", [
            'plan_date' => '2026-05-18',
            'meal_slot' => 'lunch',
            'source_type' => 'ad_hoc',
            'ad_hoc_name' => 'Forged update',
            'servings' => 4,
        ])
        ->assertNotFound();

    $this->actingAs($this->mealViewer)
        ->postJson("/sites/{$this->visibleMealSite->id}/meal-plan", [
            'plan_date' => '2026-05-19',
            'meal_slot' => 'dinner',
            'source_type' => 'recipe',
            'recipe_id' => $sharedRecipe->id,
            'servings' => 4,
            'client_ids' => [$hiddenClient->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('client_ids.0');

    $this->actingAs($this->mealViewer)
        ->postJson("/sites/{$this->visibleMealSite->id}/meal-plan", [
            'plan_date' => '2026-05-19',
            'meal_slot' => 'dinner',
            'source_type' => 'recipe',
            'recipe_id' => $hiddenRecipe->id,
            'servings' => 4,
            'client_ids' => [$visibleClient->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recipe_id');

    $this->actingAs($this->mealViewer)
        ->postJson("/sites/{$this->visibleMealSite->id}/meal-planner/check-conflicts", [
            'recipe_id' => $sharedRecipe->id,
            'client_ids' => [$hiddenClient->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('client_ids.0');

    $this->actingAs($this->mealViewer)
        ->putJson("/sites/{$this->visibleMealSite->id}/meal-planner/residents/{$hiddenClient->id}", [])
        ->assertNotFound();

    $this->actingAs($this->mealViewer)
        ->postJson("/sites/{$this->visibleMealSite->id}/meal-plan-week/copy", [
            'from_week' => '2026-05-18',
            'to_week' => '2026-05-25',
        ])
        ->assertOk();

    expect(SiteMealPlanEntry::query()
        ->where('site_id', $this->visibleMealSite->id)
        ->whereDate('plan_date', '2026-05-25')
        ->value('ad_hoc_name'))->toBe('Visible lunch')
        ->and(SiteMealPlanEntry::query()
            ->where('site_id', $this->hiddenMealSite->id)
            ->whereDate('plan_date', '2026-05-25')
            ->exists())->toBeFalse();

    $this->actingAs($this->mealViewer)
        ->postJson("/sites/{$this->hiddenMealSite->id}/meal-plan-week/copy", [
            'from_week' => '2026-05-18',
            'to_week' => '2026-05-25',
        ])
        ->assertForbidden();
});

test('inventory and week templates preserve Site ownership and global starter reuse', function (): void {
    $product = MealProduct::query()->create([
        'name' => 'Site access pantry product',
        'default_unit' => 'each',
        'is_active' => true,
    ]);
    $visibleItem = SiteMealInventoryItem::query()->create([
        'site_id' => $this->visibleMealSite->id,
        'product_id' => $product->id,
        'current_qty' => 4,
        'unit' => 'each',
    ]);
    $hiddenItem = SiteMealInventoryItem::query()->create([
        'site_id' => $this->hiddenMealSite->id,
        'product_id' => $product->id,
        'current_qty' => 9,
        'unit' => 'each',
    ]);

    $inventory = $this->actingAs($this->mealViewer)
        ->getJson("/sites/{$this->visibleMealSite->id}/meal-inventory")
        ->assertOk();
    expect(collect($inventory->json('items'))->pluck('id')->all())->toBe([$visibleItem->id]);

    $this->actingAs($this->mealViewer)
        ->putJson("/sites/{$this->visibleMealSite->id}/meal-inventory/items/{$hiddenItem->id}", [
            'par_level' => 12,
        ])
        ->assertNotFound();

    $movementCount = SiteMealInventoryMovement::query()->count();
    $this->actingAs($this->mealViewer)
        ->postJson("/sites/{$this->hiddenMealSite->id}/meal-inventory/adjust", [
            'product_id' => $product->id,
            'delta' => 2,
            'unit' => 'each',
            'reason' => 'delivery',
        ])
        ->assertForbidden();
    expect(SiteMealInventoryMovement::query()->count())->toBe($movementCount);

    $sharedRecipe = MealRecipe::query()->create([
        'name' => 'Reusable starter recipe',
        'scope' => 'shared',
        'is_active' => true,
        'serves_default' => 4,
    ]);
    $hiddenRecipe = MealRecipe::query()->create([
        'name' => 'Hidden template recipe',
        'scope' => 'house',
        'site_id' => $this->hiddenMealSite->id,
        'is_active' => true,
        'serves_default' => 4,
    ]);
    $visibleTemplate = SiteMealWeekTemplate::query()->create([
        'site_id' => $this->visibleMealSite->id,
        'name' => 'Visible local template',
        'meals' => [],
        'is_starter' => false,
    ]);
    $hiddenTemplate = SiteMealWeekTemplate::query()->create([
        'site_id' => $this->hiddenMealSite->id,
        'name' => 'Restricted local template',
        'meals' => [],
        'is_starter' => false,
    ]);
    $starter = SiteMealWeekTemplate::query()->create([
        'site_id' => $this->hiddenMealSite->id,
        'name' => 'Application starter template',
        'meals' => [[
            'day' => 0,
            'slot' => 'dinner',
            'recipe_id' => $sharedRecipe->id,
            'servings' => 4,
        ]],
        'is_starter' => true,
    ]);
    $invalidStarter = SiteMealWeekTemplate::query()->create([
        'site_id' => $this->hiddenMealSite->id,
        'name' => 'Invalid application starter',
        'meals' => [[
            'day' => 1,
            'slot' => 'dinner',
            'recipe_id' => $hiddenRecipe->id,
            'servings' => 4,
        ]],
        'is_starter' => true,
    ]);

    $templates = $this->actingAs($this->mealViewer)
        ->getJson("/sites/{$this->visibleMealSite->id}/meal-templates")
        ->assertOk();
    expect(collect($templates->json('templates'))->pluck('id'))
        ->toContain($visibleTemplate->id, $starter->id)
        ->not->toContain($hiddenTemplate->id, $invalidStarter->id);

    $this->actingAs($this->mealViewer)
        ->postJson("/sites/{$this->visibleMealSite->id}/meal-templates/{$hiddenTemplate->id}/apply", [
            'week' => '2026-06-01',
        ])
        ->assertNotFound();

    $this->actingAs($this->mealViewer)
        ->postJson("/sites/{$this->visibleMealSite->id}/meal-templates/{$starter->id}/apply", [
            'week' => '2026-06-01',
        ])
        ->assertOk();
    expect(SiteMealPlanEntry::query()
        ->where('site_id', $this->visibleMealSite->id)
        ->whereDate('plan_date', '2026-06-01')
        ->where('recipe_id', $sharedRecipe->id)
        ->exists())->toBeTrue();

    $this->actingAs($this->mealViewer)
        ->postJson("/sites/{$this->visibleMealSite->id}/meal-templates/{$invalidStarter->id}/apply", [
            'week' => '2026-06-01',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('template');

    $this->actingAs($this->mealViewer)
        ->deleteJson("/sites/{$this->visibleMealSite->id}/meal-templates/{$starter->id}")
        ->assertNotFound();
});
