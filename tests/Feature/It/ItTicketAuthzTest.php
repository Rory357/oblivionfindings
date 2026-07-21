<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Gate;

function itAuthzUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->hr = itAuthzUser('hr');
    $this->worker = itAuthzUser('support_worker');
    $this->site = Site::factory()->create();
    foreach ([$this->hr, $this->worker] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }
});

test('a support worker can raise a ticket but the triage fields are ignored', function () {
    $this->actingAs($this->worker)
        ->post('/it/tickets', [
            'title' => 'Work phone dead — Aroha House',
            'description' => 'Won’t charge on any cable.',
            'category' => 'hardware',
            'priority' => 'urgent',
            // A requester must not be able to smuggle triage decisions in:
            'assigned_to_user_id' => $this->hr->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $ticket = ItTicket::query()->firstWhere('title', 'Work phone dead — Aroha House');
    expect($ticket)->not->toBeNull();
    expect((int) $ticket->requester_user_id)->toBe($this->worker->id);
    expect($ticket->assigned_to_user_id)->toBeNull();
    expect($ticket->status)->toBe('open');
    expect($ticket->priority)->toBe('urgent');
});

test('agents can still log-and-triage with an assignee in one step', function () {
    $this->actingAs($this->hr)
        ->post('/it/tickets', [
            'title' => 'New starter laptop imaging',
            'category' => 'hardware',
            'priority' => 'normal',
            'site_id' => $this->site->id,
            'assigned_to_user_id' => $this->hr->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $ticket = ItTicket::query()->firstWhere('title', 'New starter laptop imaging');
    expect((int) $ticket->assigned_to_user_id)->toBe($this->hr->id);
    expect($ticket->status)->toBe('in_progress');
});

test('a requester sees only their own tickets in the payload', function () {
    $mine = ItTicket::query()->create([
        'tenant_id' => 1,
        'site_id' => $this->site->id,
        'title' => 'My broken headset',
        'requester_user_id' => $this->worker->id,
        'category' => 'hardware',
        'priority' => 'normal',
        'status' => 'open',
    ]);
    ItTicket::query()->create([
        'tenant_id' => 1,
        'site_id' => $this->site->id,
        'title' => 'Someone else’s VPN issue',
        'requester_user_id' => $this->hr->id,
        'category' => 'network',
        'priority' => 'high',
        'status' => 'open',
    ]);

    $this->actingAs($this->worker)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('it/index')
            ->has('myTickets', 1)
            ->where('myTickets.0.id', $mine->id)
            ->where('myTickets.0.title', 'My broken headset')
            ->missing('tickets')
            ->missing('requests')
            ->missing('assignees'));

    // Agents see the full queue — including the worker's ticket.
    $this->actingAs($this->hr)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tickets.data', 2)
            ->has('myTickets', 1));
});

test('requesters cannot work the queue or the provisioning routes', function () {
    $ticket = ItTicket::query()->create([
        'tenant_id' => 1,
        'site_id' => $this->site->id,
        'title' => 'Queue ticket',
        'requester_user_id' => $this->worker->id,
        'category' => 'other',
        'priority' => 'low',
        'status' => 'open',
    ]);

    $this->actingAs($this->worker)
        ->patch("/it/tickets/{$ticket->id}", ['status' => 'in_progress'])
        ->assertForbidden();

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/resolve")
        ->assertForbidden();
});

test('the ticket policy scopes view, reopen and delete correctly', function () {
    $ticket = ItTicket::query()->create([
        'tenant_id' => 1,
        'site_id' => $this->site->id,
        'title' => 'Policy ticket',
        'requester_user_id' => $this->worker->id,
        'category' => 'other',
        'priority' => 'low',
        'status' => 'resolved',
        'resolved_at' => now()->subDays(2),
    ]);

    $stranger = itAuthzUser('support_worker');

    // view: agent or owner only.
    expect(Gate::forUser($this->worker)->allows('view', $ticket))->toBeTrue();
    expect(Gate::forUser($this->hr)->allows('view', $ticket))->toBeTrue();
    expect(Gate::forUser($stranger)->allows('view', $ticket))->toBeFalse();

    // reopen: owner inside the 7-day window; agents anytime; strangers never.
    expect(Gate::forUser($this->worker)->allows('reopen', $ticket))->toBeTrue();
    expect(Gate::forUser($stranger)->allows('reopen', $ticket))->toBeFalse();
    expect(Gate::forUser($this->hr)->allows('reopen', $ticket))->toBeTrue();

    $ticket->update(['resolved_at' => now()->subDays(8)]);
    expect(Gate::forUser($this->worker)->allows('reopen', $ticket->fresh()))->toBeFalse();

    // delete: admins only — even it.manage holders are refused.
    $admin = itAuthzUser('admin');
    HrEmployeeProfile::factory()->create([
        'user_id' => $admin->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);
    expect(Gate::forUser($this->hr)->allows('delete', $ticket))->toBeFalse();
    expect(Gate::forUser($admin)->allows('delete', $ticket))->toBeTrue();
});

test('external portal personas hold no it.request grant', function () {
    expect(
        Role::query()->where('name', 'client')->first()
            ->permissions()->where('key', 'it.request')->exists()
    )->toBeFalse();
    expect(
        Role::query()->where('name', 'next_of_kin')->first()
            ->permissions()->where('key', 'it.request')->exists()
    )->toBeFalse();
    expect(
        Role::query()->where('name', 'support_worker')->first()
            ->permissions()->where('key', 'it.request')->exists()
    )->toBeTrue();
});
