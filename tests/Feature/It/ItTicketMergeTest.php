<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Inertia\Testing\AssertableInertia as Assert;

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
    $this->otherWorker = itMergeUser('support_worker');
    $this->site = Site::factory()->create();
    foreach ([$this->agent, $this->worker, $this->otherWorker] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }
});

function mergeTicket(array $overrides = []): ItTicket
{
    return ItTicket::factory()->create([
        'site_id' => test()->site->id,
        'requester_user_id' => test()->worker->id,
        'requested_for_user_id' => test()->worker->id,
        ...$overrides,
    ]);
}

test('an agent may merge two distinct live tickets', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);

    expect($this->agent->can('merge', [$source, $target]))->toBeTrue();
});

test('a requester cannot merge tickets', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);

    expect($this->worker->can('merge', [$source, $target]))->toBeFalse();
});

test('tickets with different requester audiences cannot be merged', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket([
        'status' => 'open',
        'requester_user_id' => $this->otherWorker->id,
        'requested_for_user_id' => $this->otherWorker->id,
    ]);

    expect($this->agent->can('merge', [$source, $target]))->toBeFalse();

    $this->actingAs($this->agent)
        ->from(route('it.tickets.show', $source))
        ->post("/it/tickets/{$source->id}/merge", [
            'target_ticket_id' => $target->id,
            'reason' => 'Same technical issue',
        ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Tickets with different requesters cannot be merged because their conversations are private.');

    expect($source->refresh()->merged_into_ticket_id)->toBeNull()
        ->and($source->status)->toBe('open');
});

test('a ticket cannot be merged into itself', function () {
    $ticket = mergeTicket(['status' => 'open']);

    expect($this->agent->can('merge', [$ticket, $ticket]))->toBeFalse();
});

test('an already-merged source cannot be merged again', function () {
    $target = mergeTicket(['status' => 'open']);
    $source = mergeTicket([
        'status' => 'closed',
        'merged_into_ticket_id' => $target->id,
        'merged_at' => now(),
    ]);
    $other = mergeTicket(['status' => 'open']);

    expect($this->agent->can('merge', [$source, $other]))->toBeFalse();
});

test('a closed target cannot receive a merge', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'closed']);

    expect($this->agent->can('merge', [$source, $target]))->toBeFalse();
});

test('merging folds the conversation and watchers onto the survivor and closes the source', function () {
    $source = mergeTicket([
        'status' => 'open',
        'requester_user_id' => $this->worker->id,
    ]);
    $target = mergeTicket(['status' => 'open']);

    $source->comments()->create([
        'author_user_id' => $this->worker->id,
        'body' => 'Same issue as the other ticket',
        'is_internal' => false,
    ]);
    $source->watchers()->syncWithoutDetaching([$this->worker->id]);

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$source->id}/merge", [
            'target_ticket_id' => $target->id,
            'reason' => 'Duplicate report of the same access issue',
        ])
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
    expect($source->events()->where('type', 'merged')->where('payload->reason', 'Duplicate report of the same access issue')->exists())->toBeTrue()
        ->and(AuditLog::query()
            ->where('action', 'it.ticket.merged')
            ->where('auditable_id', $source->id)
            ->exists())->toBeTrue();
});

test('merge requires a reason and a repeated merge cannot write duplicate history', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$source->id}/merge", ['target_ticket_id' => $target->id])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$source->id}/merge", [
            'target_ticket_id' => $target->id,
            'reason' => 'Duplicate report',
        ])
        ->assertRedirect(route('it.tickets.show', $target));

    $this->actingAs($this->agent)
        ->from(route('it.tickets.show', $source))
        ->post("/it/tickets/{$source->id}/merge", [
            'target_ticket_id' => $target->id,
            'reason' => 'Repeated stale request',
        ])
        ->assertRedirect()
        ->assertSessionHas('error', 'This ticket has already been merged.');

    expect($source->events()->where('type', 'merged')->count())->toBe(1)
        ->and($target->events()->where('type', 'merged')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.ticket.merged')->count())->toBe(1);
});

test('a merged source cannot be reopened', function () {
    $target = mergeTicket(['status' => 'open']);
    $source = mergeTicket([
        'status' => 'closed',
        'merged_into_ticket_id' => $target->id,
        'merged_at' => now(),
    ]);

    $this->actingAs($this->agent)
        ->from(route('it.tickets.show', $source))
        ->post("/it/tickets/{$source->id}/reopen", [
            'reason' => 'Attempting to reopen stale merged work.',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($source->refresh()->status)->toBe('closed');
});

test('the workspace offers live merge targets to agents but not requesters', function () {
    $me = mergeTicket([
        'status' => 'open',
        'requester_user_id' => $this->worker->id,
    ]);
    $candidate = mergeTicket(['status' => 'open']);
    mergeTicket([
        'status' => 'open',
        'requester_user_id' => $this->otherWorker->id,
        'requested_for_user_id' => $this->otherWorker->id,
    ]);
    // A closed-away merged ticket must never be offered as a target.
    mergeTicket([
        'status' => 'closed',
        'merged_into_ticket_id' => $candidate->id,
        'merged_at' => now(),
    ]);

    $this->actingAs($this->agent)
        ->get(route('it.tickets.show', $me))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.merge', true)
            ->has('mergeTargets', 1)
            ->where('mergeTargets.0.id', $candidate->id));

    $this->actingAs($this->worker)
        ->get(route('it.tickets.show', $me))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.merge', false)
            ->has('mergeTargets', 0));
});
