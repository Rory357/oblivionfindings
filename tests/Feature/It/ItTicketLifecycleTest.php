<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
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
    $this->site = Site::factory()->create();
    foreach ([$this->hr, $this->worker] as $user) {
        assignLifecycleUserToSite($user, $this->site);
    }
});

function assignLifecycleUserToSite(User $user, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);
}

function lifecycleTicket(array $overrides = [], bool $resolved = false): ItTicket
{
    $factory = ItTicket::factory();
    if ($resolved) {
        $factory = $factory->resolved();
    }

    return $factory->create([
        'site_id' => test()->site->id,
        ...$overrides,
    ]);
}

test('resolving requires a note and posts it as the final public reply', function () {
    Notification::fake();
    $watcher = itLifecycleUser('hr');
    assignLifecycleUserToSite($watcher, $this->site);
    $ticket = lifecycleTicket([
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
    expect($ticket->events()->where('type', 'resolved')->count())->toBe(1)
        ->and(AuditLog::query()
            ->where('action', 'it.work.transitioned')
            ->where('auditable_type', $ticket->getMorphClass())
            ->where('auditable_id', $ticket->id)
            ->count())->toBe(1)
        ->and(AuditLog::query()
            ->where('action', 'it.ticket.resolved')
            ->where('auditable_type', $ticket->getMorphClass())
            ->where('auditable_id', $ticket->id)
            ->count())->toBe(1);

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
    assignLifecycleUserToSite($watcher, $this->site);
    $ticket = lifecycleTicket(['requester_user_id' => $this->worker->id]);
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
    $ticket = lifecycleTicket([
        'requester_user_id' => $this->worker->id,
        'resolution_due_at' => now()->addHours(4),
    ]);

    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/resolve", ['note' => 'Done inside target.'])
        ->assertRedirect();

    expect($ticket->fresh()->sla_state)->toBe('met');
});

test('close requires a reason records it once and reopen brings the ticket back with a bump', function () {
    Notification::fake();
    $assignee = itLifecycleUser('hr');
    $watcher = itLifecycleUser('hr');
    assignLifecycleUserToSite($assignee, $this->site);
    assignLifecycleUserToSite($watcher, $this->site);
    $ticket = lifecycleTicket([
        'requester_user_id' => $this->worker->id,
        'assigned_to_user_id' => $assignee->id,
    ], resolved: true);
    $ticket->watchers()->attach($watcher->id);

    $this->actingAs($this->hr)
        ->from("/it/tickets/{$ticket->id}")
        ->post("/it/tickets/{$ticket->id}/close")
        ->assertRedirect("/it/tickets/{$ticket->id}")
        ->assertSessionHasErrors('reason');

    expect($ticket->refresh()->status)->toBe('resolved');

    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/close", [
            'reason' => 'Requester confirmed the restored service.',
        ])
        ->assertRedirect();
    $ticket->refresh();
    expect($ticket->status)->toBe('closed');
    expect($ticket->closed_at)->not->toBeNull();
    expect($ticket->events()->where('type', 'closed')->count())->toBe(1)
        ->and($ticket->events()->where('type', 'closed')->first()->payload['reason'])
        ->toBe('Requester confirmed the restored service.')
        ->and(AuditLog::query()
            ->where('action', 'it.ticket.closed')
            ->where('auditable_type', $ticket->getMorphClass())
            ->where('auditable_id', $ticket->id)
            ->count())->toBe(1);

    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/close", [
            'reason' => 'A stale repeated close.',
        ])
        ->assertSessionHas('error', 'This ticket is already closed.');

    expect($ticket->events()->where('type', 'closed')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.ticket.closed')->count())->toBe(1);

    // A stale one-click reopen cannot discard settlement without explaining why.
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/reopen")
        ->assertSessionHasErrors('reason');
    expect($ticket->fresh()->status)->toBe('closed');

    // Agent reopen: back to open, internal evidence recorded, responsible staff notified.
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/reopen", [
            'reason' => 'Monitoring shows the service failed again after validation.',
        ])
        ->assertRedirect();
    $ticket->refresh();
    expect($ticket->status)->toBe('open');
    expect($ticket->closed_at)->toBeNull();
    expect($ticket->resolved_at)->toBeNull();
    expect((int) $ticket->reopened_count)->toBe(1);
    expect($ticket->events()->where('type', 'reopened')->count())->toBe(1)
        ->and($ticket->events()->where('type', 'reopened')->first()->payload['reason'])
        ->toBe('Monitoring shows the service failed again after validation.')
        ->and($ticket->comments()->latest('id')->first()->body)
        ->toBe('Monitoring shows the service failed again after validation.')
        ->and($ticket->comments()->latest('id')->first()->is_internal)->toBeTrue()
        ->and(AuditLog::query()
            ->where('action', 'it.ticket.reopened')
            ->where('auditable_type', $ticket->getMorphClass())
            ->where('auditable_id', $ticket->id)
            ->count())->toBe(1);
    Notification::assertSentTo($assignee, TicketReopenedNotification::class);
    Notification::assertSentTo($watcher, TicketReopenedNotification::class);
    Notification::assertNotSentTo($this->hr, TicketReopenedNotification::class);

    $requesterPayload = $this->actingAs($this->worker)
        ->getJson("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->json();
    expect(collect($requesterPayload['comments'])->pluck('body')->all())
        ->not->toContain('Monitoring shows the service failed again after validation.');
    $requesterReopenEvent = collect($requesterPayload['events'])->firstWhere('type', 'reopened');
    expect($requesterReopenEvent['payload'] ?? [])->not->toHaveKey('reason');
});

test('requesters can reopen within seven days, not after', function () {
    Notification::fake();
    $inside = lifecycleTicket([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now()->subDays(3),
    ]);
    $outside = lifecycleTicket([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now()->subDays(9),
    ]);

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$inside->id}/reopen")
        ->assertSessionHasErrors('reason');
    expect($inside->fresh()->status)->toBe('resolved');

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$inside->id}/reopen", [
            'reason' => 'The same fault returned when I signed in again.',
        ])
        ->assertRedirect();
    expect($inside->fresh()->status)->toBe('open');
    expect((int) $inside->fresh()->reopened_count)->toBe(1)
        ->and($inside->comments()->latest('id')->first()->body)
        ->toBe('The same fault returned when I signed in again.')
        ->and($inside->comments()->latest('id')->first()->is_internal)->toBeFalse()
        ->and($inside->events()->where('type', 'reopened')->first()->payload['reason'])
        ->toBe('The same fault returned when I signed in again.');

    $requesterPayload = $this->actingAs($this->worker)
        ->getJson("/it/tickets/{$inside->id}")
        ->assertOk()
        ->json();
    expect(collect($requesterPayload['comments'])->pluck('body')->all())
        ->toContain('The same fault returned when I signed in again.');
    $requesterReopenEvent = collect($requesterPayload['events'])->firstWhere('type', 'reopened');
    expect($requesterReopenEvent['payload'] ?? [])->not->toHaveKey('reason');

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$outside->id}/reopen", [
            'reason' => 'The fault returned outside the allowed reopen window.',
        ])
        ->assertForbidden();
    expect($outside->fresh()->status)->toBe('resolved');
});

test('the auto-close command sweeps tickets past the reopen window', function () {
    $stale = lifecycleTicket([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now()->subDays(8),
    ]);
    $fresh = lifecycleTicket([
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
