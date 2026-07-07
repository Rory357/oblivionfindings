<?php

use App\Models\ItTicket;
use App\Models\Role;
use App\Models\User;
use App\Notifications\It\TicketReopenedNotification;
use App\Notifications\It\TicketResolvedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

function itLifecycleUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->hr = itLifecycleUser('hr');
    $this->worker = itLifecycleUser('support_worker');
});

test('resolving requires a note and posts it as the final public reply', function () {
    Notification::fake();
    $watcher = itLifecycleUser('hr');
    $ticket = ItTicket::factory()->create([
        'requester_user_id' => $this->worker->id,
        'status' => 'in_progress',
    ]);
    $ticket->watchers()->attach($watcher->id);

    // No note → validation error, nothing changes.
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/resolve")
        ->assertSessionHasErrors('note');
    expect($ticket->fresh()->status)->toBe('in_progress');

    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/resolve", [
            'note' => 'Swapped the SIM — data working again.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $ticket->refresh();
    expect($ticket->status)->toBe('resolved');
    expect($ticket->resolved_at)->not->toBeNull();
    expect($ticket->first_responded_at)->not->toBeNull();

    // The note is the last PUBLIC comment on the thread.
    $last = $ticket->comments()->orderByDesc('id')->first();
    expect($last->body)->toBe('Swapped the SIM — data working again.');
    expect($last->is_internal)->toBeFalse();
    expect($ticket->events()->where('type', 'resolved')->count())->toBe(1);

    // Requester + watcher notified; never the actor.
    Notification::assertSentTo(
        $this->worker,
        TicketResolvedNotification::class,
        fn (TicketResolvedNotification $n) => $n->toArray($this->worker)['audience'] === 'requester',
    );
    Notification::assertSentTo(
        $watcher,
        TicketResolvedNotification::class,
        fn (TicketResolvedNotification $n) => $n->toArray($watcher)['audience'] === 'watcher',
    );
    Notification::assertNotSentTo($this->hr, TicketResolvedNotification::class);
});

test('the notify toggle silences the requester but never the watchers', function () {
    Notification::fake();
    $watcher = itLifecycleUser('hr');
    $ticket = ItTicket::factory()->create(['requester_user_id' => $this->worker->id]);
    $ticket->watchers()->attach($watcher->id);

    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/resolve", [
            'note' => 'Fixed quietly.',
            'notify_requester' => false,
        ])
        ->assertRedirect();

    Notification::assertNotSentTo($this->worker, TicketResolvedNotification::class);
    Notification::assertSentTo($watcher, TicketResolvedNotification::class);
});

test('resolving inside the resolution target marks the SLA met', function () {
    $ticket = ItTicket::factory()->create([
        'requester_user_id' => $this->worker->id,
        'resolution_due_at' => now()->addHours(4),
    ]);

    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/resolve", ['note' => 'Done inside target.'])
        ->assertRedirect();

    expect($ticket->fresh()->sla_state)->toBe('met');
});

test('close stamps closed_at and reopen brings the ticket back with a bump', function () {
    Notification::fake();
    $assignee = itLifecycleUser('hr');
    $ticket = ItTicket::factory()->resolved()->create([
        'requester_user_id' => $this->worker->id,
        'assigned_to_user_id' => $assignee->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/close")
        ->assertRedirect();
    $ticket->refresh();
    expect($ticket->status)->toBe('closed');
    expect($ticket->closed_at)->not->toBeNull();
    expect($ticket->events()->where('type', 'closed')->count())->toBe(1);

    // Agent reopen: back to open, counter bumped, assignee notified.
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/reopen")
        ->assertRedirect();
    $ticket->refresh();
    expect($ticket->status)->toBe('open');
    expect($ticket->closed_at)->toBeNull();
    expect($ticket->resolved_at)->toBeNull();
    expect((int) $ticket->reopened_count)->toBe(1);
    expect($ticket->events()->where('type', 'reopened')->count())->toBe(1);
    Notification::assertSentTo($assignee, TicketReopenedNotification::class);
});

test('requesters can reopen within seven days, not after', function () {
    $inside = ItTicket::factory()->create([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now()->subDays(3),
    ]);
    $outside = ItTicket::factory()->create([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now()->subDays(9),
    ]);

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$inside->id}/reopen")
        ->assertRedirect();
    expect($inside->fresh()->status)->toBe('open');
    expect((int) $inside->fresh()->reopened_count)->toBe(1);

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$outside->id}/reopen")
        ->assertForbidden();
    expect($outside->fresh()->status)->toBe('resolved');
});

test('the auto-close command sweeps tickets past the reopen window', function () {
    $stale = ItTicket::factory()->create([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now()->subDays(8),
    ]);
    $fresh = ItTicket::factory()->create([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now()->subDays(2),
    ]);

    $this->artisan('it:close-resolved')->assertSuccessful();

    expect($stale->fresh()->status)->toBe('closed');
    expect($stale->fresh()->closed_at)->not->toBeNull();
    expect(
        $stale->fresh()->events()->where('type', 'closed')->get()
            ->contains(fn ($e) => ($e->payload['via'] ?? null) === 'auto_close'),
    )->toBeTrue();
    expect($fresh->fresh()->status)->toBe('resolved');
});
