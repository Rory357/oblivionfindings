<?php

use App\Models\ItProblem;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItWorkTask;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

function problemUser(string $role, int $tenantId = 1): User
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
    $this->agent = problemUser('hr');
    $this->requester = problemUser('support_worker');
});

test('an agent creates and finds a canonical investigating problem record', function () {
    $this->actingAs($this->agent)
        ->post('/it/problems', [
            'title' => 'Repeated VPN authentication failures',
            'description' => 'Multiple incidents share the same gateway symptom.',
            'category' => 'network',
            'priority' => 'high',
            'impact_summary' => 'On-call staff lose remote access intermittently.',
        ])
        ->assertRedirect();

    $problem = ItProblem::query()->with('ticket')->firstOrFail();
    expect($problem->ticket->reference)->toMatch('/^IT-\d{6}$/')
        ->and($problem->ticket->work_type)->toBe('problem')
        ->and($problem->ticket->workflow_state)->toBe('investigating')
        ->and($problem->ticket->status)->toBe('in_progress')
        ->and($problem->ticket->first_response_due_at)->not->toBeNull()
        ->and($problem->impact_summary)->toContain('remote access')
        ->and($problem->ticket->events()->where('type', 'created')->count())->toBe(1)
        ->and($problem->ticket->events()->where('type', 'workflow_transitioned')->count())->toBe(1);

    $this->actingAs($this->agent)
        ->get('/it/problems?state=investigating&q=VPN')
        ->assertInertia(fn ($page) => $page
            ->component('it/problems/index')
            ->has('problems.data', 1)
            ->where('problems.data.0.reference', $problem->ticket->reference));
});

test('root cause workaround and corrective action govern known error resolution and closure', function () {
    $problem = ItProblem::factory()->create();

    $this->actingAs($this->agent)
        ->post("/it/problems/{$problem->id}/transitions", [
            'workflow_state' => 'known_error',
            'reason' => 'Pattern confirmed',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->actingAs($this->agent)
        ->patch("/it/problems/{$problem->id}", [
            'root_cause' => 'Gateway certificate renewal left one node on the old chain.',
            'workaround' => 'Pin affected users to the healthy gateway node.',
            'corrective_action' => 'Replace the certificate chain and restart both nodes.',
            'next_action' => 'Schedule the permanent-fix change.',
        ])
        ->assertRedirect();
    $this->actingAs($this->agent)
        ->post("/it/problems/{$problem->id}/transitions", [
            'workflow_state' => 'known_error',
            'reason' => 'Root cause and workaround are verified.',
        ])
        ->assertRedirect();
    expect($problem->fresh()->known_error_at)->not->toBeNull()
        ->and($problem->ticket->fresh()->workflow_state)->toBe('known_error');

    $this->actingAs($this->agent)
        ->post("/it/problems/{$problem->id}/transitions", [
            'workflow_state' => 'resolved',
            'reason' => 'Permanent correction validated.',
            'resolution_code' => 'permanent_fix',
            'resolution_summary' => 'Certificate chain replaced on both gateway nodes.',
        ])
        ->assertRedirect();
    $this->actingAs($this->agent)
        ->post("/it/problems/{$problem->id}/transitions", [
            'workflow_state' => 'closed',
            'reason' => 'Post-resolution observation period passed.',
        ])
        ->assertRedirect();

    $closedTicket = $problem->ticket()->firstOrFail();
    expect($closedTicket->workflow_state)->toBe('closed')
        ->and($closedTicket->closed_at)->not->toBeNull();
});

test('affected incidents and the permanent fix change receive reciprocal typed links', function () {
    $problem = ItProblem::factory()->create([
        'workaround' => 'Reconnect through the secondary gateway.',
    ]);
    $incidentOne = ItTicket::factory()->create(['tenant_id' => 1, 'work_type' => 'incident']);
    $incidentTwo = ItTicket::factory()->create(['tenant_id' => 1, 'work_type' => 'incident']);
    $change = ItTicket::factory()->create([
        'tenant_id' => 1,
        'work_type' => 'change',
        'workflow_state' => 'draft',
    ]);

    $this->actingAs($this->agent)
        ->patch("/it/problems/{$problem->id}", [
            'incident_ids' => [$incidentOne->id, $incidentTwo->id],
            'permanent_fix_change_id' => $change->id,
        ])
        ->assertRedirect();

    expect($problem->ticket->links()->where('relationship', 'related_incident')->count())->toBe(2)
        ->and($problem->ticket->links()->where('relationship', 'related_change')->count())->toBe(1)
        ->and($incidentOne->links()->where('relationship', 'related_problem')->where('linkable_id', $problem->ticket_id)->exists())->toBeTrue()
        ->and($change->links()->where('relationship', 'related_problem')->where('linkable_id', $problem->ticket_id)->exists())->toBeTrue();

    $this->actingAs($this->agent)
        ->getJson("/it/tickets/{$incidentOne->id}")
        ->assertOk()
        ->assertJsonPath('linked_context.problems.0.reference', $problem->ticket->reference)
        ->assertJsonPath('linked_context.problems.0.workaround', 'Reconnect through the secondary gateway.')
        ->assertJsonPath('linked_context.problems.0.href', "/it/problems/{$problem->id}");

    $incidentOne->update(['requester_user_id' => $this->requester->id]);
    $this->actingAs($this->requester)
        ->getJson("/it/tickets/{$incidentOne->id}")
        ->assertOk()
        ->assertJsonCount(0, 'linked_context.problems');
});

test('the problem workspace projects the shared ticket conversation tasks approvals and timeline', function () {
    $problem = ItProblem::factory()->create();
    $problem->ticket->comments()->create([
        'tenant_id' => 1,
        'author_user_id' => $this->agent->id,
        'body' => 'Investigation started.',
        'is_internal' => true,
    ]);
    ItWorkTask::factory()->create(['tenant_id' => 1, 'ticket_id' => $problem->ticket_id]);
    ItTicketApproval::query()->create([
        'tenant_id' => 1,
        'it_ticket_id' => $problem->ticket_id,
        'requested_by' => $this->agent->id,
        'status' => 'pending',
    ]);

    $this->actingAs($this->agent)
        ->get("/it/problems/{$problem->id}")
        ->assertInertia(fn ($page) => $page
            ->component('it/problems/show')
            ->where('problem.id', $problem->id)
            ->where('ticket.href', "/it/tickets/{$problem->ticket_id}")
            ->where('ticket.comments_count', 1)
            ->where('ticket.tasks_count', 1)
            ->where('ticket.approvals_count', 1)
            ->where('ticket.events_count', $problem->ticket->events()->count()));
});

test('problem management is agent only tenant concealed and rejects invalid linked work types', function () {
    $problem = ItProblem::factory()->create(['tenant_id' => 1]);

    $this->actingAs($this->requester)->get('/it/problems')->assertForbidden();
    $this->actingAs($this->requester)
        ->patch("/it/problems/{$problem->id}", ['root_cause' => 'Injected'])
        ->assertForbidden();

    $foreignAgent = problemUser('hr', 2);
    $this->actingAs($foreignAgent)
        ->get("/it/problems/{$problem->id}")
        ->assertNotFound();

    $serviceRequest = ItTicket::factory()->create(['tenant_id' => 1, 'work_type' => 'service_request']);
    $this->actingAs($this->agent)
        ->patch("/it/problems/{$problem->id}", ['incident_ids' => [$serviceRequest->id]])
        ->assertRedirect()
        ->assertSessionHas('error');
    expect($problem->ticket->links()->count())->toBe(0);
});
