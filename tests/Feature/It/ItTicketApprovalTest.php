<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketApprovalNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * §P-S3 — approval authorisation. requestApproval lives on ItTicketPolicy (a
 * ticket action); decide lives on ItTicketApprovalPolicy (an approval action).
 * The request/approve/reject flow + resolve gate land in S9.
 */

function itApprovalUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->agent = itApprovalUser('hr');                // it.manage
    $this->manager = itApprovalUser('provider_manager'); // it.manage, a different agent
    $this->worker = itApprovalUser('support_worker');    // it.request only
    $this->site = Site::factory()->create();

    foreach ([$this->agent, $this->manager, $this->worker] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }
});

function approvalTicket(array $overrides = []): ItTicket
{
    return ItTicket::factory()->create([
        'site_id' => test()->site->id,
        ...$overrides,
    ]);
}

function pendingApprovalFor(ItTicket $ticket, User $requester): ItTicketApproval
{
    return ItTicketApproval::create([
        'it_ticket_id' => $ticket->id,
        'requested_by' => $requester->id,
        'status' => 'pending',
    ]);
}

test('an agent can request approval on a ticket that needs it', function () {
    $ticket = approvalTicket(['category' => 'account', 'requires_approval' => true]);

    expect($this->agent->can('requestApproval', $ticket))->toBeTrue();
});

test('approval cannot be requested twice while one is live', function () {
    $ticket = approvalTicket(['requires_approval' => true]);
    pendingApprovalFor($ticket, $this->agent);

    expect($this->agent->can('requestApproval', $ticket))->toBeFalse();
});

test('a ticket that does not require approval cannot request one', function () {
    $ticket = approvalTicket(['requires_approval' => false]);

    expect($this->agent->can('requestApproval', $ticket))->toBeFalse();
});

test('a requester cannot request approval', function () {
    $ticket = approvalTicket(['requires_approval' => true]);

    expect($this->worker->can('requestApproval', $ticket))->toBeFalse();
});

test('a different agent can decide a pending approval', function () {
    $ticket = approvalTicket(['requires_approval' => true]);
    $approval = pendingApprovalFor($ticket, $this->agent);

    expect($this->manager->can('decide', $approval))->toBeTrue();
});

test('an agent cannot approve their own request (separation of duties)', function () {
    $ticket = approvalTicket(['requires_approval' => true]);
    $approval = pendingApprovalFor($ticket, $this->agent);

    expect($this->agent->can('decide', $approval))->toBeFalse();
});

test('a decided approval cannot be decided again', function () {
    $ticket = approvalTicket(['requires_approval' => true]);
    $approval = ItTicketApproval::create([
        'it_ticket_id' => $ticket->id,
        'requested_by' => $this->agent->id,
        'approver_id' => $this->manager->id,
        'status' => 'approved',
        'decided_at' => now(),
    ]);

    expect($this->manager->can('decide', $approval))->toBeFalse();
});

test('the config drives which categories need approval', function () {
    expect(ItTicket::categoryNeedsApproval('account'))->toBeTrue();
    expect(ItTicket::categoryNeedsApproval('hardware'))->toBeFalse();
    expect(ItTicket::categoryNeedsApproval('other'))->toBeFalse();
    expect(ItTicket::categoryNeedsApproval(null))->toBeFalse();
});

/* ------------------------------------------------------------------ */
/*  §P-S3 (S9a) — the request / approve / reject flow */
/* ------------------------------------------------------------------ */

test('a ticket in an approval category is flagged requires_approval at creation', function () {
    $this->actingAs($this->worker)->post('/it/tickets', [
        'title' => 'New account for a starter',
        'category' => 'account',
        'priority' => 'normal',
    ])->assertRedirect();
    expect(ItTicket::query()->firstWhere('title', 'New account for a starter')->requires_approval)->toBeTrue();

    $this->actingAs($this->worker)->post('/it/tickets', [
        'title' => 'Wifi flaky',
        'category' => 'network',
        'priority' => 'normal',
    ])->assertRedirect();
    expect(ItTicket::query()->firstWhere('title', 'Wifi flaky')->requires_approval)->toBeFalse();
});

test('an agent requests approval, notifying the other agents but not themselves', function () {
    Notification::fake();
    $ticket = approvalTicket(['category' => 'account', 'requires_approval' => true]);

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$ticket->id}/approvals", ['reason' => 'New starter needs the shared mailbox'])
        ->assertRedirect();

    expect($ticket->approvals()->where('status', 'pending')->count())->toBe(1)
        ->and(AuditLog::query()
            ->where('action', 'it.ticket.approval.requested')
            ->where('auditable_id', $ticket->id)
            ->exists())->toBeTrue();
    Notification::assertSentTo($this->manager, TicketApprovalNotification::class);
    Notification::assertNotSentTo($this->agent, TicketApprovalNotification::class);
});

test('repeating an approval request is explained without creating duplicate work', function () {
    $ticket = approvalTicket(['requires_approval' => true]);

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$ticket->id}/approvals", ['reason' => 'Manager sign-off required'])
        ->assertRedirect();

    $this->actingAs($this->manager)
        ->from(route('it.tickets.show', $ticket))
        ->post("/it/tickets/{$ticket->id}/approvals", ['reason' => 'Duplicate request'])
        ->assertRedirect()
        ->assertSessionHas('error', 'This ticket already has an active approval decision.');

    expect($ticket->approvals()->count())->toBe(1)
        ->and($ticket->events()->where('type', 'approval_requested')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.ticket.approval.requested')->count())->toBe(1);
});

test('a manager approves a pending request and the requester is told', function () {
    Notification::fake();
    $ticket = approvalTicket(['requires_approval' => true]);
    $approval = pendingApprovalFor($ticket, $this->agent);

    $this->actingAs($this->manager)
        ->post("/it/approvals/{$approval->id}/decide", ['decision' => 'approve'])
        ->assertRedirect();

    $approval->refresh();
    expect($approval->status)->toBe('approved');
    expect($approval->approver_id)->toBe($this->manager->id);
    expect($approval->decided_at)->not->toBeNull();
    expect(AuditLog::query()
        ->where('action', 'it.ticket.approval.approved')
        ->where('auditable_id', $ticket->id)
        ->exists())->toBeTrue();
    Notification::assertSentTo($this->agent, TicketApprovalNotification::class);
});

test('a decided approval cannot be decided again or emit a contradictory event', function () {
    $ticket = approvalTicket(['requires_approval' => true]);
    $approval = pendingApprovalFor($ticket, $this->agent);

    $this->actingAs($this->manager)
        ->post("/it/approvals/{$approval->id}/decide", ['decision' => 'approve'])
        ->assertRedirect();

    $this->actingAs($this->worker)
        ->from(route('it.tickets.show', $ticket))
        ->post("/it/approvals/{$approval->id}/decide", [
            'decision' => 'reject',
            'reason' => 'Conflicting late decision',
        ])
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->from(route('it.tickets.show', $ticket))
        ->post("/it/approvals/{$approval->id}/decide", [
            'decision' => 'reject',
            'reason' => 'Conflicting late decision',
        ])
        ->assertRedirect()
        ->assertSessionHas('error', 'This approval has already been decided.');

    expect($approval->refresh()->status)->toBe('approved')
        ->and($ticket->events()->where('type', 'approval_approved')->count())->toBe(1)
        ->and($ticket->events()->where('type', 'approval_rejected')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'it.ticket.approval.approved')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.ticket.approval.rejected')->count())->toBe(0);
});

test('rejecting records the verdict and notifies the requester', function () {
    Notification::fake();
    $ticket = approvalTicket(['requires_approval' => true]);
    $approval = pendingApprovalFor($ticket, $this->agent);

    $this->actingAs($this->manager)
        ->post("/it/approvals/{$approval->id}/decide", ['decision' => 'reject', 'reason' => 'Not this quarter'])
        ->assertRedirect();

    expect($approval->refresh()->status)->toBe('rejected');
    Notification::assertSentTo($this->agent, TicketApprovalNotification::class);
});

test('a rejection requires a reason that the requester can act on', function () {
    $ticket = approvalTicket(['requires_approval' => true]);
    $approval = pendingApprovalFor($ticket, $this->agent);

    $this->actingAs($this->manager)
        ->post("/it/approvals/{$approval->id}/decide", ['decision' => 'reject'])
        ->assertSessionHasErrors('reason');

    expect($approval->refresh()->status)->toBe('pending');
});

test('an agent cannot approve their own request through the route', function () {
    $ticket = approvalTicket(['requires_approval' => true]);
    $approval = pendingApprovalFor($ticket, $this->agent);

    $this->actingAs($this->agent)
        ->post("/it/approvals/{$approval->id}/decide", ['decision' => 'approve'])
        ->assertForbidden();

    expect($approval->refresh()->status)->toBe('pending');
});

/* ------------------------------------------------------------------ */
/*  §P-S3 (S9b) — the resolve gate */
/* ------------------------------------------------------------------ */

function approvedTicket(int $requesterId, int $approverId): ItTicket
{
    $ticket = approvalTicket([
        'category' => 'account',
        'requires_approval' => true,
        'status' => 'in_progress',
    ]);
    ItTicketApproval::create([
        'it_ticket_id' => $ticket->id,
        'requested_by' => $requesterId,
        'approver_id' => $approverId,
        'status' => 'approved',
        'decided_at' => now(),
    ]);

    return $ticket;
}

test('a ticket needing approval cannot be resolved until it is approved', function () {
    $ticket = approvalTicket([
        'category' => 'account',
        'requires_approval' => true,
        'status' => 'in_progress',
    ]);

    $this->actingAs($this->agent)
        ->from(route('it.tickets.show', $ticket))
        ->post("/it/tickets/{$ticket->id}/resolve", ['note' => 'Set the account up'])
        ->assertRedirect()
        ->assertSessionHas('error');
    expect($ticket->refresh()->status)->toBe('in_progress');

    ItTicketApproval::create([
        'it_ticket_id' => $ticket->id,
        'requested_by' => $this->agent->id,
        'approver_id' => $this->manager->id,
        'status' => 'approved',
        'decided_at' => now(),
    ]);

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$ticket->id}/resolve", ['note' => 'Set the account up'])
        ->assertRedirect();
    expect($ticket->refresh()->status)->toBe('resolved');
});

test('a rejected approval still blocks resolution', function () {
    $ticket = approvalTicket([
        'requires_approval' => true,
        'status' => 'in_progress',
    ]);
    ItTicketApproval::create([
        'it_ticket_id' => $ticket->id,
        'requested_by' => $this->agent->id,
        'approver_id' => $this->manager->id,
        'status' => 'rejected',
        'decided_at' => now(),
    ]);

    $this->actingAs($this->agent)
        ->from(route('it.tickets.show', $ticket))
        ->post("/it/tickets/{$ticket->id}/resolve", ['note' => 'Trying anyway'])
        ->assertSessionHas('error');
    expect($ticket->refresh()->status)->toBe('in_progress');
});

test('the update route also refuses to resolve an unapproved ticket', function () {
    $ticket = approvalTicket([
        'requires_approval' => true,
        'status' => 'in_progress',
    ]);

    $this->actingAs($this->agent)
        ->from(route('it.tickets.show', $ticket))
        ->patch("/it/tickets/{$ticket->id}", ['status' => 'resolved'])
        ->assertSessionHasErrors('status');
    expect($ticket->refresh()->status)->toBe('in_progress');
});

test('an approved ticket still resolves only through the governed resolution journey', function () {
    $ticket = approvedTicket($this->agent->id, $this->manager->id);

    $this->actingAs($this->agent)
        ->patch("/it/tickets/{$ticket->id}", ['status' => 'resolved'])
        ->assertSessionHasErrors('status');
    expect($ticket->refresh()->status)->toBe('in_progress');

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$ticket->id}/resolve", ['note' => 'Approved account access was provisioned and verified.'])
        ->assertRedirect();
    expect($ticket->refresh()->status)->toBe('resolved');
});

test('a ticket that does not require approval resolves normally', function () {
    $ticket = approvalTicket([
        'category' => 'network',
        'requires_approval' => false,
        'status' => 'in_progress',
    ]);

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$ticket->id}/resolve", ['note' => 'Rebooted the access point'])
        ->assertRedirect();
    expect($ticket->refresh()->status)->toBe('resolved');
});

/* ------------------------------------------------------------------ */
/*  §P-S3 (S10a) — the workspace exposes approval state + affordances */
/* ------------------------------------------------------------------ */

test('the workspace exposes approval state and affordances', function () {
    $ticket = approvalTicket([
        'category' => 'account',
        'requires_approval' => true,
        'status' => 'in_progress',
    ]);

    // No request yet: an agent can raise one; nothing to decide.
    $this->actingAs($this->agent)
        ->get(route('it.tickets.show', $ticket))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ticket.requires_approval', true)
            ->where('ticket.approval', null)
            ->where('can.requestApproval', true)
            ->where('can.decideApproval', false));

    // With a pending request, a different agent can decide; the requester can't.
    pendingApprovalFor($ticket, $this->agent);

    $this->actingAs($this->manager)
        ->get(route('it.tickets.show', $ticket))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ticket.approval.status', 'pending')
            ->where('can.decideApproval', true)
            ->where('can.requestApproval', false));

    $this->actingAs($this->agent)
        ->get(route('it.tickets.show', $ticket))
        ->assertInertia(fn (Assert $page) => $page->where('can.decideApproval', false));
});
