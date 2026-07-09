<?php

use App\Models\ItTicket;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

/*
 * §P-S2 — ticket merge authorisation (ItTicketPolicy@merge). The fold itself
 * (reparenting the thread/events/watchers) lands in S6; here we pin the guards.
 */

function itMergeUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->agent = itMergeUser('hr');              // holds it.manage
    $this->worker = itMergeUser('support_worker'); // it.request only
});

test('an agent may merge two distinct live tickets', function () {
    $source = ItTicket::factory()->create(['tenant_id' => 1, 'status' => 'open']);
    $target = ItTicket::factory()->create(['tenant_id' => 1, 'status' => 'open']);

    expect($this->agent->can('merge', [$source, $target]))->toBeTrue();
});

test('a requester cannot merge tickets', function () {
    $source = ItTicket::factory()->create(['tenant_id' => 1, 'status' => 'open']);
    $target = ItTicket::factory()->create(['tenant_id' => 1, 'status' => 'open']);

    expect($this->worker->can('merge', [$source, $target]))->toBeFalse();
});

test('a ticket cannot be merged into itself', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1, 'status' => 'open']);

    expect($this->agent->can('merge', [$ticket, $ticket]))->toBeFalse();
});

test('an already-merged source cannot be merged again', function () {
    $target = ItTicket::factory()->create(['tenant_id' => 1, 'status' => 'open']);
    $source = ItTicket::factory()->create([
        'tenant_id' => 1,
        'status' => 'closed',
        'merged_into_ticket_id' => $target->id,
        'merged_at' => now(),
    ]);
    $other = ItTicket::factory()->create(['tenant_id' => 1, 'status' => 'open']);

    expect($this->agent->can('merge', [$source, $other]))->toBeFalse();
});

test('a closed target cannot receive a merge', function () {
    $source = ItTicket::factory()->create(['tenant_id' => 1, 'status' => 'open']);
    $target = ItTicket::factory()->create(['tenant_id' => 1, 'status' => 'closed']);

    expect($this->agent->can('merge', [$source, $target]))->toBeFalse();
});
