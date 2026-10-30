<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteComplaint;
use App\Models\RespiteMedicationReconciliation;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\Calendar\IcsFeedBuilder;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use App\Support\Respite\RespiteWallClock;
use App\Support\WorkerClock;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * Respite forms post NZ wall-clock times (datetime-local or date, no offset).
 * Storage is UTC. Before 31 Oct 2026 they were saved as typed, so a 10 am NZ
 * arrival read back as 10 am UTC (11 pm NZ). These pin the conversion at each
 * Respite input, both daylight-saving changes and the read-back.
 */

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->admin->id,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
    ]);
    $this->serviceContext = ServiceContext::factory()->create([
        'type' => 'planned_respite',
        'is_active' => true,
    ]);
    $this->client = Client::factory()->create([
        'site_id' => $this->site->id,
        'service_context_id' => $this->serviceContext->id,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** The raw stored value, as the database (session time zone +00:00) holds it. */
function respiteStoredUtc(string $table, int $id, string $column): ?string
{
    $value = DB::table($table)->where('id', $id)->value($column);

    return $value === null ? null : substr((string) $value, 0, 19);
}

/** Post the booking form as the browser does and return the new booking. */
function respitePostBooking(object $test, string $start, string $end): RespiteBooking
{
    $before = RespiteBooking::query()->max('id') ?? 0;

    $test->actingAs($test->admin)
        ->post(route('respite.bookings.store'), [
            'client_id' => $test->client->id,
            'start_at' => $start,
            'end_at' => $end,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    return RespiteBooking::query()->where('id', '>', $before)->sole();
}

/** An active stay at the admin's Site for the stay pop-ups. */
function respiteActiveStay(object $test): RespiteStay
{
    $booking = RespiteBooking::factory()->create([
        'client_id' => $test->client->id,
        'location_id' => $test->site->id,
        'status' => 'confirmed',
    ]);

    return RespiteStay::create([
        'booking_id' => $booking->id,
        'client_id' => $test->client->id,
        'status' => 'active',
        'actual_start' => now()->subDay(),
        'created_by' => $test->admin->id,
    ]);
}

test('a booking entered in NZ time is stored as UTC, and its shift follows', function () {
    // Mon 5 Oct 2026 10 am to Wed 7 Oct 10 am NZDT (+13).
    $booking = respitePostBooking($this, '2026-10-05T10:00', '2026-10-07T10:00');

    expect(respiteStoredUtc('respite_bookings', $booking->id, 'start_at'))->toBe('2026-10-04 21:00:00')
        ->and(respiteStoredUtc('respite_bookings', $booking->id, 'end_at'))->toBe('2026-10-06 21:00:00');

    $shift = Shift::query()->where('respite_booking_id', $booking->id)->sole();
    expect(respiteStoredUtc('shifts', $shift->id, 'starts_at'))->toBe('2026-10-04 21:00:00')
        ->and(respiteStoredUtc('shifts', $shift->id, 'ends_at'))->toBe('2026-10-06 21:00:00');
});

test('rescheduling a booking in NZ time updates the booking and its shift in UTC', function () {
    $booking = respitePostBooking($this, '2026-10-05T10:00', '2026-10-07T10:00');

    $this->actingAs($this->admin)
        ->put(route('respite.bookings.update', $booking), [
            'start_at' => '2026-10-05T11:30',
            'end_at' => '2026-10-07T10:00',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $shift = Shift::query()->where('respite_booking_id', $booking->id)->sole();
    expect(respiteStoredUtc('respite_bookings', $booking->id, 'start_at'))->toBe('2026-10-04 22:30:00')
        ->and(respiteStoredUtc('respite_bookings', $booking->id, 'end_at'))->toBe('2026-10-06 21:00:00')
        ->and(respiteStoredUtc('shifts', $shift->id, 'starts_at'))->toBe('2026-10-04 22:30:00');
});

test('a time that already carries an offset keeps its instant (no second conversion)', function () {
    $booking = respitePostBooking($this, '2026-10-04T21:00:00Z', '2026-10-07T10:00:00+13:00');

    expect(respiteStoredUtc('respite_bookings', $booking->id, 'start_at'))->toBe('2026-10-04 21:00:00')
        ->and(respiteStoredUtc('respite_bookings', $booking->id, 'end_at'))->toBe('2026-10-06 21:00:00');
});

test('April: a stay across the end of daylight saving keeps 10 am NZ on both sides', function () {
    // NZDT (+13) ends at 3 am Sun 5 Apr 2026; NZST is +12.
    $across = respitePostBooking($this, '2026-04-04T10:00', '2026-04-06T10:00');
    expect(respiteStoredUtc('respite_bookings', $across->id, 'start_at'))->toBe('2026-04-03 21:00:00')
        ->and(respiteStoredUtc('respite_bookings', $across->id, 'end_at'))->toBe('2026-04-05 22:00:00')
        ->and(RespiteWallClock::nights($across->start_at, $across->end_at))->toBe(2);

    // The repeated 2-3 am hour reads as standard time (+12), the second occurrence.
    expect(RespiteWallClock::toUtc('2026-04-05T02:30')->format('Y-m-d H:i'))->toBe('2026-04-04 14:30');
});

test('September: a stay across the start of daylight saving keeps 10 am NZ on both sides', function () {
    // NZST (+12) becomes NZDT (+13) at 2 am Sun 27 Sep 2026.
    $across = respitePostBooking($this, '2026-09-26T10:00', '2026-09-28T10:00');
    expect(respiteStoredUtc('respite_bookings', $across->id, 'start_at'))->toBe('2026-09-25 22:00:00')
        ->and(respiteStoredUtc('respite_bookings', $across->id, 'end_at'))->toBe('2026-09-27 21:00:00')
        ->and(RespiteWallClock::nights($across->start_at, $across->end_at))->toBe(2);

    // The skipped 2-3 am hour moves an hour later, to 3:30 am NZDT.
    expect(RespiteWallClock::toUtc('2026-09-27T02:30')->format('Y-m-d H:i'))->toBe('2026-09-26 14:30');
});

test('the workspace and booking page read back the NZ times that were typed', function () {
    // Mon 2 pm arrival, Thu 10 am departure: 3 NZ nights, though the UTC
    // dates (5th and 7th) are only 2 apart.
    $booking = respitePostBooking($this, '2026-10-05T14:00', '2026-10-08T10:00');
    $nz = fn (string $iso) => Carbon::parse($iso)->setTimezone('Pacific/Auckland')->format('Y-m-d\TH:i');

    $this->actingAs($this->admin)
        ->get(route('respite.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('bookings.0.id', $booking->id)
            ->where('bookings.0.start', fn ($iso) => $nz($iso) === '2026-10-05T14:00')
            ->where('bookings.0.end', fn ($iso) => $nz($iso) === '2026-10-08T10:00')
            ->where('bookings.0.nights', 3));

    $this->get(route('respite.bookings.show', $booking))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('booking.start_at', fn ($iso) => $nz($iso) === '2026-10-05T14:00')
            ->where('booking.end_at', fn ($iso) => $nz($iso) === '2026-10-08T10:00'));
});

test('the site calendar and its .ics feed show a morning arrival on its NZ day', function () {
    // 10 am NZ on the 5th is 9 pm UTC on the 4th.
    respitePostBooking($this, '2026-10-05T10:00', '2026-10-07T10:00');

    $items = app(SiteCalendarAggregator::class)->itemsForRange(
        [$this->site->id],
        Carbon::parse('2026-10-01'),
        Carbon::parse('2026-10-31'),
        ['sources' => ['respite']],
    );

    expect($items)->toHaveCount(1)
        ->and($items[0]->start)->toBe('2026-10-05T10:00:00+13:00')
        ->and(app(IcsFeedBuilder::class)->build($items))->toContain('DTSTART;VALUE=DATE:20261005');
});

test('booking requests store NZ dates and times as UTC', function () {
    // Workspace pop-up and client profile: date-only start and end (NZ midnight).
    $this->actingAs($this->admin)
        ->post(route('respite.requests.store'), [
            'client_id' => $this->client->id,
            'requested_start' => '2026-10-05',
            'requested_end' => '2026-10-08',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $dateOnly = RespiteBookingRequest::query()->latest('id')->firstOrFail();
    expect(respiteStoredUtc('respite_booking_requests', $dateOnly->id, 'requested_start'))->toBe('2026-10-04 11:00:00')
        ->and(respiteStoredUtc('respite_booking_requests', $dateOnly->id, 'requested_end'))->toBe('2026-10-07 11:00:00');

    // Request page: datetime-local.
    $this->actingAs($this->admin)
        ->post(route('respite.requests.store'), [
            'client_id' => $this->client->id,
            'requested_start' => '2026-10-12T10:00',
            'requested_end' => '2026-10-14T15:30',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $request = RespiteBookingRequest::query()->latest('id')->firstOrFail();
    expect(respiteStoredUtc('respite_booking_requests', $request->id, 'requested_start'))->toBe('2026-10-11 21:00:00')
        ->and(respiteStoredUtc('respite_booking_requests', $request->id, 'requested_end'))->toBe('2026-10-14 02:30:00');

    $this->actingAs($this->admin)
        ->put(route('respite.requests.update', $request), [
            'requested_start' => '2026-10-12T10:00',
            'requested_end' => '2026-10-14T16:00',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect(respiteStoredUtc('respite_booking_requests', $request->id, 'requested_end'))->toBe('2026-10-14 03:00:00');
});

test('stay pop-up times, a referral and the comms log NZ date filter use NZ time', function () {
    $stay = respiteActiveStay($this);

    $this->actingAs($this->admin)
        ->post(route('respite.stays.medication-reconciliation.store', $stay), [
            'type' => 'admission',
            'status' => 'completed',
            'source' => 'pharmacy_pack',
            'count_received' => 0,
            'first_dose_due_at' => '2026-10-05T20:00',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $reconciliation = RespiteMedicationReconciliation::query()->where('stay_id', $stay->id)->sole();
    expect(respiteStoredUtc('respite_medication_reconciliations', $reconciliation->id, 'first_dose_due_at'))
        ->toBe('2026-10-05 07:00:00');

    $this->actingAs($this->admin)
        ->post(route('respite.stays.complaints.store', $stay), [
            'source' => 'whanau',
            'received_at' => '2026-10-05T08:15',
            'nature' => 'Whānau asked about meal timing.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $complaint = RespiteComplaint::query()->where('stay_id', $stay->id)->sole();
    expect(respiteStoredUtc('respite_complaints', $complaint->id, 'received_at'))->toBe('2026-10-04 19:15:00');

    $this->actingAs($this->admin)
        ->post(route('respite.stays.bed-hold', $stay), [
            'bed_hold_status' => 'held',
            'bed_hold_reason' => 'whanau_visit',
            'bed_hold_until' => '2026-10-06T18:00',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect(respiteStoredUtc('respite_stays', $stay->id, 'bed_hold_until'))->toBe('2026-10-06 05:00:00');

    $this->actingAs($this->admin)
        ->post(route('respite.stays.incidents.store', $stay), [
            'type' => 'fall',
            'severity' => 'low',
            'title' => 'Slipped in the hallway',
            'description' => 'No injury; checked by staff.',
            'occurred_at' => '2026-10-05T07:45',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $incident = ClientIncident::query()->where('respite_stay_id', $stay->id)->sole();
    expect(respiteStoredUtc('client_incidents', $incident->id, 'occurred_at'))->toBe('2026-10-04 18:45:00');

    $this->actingAs($this->admin)
        ->post(route('respite.referrals.store'), [
            'client_id' => $this->client->id,
            'referrer_type' => 'nasc',
            'referrer_name' => 'NASC Coordinator',
            'referral_reason' => 'Planned carer break',
            'urgency' => 'planned',
            'received_at' => '2026-10-05',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $referral = RespiteReferral::query()->latest('id')->firstOrFail();
    expect(respiteStoredUtc('respite_referrals', $referral->id, 'received_at'))->toBe('2026-10-04 11:00:00')
        ->and(WorkerClock::localDate($referral->received_at))->toBe('2026-10-05');

    // 8:15 am NZ on the 5th is the 4th in UTC; the NZ-date filter must still find it.
    $this->actingAs($this->admin)
        ->post(route('respite.communication-logs.store'), [
            'stay_id' => $stay->id,
            'channel' => 'phone',
            'participants' => [['name' => 'Moana', 'role' => 'whānau']],
            'summary' => 'Confirmed pick-up time.',
            'occurred_at' => '2026-10-05T08:15',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect(respiteStoredUtc('respite_communication_logs', DB::table('respite_communication_logs')->where('stay_id', $stay->id)->value('id'), 'occurred_at'))
        ->toBe('2026-10-04 19:15:00');

    $logsOn = fn (string $date) => $this->actingAs($this->admin)
        ->get(route('respite.communication-logs.index', ['date_from' => $date, 'date_to' => $date]))
        ->assertOk();
    $logsOn('2026-10-05')->assertInertia(fn (AssertableInertia $page) => $page->has('logs.data', 1));
    $logsOn('2026-10-04')->assertInertia(fn (AssertableInertia $page) => $page->has('logs.data', 0));
});

test('the workspace restraint form needs an explicit offset and stores the instant as UTC', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 09:00', 'Pacific/Auckland')->utc());
    $stay = respiteActiveStay($this);
    $payload = [
        'restraint_type' => 'physical_hold',
        'severity' => 'medium',
        'trigger_description' => 'Escalating distress',
        'de_escalation_attempted' => 'Calm voice and space offered',
        'restraint_description' => 'Brief guided hold',
        'within_support_plan' => false,
        'deviation_reason' => 'No current plan covers this stay',
    ];

    // A bare clock reading is refused, as on the H&S restraint form.
    $this->actingAs($this->admin)
        ->post(route('respite.stays.restraints.store', $stay), [...$payload, 'started_at' => '2026-10-06T08:00'])
        ->assertSessionHasErrors('started_at');
    // So is an actual time in the future.
    $this->post(route('respite.stays.restraints.store', $stay), [...$payload, 'started_at' => '2026-10-06T10:00:00+13:00'])
        ->assertSessionHasErrors('started_at');
    expect(RestraintEvent::query()->count())->toBe(0);

    // What the form sends: NZ wall-clock plus the Pacific/Auckland offset.
    $this->post(route('respite.stays.restraints.store', $stay), [
        ...$payload,
        'started_at' => '2026-10-06T08:00:00+13:00',
        'ended_at' => '2026-10-06T08:12:00+13:00',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $event = RestraintEvent::sole();
    expect(respiteStoredUtc('restraint_events', $event->id, 'started_at'))->toBe('2026-10-05 19:00:00')
        ->and(respiteStoredUtc('restraint_events', $event->id, 'ended_at'))->toBe('2026-10-05 19:12:00')
        ->and($event->duration_minutes)->toBe(12)
        ->and($event->stay_id)->toBe($stay->id);
});

test('nights are counted on NZ calendar days', function () {
    // Mon 2 pm NZDT to Thu 10 am NZDT.
    expect(RespiteWallClock::nights(Carbon::parse('2026-10-05 01:00:00'), Carbon::parse('2026-10-07 21:00:00')))->toBe(3)
        // Across the April change (49 hours) and the September change (47 hours).
        ->and(RespiteWallClock::nights(Carbon::parse('2026-04-03 21:00:00'), Carbon::parse('2026-04-05 22:00:00')))->toBe(2)
        ->and(RespiteWallClock::nights(Carbon::parse('2026-09-25 22:00:00'), Carbon::parse('2026-09-27 21:00:00')))->toBe(2);
});

test('the respite allocation counts nights on NZ days inside the NZ period', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-20 09:00', 'Pacific/Auckland')->utc());
    DB::table('client_respite_allocations')->insert([
        'client_id' => $this->client->id,
        'period_start' => '2026-10-05',
        'period_end' => '2026-10-31',
        'nights_allocated' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // 10 am NZ on 5 Oct (21:00Z on the 4th, before the period in UTC) to
    // 10 am NZ on 8 Oct: 3 nights, all inside the period.
    RespiteBooking::factory()->create([
        'client_id' => $this->client->id,
        'location_id' => $this->site->id,
        'status' => 'completed',
        'start_at' => Carbon::parse('2026-10-05 10:00', 'Pacific/Auckland')->utc(),
        'end_at' => Carbon::parse('2026-10-08 10:00', 'Pacific/Auckland')->utc(),
    ]);

    $summary = app(\App\Services\Respite\ClientRespiteAllocationSummary::class)->forClient($this->client);

    expect($summary['used'])->toBe(3)
        ->and($summary['remaining'])->toBe(7);
});
