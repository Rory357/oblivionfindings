<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\Permission;
use App\Models\SafeguardingActionPlan;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\TaskWatcher;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

/**
 * A user with the given permission keys granted via overrides — the same
 * seeding idiom as AllTasksDashboardTest::makeTasksUser().
 */
function makeWatcherUser(array $permissionKeys, ?Site $site = null): User
{
    $user = User::factory()->create(['approved_at' => now()]);

    foreach ($permissionKeys as $permissionKey) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $permissionKey],
            ['description' => str_replace('.', ' ', $permissionKey), 'group' => explode('.', $permissionKey)[0]],
        );
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    if ($site) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth(),
            'end_date' => null,
        ]);
    }

    return $user;
}

/** @param array<string, mixed> $attributes */
function makeWatcherIncident(Site $site, array $attributes = []): ClientIncident
{
    $client = Client::factory()->create(['site_id' => $site->id]);

    return ClientIncident::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'status' => 'submitted',
        ...$attributes,
    ]);
}

// ---------------------------------------------------------------------------
// Watch / follow
// ---------------------------------------------------------------------------

it('adds a task_watchers row and surfaces the item under the following filter only after watching', function () {
    $site = Site::factory()->create();
    $user = makeWatcherUser(['incidents.viewAny'], $site);
    $incident = makeWatcherIncident($site);

    $inList = fn ($items) => collect($items)->contains(fn ($i) => $i['id'] === 'incident-'.$incident->id);

    // Not watched yet → the following filter hides it, no row exists.
    expect(TaskWatcher::query()->count())->toBe(0);

    $this->actingAs($user)
        ->get('/tasks?following=1')
        ->assertInertia(fn ($page) => $page->where('items', fn ($items) => ! $inList($items)));

    // Follow it.
    $this->actingAs($user)
        ->post("/tasks/incident/{$incident->id}/watch", ['watching' => true])
        ->assertRedirect()
        ->assertSessionHas('success', 'Following this task.');

    $this->assertDatabaseHas('task_watchers', [
        'source' => 'incident',
        'item_id' => $incident->id,
        'user_id' => $user->id,
    ]);

    // Now it appears under ?following=1.
    $this->actingAs($user)
        ->get('/tasks?following=1')
        ->assertInertia(fn ($page) => $page
            ->where('filters.following', true)
            ->where('items', $inList));

    // Unfollow → row removed and it drops out of the filter again.
    $this->actingAs($user)
        ->post("/tasks/incident/{$incident->id}/watch", ['watching' => false])
        ->assertRedirect()
        ->assertSessionHas('success', 'Stopped following.');

    $this->assertDatabaseMissing('task_watchers', [
        'source' => 'incident',
        'item_id' => $incident->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get('/tasks?following=1')
        ->assertInertia(fn ($page) => $page->where('items', fn ($items) => ! $inList($items)));
});

it('reflects the watched count in stats.watching', function () {
    $site = Site::factory()->create();
    $user = makeWatcherUser(['incidents.viewAny'], $site);
    $a = makeWatcherIncident($site);
    $b = makeWatcherIncident($site);
    makeWatcherIncident($site); // unwatched

    $this->actingAs($user)->get('/tasks')
        ->assertInertia(fn ($page) => $page->where('stats.watching', 0));

    $this->actingAs($user)->post("/tasks/incident/{$a->id}/watch", ['watching' => true]);
    $this->actingAs($user)->post("/tasks/incident/{$b->id}/watch", ['watching' => true]);

    $this->actingAs($user)->get('/tasks')
        ->assertInertia(fn ($page) => $page->where('stats.watching', 2));
});

it('scopes the following filter to the acting user — a second user\'s watch does not leak', function () {
    $site = Site::factory()->create();
    $me = makeWatcherUser(['incidents.viewAny'], $site);
    $other = makeWatcherUser(['incidents.viewAny'], $site);

    $mine = makeWatcherIncident($site);
    $theirs = makeWatcherIncident($site);

    TaskWatcher::query()->create(['source' => 'incident', 'item_id' => $mine->id, 'user_id' => $me->id]);
    TaskWatcher::query()->create(['source' => 'incident', 'item_id' => $theirs->id, 'user_id' => $other->id]);

    $this->actingAs($me)
        ->get('/tasks?following=1')
        ->assertInertia(fn ($page) => $page
            ->where('items', fn ($items) => collect($items)->pluck('id')->all() === ['incident-'.$mine->id]));
});

it('rejects watching an incident outside the viewer site scope', function () {
    $localSite = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    $user = makeWatcherUser(['incidents.viewAny'], $localSite);
    $incident = makeWatcherIncident($foreignSite);

    $this->actingAs($user)
        ->post("/tasks/incident/{$incident->id}/watch", ['watching' => true])
        ->assertNotFound();

    expect(TaskWatcher::query()
        ->where('source', 'incident')
        ->where('item_id', $incident->id)
        ->where('user_id', $user->id)
        ->exists())->toBeFalse();
});

it('allows a user to stop following after module read permission is revoked', function () {
    $site = Site::factory()->create();
    $user = makeWatcherUser(['incidents.viewAny'], $site);
    $incident = makeWatcherIncident($site);
    TaskWatcher::query()->create([
        'source' => 'incident',
        'item_id' => $incident->id,
        'user_id' => $user->id,
    ]);
    $permission = Permission::query()
        ->where('key', 'incidents.viewAny')
        ->firstOrFail();
    $user->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => false],
    ]);
    $user->unsetRelation('permissionOverrides');
    $user->unsetRelation('roles');

    $this->actingAs($user)
        ->post("/tasks/incident/{$incident->id}/watch", ['watching' => false])
        ->assertRedirect()
        ->assertSessionHas('success', 'Stopped following.');

    expect(TaskWatcher::query()
        ->where('source', 'incident')
        ->where('item_id', $incident->id)
        ->where('user_id', $user->id)
        ->exists())->toBeFalse();
});

it('keeps a restricted safeguarding viewers own follow state available for unfollow', function () {
    $user = makeWatcherUser(['safeguarding.viewAny']);
    $concern = SafeguardingConcern::factory()->create([
        'status' => 'reported',
        'is_sensitive' => true,
        'reported_by_user_id' => User::factory()->create()->id,
        'assigned_to_user_id' => null,
    ]);
    TaskWatcher::query()->create([
        'source' => 'safeguarding',
        'item_id' => $concern->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson('/tasks/detail?'.http_build_query([
            'source' => 'safeguarding',
            'id' => $concern->id,
        ]))
        ->assertOk()
        ->assertJsonPath('item.restricted', true)
        ->assertJsonPath('canWatch', true)
        ->assertJsonPath('watchersHidden', true)
        ->assertJsonPath('watchers', [])
        ->assertJsonPath('isWatching', true);

    $this->actingAs($user)
        ->post("/tasks/safeguarding/{$concern->id}/watch", [
            'watching' => false,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Stopped following.');

    $this->assertDatabaseMissing('task_watchers', [
        'source' => 'safeguarding',
        'item_id' => $concern->id,
        'user_id' => $user->id,
    ]);
});

// ---------------------------------------------------------------------------
// Watcher FYI notification on reassignment
// ---------------------------------------------------------------------------

it('notifies a watcher when a followed item is reassigned to someone else', function () {
    // Safeguarding is the assignable provider — reassignment fans out to
    // watchers via TaskAssignmentNotifier.
    $actor = makeWatcherUser(['safeguarding.viewAny', 'safeguarding.update']);
    $assignee = makeWatcherUser(['safeguarding.viewAny']);
    $watcher = makeWatcherUser(['safeguarding.viewAny']);

    $concern = SafeguardingConcern::factory()->create([
        'status' => 'reported',
        'is_sensitive' => false,
    ]);

    // Watcher follows the concern.
    TaskWatcher::query()->create([
        'source' => 'safeguarding',
        'item_id' => $concern->id,
        'user_id' => $watcher->id,
    ]);

    expect($watcher->notifications()->count())->toBe(0);

    // Actor assigns it to a third party from the queue.
    $this->actingAs($actor)
        ->post("/tasks/safeguarding/{$concern->id}/assign", ['assignee_id' => $assignee->id])
        ->assertRedirect()
        ->assertSessionHas('success', 'Task assigned.');

    // The concern is now assigned.
    expect($concern->fresh()->assigned_to_user_id)->toBe($assignee->id);

    // The watcher (neither actor nor new assignee) received the FYI ping.
    expect($watcher->fresh()->notifications()->count())->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------------
// Split — incident follow-up
// ---------------------------------------------------------------------------

it('splits an incident into a follow-up assigned to the chosen user', function () {
    $site = Site::factory()->create();
    $user = makeWatcherUser(['incidents.viewAny', 'incidents.followups.manage'], $site);
    $assignee = makeWatcherUser(['incidents.viewAny'], $site);
    $incident = makeWatcherIncident($site);

    $this->actingAs($user)
        ->post("/tasks/incident/{$incident->id}/split", [
            'title' => 'Chase GP report',
            'description' => 'Follow up with the GP for the discharge summary.',
            'assignee_id' => $assignee->id,
            'due_at' => now()->addDays(3)->toDateTimeString(),
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Child task created.');

    $followup = IncidentFollowup::query()
        ->where('client_incident_id', $incident->id)
        ->first();

    expect($followup)->not->toBeNull()
        ->and($followup->assigned_to_user_id)->toBe($assignee->id)
        ->and($followup->created_by)->toBe($user->id)
        ->and($followup->completed_at)->toBeNull()
        ->and((string) $followup->notes)->toContain('Chase GP report');

    // The assignee (a third party) got the assignment FYI.
    expect($assignee->fresh()->notifications()->count())->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------------
// Split — safeguarding action plan
// ---------------------------------------------------------------------------

it('splits a non-sensitive safeguarding concern into a pending action plan', function () {
    $user = makeWatcherUser(['safeguarding.viewAny', 'safeguarding.investigate']);
    $assignee = makeWatcherUser(['safeguarding.viewAny']);
    $concern = SafeguardingConcern::factory()->create([
        'status' => 'reported',
        'is_sensitive' => false,
    ]);

    $this->actingAs($user)
        ->post("/tasks/safeguarding/{$concern->id}/split", [
            'title' => 'Arrange safeguarding review meeting',
            'assignee_id' => $assignee->id,
            'due_at' => now()->addWeek()->toDateTimeString(),
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Child task created.');

    $plan = SafeguardingActionPlan::query()
        ->where('safeguarding_concern_id', $concern->id)
        ->first();

    expect($plan)->not->toBeNull()
        ->and($plan->status)->toBe('pending')
        ->and($plan->assigned_to_user_id)->toBe($assignee->id)
        ->and((int) $plan->priority)->toBe(3)
        ->and($plan->created_by)->toBe($user->id)
        ->and((string) $plan->action_description)->toContain('Arrange safeguarding review meeting');
});

// ---------------------------------------------------------------------------
// Split — permission gate
// ---------------------------------------------------------------------------

it('blocks a split from a user who lacks the child-create permission', function () {
    // Can VIEW incidents (so the source is visible and passes canView), but has
    // NOT been granted incidents.followups.manage → createChild rejects.
    $user = makeWatcherUser(['incidents.viewAny']);
    $incident = ClientIncident::factory()->create(['status' => 'submitted']);

    $this->actingAs($user)
        ->post("/tasks/incident/{$incident->id}/split", [
            'title' => 'Should not be created',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(IncidentFollowup::query()->where('client_incident_id', $incident->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Split — need-to-know redaction
// ---------------------------------------------------------------------------

it('blocks a restricted viewer from splitting a sensitive safeguarding concern', function () {
    // Has viewAny + investigate (write gate) but NOT viewSensitive, and is
    // neither the reporter nor the assignee → the concern is restricted, so the
    // provider must refuse to fork an action off it.
    $reporter = makeWatcherUser(['safeguarding.viewAny']);
    $user = makeWatcherUser(['safeguarding.viewAny', 'safeguarding.investigate']);

    $concern = SafeguardingConcern::factory()->create([
        'status' => 'reported',
        'is_sensitive' => true,
        'reported_by_user_id' => $reporter->id,
        'assigned_to_user_id' => null,
    ]);

    $this->actingAs($user)
        ->post("/tasks/safeguarding/{$concern->id}/split", [
            'title' => 'Restricted — must not create',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(SafeguardingActionPlan::query()->where('safeguarding_concern_id', $concern->id)->count())->toBe(0);
});
