<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketAssignedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

function itBulkUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->hr = itBulkUser('hr');
    $this->manager = itBulkUser('provider_manager');
    $this->worker = itBulkUser('support_worker');

    foreach ([$this->hr, $this->manager, $this->worker] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }
});

test('bulk assign hands a selection to one agent and notifies them once per ticket', function () {
    Notification::fake();
    $tickets = ItTicket::factory()->count(3)->create(['site_id' => $this->site->id]);

    $this->actingAs($this->hr)
        ->post('/it/tickets/bulk', [
            'ids' => $tickets->pluck('id')->all(),
            'action' => 'assign',
            'assigned_to_user_id' => $this->manager->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', '3 ticket(s) assigned.');

    foreach ($tickets as $ticket) {
        expect($ticket->refresh()->assigned_to_user_id)->toBe($this->manager->id);
        expect($ticket->events()->where('type', 'assigned')->count())->toBe(1);
    }
    Notification::assertSentToTimes($this->manager, TicketAssignedNotification::class, 3);

    // Re-running the same selection changes nothing and stays silent.
    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => $tickets->pluck('id')->all(),
        'action' => 'assign',
        'assigned_to_user_id' => $this->manager->id,
    ])->assertSessionHas('success', '0 ticket(s) assigned · 3 unchanged.');
    Notification::assertSentToTimes($this->manager, TicketAssignedNotification::class, 3);
});

test('bulk assign to yourself never self-notifies', function () {
    Notification::fake();
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id]);

    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => [$ticket->id],
        'action' => 'assign',
        'assigned_to_user_id' => $this->hr->id,
    ])->assertRedirect();

    expect($ticket->refresh()->assigned_to_user_id)->toBe($this->hr->id);
    Notification::assertNotSentTo($this->hr, TicketAssignedNotification::class);
});

test('bulk priority restamps the SLA clock from the new target', function () {
    // Created through the real write path so due dates are stamped low (4320/10080).
    $this->actingAs($this->worker)->post('/it/tickets', [
        'title' => 'Bulk priority fixture',
        'category' => 'hardware',
        'priority' => 'low',
    ])->assertRedirect();
    $ticket = ItTicket::query()->firstWhere('title', 'Bulk priority fixture');

    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => [$ticket->id],
        'action' => 'priority',
        'priority' => 'urgent',
    ])->assertRedirect();

    $ticket->refresh();
    expect($ticket->priority)->toBe('urgent');
    // Urgent defaults 60/240, anchored at creation — re-targeted, not restarted.
    expect($ticket->first_response_due_at->equalTo($ticket->created_at->copy()->addMinutes(60)))->toBeTrue();
    expect($ticket->events()->where('type', 'priority_changed')->count())->toBe(1);
});

test('bulk status moves working tickets and starts the waiting pause', function () {
    $open = ItTicket::factory()->create(['site_id' => $this->site->id]);
    $resolved = ItTicket::factory()->resolved()->create(['site_id' => $this->site->id]);

    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => [$open->id, $resolved->id],
        'action' => 'status',
        'status' => 'waiting',
    ])->assertSessionHas('success', '1 ticket(s) updated · 1 unchanged.');

    expect($open->refresh()->status)->toBe('waiting');
    expect($open->waiting_since)->not->toBeNull();
    expect($resolved->refresh()->status)->toBe('resolved'); // bulk never un-resolves

    // Settling by bare status is refused at validation — resolve needs a note.
    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => [$open->id],
        'action' => 'status',
        'status' => 'resolved',
    ])->assertSessionHasErrors('status');
});

test('bulk close settles everything still open and skips the already-closed', function () {
    $open = ItTicket::factory()->create(['site_id' => $this->site->id]);
    $waiting = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'status' => 'waiting',
        'waiting_since' => now()->subMinutes(30),
    ]);
    $closed = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'status' => 'closed',
        'closed_at' => now(),
    ]);

    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => [$open->id, $waiting->id, $closed->id],
        'action' => 'close',
    ])->assertSessionHas('success', '2 ticket(s) closed · 1 unchanged.');

    expect($open->refresh()->status)->toBe('closed');
    $waiting->refresh();
    expect($waiting->status)->toBe('closed');
    expect((int) $waiting->sla_paused_minutes)->toBeGreaterThanOrEqual(29); // pause banked on the way out
    expect($open->events()->where('type', 'closed')->count())->toBe(1);
});

test('bulk is agent-only and constrained to canonical Site access', function () {
    $inaccessibleSite = Site::factory()->create();
    $inaccessible = ItTicket::factory()->create(['site_id' => $inaccessibleSite->id]);
    $mine = ItTicket::factory()->create(['site_id' => $this->site->id]);

    $this->actingAs($this->worker)->post('/it/tickets/bulk', [
        'ids' => [$mine->id],
        'action' => 'close',
    ])->assertForbidden();

    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => [$inaccessible->id, $mine->id],
        'action' => 'close',
    ])->assertSessionHas('success', '1 ticket(s) closed · 1 unchanged.');

    expect($inaccessible->refresh()->status)->toBe('open')
        ->and($mine->refresh()->status)->toBe('closed');
});
