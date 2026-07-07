<?php

use App\Models\ItTicket;
use App\Models\Role;
use App\Models\User;
use App\Notifications\It\TicketSlaNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

function itSlaCmdUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

function itSlaCmdTicket(User $requester, array $overrides = []): ItTicket
{
    test()->actingAs($requester)
        ->post('/it/tickets', array_merge([
            'title' => 'SLA fixture '.uniqid(),
            'category' => 'hardware',
            'priority' => 'normal',
        ], array_intersect_key($overrides, array_flip(['title', 'category', 'priority', 'description']))))
        ->assertRedirect();

    $ticket = ItTicket::query()->latest('id')->first();
    $direct = array_diff_key($overrides, array_flip(['title', 'category', 'priority', 'description']));
    if ($direct !== []) {
        $ticket->forceFill($direct)->save();
        $ticket->refresh();
    }

    return $ticket;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->hr = itSlaCmdUser('hr');
    $this->worker = itSlaCmdUser('support_worker');
});

test('the command flags a ticket at risk and tells the assignee exactly once', function () {
    Notification::fake();
    // Urgent defaults: first response 60m / resolution 240m.
    $ticket = itSlaCmdTicket($this->worker, [
        'priority' => 'urgent',
        'assigned_to_user_id' => $this->hr->id,
    ]);

    // 50 of 60 minutes gone — 10m left is inside the 25% at-risk band.
    $this->travel(50)->minutes();
    $this->artisan('it:check-sla')->assertSuccessful();

    $ticket->refresh();
    expect($ticket->sla_state)->toBe('at_risk');
    expect($ticket->events()->where('type', 'sla_at_risk')->count())->toBe(1);
    Notification::assertSentToTimes($this->hr, TicketSlaNotification::class, 1);
    Notification::assertSentTo($this->hr, TicketSlaNotification::class,
        fn ($n, $channels, $notifiable) => $n->toArray($notifiable)['transition'] === 'at_risk'
            && $n->toArray($notifiable)['clock'] === 'first_response');

    // Idempotent: a second run moves nothing and re-sends nothing.
    $this->artisan('it:check-sla')->assertSuccessful();
    expect($ticket->refresh()->sla_state)->toBe('at_risk');
    expect($ticket->events()->where('type', 'sla_at_risk')->count())->toBe(1);
    Notification::assertSentToTimes($this->hr, TicketSlaNotification::class, 1);
});

test('a breach notifies the assignee and every it.manage agent exactly once', function () {
    Notification::fake();
    $manager = itSlaCmdUser('provider_manager'); // holds it.manage via the seeder
    $ticket = itSlaCmdTicket($this->worker, [
        'priority' => 'urgent',
        'assigned_to_user_id' => $this->hr->id,
    ]);

    // Straight past the 60m first-response target between hourly runs:
    // enters breached directly — no at-risk noise on the way through.
    $this->travel(70)->minutes();
    $this->artisan('it:check-sla')->assertSuccessful();

    $ticket->refresh();
    expect($ticket->sla_state)->toBe('breached');
    expect($ticket->events()->where('type', 'sla_breached')->count())->toBe(1);
    expect($ticket->events()->where('type', 'sla_at_risk')->count())->toBe(0);

    // hr is assignee AND an it.manage agent — deduped to one notification.
    Notification::assertSentToTimes($this->hr, TicketSlaNotification::class, 1);
    Notification::assertSentToTimes($manager, TicketSlaNotification::class, 1);
    Notification::assertNotSentTo($this->worker, TicketSlaNotification::class);

    $this->artisan('it:check-sla')->assertSuccessful();
    Notification::assertSentToTimes($this->hr, TicketSlaNotification::class, 1);
    Notification::assertSentToTimes($manager, TicketSlaNotification::class, 1);
});

test('paused waiting minutes hold the resolution clock back', function () {
    Notification::fake();
    // Normal defaults: resolution 4320m (3 days). Stamp first response on
    // all three so only the resolution clock is live.
    $banked = itSlaCmdTicket($this->worker, [
        'first_responded_at' => now(),
        'sla_paused_minutes' => 2000,
    ]);
    $liveWaiting = itSlaCmdTicket($this->worker, [
        'first_responded_at' => now(),
        'status' => 'waiting',
        'waiting_since' => now(),
    ]);
    $control = itSlaCmdTicket($this->worker, [
        'first_responded_at' => now(),
    ]);

    // 3 days + 1 hour: the raw resolution target has passed on all three.
    $this->travel(3)->days();
    $this->travel(1)->hours();
    $this->artisan('it:check-sla')->assertSuccessful();

    // Banked pause (2000m) and a live pause running since creation both
    // push the effective deadline out; the unpaused control breaches.
    expect($banked->refresh()->sla_state)->toBe('ok');
    expect($liveWaiting->refresh()->sla_state)->toBe('ok');
    expect($control->refresh()->sla_state)->toBe('breached');
});

test('an unassigned urgent ticket escalates to admins once after 30 minutes', function () {
    Notification::fake();
    $admin = itSlaCmdUser('admin');
    $unassignedUrgent = itSlaCmdTicket($this->worker, ['priority' => 'urgent']);
    $assignedUrgent = itSlaCmdTicket($this->worker, [
        'priority' => 'urgent',
        'assigned_to_user_id' => $this->hr->id,
    ]);
    $unassignedNormal = itSlaCmdTicket($this->worker, ['priority' => 'normal']);

    // 40 minutes: past the 30m escalation line, still inside every SLA
    // window (urgent first response has 20 of 60 minutes left — above the
    // 25% band), so the only notification in play is the escalation.
    $this->travel(40)->minutes();
    $this->artisan('it:check-sla')->assertSuccessful();

    expect($unassignedUrgent->events()->where('type', 'sla_escalated')->count())->toBe(1);
    expect($assignedUrgent->events()->where('type', 'sla_escalated')->count())->toBe(0);
    expect($unassignedNormal->events()->where('type', 'sla_escalated')->count())->toBe(0);
    Notification::assertSentToTimes($admin, TicketSlaNotification::class, 1);
    Notification::assertSentTo($admin, TicketSlaNotification::class,
        fn ($n, $channels, $notifiable) => $n->toArray($notifiable)['transition'] === 'escalation'
            && $n->toArray($notifiable)['ticket_id'] === $unassignedUrgent->id);
    Notification::assertNotSentTo($this->hr, TicketSlaNotification::class);

    // The event row is the guard: a re-run never re-pages the admins.
    $this->artisan('it:check-sla')->assertSuccessful();
    expect($unassignedUrgent->events()->where('type', 'sla_escalated')->count())->toBe(1);
    Notification::assertSentToTimes($admin, TicketSlaNotification::class, 1);
});

test('unstamped, resolved and met tickets are left alone', function () {
    Notification::fake();
    // Factory fixtures never stamp SLA targets — the command must skip them.
    $legacy = ItTicket::factory()->create(['status' => 'open']);

    // Resolved inside target → met; resolution drops it out of the open set.
    $met = itSlaCmdTicket($this->worker, ['priority' => 'urgent']);
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$met->id}/resolve", ['note' => 'Swapped the charger.'])
        ->assertRedirect();
    expect($met->refresh()->sla_state)->toBe('met');

    $this->travel(10)->days();
    $this->artisan('it:check-sla')->assertSuccessful();

    expect($legacy->refresh()->sla_state)->toBe('ok');
    expect($legacy->events()->count())->toBe(0);
    expect($met->refresh()->sla_state)->toBe('met');
    expect($met->events()->whereIn('type', ['sla_at_risk', 'sla_breached', 'sla_escalated'])->count())->toBe(0);
    // Fixture receipts (created/resolved) aside, the SLA watchdog itself
    // stayed silent.
    Notification::assertSentTimes(TicketSlaNotification::class, 0);
});

test('a growing waiting pause relaxes a breached ticket without re-paging anyone', function () {
    Notification::fake();
    $ticket = itSlaCmdTicket($this->worker, [
        'priority' => 'urgent',
        'assigned_to_user_id' => $this->hr->id,
        'first_responded_at' => now(), // isolate the resolution clock (240m)
    ]);

    $this->travel(250)->minutes();
    $this->artisan('it:check-sla')->assertSuccessful();
    expect($ticket->refresh()->sla_state)->toBe('breached');
    Notification::assertSentToTimes($this->hr, TicketSlaNotification::class, 1);

    // The requester goes quiet and the agent parks it: banked pause moves
    // the effective deadline out past now — the state honestly relaxes,
    // silently (no event spam, no notification).
    $ticket->forceFill(['sla_paused_minutes' => 300])->save();
    $this->artisan('it:check-sla')->assertSuccessful();

    expect($ticket->refresh()->sla_state)->toBe('ok');
    expect($ticket->events()->where('type', 'sla_breached')->count())->toBe(1);
    Notification::assertSentToTimes($this->hr, TicketSlaNotification::class, 1);
});
