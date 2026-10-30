<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\TaskAggregator;
use Database\Seeders\RbacSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// My Day's "My tasks" card reads every task module (about 2 s for a manager on the .com).
// Since 31 Oct 2026 the first response leaves it out and the browser fetches it straight
// after, in its own background request that builds only the card, for the same person.

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
});

function myDayCardUser(string $role, Site $site): User
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

/** A partial reload of My Day asking for $only, as the browser sends it. */
function myDayCardPartial(User $viewer, string $only): TestResponse
{
    $version = (string) test()->actingAs($viewer)->get('/my-day')->viewData('page')['version'];

    return test()->actingAs($viewer)->get('/my-day', [
        'X-Inertia' => 'true', 'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'my-day/index', 'X-Inertia-Partial-Data' => $only,
    ])->assertOk();
}

it('leaves the My tasks card out of the first My Day response and fills the same card in the background', function (string $role) {
    $viewer = myDayCardUser($role, $this->site);
    // Every other partial reload still re-reads the card, as before: that is the reference.
    $expected = myDayCardPartial($viewer, 'stats')->json('props.myTasks');
    expect($expected['viewer_id'])->toBe($viewer->id);

    $response = $this->actingAs($viewer)->get('/my-day')->assertOk();
    expect($response->viewData('page')['deferredProps']['my-tasks'] ?? null)->toBe(['myTasks']);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('my-day/index')
        ->missing('myTasks')
        ->has('stats')
        ->loadDeferredProps('my-tasks', fn (Assert $fill) => $fill
            ->where('myTasks.viewer_id', $viewer->id)
            ->where('myTasks.total', $expected['total'])
            ->where('myTasks.items', fn ($items) => collect($items)->pluck('id')->all() === array_column($expected['items'], 'id'))
            // The fill builds only the card, not the page again.
            ->missing('stats')->missing('shifts')->missing('notifications')->missing('data_unavailable')));
})->with(['admin', 'provider_manager', 'team_lead', 'support_worker']);

it('builds no My Day page data for the shared header fill', function () {
    $viewer = myDayCardUser('support_worker', $this->site);

    $props = myDayCardPartial($viewer, 'inbox,taskNav')->json('props');

    expect($props)->toHaveKeys(['inbox', 'taskNav'])
        ->and($props)->not->toHaveKeys(['myTasks', 'stats', 'shifts', 'medications_due']);
});

it('still re-reads the My tasks card on every other partial reload', function () {
    $viewer = myDayCardUser('support_worker', $this->site);

    myDayCardPartial($viewer, 'active_shift,clock')
        ->assertJsonPath('props.myTasks.viewer_id', $viewer->id);
});

it('says the My tasks card could not load, rather than showing nothing assigned', function () {
    $viewer = myDayCardUser('support_worker', $this->site);
    $this->app->instance(TaskAggregator::class, new class extends TaskAggregator
    {
        public function itemsFor(User $user, array $filters = []): array
        {
            throw new RuntimeException('Task modules unreachable.');
        }
    });

    myDayCardPartial($viewer, 'myTasks')
        ->assertJsonPath('props.myTasks.unavailable', true)
        ->assertJsonPath('props.myTasks.total', 0);
});
