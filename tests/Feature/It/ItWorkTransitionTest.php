<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Services\ItWorkTransitionService;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItWorkTask;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function itTransitionAgent(?Site $site = null): User
{
    $user = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
        'organization_id' => 1,
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
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
});

it('applies an allowed lifecycle state for every canonical work type', function (
    string $workType,
    string $from,
    string $to,
    string $expectedStatus,
    ?string $waitingParty,
) {
    $site = Site::factory()->create();
    $agent = itTransitionAgent($site);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'work_type' => $workType,
        'workflow_state' => $from,
        'status' => in_array($from, ['submitted', 'draft', 'declared'], true) ? 'open' : 'in_progress',
    ]);

    $result = app(ItWorkTransitionService::class)->transition(
        $ticket,
        new ItTransitionInput(
            actor: $agent,
            to: ItWorkflowState::from($to),
            reason: $waitingParty ? 'Approval is required' : 'Work has started',
            waitingParty: $waitingParty,
        ),
    );

    expect($result->workflow_state)->toBe($to)
        ->and($result->status)->toBe($expectedStatus)
        ->and($result->events()->count())->toBe(1)
        ->and($result->events()->sole()->type)->toBe('workflow_transitioned')
        ->and($result->events()->sole()->payload)->toMatchArray([
            'from_workflow_state' => $from,
            'to_workflow_state' => $to,
        ]);
})->with([
    'incident' => ['incident', 'submitted', 'triaged', 'in_progress', null],
    'service request' => ['service_request', 'submitted', 'fulfilling', 'in_progress', null],
    'security request' => ['security_request', 'submitted', 'approval_pending', 'waiting', 'approver'],
    'problem' => ['problem', 'submitted', 'investigating', 'in_progress', null],
    'change' => ['change', 'draft', 'assessment', 'in_progress', null],
    'task' => ['task', 'submitted', 'in_progress', 'in_progress', null],
    'major incident' => ['major_incident', 'declared', 'responding', 'in_progress', null],
]);

it('rejects a lifecycle state that is not allowed for the work type', function () {
    $site = Site::factory()->create();
    $agent = itTransitionAgent($site);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'work_type' => 'incident',
        'workflow_state' => 'submitted',
    ]);

    expect(fn () => app(ItWorkTransitionService::class)->transition(
        $ticket,
        new ItTransitionInput(
            actor: $agent,
            to: ItWorkflowState::Implementing,
        ),
    ))->toThrow(DomainException::class, 'not allowed');

    expect($ticket->fresh()->workflow_state)->toBe('submitted')
        ->and($ticket->events()->doesntExist())->toBeTrue();
});

it('requires a waiting party and reason before pausing work', function () {
    $site = Site::factory()->create();
    $agent = itTransitionAgent($site);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'work_type' => 'incident',
        'workflow_state' => 'in_progress',
        'status' => 'in_progress',
    ]);
    $service = app(ItWorkTransitionService::class);

    expect(fn () => $service->transition(
        $ticket,
        new ItTransitionInput(
            actor: $agent,
            to: ItWorkflowState::Waiting,
        ),
    ))->toThrow(DomainException::class, 'waiting party and reason');

    $result = $service->transition(
        $ticket,
        new ItTransitionInput(
            actor: $agent,
            to: ItWorkflowState::Waiting,
            reason: 'Vendor replacement is due tomorrow',
            waitingParty: 'vendor',
            nextAction: 'Confirm the replacement tracking number',
        ),
    );

    expect($result->status)->toBe('waiting')
        ->and($result->waiting_party)->toBe('vendor')
        ->and($result->waiting_reason)->toBe('Vendor replacement is due tomorrow')
        ->and($result->next_action)->toBe('Confirm the replacement tracking number')
        ->and($result->waiting_since)->not->toBeNull();
});

it('blocks settlement until approvals required tasks and resolution evidence are complete', function () {
    $site = Site::factory()->create();
    $agent = itTransitionAgent($site);
    $service = app(ItWorkTransitionService::class);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'work_type' => 'incident',
        'workflow_state' => 'in_progress',
        'status' => 'in_progress',
        'requires_approval' => true,
    ]);

    expect(fn () => $service->transition(
        $ticket,
        new ItTransitionInput(
            actor: $agent,
            to: ItWorkflowState::Resolved,
            resolutionCode: 'restored',
            resolutionSummary: 'Service restored.',
        ),
    ))->toThrow(DomainException::class, 'approval');

    ItTicketApproval::create([
        'tenant_id' => 1,
        'it_ticket_id' => $ticket->id,
        'requested_by' => User::factory()->create()->id,
        'approver_id' => $agent->id,
        'status' => 'approved',
        'decided_at' => now(),
    ]);
    $task = ItWorkTask::factory()->for($ticket, 'ticket')->create([
        'tenant_id' => 1,
        'is_required' => true,
        'status' => 'pending',
    ]);

    expect(fn () => $service->transition(
        $ticket,
        new ItTransitionInput(
            actor: $agent,
            to: ItWorkflowState::Resolved,
            resolutionCode: 'restored',
            resolutionSummary: 'Service restored.',
        ),
    ))->toThrow(DomainException::class, 'required tasks');

    $task->update([
        'status' => 'completed',
        'completed_at' => now(),
        'completed_by_user_id' => $agent->id,
    ]);

    expect(fn () => $service->transition(
        $ticket,
        new ItTransitionInput(
            actor: $agent,
            to: ItWorkflowState::Resolved,
        ),
    ))->toThrow(DomainException::class, 'resolution code and summary');

    $result = $service->transition(
        $ticket,
        new ItTransitionInput(
            actor: $agent,
            to: ItWorkflowState::Resolved,
            resolutionCode: 'restored',
            resolutionSummary: 'The failed switch was replaced and service checks passed.',
        ),
    );

    expect($result->status)->toBe('resolved')
        ->and($result->workflow_state)->toBe('resolved')
        ->and($result->resolution_code)->toBe('restored')
        ->and($result->resolution_summary)->toContain('switch was replaced')
        ->and($result->resolved_at)->not->toBeNull();
});

it('authorizes the canonical Site independently of the legacy storage context', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $agent = itTransitionAgent($site);
    $allowed = ItTicket::factory()->create([
        'tenant_id' => 202,
        'site_id' => $site->id,
        'workflow_state' => 'submitted',
    ]);
    $denied = ItTicket::factory()->create([
        'tenant_id' => 202,
        'site_id' => $otherSite->id,
        'workflow_state' => 'submitted',
    ]);

    $result = app(ItWorkTransitionService::class)->transition(
        $allowed,
        new ItTransitionInput(
            actor: $agent,
            to: ItWorkflowState::Triaged,
        ),
    );

    expect($result->workflow_state)->toBe('triaged');
    expect(fn () => app(ItWorkTransitionService::class)->transition(
        $denied,
        new ItTransitionInput(
            actor: $agent,
            to: ItWorkflowState::Triaged,
        ),
    ))->toThrow(DomainException::class, 'not allowed');

    expect($denied->fresh()->workflow_state)->toBe('submitted');
});

it('allows an owning requester reply to resume waiting work without manage permission', function () {
    $requester = User::factory()->create(['organization_id' => 1]);
    $ticket = ItTicket::factory()->create([
        'requester_user_id' => $requester->id,
        'work_type' => 'incident',
        'workflow_state' => 'waiting',
        'status' => 'waiting',
        'waiting_party' => 'requester',
        'waiting_reason' => 'More information required',
        'waiting_since' => now()->subMinutes(30),
    ]);

    $result = app(ItWorkTransitionService::class)->transition(
        $ticket,
        new ItTransitionInput(
            actor: $requester,
            to: ItWorkflowState::InProgress,
            reason: 'Requester replied',
            source: 'requester_reply',
        ),
    );

    expect($result->status)->toBe('in_progress')
        ->and($result->workflow_state)->toBe('in_progress')
        ->and($result->waiting_party)->toBeNull()
        ->and($result->waiting_reason)->toBeNull()
        ->and($result->waiting_since)->toBeNull()
        ->and($result->sla_paused_minutes)->toBeGreaterThanOrEqual(29);
});

it('exposes the governed transition through the existing ticket authorization boundary', function () {
    $site = Site::factory()->create();
    $agent = itTransitionAgent($site);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'work_type' => 'incident',
        'workflow_state' => 'submitted',
    ]);

    $this->actingAs($agent)
        ->post("/it/tickets/{$ticket->id}/transitions", [
            'workflow_state' => 'triaged',
            'reason' => 'Impact and urgency confirmed',
            'next_action' => 'Assign the infrastructure team',
        ])
        ->assertRedirect();

    expect($ticket->fresh()->workflow_state)->toBe('triaged')
        ->and($ticket->fresh()->next_action)->toBe('Assign the infrastructure team')
        ->and($ticket->events()->sole()->type)->toBe('workflow_transitioned');

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post("/it/tickets/{$ticket->id}/transitions", [
            'workflow_state' => 'in_progress',
        ])
        ->assertForbidden();
});
