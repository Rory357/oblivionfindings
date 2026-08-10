<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\ItWorkTask;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

function workTaskUser(string $role, ?Site $site = null): User
{
    $user = User::factory()->create([
        'role' => $role,
        'approved_at' => now(),
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    if ($site) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->agent = workTaskUser('hr', $this->site);
    $this->requester = workTaskUser('support_worker');
    $this->ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->requester->id,
    ]);
});

test('agents create ordered required and optional tasks with dependencies and assignments', function () {
    $team = ItTeam::factory()->create();
    $assignee = workTaskUser('hr', $this->site);

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$this->ticket->id}/tasks", [
            'title' => 'Confirm manager approval',
            'is_required' => true,
            'sort_order' => 20,
        ])
        ->assertRedirect();
    $approvalTask = ItWorkTask::query()->firstOrFail();

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$this->ticket->id}/tasks", [
            'title' => 'Create the account',
            'description' => 'Use the approved least-privilege role.',
            'is_required' => false,
            'team_id' => $team->id,
            'assigned_to_user_id' => $assignee->id,
            'due_at' => now()->addDay()->toIso8601String(),
            'sort_order' => 10,
            'dependency_ids' => [$approvalTask->id],
        ])
        ->assertRedirect();

    $accountTask = ItWorkTask::query()->where('title', 'Create the account')->firstOrFail();
    expect($accountTask->is_required)->toBeFalse()
        ->and((int) $accountTask->team_id)->toBe($team->id)
        ->and((int) $accountTask->assigned_to_user_id)->toBe($assignee->id)
        ->and($accountTask->due_at)->not->toBeNull()
        ->and($accountTask->dependencies()->whereKey($approvalTask->id)->exists())->toBeTrue()
        ->and($this->ticket->events()->where('type', 'work_task_created')->count())->toBe(2)
        ->and(AuditLog::query()
            ->where('action', 'it.ticket.task.created')
            ->where('auditable_id', $this->ticket->id)
            ->count())->toBe(2);

    $this->actingAs($this->agent)
        ->getJson("/it/tickets/{$this->ticket->id}")
        ->assertOk()
        ->assertJsonPath('linked_context.tasks.0.id', $accountTask->id)
        ->assertJsonPath('linked_context.tasks.0.team.name', $team->name)
        ->assertJsonPath('linked_context.tasks.0.assignee.name', $assignee->name)
        ->assertJsonPath('linked_context.tasks.0.dependencies.0.id', $approvalTask->id)
        ->assertJsonPath('linked_context.tasks.1.id', $approvalTask->id)
        ->assertJsonPath('teamOptions.0.id', $team->id)
        ->assertJsonPath('teamOptions.0.name', $team->name);

    ItTicketEvent::record($this->ticket, 'status_changed', $this->agent->id, [
        'from' => 'open',
        'to' => 'in_progress',
        'reason' => 'Internal triage evidence that must not reach the requester.',
        'via' => 'agent_triage',
    ]);

    $this->actingAs($this->requester)
        ->getJson("/it/tickets/{$this->ticket->id}")
        ->assertOk()
        ->assertJsonCount(0, 'linked_context.tasks')
        ->assertJsonCount(0, 'teamOptions')
        ->assertJsonCount(1, 'events')
        ->assertJsonPath('events.0.type', 'status_changed')
        ->assertJsonPath('events.0.payload.from', 'open')
        ->assertJsonPath('events.0.payload.to', 'in_progress')
        ->assertJsonMissingPath('events.0.payload.reason')
        ->assertJsonMissingPath('events.0.payload.via');
});

test('dependencies and evidence gate completion while optional tasks do not gate resolution', function () {
    $prerequisite = ItWorkTask::factory()->create([
        'ticket_id' => $this->ticket->id,
        'title' => 'Capture approval evidence',
        'evidence_required' => true,
    ]);
    $dependent = ItWorkTask::factory()->create([
        'ticket_id' => $this->ticket->id,
        'title' => 'Apply the change',
    ]);
    $dependent->dependencies()->attach($prerequisite->id);

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$this->ticket->id}/tasks/{$dependent->id}/complete", [
            'completion_note' => 'Done',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');
    $this->actingAs($this->agent)
        ->post("/it/tickets/{$this->ticket->id}/tasks/{$prerequisite->id}/complete", [
            'completion_note' => 'Approved',
        ])
        ->assertSessionHas('error');

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$this->ticket->id}/tasks/{$prerequisite->id}/complete", [
            'completion_note' => 'Approved',
            'evidence' => ['CAB-2026-0042'],
        ])
        ->assertRedirect();
    $this->actingAs($this->agent)
        ->post("/it/tickets/{$this->ticket->id}/tasks/{$dependent->id}/complete", [
            'completion_note' => 'Applied successfully',
        ])
        ->assertRedirect();

    expect($prerequisite->fresh()->status)->toBe('completed')
        ->and($dependent->fresh()->status)->toBe('completed')
        ->and($this->ticket->events()->where('type', 'work_task_completed')->count())->toBe(2);

    ItWorkTask::factory()->create([
        'ticket_id' => $this->ticket->id,
        'title' => 'Optional follow-up',
        'is_required' => false,
    ]);
    $this->actingAs($this->agent)
        ->post("/it/tickets/{$this->ticket->id}/resolve", [
            'note' => 'Required work completed; optional follow-up remains.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect($this->ticket->fresh()->status)->toBe('resolved');
});

test('task updates and reopening are governed and recorded on the ticket timeline', function () {
    $task = ItWorkTask::factory()->create([
        'ticket_id' => $this->ticket->id,
    ]);

    $this->actingAs($this->agent)
        ->patch("/it/tickets/{$this->ticket->id}/tasks/{$task->id}", [
            'title' => 'Updated task title',
            'status' => 'in_progress',
            'sort_order' => 30,
            'evidence_required' => true,
        ])
        ->assertRedirect();
    $this->actingAs($this->agent)
        ->post("/it/tickets/{$this->ticket->id}/tasks/{$task->id}/complete", [
            'completion_note' => 'Verified',
            'evidence' => ['screenshot-001'],
        ])
        ->assertRedirect();
    $this->actingAs($this->agent)
        ->post("/it/tickets/{$this->ticket->id}/tasks/{$task->id}/reopen", [
            'reason' => 'Evidence must be recollected after the rollback.',
        ])
        ->assertRedirect();

    $task->refresh();
    expect($task->title)->toBe('Updated task title')
        ->and($task->status)->toBe('pending')
        ->and($task->completed_at)->toBeNull()
        ->and($task->evidence)->toBeNull()
        ->and($this->ticket->events()->where('type', 'work_task_updated')->count())->toBe(1)
        ->and($this->ticket->events()->where('type', 'work_task_completed')->count())->toBe(1)
        ->and($this->ticket->events()->where('type', 'work_task_reopened')->count())->toBe(1)
        ->and(AuditLog::query()
            ->whereIn('action', [
                'it.ticket.task.updated',
                'it.ticket.task.completed',
                'it.ticket.task.reopened',
            ])
            ->where('auditable_id', $this->ticket->id)
            ->count())->toBe(3);
});

test('task cancellation is reasoned and settled tickets are immutable', function () {
    $task = ItWorkTask::factory()->create([
        'ticket_id' => $this->ticket->id,
        'is_required' => true,
    ]);

    $this->actingAs($this->agent)
        ->patch("/it/tickets/{$this->ticket->id}/tasks/{$task->id}", [
            'status' => 'cancelled',
            'is_required' => false,
        ])
        ->assertSessionHasErrors('reason');
    expect($task->fresh()->status)->toBe('pending');

    $this->actingAs($this->agent)
        ->patch("/it/tickets/{$this->ticket->id}/tasks/{$task->id}", [
            'status' => 'cancelled',
            'reason' => 'No longer needed',
        ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Required tasks cannot be cancelled. Make the task optional or complete it.');

    $this->actingAs($this->agent)
        ->patch("/it/tickets/{$this->ticket->id}/tasks/{$task->id}", [
            'status' => 'cancelled',
            'is_required' => false,
            'reason' => 'The approved design no longer requires this optional check.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $task->refresh();
    expect($task->status)->toBe('cancelled')
        ->and($task->is_required)->toBeFalse()
        ->and(AuditLog::query()
            ->where('action', 'it.ticket.task.updated')
            ->where('auditable_id', $this->ticket->id)
            ->where('meta->reason', 'The approved design no longer requires this optional check.')
            ->exists())->toBeTrue();

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$this->ticket->id}/tasks/{$task->id}/complete")
        ->assertRedirect()
        ->assertSessionHas('error', 'Restore this cancelled task before completing it.');

    $this->ticket->update(['status' => 'resolved']);
    $this->actingAs($this->agent)
        ->post("/it/tickets/{$this->ticket->id}/tasks", ['title' => 'Late mutation'])
        ->assertRedirect()
        ->assertSessionHas('error', 'Reopen this ticket before changing its work tasks.');

    expect(ItWorkTask::query()->where('title', 'Late mutation')->exists())->toBeFalse();
});

test('task routes reject requesters other Site technicians and cross-ticket dependencies', function () {
    $this->actingAs($this->requester)
        ->post("/it/tickets/{$this->ticket->id}/tasks", ['title' => 'Injected'])
        ->assertForbidden();

    $otherSite = Site::factory()->create();
    $remoteAgent = workTaskUser('hr', $otherSite);
    $this->actingAs($remoteAgent)
        ->post("/it/tickets/{$this->ticket->id}/tasks", ['title' => 'Other Site task'])
        ->assertNotFound();

    $otherTicket = ItTicket::factory()->create(['site_id' => $this->site->id]);
    $otherTask = ItWorkTask::factory()->create([
        'ticket_id' => $otherTicket->id,
    ]);
    $this->actingAs($this->agent)
        ->post("/it/tickets/{$this->ticket->id}/tasks", [
            'title' => 'Cross-ticket dependency',
            'dependency_ids' => [$otherTask->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    $first = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id]);
    $second = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id]);
    $second->dependencies()->attach($first->id);
    $this->actingAs($this->agent)
        ->patch("/it/tickets/{$this->ticket->id}/tasks/{$first->id}", [
            'dependency_ids' => [$second->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(ItWorkTask::query()->where('title', 'Injected')->exists())->toBeFalse()
        ->and(ItWorkTask::query()->where('title', 'Other Site task')->exists())->toBeFalse()
        ->and(ItWorkTask::query()->where('title', 'Cross-ticket dependency')->exists())->toBeFalse()
        ->and($first->dependencies()->exists())->toBeFalse();
});
