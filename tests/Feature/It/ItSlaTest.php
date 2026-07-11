<?php

use App\Models\ItSlaPolicy;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\User;
use App\Support\It\BusinessHours;
use Carbon\CarbonImmutable;
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

/* ------------------------------------------------------------------ */
/*  §P-S2 — a business-hours calendar rolls targets onto working time  */
/* ------------------------------------------------------------------ */

test('a business-hours policy rolls SLA targets onto working time', function () {
    ItSlaPolicy::query()->create([
        'tenant_id' => 1,
        'priority' => 'normal',
        'first_response_minutes' => 60,
        'resolution_minutes' => 480, // 8 working hours
        'business_hours' => BusinessHours::nzDefault()['business_hours'], // Mon–Fri 08:00–17:00
        'holiday_dates' => [],
    ]);

    // Freeze "now" at Friday 16:30 NZ so the ticket anchors after-hours. Travel
    // to the UTC instant (production now() is UTC); assertions stay in NZ.
    $friday = CarbonImmutable::parse('2026-07-06 00:00', 'Pacific/Auckland')->startOfWeek()->addDays(4)->setTime(16, 30);
    $this->travelTo($friday->utc());
    $this->actingAs($this->worker)->post('/it/tickets', [
        'title' => 'After-hours breakage',
        'category' => 'hardware',
        'priority' => 'normal',
    ])->assertRedirect();
    $this->travelBack();

    $ticket = ItTicket::query()->firstWhere('title', 'After-hours breakage');

    // First response 60 working min: Fri 16:30->17:00 (30) + Mon 08:00->08:30 (30).
    expect($ticket->first_response_due_at->equalTo($friday->addDays(3)->setTime(8, 30)))->toBeTrue();
    // Resolution 480 working min: Fri 16:30->17:00 (30) leaves 450; Mon 08:00 + 7h30 => 15:30.
    expect($ticket->resolution_due_at->equalTo($friday->addDays(3)->setTime(15, 30)))->toBeTrue();
});

test('SLA targets skip a public holiday', function () {
    $monday = CarbonImmutable::parse('2026-07-06 00:00', 'Pacific/Auckland')->startOfWeek();
    $tuesday = $monday->addDay();

    ItSlaPolicy::query()->create([
        'tenant_id' => 1,
        'priority' => 'high',
        'first_response_minutes' => 60,
        'resolution_minutes' => 540, // 9 working hours
        'business_hours' => BusinessHours::nzDefault()['business_hours'],
        'holiday_dates' => [$tuesday->format('Y-m-d')], // Tuesday is a holiday
    ]);

    $this->travelTo($monday->setTime(16, 0)->utc()); // Mon 16:00 NZ, travelled as its UTC instant
    $this->actingAs($this->worker)->post('/it/tickets', [
        'title' => 'Holiday-spanning ticket',
        'category' => 'network',
        'priority' => 'high',
    ])->assertRedirect();
    $this->travelBack();

    $ticket = ItTicket::query()->firstWhere('title', 'Holiday-spanning ticket');
    // 540 working min: Mon 16:00->17:00 (60), skip Tue (holiday), Wed 08:00 + 8h => Wed 16:00.
    expect($ticket->resolution_due_at->equalTo($monday->addDays(2)->setTime(16, 0)))->toBeTrue();
});

/* ------------------------------------------------------------------ */
/*  §P-S4a — the editor sets/clears a tenant business-hours calendar   */
/* ------------------------------------------------------------------ */

test('the SLA editor writes a business-hours calendar across the whole grid', function () {
    $admin = itSlaUser('admin');

    $this->actingAs($admin)
        ->put('/it/sla-policies', array_merge(itSlaGrid(), [
            'business_hours_enabled' => true,
            'open_time' => '08:00',
            'close_time' => '17:00',
            'working_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'holiday_dates' => ['2026-12-25', '2026-12-26'],
        ]))
        ->assertRedirect();

    $rows = ItSlaPolicy::query()->where('tenant_id', 1)->get();
    expect($rows)->toHaveCount(4);
    $rows->each(function (ItSlaPolicy $row) {
        expect($row->business_hours['mon'])->toBe([['08:00', '17:00']]);
        expect($row->business_hours['sat'])->toBe([]);
        expect($row->holiday_dates)->toBe(['2026-12-25', '2026-12-26']);
    });

    // The calendar is now live for stamping.
    expect(ItSlaPolicy::calendarFor(1, 'urgent'))->not->toBeNull();
});

test('the SLA editor clears the calendar back to 24/7 when disabled', function () {
    $admin = itSlaUser('admin');

    $this->actingAs($admin)->put('/it/sla-policies', array_merge(itSlaGrid(), [
        'business_hours_enabled' => true,
        'open_time' => '09:00',
        'close_time' => '17:30',
        'working_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
    ]))->assertRedirect();
    expect(ItSlaPolicy::calendarFor(1, 'urgent'))->not->toBeNull();

    $this->actingAs($admin)->put('/it/sla-policies', array_merge(itSlaGrid(), [
        'business_hours_enabled' => false,
    ]))->assertRedirect();

    expect(ItSlaPolicy::calendarFor(1, 'urgent'))->toBeNull();
    ItSlaPolicy::query()->where('tenant_id', 1)->get()->each(
        fn (ItSlaPolicy $row) => expect($row->business_hours)->toBeNull(),
    );
});

test('the SLA editor rejects a close time at or before the open time', function () {
    $admin = itSlaUser('admin');

    $this->actingAs($admin)
        ->from('/it?tab=tickets')
        ->put('/it/sla-policies', array_merge(itSlaGrid(), [
            'business_hours_enabled' => true,
            'open_time' => '17:00',
            'close_time' => '08:00',
            'working_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
        ]))
        ->assertRedirect('/it?tab=tickets')
        ->assertSessionHasErrors('close_time');
});

test('enabling business hours requires at least one working day', function () {
    $admin = itSlaUser('admin');

    $this->actingAs($admin)
        ->from('/it?tab=tickets')
        ->put('/it/sla-policies', array_merge(itSlaGrid(), [
            'business_hours_enabled' => true,
            'open_time' => '08:00',
            'close_time' => '17:00',
            'working_days' => [],
        ]))
        ->assertRedirect('/it?tab=tickets')
        ->assertSessionHasErrors('working_days');
});
