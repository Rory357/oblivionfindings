<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\ItModuleNavigation;
use App\Models\AuditLog;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;

function serviceManagementSetupUser(string $role = 'hr'): User
{
    $user = User::factory()->create([
        'role' => $role,
        'approved_at' => now(),
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

function serviceManagementAssignSite(User $user, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->manager = serviceManagementSetupUser();
    $this->member = serviceManagementSetupUser();
    $this->assignee = serviceManagementSetupUser();
    $this->requester = serviceManagementSetupUser('support_worker');
    foreach ([$this->manager, $this->member, $this->assignee, $this->requester] as $user) {
        serviceManagementAssignSite($user, $this->site);
    }
});

test('IT pages share the approved grouped navigation while preserving existing deep links', function () {
    $this->actingAs($this->manager)
        ->get('/it?tab=provisioning&status=pending')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('it/index')
            ->has('itNavigation', 4)
            ->where('itNavigation.0.label', 'Service Desk')
            ->where('itNavigation.1.label', 'Service Delivery')
            ->where('itNavigation.2.label', 'Operations')
            ->where('itNavigation.3.label', 'Setup')
            ->where('itNavigation.0.items.0.label', 'Overview')
            ->where('itNavigation.0.items.3.href', '/it?tab=knowledge')
            ->where('itNavigation.1.items.0.href', '/it?tab=catalog')
            ->where('itNavigation.1.items.1.label', 'Provisioning')
            ->where('itNavigation.2.items.0.href', '/it/problems')
            ->where('itNavigation.3.items.0.href', '/it/setup'));

    expect(route('it.index'))->toEndWith('/it')
        ->and(route('it.problems.index'))->toEndWith('/it/problems')
        ->and(route('it.changes.index'))->toEndWith('/it/changes')
        ->and(route('it.major-incidents.index'))->toEndWith('/it/major-incidents')
        ->and(route('it.setup.index'))->toEndWith('/it/setup');

    $this->actingAs($this->requester)
        ->get('/it')
        ->assertInertia(fn ($page) => $page
            ->has('itNavigation', 1)
            ->where('itNavigation.0.label', 'Service Desk'));
});

test('IT navigation emits the requester catalogue only with exact request access', function () {
    $requestPermission = Permission::query()->where('key', 'it.request')->firstOrFail();
    $this->manager->permissionOverrides()->syncWithoutDetaching([
        $requestPermission->id => ['allowed' => false],
    ]);

    $agentLabels = collect(ItModuleNavigation::forUser($this->manager->fresh()))
        ->flatMap(fn (array $group): array => $group['items'])
        ->pluck('label');
    $adminLabels = collect(ItModuleNavigation::forUser(serviceManagementSetupUser('admin')))
        ->flatMap(fn (array $group): array => $group['items'])
        ->pluck('label');

    expect($agentLabels)->not->toContain('Service catalogue')
        ->and($adminLabels)->toContain('Service catalogue');
});

test('IT navigation never produces an unsupported SLA tab', function () {
    $admin = serviceManagementSetupUser('admin');
    serviceManagementAssignSite($admin, $this->site);

    $items = collect(ItModuleNavigation::forUser($admin))
        ->flatMap(fn (array $group): array => $group['items']);
    $sla = $items->firstWhere('label', 'SLA policies');

    expect($sla)->not->toBeNull()
        ->and($sla['href'])->toBe('/it?tab=tickets&action=sla')
        ->and($items->pluck('href'))->not->toContain('/it?tab=sla');
});

test('IT navigation requires the exact Security destination permissions and module access', function () {
    $this->seed(SecurityDevicesPermissionsSeeder::class);

    $viewer = serviceManagementSetupUser();
    serviceManagementAssignSite($viewer, $this->site);

    $destinationPermissions = Permission::query()
        ->whereIn('key', [
            'securityDevices.events.view',
            'securityDevices.integrations.view',
        ])
        ->pluck('id')
        ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
        ->all();
    $viewer->permissionOverrides()->syncWithoutDetaching($destinationPermissions);

    $labelsWithoutModuleAccess = collect(ItModuleNavigation::forUser($viewer))
        ->flatMap(fn (array $group): array => $group['items'])
        ->pluck('label');

    expect($labelsWithoutModuleAccess)
        ->not->toContain('Monitoring')
        ->not->toContain('Integrations & API');

    $modulePermission = Permission::query()
        ->where('key', 'securityDevices.viewAny')
        ->firstOrFail();
    $viewer->permissionOverrides()->syncWithoutDetaching([
        $modulePermission->id => ['allowed' => true],
    ]);

    $labelsWithExactAccess = collect(ItModuleNavigation::forUser($viewer->fresh()))
        ->flatMap(fn (array $group): array => $group['items'])
        ->pluck('label');

    expect($labelsWithExactAccess)
        ->toContain('Monitoring')
        ->toContain('Integrations & API');
});

test('agents configure application teams membership roles services and queue routing with audit', function () {
    $this->actingAs($this->manager)
        ->post('/it/setup/teams', [
            'name' => 'Network operations',
            'description' => 'Owns network incidents and changes.',
            'manager_user_id' => $this->manager->id,
            'is_active' => true,
            'members' => [
                ['user_id' => $this->member->id, 'role' => 'lead'],
                ['user_id' => $this->assignee->id, 'role' => 'member'],
            ],
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $team = ItTeam::query()->sole();
    expect($team->members()->whereKey($this->member->id)->first()->pivot->role)->toBe('lead')
        ->and($team->members()->whereKey($this->assignee->id)->first()->pivot->role)->toBe('member');

    $this->actingAs($this->manager)
        ->post('/it/setup/services', [
            'key' => 'identity',
            'name' => 'Identity and access',
            'description' => 'Authentication and directory services.',
            'owner_user_id' => $this->manager->id,
            'status' => 'operational',
            'criticality' => 'critical',
            'is_active' => true,
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();
    $service = ItService::query()->sole();

    $this->actingAs($this->manager)
        ->post('/it/setup/queues', [
            'key' => 'network-urgent',
            'name' => 'Urgent network',
            'description' => 'Urgent network and identity work.',
            'team_id' => $team->id,
            'routing_priority' => 10,
            'is_default' => false,
            'work_types' => ['incident'],
            'categories' => ['network'],
            'priorities' => ['urgent'],
            'service_ids' => [$service->id],
            'default_assignee_user_id' => $this->assignee->id,
            'is_active' => true,
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $queue = ItQueue::query()->sole();
    expect($queue->team->is($team))->toBeTrue()
        ->and($queue->filter_rules)->toMatchArray([
            'routing_priority' => 10,
            'work_types' => ['incident'],
            'categories' => ['network'],
            'priorities' => ['urgent'],
            'service_ids' => [$service->id],
            'default_assignee_user_id' => $this->assignee->id,
        ])
        ->and(AuditLog::query()->where('action', 'it.setup.team.created')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'it.setup.service.created')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'it.setup.queue.created')->exists())->toBeTrue();
});

test('ticket intake applies the highest priority matching queue service owner team and default assignee', function () {
    $team = ItTeam::factory()->create([
        'manager_user_id' => $this->manager->id,
    ]);
    $team->members()->attach($this->assignee->id, ['role' => 'member']);
    $service = ItService::factory()->create([
        'owner_user_id' => $this->manager->id,
        'key' => 'identity',
    ]);
    $queue = ItQueue::factory()->create([
        'team_id' => $team->id,
        'key' => 'identity-urgent',
        'filter_rules' => [
            'routing_priority' => 5,
            'is_default' => false,
            'work_types' => ['incident'],
            'categories' => ['network'],
            'priorities' => ['urgent'],
            'service_ids' => [$service->id],
            'default_assignee_user_id' => $this->assignee->id,
        ],
    ]);

    $this->actingAs($this->manager)
        ->post('/it/tickets', [
            'title' => 'Identity edge unavailable',
            'description' => 'All authentication traffic is failing.',
            'category' => 'network',
            'priority' => 'urgent',
            'work_type' => 'incident',
            'it_service_id' => $service->id,
            'site_id' => $this->site->id,
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $ticket = ItTicket::query()->sole();
    expect($ticket->queue_id)->toBe($queue->id)
        ->and($ticket->team_id)->toBe($team->id)
        ->and($ticket->it_service_id)->toBe($service->id)
        ->and($ticket->owner_user_id)->toBe($this->manager->id)
        ->and($ticket->assigned_to_user_id)->toBe($this->assignee->id)
        ->and($ticket->events()->where('type', 'routing_applied')->exists())->toBeTrue();
});

test('setup exposes Site-scoped workload counts and keeps configuration application-wide', function () {
    $team = ItTeam::factory()->create();
    $queue = ItQueue::factory()->create(['team_id' => $team->id]);
    $service = ItService::factory()->create();
    ItTicket::factory()->count(2)->create([
        'site_id' => $this->site->id,
        'team_id' => $team->id,
        'queue_id' => $queue->id,
        'it_service_id' => $service->id,
        'status' => 'open',
    ]);
    $remoteSite = Site::factory()->create();
    ItTicket::factory()->create([
        'site_id' => $remoteSite->id,
        'team_id' => $team->id,
        'queue_id' => $queue->id,
        'it_service_id' => $service->id,
        'status' => 'open',
    ]);

    $this->actingAs($this->manager)
        ->get('/it/setup')
        ->assertInertia(fn ($page) => $page
            ->component('it/setup/index')
            ->has('generatedAt')
            ->where('teams.0.workload.open_tickets', 2)
            ->where('queues.0.workload.open_tickets', 2)
            ->where('services.0.workload.open_tickets', 2));

    $this->actingAs($this->requester)->get('/it/setup')->assertForbidden();

    $remoteManager = serviceManagementSetupUser('hr');
    serviceManagementAssignSite($remoteManager, $remoteSite);
    $this->actingAs($remoteManager)
        ->patch("/it/setup/teams/{$team->id}", ['name' => 'Shared service desk'])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();
    expect($team->fresh()->name)->toBe('Shared service desk');

    $archivedSite = Site::factory()->create(['is_active' => false, 'archived' => true, 'archived_at' => now()]);
    $this->actingAs($this->manager)
        ->post('/it/setup/queues', [
            'key' => 'invalid',
            'name' => 'Invalid queue',
            'site_ids' => [$archivedSite->id],
        ])
        ->assertSessionHasErrors('site_ids.0');
});
