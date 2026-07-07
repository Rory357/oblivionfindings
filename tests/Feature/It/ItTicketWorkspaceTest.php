<?php

use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\Role;
use App\Models\User;
use App\Notifications\It\TicketAssignedNotification;
use App\Notifications\It\TicketRepliedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

function itWorkspaceUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->hr = itWorkspaceUser('hr');
    $this->worker = itWorkspaceUser('support_worker');
});

test('the workspace strips internal notes from requester payloads server-side', function () {
    $ticket = ItTicket::factory()->create(['requester_user_id' => $this->worker->id]);
    $ticket->comments()->create([
        'tenant_id' => 1,
        'author_user_id' => $this->worker->id,
        'body' => 'Public: it is still broken.',
        'is_internal' => false,
    ]);
    $ticket->comments()->create([
        'tenant_id' => 1,
        'author_user_id' => $this->hr->id,
        'body' => 'Internal: suspect Hemi dropped it — order a rugged case.',
        'is_internal' => true,
    ]);

    // Requester: own ticket loads, internal note absent from the PAYLOAD.
    $this->actingAs($this->worker)
        ->get("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('it/tickets/show')
            ->where('ticket.id', $ticket->id)
            ->has('comments', 1)
            ->where('comments.0.is_internal', false)
            ->where('can.manage', false)
            ->where('can.internal', false));

    // Agent: both messages.
    $this->actingAs($this->hr)
        ->get("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('comments', 2)
            ->where('can.manage', true));

    // A different requester: not their ticket, not their business.
    $stranger = itWorkspaceUser('support_worker');
    $this->actingAs($stranger)->get("/it/tickets/{$ticket->id}")->assertForbidden();
});

test('comments respect the internal gate and stamp the first agent response', function () {
    Notification::fake();
    $ticket = ItTicket::factory()->create(['requester_user_id' => $this->worker->id]);

    // Requester replies publicly — fine; internal — forbidden.
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'Any update?'])
        ->assertRedirect();
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'sneaky', 'is_internal' => true])
        ->assertForbidden();

    // Agent internal note: allowed, does NOT stamp first response.
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'Checking the MDM logs.', 'is_internal' => true])
        ->assertRedirect();
    expect($ticket->fresh()->first_responded_at)->toBeNull();

    // First PUBLIC agent reply stamps it; a second one does not move it.
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'On it — swap unit heading your way.'])
        ->assertRedirect();
    $stamped = $ticket->fresh()->first_responded_at;
    expect($stamped)->not->toBeNull();

    $this->travel(10)->minutes();
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'Also updating the OS.'])
        ->assertRedirect();
    expect($ticket->fresh()->first_responded_at->equalTo($stamped))->toBeTrue();
});

test('a requester reply resumes a waiting ticket and banks the paused minutes', function () {
    $ticket = ItTicket::factory()->create([
        'requester_user_id' => $this->worker->id,
        'status' => 'waiting',
        'waiting_since' => now()->subMinutes(30),
    ]);

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'Here is the photo you asked for.'])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress');
    expect($ticket->waiting_since)->toBeNull();
    expect($ticket->sla_paused_minutes)->toBeGreaterThanOrEqual(29);
    expect(
        $ticket->events()->where('type', 'status_changed')->get()
            ->contains(fn (ItTicketEvent $e) => ($e->payload['via'] ?? null) === 'requester_reply'),
    )->toBeTrue();
});

test('public replies notify the other side of the conversation only', function () {
    Notification::fake();
    $watcher = itWorkspaceUser('hr');
    $ticket = ItTicket::factory()->create([
        'requester_user_id' => $this->worker->id,
        'assigned_to_user_id' => $this->hr->id,
        'status' => 'in_progress',
    ]);
    $ticket->watchers()->attach($watcher->id);

    // Agent public reply → requester hears about it; agent side does not.
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'Replacement approved.'])
        ->assertRedirect();
    Notification::assertSentTo(
        $this->worker,
        TicketRepliedNotification::class,
        fn (TicketRepliedNotification $n) => $n->toArray($this->worker)['audience'] === 'requester',
    );
    Notification::assertNotSentTo($watcher, TicketRepliedNotification::class);

    Notification::fake();

    // Requester reply → assignee + watcher hear; the actor never does.
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'Thanks — when?'])
        ->assertRedirect();
    Notification::assertSentTo($this->hr, TicketRepliedNotification::class);
    Notification::assertSentTo($watcher, TicketRepliedNotification::class);
    Notification::assertNotSentTo($this->worker, TicketRepliedNotification::class);

    Notification::fake();

    // Internal notes notify nobody.
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'internal musing', 'is_internal' => true])
        ->assertRedirect();
    Notification::assertNothingSent();
});

test('watch and unwatch are agent actions recorded on the trail', function () {
    $ticket = ItTicket::factory()->create(['requester_user_id' => $this->worker->id]);

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/watch")
        ->assertForbidden();

    $this->actingAs($this->hr)->post("/it/tickets/{$ticket->id}/watch")->assertRedirect();
    expect($ticket->watchers()->count())->toBe(1);
    expect($ticket->events()->where('type', 'watcher_added')->count())->toBe(1);

    // Idempotent: watching twice records once.
    $this->actingAs($this->hr)->post("/it/tickets/{$ticket->id}/watch")->assertRedirect();
    expect($ticket->events()->where('type', 'watcher_added')->count())->toBe(1);

    $this->actingAs($this->hr)->post("/it/tickets/{$ticket->id}/unwatch")->assertRedirect();
    expect($ticket->watchers()->count())->toBe(0);
    expect($ticket->events()->where('type', 'watcher_removed')->count())->toBe(1);
});

test('triage updates write the activity trail and notify the new assignee', function () {
    Notification::fake();
    $ticket = ItTicket::factory()->create(['requester_user_id' => $this->worker->id]);
    $colleague = itWorkspaceUser('hr');

    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", [
            'assigned_to_user_id' => $colleague->id,
            'priority' => 'high',
        ])
        ->assertRedirect();

    expect($ticket->events()->where('type', 'assigned')->count())->toBe(1);
    expect($ticket->events()->where('type', 'priority_changed')->count())->toBe(1);
    Notification::assertSentTo($colleague, TicketAssignedNotification::class);

    Notification::fake();

    // Assign-to-self never self-notifies.
    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", ['assigned_to_user_id' => $this->hr->id])
        ->assertRedirect();
    Notification::assertNotSentTo($this->hr, TicketAssignedNotification::class);

    // waiting via PATCH pauses; leaving banks the minutes.
    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", ['status' => 'waiting'])
        ->assertRedirect();
    expect($ticket->fresh()->waiting_since)->not->toBeNull();

    $this->travel(45)->minutes();
    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", ['status' => 'in_progress'])
        ->assertRedirect();
    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress');
    expect($ticket->waiting_since)->toBeNull();
    expect($ticket->sla_paused_minutes)->toBeGreaterThanOrEqual(44);
});
