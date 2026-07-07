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

/* ------------------------------------------------------------------ */
/*  §N7 — the admin SLA target editor (PUT it.sla.update)             */
/* ------------------------------------------------------------------ */

/** A full valid editor payload (the §G defaults), with per-priority overrides. */
function itSlaGrid(array $overrides = []): array
{
    $grid = [];
    foreach (ItSlaPolicy::DEFAULTS as $priority => [$response, $resolution]) {
        $grid[$priority] = [
            'first_response_minutes' => $response,
            'resolution_minutes' => $resolution,
        ];
    }

    return array_replace_recursive($grid, $overrides);
}

test('an admin can retune the grid and new tickets stamp from it', function () {
    $admin = itSlaUser('admin');

    $this->actingAs($admin)
        ->put('/it/sla-policies', itSlaGrid([
            'urgent' => ['first_response_minutes' => 30, 'resolution_minutes' => 120],
        ]))
        ->assertRedirect();

    // The whole grid materialises as tenant rows (editable from here on).
    expect(ItSlaPolicy::query()->where('tenant_id', 1)->count())->toBe(4);
    expect(ItSlaPolicy::minutesFor(1, 'urgent'))->toBe([30, 120]);

    $this->actingAs($this->worker)
        ->post('/it/tickets', [
            'title' => 'Stamped by the retuned grid',
            'category' => 'network',
            'priority' => 'urgent',
        ])
        ->assertRedirect();

    $ticket = ItTicket::query()->firstWhere('title', 'Stamped by the retuned grid');
    expect($ticket->first_response_due_at->equalTo($ticket->created_at->copy()->addMinutes(30)))->toBeTrue();
    expect($ticket->resolution_due_at->equalTo($ticket->created_at->copy()->addMinutes(120)))->toBeTrue();
});

test('the SLA editor is admin-only — it.manage alone is not enough', function () {
    // hr holds it.manage but not the admin role; a worker holds neither.
    $this->actingAs($this->hr)->put('/it/sla-policies', itSlaGrid())->assertForbidden();
    $this->actingAs($this->worker)->put('/it/sla-policies', itSlaGrid())->assertForbidden();
    expect(ItSlaPolicy::query()->count())->toBe(0);
});

test('the grid refuses a resolution target tighter than first response', function () {
    $admin = itSlaUser('admin');

    $this->actingAs($admin)
        ->from('/it?tab=tickets')
        ->put('/it/sla-policies', itSlaGrid([
            'urgent' => ['first_response_minutes' => 60, 'resolution_minutes' => 30],
        ]))
        ->assertRedirect('/it?tab=tickets')
        ->assertSessionHasErrors('urgent.resolution_minutes');

    expect(ItSlaPolicy::query()->count())->toBe(0);
});
