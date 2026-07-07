<?php

use App\Models\ItSlaPolicy;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ItSlaPolicySeeder;
use Database\Seeders\RbacSeeder;

function itSlaUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->hr = itSlaUser('hr');
    $this->worker = itSlaUser('support_worker');
});

test('creating a ticket stamps SLA due dates from the default policy', function () {
    $this->actingAs($this->worker)
        ->post('/it/tickets', [
            'title' => 'Screen flickers',
            'category' => 'hardware',
            'priority' => 'normal',
        ])
        ->assertRedirect();

    $ticket = ItTicket::query()->firstWhere('title', 'Screen flickers');
    // §G normal defaults: 1440 / 4320 minutes from creation.
    expect($ticket->first_response_due_at->equalTo($ticket->created_at->copy()->addMinutes(1440)))->toBeTrue();
    expect($ticket->resolution_due_at->equalTo($ticket->created_at->copy()->addMinutes(4320)))->toBeTrue();
    expect($ticket->sla_state)->toBe('ok');
});

test('urgent tickets get the tight default targets', function () {
    $this->actingAs($this->worker)
        ->post('/it/tickets', [
            'title' => 'Whole house offline',
            'category' => 'network',
            'priority' => 'urgent',
        ])
        ->assertRedirect();

    $ticket = ItTicket::query()->firstWhere('title', 'Whole house offline');
    expect($ticket->first_response_due_at->equalTo($ticket->created_at->copy()->addMinutes(60)))->toBeTrue();
    expect($ticket->resolution_due_at->equalTo($ticket->created_at->copy()->addMinutes(240)))->toBeTrue();
});

test('a tenant policy row overrides the code defaults', function () {
    ItSlaPolicy::query()->create([
        'tenant_id' => 1,
        'priority' => 'high',
        'first_response_minutes' => 30,
        'resolution_minutes' => 90,
    ]);

    $this->actingAs($this->worker)
        ->post('/it/tickets', [
            'title' => 'Custom-policy ticket',
            'category' => 'other',
            'priority' => 'high',
        ])
        ->assertRedirect();

    $ticket = ItTicket::query()->firstWhere('title', 'Custom-policy ticket');
    expect($ticket->first_response_due_at->equalTo($ticket->created_at->copy()->addMinutes(30)))->toBeTrue();
    expect($ticket->resolution_due_at->equalTo($ticket->created_at->copy()->addMinutes(90)))->toBeTrue();
});

test('a priority change re-targets the clock without restarting it', function () {
    $this->actingAs($this->worker)
        ->post('/it/tickets', [
            'title' => 'Escalate me later',
            'category' => 'hardware',
            'priority' => 'low',
        ])
        ->assertRedirect();
    $ticket = ItTicket::query()->firstWhere('title', 'Escalate me later');

    // Two hours pass before triage bumps it to urgent.
    $this->travel(2)->hours();
    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", ['priority' => 'urgent'])
        ->assertRedirect();

    $ticket->refresh();
    // Anchored at CREATION (+60m urgent target), not at the change time —
    // the ticket is already 2h old, so it is already past first response.
    expect($ticket->first_response_due_at->equalTo($ticket->created_at->copy()->addMinutes(60)))->toBeTrue();
    expect($ticket->resolution_due_at->equalTo($ticket->created_at->copy()->addMinutes(240)))->toBeTrue();
});

test('the seeder materialises editable default rows and factories stay unstamped', function () {
    $this->seed(ItSlaPolicySeeder::class);
    expect(ItSlaPolicy::query()->where('tenant_id', 1)->count())->toBe(4);
    expect(ItSlaPolicy::minutesFor(1, 'urgent'))->toBe([60, 240]);

    // Factory tickets (test fixtures) never auto-stamp — stamping is the
    // controller write-path's job.
    $fixture = ItTicket::factory()->create();
    expect($fixture->first_response_due_at)->toBeNull();
});
