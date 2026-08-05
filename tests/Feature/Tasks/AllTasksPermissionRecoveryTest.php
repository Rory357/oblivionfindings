<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\TaskWatcher;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\Tasks\Providers\ControlRoomAlertProvider;
use App\Services\Tasks\TaskAggregator;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function makeTaskRecoveryUser(Site $site, array $permissionKeys, string $name): User
{
    $user = User::factory()->create([
        'organization_id' => $site->tenant_id,
        'role' => 'support_worker',
        'name' => $name,
        'approved_at' => now(),
    ]);

    foreach ($permissionKeys as $permissionKey) {
        $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    HrEmployeeProfile::factory()->create([
        'tenant_id' => $site->tenant_id,
        'user_id' => $user->id,
        'position_role' => 'support_worker',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    return $user;
}

function denyTaskRecoveryPermissions(User $user, array $permissionKeys): void
{
    foreach ($permissionKeys as $permissionKey) {
        $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => false],
        ]);
    }

    $user->unsetRelation('permissionOverrides');
    $user->unsetRelation('roles');
}

it('maps Control Room task destinations to the same manage view and no-access decisions', function () {
    $site = Site::factory()->create(['tenant_id' => 1]);
    $owner = makeTaskRecoveryUser($site, ['controlRoom.viewAny'], 'Current Control Room Owner');
    $manager = makeTaskRecoveryUser($site, [
        'controlRoom.viewAny',
        'controlRoom.alerts.manage',
    ], 'Response Manager');
    $viewer = makeTaskRecoveryUser($site, [
        'controlRoom.alerts.view',
    ], 'Read Only Worker');
    $noDestination = makeTaskRecoveryUser($site, [
        'controlRoom.viewAny',
    ], 'List Only Worker');
    $alert = ControlRoomAlert::factory()->triaging()->create([
        'site_id' => $site->id,
        'assigned_to_user_id' => $owner->id,
    ]);
    $returnTo = '/tasks?q='.$alert->reference_number.'&sources=alert&bucket=in_progress';
    $access = app(ControlRoomAlertAccessService::class);

    expect($access->destinationFor($alert, $manager, $returnTo))->toMatchArray([
        'label' => 'Continue Control Room response',
        'help' => null,
    ])->and($access->destinationFor($alert, $manager, $returnTo)['href'])
        ->toContain('/control-room/alerts/'.$alert->id)
        ->toContain('return_to=')
        ->and($access->destinationFor($alert, $viewer, $returnTo))->toMatchArray([
            'label' => 'View alert',
            'help' => null,
        ])->and($access->destinationFor($alert, $noDestination, $returnTo))->toBe([
            'href' => null,
            'label' => 'No action for you',
            'help' => 'This response is owned by Current Control Room Owner. Contact a Control Room manager if you need access.',
        ]);

    $item = collect((new ControlRoomAlertProvider)->tasks($noDestination, [
        'return_to' => $returnTo,
    ]))->firstWhere('id', 'alert-'.$alert->id);

    expect($item)->not->toBeNull()
        ->and($item->link)->toBeNull()
        ->and($item->actionLabel)->toBe('No action for you')
        ->and($item->actionHelp)->toBe(
            'This response is owned by Current Control Room Owner. Contact a Control Room manager if you need access.',
        );

    AuditLog::query()->create([
        'organization_id' => $site->tenant_id,
        'user_id' => $owner->id,
        'action' => 'controlRoom.alert.view',
        'auditable_type' => ControlRoomAlert::class,
        'auditable_id' => $alert->id,
    ]);
    TaskWatcher::query()->create([
        'source' => 'alert',
        'item_id' => $alert->id,
        'user_id' => $owner->id,
    ]);

    $this->actingAs($noDestination)
        ->getJson('/tasks/detail?'.http_build_query([
            'source' => 'alert',
            'id' => $alert->id,
            'return_to' => $returnTo,
        ]))
        ->assertOk()
        ->assertJsonPath('item.link', null)
        ->assertJsonPath('item.actionLabel', 'No action for you')
        ->assertJsonPath('canOpen', false)
        ->assertJsonPath('canWatch', false)
        ->assertJsonPath('timeline', [])
        ->assertJsonPath('watchers', [])
        ->assertJsonPath('watchersHidden', true)
        ->assertJsonPath('isWatching', false);

    $this->actingAs($noDestination)
        ->post('/tasks/alert/'.$alert->id.'/watch', ['watching' => true])
        ->assertNotFound();

    $this->actingAs($noDestination)
        ->get('/control-room/alerts/'.$alert->id.'?'.http_build_query([
            'return_to' => $returnTo,
        ]))
        ->assertRedirect($returnTo)
        ->assertSessionHas(
            'error',
            'Your access changed. The item is still listed, but you can no longer open that Control Room response.',
        );
});

it('returns permission-scoped task detail actions and preserves the filtered return path', function () {
    $site = Site::factory()->create(['tenant_id' => 1]);
    $viewer = makeTaskRecoveryUser($site, [
        'controlRoom.alerts.view',
    ], 'Read Only Worker');
    $alert = ControlRoomAlert::factory()->triaging()->create([
        'site_id' => $site->id,
    ]);
    $returnTo = '/tasks?q='.$alert->reference_number.'&sources=alert&bucket=in_progress';

    $this->actingAs($viewer)
        ->getJson('/tasks/detail?'.http_build_query([
            'source' => 'alert',
            'id' => $alert->id,
            'return_to' => $returnTo,
        ]))
        ->assertOk()
        ->assertJsonPath('item.actionLabel', 'View alert')
        ->assertJsonPath('canOpen', true)
        ->assertJsonPath('canWatch', true)
        ->assertJsonPath('canAssign', false)
        ->assertJsonPath('canSplit', false)
        ->assertJsonPath('item.link', fn ($link) => is_string($link)
            && str_contains($link, 'return_to='));

    $this->actingAs($viewer)
        ->get('/control-room/alerts/'.$alert->id.'?'.http_build_query([
            'return_to' => $returnTo,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('control-room/show')
            ->where('return_to', $returnTo)
            ->where('can.view', true)
            ->where('can.manage', false)
            ->where('can.assign', false));

    $this->actingAs($viewer)
        ->post('/tasks/alert/'.$alert->id.'/watch', ['watching' => true])
        ->assertRedirect()
        ->assertSessionHas('success', 'Following this task.');

    $this->assertDatabaseHas('task_watchers', [
        'source' => 'alert',
        'item_id' => $alert->id,
        'user_id' => $viewer->id,
    ]);
});

it('recovers permission drift to the exact safe task filters instead of a bare 403', function () {
    $site = Site::factory()->create(['tenant_id' => 1]);
    $user = makeTaskRecoveryUser($site, [
        'controlRoom.viewAny',
        'controlRoom.alerts.view',
    ], 'Drifted Worker');
    $alert = ControlRoomAlert::factory()->triaging()->create([
        'site_id' => $site->id,
    ]);
    $returnTo = '/tasks?q='.$alert->reference_number
        .'&sources=alert&bucket=in_progress&assigned=me&overdue=1'
        .'&due=week&following=1&done=1&page=2&severity=high';
    denyTaskRecoveryPermissions($user, [
        'controlRoom.viewAny',
        'controlRoom.alerts.view',
        'controlRoom.alerts.manage',
    ]);

    $this->actingAs($user)
        ->get('/control-room/alerts/'.$alert->id.'?'.http_build_query([
            'return_to' => $returnTo,
        ]))
        ->assertRedirect($returnTo)
        ->assertSessionHas(
            'error',
            'Your access changed. The item is still listed, but you can no longer open that Control Room response.',
        );
});

it('keeps invalid recovery targets and unrelated authorization failures as normal 403 responses', function () {
    $site = Site::factory()->create(['tenant_id' => 1]);
    $user = makeTaskRecoveryUser($site, [], 'Unauthorized Worker');
    $alert = ControlRoomAlert::factory()->triaging()->create([
        'site_id' => $site->id,
    ]);

    $this->actingAs($user)
        ->get('/control-room/alerts/'.$alert->id.'?'.http_build_query([
            'return_to' => 'https://evil.example/tasks?q=stolen',
        ]))
        ->assertForbidden();

    $this->actingAs($user)
        ->get('/control-room/alerts/'.$alert->id.'?'.http_build_query([
            'return_to' => '/tasks?q=allowed&evil=drop-me',
        ]))
        ->assertRedirect('/tasks?q=allowed')
        ->assertSessionHas(
            'error',
            'Your access changed. The item is still listed, but you can no longer open that Control Room response.',
        );

    $this->actingAs($user)
        ->getJson('/control-room/alerts/'.$alert->id.'?'.http_build_query([
            'return_to' => '/tasks?q=allowed&evil=drop-me',
        ]))
        ->assertForbidden()
        ->assertJsonPath(
            'message',
            'Your access changed. The item is still listed, but you can no longer open that Control Room response.',
        );
});

it('keeps the provider row action aligned with the shared access decision', function () {
    $site = Site::factory()->create(['tenant_id' => 1]);
    $viewer = makeTaskRecoveryUser($site, [
        'controlRoom.alerts.view',
    ], 'Read Only Worker');
    $alert = ControlRoomAlert::factory()->triaging()->create([
        'site_id' => $site->id,
    ]);
    $returnTo = '/tasks?q='.$alert->reference_number.'&sources=alert';

    $item = collect((new ControlRoomAlertProvider)->tasks($viewer, [
        'return_to' => $returnTo,
    ]))->firstWhere('id', 'alert-'.$alert->id);

    expect($item)->not->toBeNull()
        ->and($item->actionLabel)->toBe('View alert')
        ->and($item->link)->toContain('return_to=');
});

it('rejects a Control Room watch mutation when the alert is outside the shared readable scope', function () {
    $localSite = Site::factory()->create(['tenant_id' => 1]);
    $foreignSite = Site::factory()->create(['tenant_id' => 1]);
    $viewer = makeTaskRecoveryUser($localSite, [
        'controlRoom.alerts.view',
    ], 'Site Scoped Viewer');
    $alert = ControlRoomAlert::factory()->triaging()->create([
        'site_id' => $foreignSite->id,
    ]);

    $this->actingAs($viewer)
        ->post('/tasks/alert/'.$alert->id.'/watch', ['watching' => true])
        ->assertNotFound();

    expect(TaskWatcher::query()
        ->where('source', 'alert')
        ->where('item_id', $alert->id)
        ->where('user_id', $viewer->id)
        ->exists())->toBeFalse();
});

it('removes and rejects assignment when readable alert access drifts away', function () {
    $site = Site::factory()->create(['tenant_id' => 1]);
    $actor = makeTaskRecoveryUser($site, [
        'controlRoom.viewAny',
        'controlRoom.alerts.view',
        'controlRoom.alerts.assign',
    ], 'Drifted Alert Assigner');
    $assignee = makeTaskRecoveryUser($site, [
        'controlRoom.alerts.view',
    ], 'Eligible Alert Assignee');
    $alert = ControlRoomAlert::factory()->triaging()->create([
        'site_id' => $site->id,
        'assigned_to_user_id' => null,
    ]);
    $returnTo = '/tasks?q='.$alert->reference_number.'&sources=alert';

    $this->actingAs($actor)
        ->getJson('/tasks/detail?'.http_build_query([
            'source' => 'alert',
            'id' => $alert->id,
            'return_to' => $returnTo,
        ]))
        ->assertOk()
        ->assertJsonPath('canAssign', true);

    denyTaskRecoveryPermissions($actor, [
        'controlRoom.alerts.view',
    ]);

    $this->actingAs($actor)
        ->getJson('/tasks/detail?'.http_build_query([
            'source' => 'alert',
            'id' => $alert->id,
            'return_to' => $returnTo,
        ]))
        ->assertOk()
        ->assertJsonPath('item.actionLabel', 'No action for you')
        ->assertJsonPath('canAssign', false);

    $this->actingAs($actor)
        ->post('/tasks/alert/'.$alert->id.'/assign', [
            'assignee_id' => $assignee->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('error', 'You do not have permission to assign this record.');

    expect($alert->fresh()->assigned_to_user_id)->toBeNull();
});

it('prunes a stale cross-site watcher before assignment notifications are sent', function () {
    $localSite = Site::factory()->create(['tenant_id' => 1]);
    $foreignSite = Site::factory()->create(['tenant_id' => 1]);
    $actor = makeTaskRecoveryUser($localSite, [
        'controlRoom.viewAny',
        'controlRoom.alerts.view',
        'controlRoom.alerts.assign',
    ], 'Scoped Alert Assigner');
    $assignee = makeTaskRecoveryUser($localSite, [
        'controlRoom.alerts.view',
    ], 'Scoped Alert Assignee');
    $assignee->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'coordinator')->value('id'),
    ]);
    $staleWatcher = makeTaskRecoveryUser($foreignSite, [
        'controlRoom.alerts.view',
    ], 'Stale Foreign Watcher');
    $alert = ControlRoomAlert::factory()->triaging()->create([
        'site_id' => $localSite->id,
        'assigned_to_user_id' => null,
    ]);
    TaskWatcher::query()->create([
        'source' => 'alert',
        'item_id' => $alert->id,
        'user_id' => $staleWatcher->id,
    ]);

    $this->actingAs($actor)
        ->post('/tasks/alert/'.$alert->id.'/assign', [
            'assignee_id' => $assignee->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Task assigned.');

    expect($staleWatcher->fresh()->notifications()->count())->toBe(0)
        ->and(TaskWatcher::query()
            ->where('source', 'alert')
            ->where('item_id', $alert->id)
            ->where('user_id', $staleWatcher->id)
            ->exists())->toBeFalse();
});

it('keeps an authorized watcher for an exact alert beyond the 300-row feed cap', function () {
    $site = Site::factory()->create(['tenant_id' => 1]);
    $watcher = makeTaskRecoveryUser($site, [
        'controlRoom.alerts.view',
    ], 'Authorized Older Alert Watcher');
    $target = ControlRoomAlert::factory()->triaging()->create([
        'site_id' => $site->id,
        'triggered_at' => now()->subYear(),
    ]);
    ControlRoomAlert::factory()
        ->count(301)
        ->triaging()
        ->create([
            'site_id' => $site->id,
            'triggered_at' => now(),
        ]);
    TaskWatcher::query()->create([
        'source' => 'alert',
        'item_id' => $target->id,
        'user_id' => $watcher->id,
    ]);

    $ids = app(TaskAggregator::class)
        ->authorizedWatcherIdsFor('alert', $target->id);

    expect($ids)->toBe([$watcher->id])
        ->and(TaskWatcher::query()
            ->where('source', 'alert')
            ->where('item_id', $target->id)
            ->where('user_id', $watcher->id)
            ->exists())->toBeTrue();
});

it('filters stale follower names from readable detail without mutating on GET', function () {
    $localSite = Site::factory()->create(['tenant_id' => 1]);
    $foreignSite = Site::factory()->create(['tenant_id' => 1]);
    $viewer = makeTaskRecoveryUser($localSite, [
        'controlRoom.alerts.view',
    ], 'Readable Alert Viewer');
    $staleWatcher = makeTaskRecoveryUser($foreignSite, [
        'controlRoom.alerts.view',
    ], 'Foreign Historical Watcher');
    $alert = ControlRoomAlert::factory()->triaging()->create([
        'site_id' => $localSite->id,
    ]);
    TaskWatcher::query()->create([
        'source' => 'alert',
        'item_id' => $alert->id,
        'user_id' => $staleWatcher->id,
    ]);

    $this->actingAs($viewer)
        ->getJson('/tasks/detail?'.http_build_query([
            'source' => 'alert',
            'id' => $alert->id,
            'return_to' => '/tasks?sources=alert',
        ]))
        ->assertOk()
        ->assertJsonPath('watchers', [])
        ->assertJsonPath('watchersHidden', false);

    expect(TaskWatcher::query()
        ->where('source', 'alert')
        ->where('item_id', $alert->id)
        ->where('user_id', $staleWatcher->id)
        ->exists())->toBeTrue();
});
