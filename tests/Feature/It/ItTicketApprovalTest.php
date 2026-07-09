<?php

use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

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
