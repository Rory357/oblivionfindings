<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Tasks\Providers\EmergencyDrillFindingProvider;
use App\Services\Tasks\Providers\EmergencyDrillProvider;
use App\Services\Tasks\TaskAggregator;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

// The sidebar's All Tasks count reads every task module (seconds for a manager).
// Since 30 Oct 2026 it loads after the page, in the inbox's background request;
// the link itself is decided with the page from the modules' own read checks.
// No mocks here: these are the real task modules.

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
});

function taskBadgeUser(string $role, Site $site): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => $role]);
    $user->roles()->sync([Role::query()->where('name', $role)->value('id')]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'position_role' => $role,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $user->fresh();
}

/** The count the sidebar showed before this change, worked out fresh. */
function taskBadgeExpected(User $viewer): array
{
    Cache::forget("user:{$viewer->id}:task-nav:v1");
    $projection = app(TaskAggregator::class)->navigationBadgeFor($viewer->fresh());
    Cache::forget("user:{$viewer->id}:task-nav:v1");

    return ['view' => $projection['view'], 'badge' => $projection['view'] ? $projection['badge'] : 0, 'badgeDegraded' => $projection['degraded']];
}

it('sends no task count with the page and loads the right one straight after, with the inbox', function (string $role, string $url) {
    $viewer = taskBadgeUser($role, $this->site);
    if ($url === '/control-room' && ! $viewer->canDo('controlRoom.viewAny')) {
        $this->actingAs($viewer)->get($url)->assertForbidden();

        return;
    }
    $expected = taskBadgeExpected($viewer);
    expect($expected['view'])->toBeTrue(); // every staff role here reads at least one task module

    $response = $this->actingAs($viewer)->get($url)->assertOk();
    expect($response->viewData('page')['deferredProps']['default'] ?? [])->toContain('taskNav', 'inbox');
    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.can.tasks.view', true)
        ->missing('auth.can.tasks.badge')
        ->missing('taskNav')
        ->loadDeferredProps('default', fn (Assert $fill) => $fill->where('taskNav', $expected)));
})->with(['admin', 'provider_manager', 'team_lead', 'support_worker'])
    ->with(['/dashboard', '/my-day', '/control-room', '/clients']);

it('shows the All Tasks link to exactly the people who saw it before, for every demo role', function () {
    // Before this change the link followed the full count's `view`. The quick
    // check must give the same answer for every role the app seeds.
    $roles = Role::query()->orderBy('name')->pluck('name');
    expect($roles)->not->toBeEmpty();

    $shown = 0;
    foreach ($roles as $role) {
        $viewer = taskBadgeUser($role, $this->site);
        $before = app(TaskAggregator::class)->navigationBadgeFor($viewer->fresh())['view'];
        Cache::forget("user:{$viewer->id}:task-nav:v1");
        expect(app(TaskAggregator::class)->navigationVisibleFor($viewer->fresh()))->toBe($before, "role {$role}");

        $response = $this->actingAs($viewer)->get('/settings/profile');
        if ($response->status() !== 200) {
            continue; // e.g. a portal role sent elsewhere; the check above still covers it
        }
        $response->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.tasks.view', $before)
            // No count comes with the page: it loads afterwards, and is empty for people without the link.
            ->missing('taskNav')
            ->when(! $before, fn (Assert $p) => $p->loadDeferredProps('default', fn (Assert $fill) => $fill->where('taskNav', null))));
        $shown += (int) $before;
    }
    expect($shown)->toBeGreaterThan(0);
});

it('never shows the All Tasks link to someone with no task access', function () {
    $viewer = User::factory()->create(['approved_at' => now()]);
    $viewer->roles()->detach();
    $viewer->permissionOverrides()->detach();

    $this->actingAs($viewer)->get('/settings/profile')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.tasks.view', false)
            ->missing('taskNav')
            ->loadDeferredProps('default', fn (Assert $fill) => $fill->where('taskNav', null)));
    expect(app(TaskAggregator::class)->navigationBadgeFor($viewer->fresh())['view'])->toBeFalse();
});

it('asks H&S modules only whether they may be read when no other module is, and loads none of their work', function () {
    // Every demo role reads a plain module first; a custom H&S-only role reaches
    // this path. The answer must match the full count's, without its cost.
    $worker = User::factory()->frontlineWorker()->create();
    foreach (['hazards.view' => true, 'hazards.manage' => false, 'healthSafety.viewAllSites' => false] as $key => $allowed) {
        $worker->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', $key)->firstOrFail()->id => ['allowed' => $allowed]]);
    }
    HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
        'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
    $drills = new class extends EmergencyDrillProvider
    {
        public int $loads = 0;

        public function authorizedNavigationTasksInContext(User $viewer, HealthSafetyReadContext $context): array
        {
            $this->loads++;

            return parent::authorizedNavigationTasksInContext($viewer, $context);
        }
    };
    $aggregate = new TaskAggregator([$drills, new EmergencyDrillFindingProvider]);

    expect($aggregate->navigationVisibleFor($worker->fresh()))->toBeTrue()
        ->and($drills->loads)->toBe(0)
        ->and($aggregate->navigationBadgeFor($worker->fresh())['view'])->toBeTrue()
        ->and($drills->loads)->toBe(1);

    $outsider = User::factory()->create(['approved_at' => now()]);
    $outsider->roles()->detach();
    $outsider->permissionOverrides()->detach();
    expect($aggregate->navigationVisibleFor($outsider->fresh()))->toBeFalse()
        ->and($aggregate->navigationBadgeFor($outsider->fresh())['view'])->toBeFalse();
});

it('hides the All Tasks link on the next page once the role that gave it is removed', function () {
    $viewer = taskBadgeUser('support_worker', $this->site);
    $this->actingAs($viewer)->get('/settings/profile')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.tasks.view', true)
            // Fill the 90 s count cache too: the link must not lean on it.
            ->loadDeferredProps('default', fn (Assert $fill) => $fill->where('taskNav.view', true)));

    $viewer->roles()->detach();

    $this->actingAs($viewer)->get('/settings/profile')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.tasks.view', false)
            // The cached count from the first page is not handed out either.
            ->loadDeferredProps('default', fn (Assert $fill) => $fill->where('taskNav', null)));
});

it('keeps each person\'s count to themselves', function () {
    $manager = taskBadgeUser('provider_manager', $this->site);
    $worker = taskBadgeUser('support_worker', $this->site);
    $expectedWorker = taskBadgeExpected($worker);

    // The manager's count is cached first; the worker must still get their own.
    $this->actingAs($manager)->get('/settings/profile')
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('default'));
    expect(Cache::has("user:{$manager->id}:task-nav:v1"))->toBeTrue();

    $this->actingAs($worker)->get('/settings/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('default', fn (Assert $fill) => $fill->where('taskNav', $expectedWorker)));
});
