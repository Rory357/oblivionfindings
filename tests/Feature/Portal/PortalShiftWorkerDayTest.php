<?php

use App\Models\Client;
use App\Models\FamilyVisitRequest;
use App\Models\RespiteBooking;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Support\WorkerClock;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Family-portal days follow the NZ calendar. Storage is UTC, so a 9 am NZ
 * shift (20:00Z) sits on the previous UTC day, and UTC midnight is 1 pm NZ.
 *
 * The clock is frozen at 7:30 am NZDT on Tue 6 Oct 2026 (18:30Z on 5 Oct).
 */

function portalWorkerDayNz(string $wallClock): Carbon
{
    return Carbon::parse($wallClock, 'Pacific/Auckland')->utc();
}

/** @return array{client: Client, user: User, morning: Shift, finished: Shift, tomorrow: Shift} */
function portalWorkerDayFixture(): array
{
    Carbon::setTestNow(portalWorkerDayNz('2026-10-06 07:30:00'));

    $client = Client::factory()->create([
        'site_id' => Site::factory()->create()->id,
        'status' => 'active',
    ]);
    $user = User::factory()->create([
        'role' => 'client',
        'approved_at' => now(),
    ]);
    $role = Role::query()->firstOrCreate(
        ['name' => 'client'],
        ['label' => 'Client', 'level' => 1, 'type' => 'system'],
    );
    $user->roles()->attach($role->id);
    $client->portalUsers()->attach($user->id, ['relation' => 'self']);

    $shift = fn (string $start, string $end) => Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $client->site_id,
        'status' => 'scheduled',
        'starts_at' => portalWorkerDayNz($start),
        'ends_at' => portalWorkerDayNz($end),
    ]);

    return [
        'client' => $client,
        'user' => $user,
        // Today NZ, but 2026-10-05 in UTC.
        'morning' => $shift('2026-10-06 09:00:00', '2026-10-06 13:00:00'),
        // Yesterday NZ (01:00Z-05:00Z on 5 Oct), after UTC midnight.
        'finished' => $shift('2026-10-05 14:00:00', '2026-10-05 18:00:00'),
        // Tomorrow NZ (19:00Z on 6 Oct), before the next UTC midnight.
        'tomorrow' => $shift('2026-10-07 08:00:00', '2026-10-07 12:00:00'),
    ];
}

afterEach(function () {
    Carbon::setTestNow();
});

it('dates an instant on its NZ calendar day', function () {
    expect(WorkerClock::localDate(portalWorkerDayNz('2026-10-06 09:00:00')))->toBe('2026-10-06')
        ->and(portalWorkerDayNz('2026-10-06 09:00:00')->toDateString())->toBe('2026-10-05');
});

it('lists a morning NZ shift under its NZ day on the portal schedule', function () {
    $fixture = portalWorkerDayFixture();

    $this->actingAs($fixture['user'])
        ->get(route('portal.clients.schedule', $fixture['client'], false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/schedule')
            ->where('showShifts', true)
            ->has('shifts', 2)
            ->where('shifts.0.id', $fixture['morning']->id)
            ->where('shifts.0.date', '2026-10-06')
            ->where('shifts.0.starts_at', '2026-10-05T20:00:00.000000Z')
            ->where('shifts.1.id', $fixture['tomorrow']->id)
            ->where('shifts.1.date', '2026-10-07'));
});

it('counts today on the family dashboard from NZ midnight to NZ midnight', function () {
    $fixture = portalWorkerDayFixture();

    $this->actingAs($fixture['user'])
        ->get(route('portal.clients.dashboard', $fixture['client'], false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/family-dashboard')
            ->has('todayShifts', 1)
            ->where('todayShifts.0.id', $fixture['morning']->id)
            ->where('stats.shiftsToday', 1)
            ->where('dailySummary.scheduledToday', 1)
            ->has('weekShifts', 2)
            ->has('monthShifts', 2)
            ->where('monthShifts.0.id', $fixture['morning']->id)
            ->where('monthShifts.0.date', '2026-10-06')
            ->where('monthShifts.1.id', $fixture['tomorrow']->id)
            ->where('monthShifts.1.date', '2026-10-07'));
});

it('keeps a sleepover that crosses NZ midnight on the day it started', function () {
    $fixture = portalWorkerDayFixture();
    $sleepover = Shift::factory()->create([
        'client_id' => $fixture['client']->id,
        'site_id' => $fixture['client']->site_id,
        'status' => 'in_progress',
        'is_sleepover' => true,
        'starts_at' => portalWorkerDayNz('2026-10-05 22:00:00'),
        'ends_at' => portalWorkerDayNz('2026-10-06 08:00:00'),
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('portal.clients.dashboard', $fixture['client'], false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('todayShifts', 2)
            ->where('todayShifts.0.id', $sleepover->id)
            ->where('monthShifts.0.id', $sleepover->id)
            ->where('monthShifts.0.date', '2026-10-05'));
});

it('dates a morning NZ respite stay on its NZ day', function () {
    $fixture = portalWorkerDayFixture();
    $booking = RespiteBooking::factory()->create([
        'client_id' => $fixture['client']->id,
        'status' => 'confirmed',
        // 10 am NZ on 8 Oct is 21:00Z on 7 Oct.
        'start_at' => portalWorkerDayNz('2026-10-08 10:00:00'),
        'end_at' => portalWorkerDayNz('2026-10-10 15:00:00'),
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('portal.clients.schedule', $fixture['client'], false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('respiteStays.0.id', $booking->id)
            ->where('respiteStays.0.date', '2026-10-08'));

    $this->get(route('portal.clients.dashboard', $fixture['client'], false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcomingRespite.0.id', $booking->id)
            ->where('upcomingRespite.0.date', '2026-10-08'));
});

it('reads portal calendar boundaries as NZ times for shift instants', function (string $start, string $end) {
    $fixture = portalWorkerDayFixture();

    $response = $this->actingAs($fixture['user'])
        ->getJson(route('portal.clients.calendar.events', [
            'client' => $fixture['client'],
            'start' => $start,
            'end' => $end,
        ], false))
        ->assertOk();

    expect(collect($response->json())->pluck('id')->all())
        ->toBe(['shift-'.$fixture['morning']->id]);
})->with([
    'FullCalendar offset range' => ['2026-10-06T00:00:00+13:00', '2026-10-07T00:00:00+13:00'],
    'bare NZ dates' => ['2026-10-06', '2026-10-07'],
]);

it('places a family visit at its NZ wall-clock time on the portal calendar', function () {
    $fixture = portalWorkerDayFixture();
    $visit = FamilyVisitRequest::query()->create([
        'user_id' => $fixture['user']->id,
        'client_id' => $fixture['client']->id,
        'requested_date' => '2026-10-08',
        'preferred_time_start' => '10:00',
        'preferred_time_end' => '11:30',
        'visit_type' => 'in_person',
        'status' => 'approved',
    ]);

    $events = collect($this->actingAs($fixture['user'])
        ->getJson(route('portal.clients.calendar.events', [
            'client' => $fixture['client'],
            'start' => '2026-10-08T00:00:00+13:00',
            'end' => '2026-10-09T00:00:00+13:00',
        ], false))
        ->assertOk()
        ->json());

    $event = $events->firstWhere('id', 'visit-'.$visit->id);

    expect($event)->not->toBeNull()
        ->and(Carbon::parse($event['start'])->utc()->toIso8601String())->toBe('2026-10-07T21:00:00+00:00')
        ->and(Carbon::parse($event['end'])->utc()->toIso8601String())->toBe('2026-10-07T22:30:00+00:00');
});

it('refuses a visit request for a date that has already passed in NZ', function () {
    $fixture = portalWorkerDayFixture();

    // 7:30 am NZ on 6 Oct: the UTC date is still 5 Oct.
    $this->actingAs($fixture['user'])
        ->post(route('portal.clients.visit-requests.store', $fixture['client'], false), [
            'requested_date' => '2026-10-05',
            'visit_type' => 'in_person',
        ])
        ->assertSessionHasErrors('requested_date');

    $this->post(route('portal.clients.visit-requests.store', $fixture['client'], false), [
        'requested_date' => '2026-10-06',
        'visit_type' => 'in_person',
    ])->assertSessionHasNoErrors();

    expect(FamilyVisitRequest::query()->where('client_id', $fixture['client']->id)->pluck('requested_date')
        ->map->toDateString()->all())->toBe(['2026-10-06']);
});
