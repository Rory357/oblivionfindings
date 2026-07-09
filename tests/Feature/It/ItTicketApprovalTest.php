<?php

use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\Role;
use App\Models\User;
use App\Notifications\It\TicketApprovalNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

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
});

function pendingApprovalFor(ItTicket $ticket, User $requester): ItTicketApproval
{
    return ItTicketApproval::create([
        'tenant_id' => 1,
        'it_ticket_id' => $ticket->id,
        'requested_by' => $requester->id,
        'status' => 'pending',
    ]);
}

test('an agent can request approval on a ticket that needs it', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1, 'category' => 'account', 'requires_approval' => true]);

    expect($this->agent->can('requestApproval', $ticket))->toBeTrue();
});

test('approval cannot be requested twice while one is live', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1, 'requires_approval' => true]);
    pendingApprovalFor($ticket, $this->agent);

    expect($this->agent->can('requestApproval', $ticket))->toBeFalse();
});

test('a ticket that does not require approval cannot request one', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1, 'requires_approval' => false]);

    expect($this->agent->can('requestApproval', $ticket))->toBeFalse();
});

test('a requester cannot request approval', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1, 'requires_approval' => true]);

    expect($this->worker->can('requestApproval', $ticket))->toBeFalse();
});

test('a different agent can decide a pending approval', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1, 'requires_approval' => true]);
    $approval = pendingApprovalFor($ticket, $this->agent);

    expect($this->manager->can('decide', $approval))->toBeTrue();
});

test('an agent cannot approve their own request (separation of duties)', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1, 'requires_approval' => true]);
    $approval = pendingApprovalFor($ticket, $this->agent);

    expect($this->agent->can('decide', $approval))->toBeFalse();
});

test('a decided approval cannot be decided again', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1, 'requires_approval' => true]);
    $approval = ItTicketApproval::create([
        'tenant_id' => 1,
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
    expect(ItTicket::categoryNeedsApproval('hardware'))->toBeTrue();
    expect(ItTicket::categoryNeedsApproval('other'))->toBeFalse();
    expect(ItTicket::categoryNeedsApproval(null))->toBeFalse();
});

/* ------------------------------------------------------------------ */
/*  §P-S3 (S9a) — the request / approve / reject flow                  */
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
    $ticket = ItTicket::factory()->create(['tenant_id' => 1, 'category' => 'account', 'requires_approval' => true]);

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$ticket->id}/approvals", ['reason' => 'New starter needs the shared mailbox'])
        ->assertRedirect();

    expect($ticket->approvals()->where('status', 'pending')->count())->toBe(1);
    Notification::assertSentTo($this->manager, TicketApprovalNotification::class);
    Notification::assertNotSentTo($this->agent, TicketApprovalNotification::class);
});

test('a manager approves a pending request and the requester is told', function () {
    Notification::fake();
    $ticket = ItTicket::factory()->create(['tenant_id' => 1, 'requires_approval' => true]);
    $approval = pendingApprovalFor($ticket, $this->agent);

    $this->actingAs($this->manager)
        ->post("/it/approvals/{$approval->id}/decide", ['decision' => 'approve'])
        ->assertRedirect();

    $approval->refresh();
    expect($approval->status)->toBe('approved');
    expect($approval->approver_id)->toBe($this->manager->id);
    expect($approval->decided_at)->not->toBeNull();
    Notification::assertSentTo($this->agent, TicketApprovalNotification::class);
});

test('rejecting records the verdict and notifies the requester', function () {
    Notification::fake();
    $ticket = ItTicket::factory()->create(['tenant_id' => 1, 'requires_approval' => true]);
    $approval = pendingApprovalFor($ticket, $this->agent);

    $this->actingAs($this->manager)
        ->post("/it/approvals/{$approval->id}/decide", ['decision' => 'reject', 'reason' => 'Not this quarter'])
        ->assertRedirect();

    expect($approval->refresh()->status)->toBe('rejected');
    Notification::assertSentTo($this->agent, TicketApprovalNotification::class);
});

test('an agent cannot approve their own request through the route', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1, 'requires_approval' => true]);
    $approval = pendingApprovalFor($ticket, $this->agent);

    $this->actingAs($this->agent)
        ->post("/it/approvals/{$approval->id}/decide", ['decision' => 'approve'])
        ->assertForbidden();

    expect($approval->refresh()->status)->toBe('pending');
});
