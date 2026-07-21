<?php

use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\ItWorkTask;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('provides the shared service management persistence contract', function () {
    expect(Schema::hasColumns('it_teams', [
        'tenant_id',
        'manager_user_id',
        'name',
        'description',
        'is_active',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('it_team_members', [
            'team_id',
            'user_id',
            'role',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('it_queues', [
            'tenant_id',
            'team_id',
            'key',
            'name',
            'filter_rules',
            'is_active',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('it_services', [
            'tenant_id',
            'owner_user_id',
            'key',
            'name',
            'status',
            'criticality',
            'is_active',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('it_work_tasks', [
            'tenant_id',
            'ticket_id',
            'parent_task_id',
            'team_id',
            'assigned_to_user_id',
            'completed_by_user_id',
            'title',
            'status',
            'due_at',
            'is_required',
            'evidence_required',
            'completed_at',
            'sort_order',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('it_tickets', [
            'requested_for_user_id',
            'owner_user_id',
            'site_id',
            'team_id',
            'queue_id',
            'it_service_id',
            'workflow_state',
            'is_sensitive',
            'waiting_party',
            'next_action',
            'due_at',
        ]))->toBeTrue();
});

it('relates application-owned teams queues services tasks and canonical ticket ownership', function () {
    $manager = User::factory()->create();
    $member = User::factory()->create();
    $requester = User::factory()->create();
    $requestedFor = User::factory()->create();
    $site = Site::factory()->create(['tenant_id' => 1]);
    $team = ItTeam::factory()->create([
        'tenant_id' => 1,
        'manager_user_id' => $manager->id,
    ]);
    $team->members()->attach($member->id, ['role' => 'member']);
    $queue = ItQueue::factory()->for($team, 'team')->create(['tenant_id' => 1]);
    $service = ItService::factory()->create([
        'tenant_id' => 1,
        'owner_user_id' => $manager->id,
    ]);
    $ticket = ItTicket::factory()->create([
        'tenant_id' => 1,
        'requester_user_id' => $requester->id,
        'requested_for_user_id' => $requestedFor->id,
        'owner_user_id' => $manager->id,
        'site_id' => $site->id,
        'team_id' => $team->id,
        'queue_id' => $queue->id,
        'it_service_id' => $service->id,
        'work_type' => 'security_request',
        'workflow_state' => 'submitted',
        'is_sensitive' => true,
        'waiting_party' => 'approver',
        'next_action' => 'Manager approval required',
        'due_at' => now()->addDay(),
    ]);
    $parentTask = ItWorkTask::factory()->for($ticket, 'ticket')->create([
        'tenant_id' => 1,
        'team_id' => $team->id,
        'is_required' => true,
    ]);
    $childTask = ItWorkTask::factory()->for($ticket, 'ticket')->create([
        'tenant_id' => 1,
        'parent_task_id' => $parentTask->id,
        'assigned_to_user_id' => $member->id,
    ]);

    expect($team->manager->is($manager))->toBeTrue()
        ->and($team->members()->sole()->is($member))->toBeTrue()
        ->and($team->members()->sole()->pivot->role)->toBe('member')
        ->and($queue->team->is($team))->toBeTrue()
        ->and($service->owner->is($manager))->toBeTrue()
        ->and($ticket->requestedFor->is($requestedFor))->toBeTrue()
        ->and($ticket->owner->is($manager))->toBeTrue()
        ->and($ticket->site->is($site))->toBeTrue()
        ->and($ticket->team->is($team))->toBeTrue()
        ->and($ticket->queue->is($queue))->toBeTrue()
        ->and($ticket->service->is($service))->toBeTrue()
        ->and($ticket->is_sensitive)->toBeTrue()
        ->and($ticket->due_at)->not->toBeNull()
        ->and($ticket->tasks()->count())->toBe(2)
        ->and($childTask->parent->is($parentTask))->toBeTrue()
        ->and($parentTask->children()->sole()->is($childTask))->toBeTrue();
});

it('retains existing work types and declares the new governed relationships', function () {
    expect(ItTicket::WORK_TYPES)->toBe([
        'incident',
        'service_request',
        'problem',
        'change',
        'task',
        'security_request',
        'major_incident',
    ])->and(ItTicketLink::RELATIONSHIPS)->toContain(
        'affected_device',
        'source_alert',
        'related_incident',
        'related_problem',
        'related_change',
        'major_incident_member',
        'affected_service',
        'affected_site',
    );
});
