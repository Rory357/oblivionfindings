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

test('merging folds the conversation and watchers onto the survivor and closes the source', function () {
    $source = ItTicket::factory()->create([
        'tenant_id' => 1,
        'status' => 'open',
        'requester_user_id' => $this->worker->id,
    ]);
    $target = ItTicket::factory()->create(['tenant_id' => 1, 'status' => 'open']);

    $source->comments()->create([
        'tenant_id' => 1,
        'author_user_id' => $this->worker->id,
        'body' => 'Same issue as the other ticket',
        'is_internal' => false,
    ]);
    $source->watchers()->syncWithoutDetaching([$this->worker->id]);

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$source->id}/merge", ['target_ticket_id' => $target->id])
        ->assertRedirect(route('it.tickets.show', $target));

    $source->refresh();
    expect($source->status)->toBe('closed');
    expect($source->merged_into_ticket_id)->toBe($target->id);
    expect($source->merged_at)->not->toBeNull();

    // Conversation + watcher moved to the survivor; the source is emptied.
    expect($target->comments()->count())->toBe(1);
    expect($source->comments()->count())->toBe(0);
    expect($target->watchers()->where('users.id', $this->worker->id)->exists())->toBeTrue();

    // A merged marker on each side.
    expect($source->events()->where('type', 'merged')->count())->toBe(1);
    expect($target->events()->where('type', 'merged')->count())->toBe(1);
});

test('a merged source cannot be reopened', function () {
    $target = ItTicket::factory()->create(['tenant_id' => 1, 'status' => 'open']);
    $source = ItTicket::factory()->create([
        'tenant_id' => 1,
        'status' => 'closed',
        'merged_into_ticket_id' => $target->id,
        'merged_at' => now(),
    ]);

    $this->actingAs($this->agent)
        ->from(route('it.tickets.show', $source))
        ->post("/it/tickets/{$source->id}/reopen")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($source->refresh()->status)->toBe('closed');
});
