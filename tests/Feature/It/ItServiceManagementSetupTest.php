<?php

use App\Models\AuditLog;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

function serviceManagementSetupUser(string $role = 'hr', int $tenantId = 1): User
{
    $user = User::factory()->create([
        'role' => $role,
        'approved_at' => now(),
        'organization_id' => $tenantId,
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->manager = serviceManagementSetupUser();
    $this->member = serviceManagementSetupUser();
    $this->assignee = serviceManagementSetupUser();
    $this->requester = serviceManagementSetupUser('support_worker');
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

test('agents configure tenant teams membership roles services and queue routing with audit', function () {
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
        'tenant_id' => 1,
        'manager_user_id' => $this->manager->id,
    ]);
    $team->members()->attach($this->assignee->id, ['role' => 'member']);
    $service = ItService::factory()->create([
        'tenant_id' => 1,
        'owner_user_id' => $this->manager->id,
        'key' => 'identity',
    ]);
    $queue = ItQueue::factory()->create([
        'tenant_id' => 1,
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

test('setup exposes workload counts and keeps all configuration tenant concealed', function () {
    $team = ItTeam::factory()->create(['tenant_id' => 1]);
    $queue = ItQueue::factory()->create(['tenant_id' => 1, 'team_id' => $team->id]);
    $service = ItService::factory()->create(['tenant_id' => 1]);
    ItTicket::factory()->count(2)->create([
        'tenant_id' => 1,
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

    $foreignAgent = serviceManagementSetupUser('hr', 2);
    $this->actingAs($foreignAgent)
        ->patch("/it/setup/teams/{$team->id}", ['name' => 'Foreign rename'])
        ->assertNotFound();

    $foreignService = ItService::factory()->create(['tenant_id' => 2]);
    $this->actingAs($this->manager)
        ->post('/it/setup/queues', [
            'key' => 'invalid',
            'name' => 'Invalid queue',
            'service_ids' => [$foreignService->id],
        ])
        ->assertSessionHasErrors('service_ids.0');
});
