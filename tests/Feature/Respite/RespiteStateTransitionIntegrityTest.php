<?php

namespace Tests\Feature\Respite;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Events\Respite\RespiteEvent;
use App\Models\Client;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteCalendarEvent;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;
use App\Models\Role;
use App\Models\ServiceAgreement;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Respite\RespiteShiftSync;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RespiteStateTransitionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    private Client $client;

    private ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['respite_capacity' => 0]);
        $this->serviceContext = ServiceContext::factory()->create([
            'type' => 'planned_respite',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_request_approval_serializes_replay_and_creates_one_booking_graph(): void
    {
        Event::fake([RespiteEvent::class]);
        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'for update')) {
                $locks[] = $sql;
            }
        });
        $request = $this->bookingRequest('submitted');

        $this->actingAs($this->admin)
            ->put(route('respite.requests.update', $request), ['status' => 'under_review'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('under_review', $request->refresh()->status);

        $this->actingAs($this->admin)
            ->post(route('respite.requests.approve', $request))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('respite.requests.approve', $request))
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $booking = RespiteBooking::query()->where('booking_request_id', $request->id)->sole();

        $this->actingAs($this->admin)
            ->post(route('respite.bookings.store'), [
                'booking_request_id' => $request->id,
                'client_id' => $request->client_id,
                'start_at' => $request->requested_start->format('Y-m-d H:i:s'),
                'end_at' => $request->requested_end->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('booking_request_id');

        $this->assertSame('approved', $request->refresh()->status);
        $this->assertSame('pending', $booking->status);
        $this->assertSame(1, RespiteBooking::withTrashed()->where('booking_request_id', $request->id)->count());
        $shift = Shift::query()->where('respite_booking_id', $booking->id)->sole();
        $this->assertSame($this->client->id, $shift->client_id);
        $this->assertSame($this->site->id, $shift->site_id);
        $this->assertSame($this->serviceContext->id, $shift->service_context_id);
        $this->assertTrue(collect($locks)->contains(
            fn (string $sql) => str_contains($sql, 'respite_booking_requests'),
        ));
        Event::assertDispatchedTimes(RespiteEvent::class, 3);
    }

    public function test_request_referral_and_funding_binding_is_atomic_and_replay_safe(): void
    {
        Event::fake([RespiteEvent::class]);
        $referral = RespiteReferral::query()->create([
            'client_id' => $this->client->id,
            'referrer_name' => 'NASC Coordinator',
            'referral_reason' => 'Planned respite block',
            'urgency' => 'planned',
            'status' => 'received',
            'received_at' => now(),
        ]);
        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $foreignAgreement = ServiceAgreement::factory()->create([
            'client_id' => $foreignClient->id,
            'status' => 'active',
        ]);
        $payload = [
            'client_id' => $this->client->id,
            'service_context_id' => $this->serviceContext->id,
            'referral_id' => $referral->id,
            'requested_start' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'requested_end' => now()->addDays(9)->format('Y-m-d H:i:s'),
        ];

        $this->actingAs($this->admin)
            ->post(route('respite.requests.store'), [
                ...$payload,
                'service_agreement_id' => $foreignAgreement->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('service_agreement_id');
        $this->assertDatabaseMissing('respite_booking_requests', ['referral_id' => $referral->id]);
        $this->assertSame('received', $referral->refresh()->status);
        $this->assertNull($referral->linked_booking_request_id);
        Event::assertNothingDispatched();

        $this->actingAs($this->admin)
            ->post(route('respite.requests.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $request = RespiteBookingRequest::query()->where('referral_id', $referral->id)->sole();
        $this->assertSame('submitted', $request->status);
        $this->assertSame('accepted', $referral->refresh()->status);
        $this->assertSame($request->id, $referral->linked_booking_request_id);

        $this->actingAs($this->admin)
            ->post(route('respite.requests.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('referral_id');
        $this->assertSame(1, RespiteBookingRequest::withTrashed()->where('referral_id', $referral->id)->count());
        Event::assertDispatchedTimes(RespiteEvent::class, 2);
    }

    public function test_generic_privileged_and_terminal_transitions_fail_without_side_effects(): void
    {
        Event::fake([RespiteEvent::class]);
        $rejected = $this->bookingRequest('rejected');
        $submitted = $this->bookingRequest('submitted');

        $this->actingAs($this->admin)
            ->put(route('respite.requests.update', $submitted), [
                'status' => 'approved',
                'decision_notes' => 'Must not persist.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertSame('submitted', $submitted->refresh()->status);
        $this->assertNull($submitted->decision_notes);

        $this->actingAs($this->admin)
            ->post(route('respite.requests.approve', $rejected))
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertDatabaseMissing('respite_bookings', ['booking_request_id' => $rejected->id]);

        $booking = $this->booking('pending');
        foreach (['confirmed', 'in_progress', 'completed'] as $privilegedStatus) {
            $this->actingAs($this->admin)
                ->put(route('respite.bookings.update', $booking), [
                    'status' => $privilegedStatus,
                    'cancellation_reason' => 'Must not persist.',
                ])
                ->assertRedirect()
                ->assertSessionHasErrors('status');
            $this->assertSame('pending', $booking->refresh()->status);
            $this->assertNull($booking->cancellation_reason);
        }
        Event::assertNothingDispatched();

        $this->actingAs($this->admin)
            ->post(route('respite.bookings.confirm', $booking), [
                'readiness_override_reason' => 'Authorised test readiness override.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $booking->refresh()->status);
        $this->assertSame(1, RespiteCalendarEvent::query()->where('booking_id', $booking->id)->count());

        $this->actingAs($this->admin)
            ->post(route('respite.bookings.confirm', $booking), [
                'readiness_override_reason' => 'Replay must not project again.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertSame(1, RespiteCalendarEvent::query()->where('booking_id', $booking->id)->count());

        $noShowBooking = $this->booking('confirmed');
        $noShowShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'respite_booking_id' => $noShowBooking->id,
            'status' => 'scheduled',
        ]);
        $this->actingAs($this->admin)
            ->put(route('respite.bookings.update', $noShowBooking), ['status' => 'no_show'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('no_show', $noShowBooking->refresh()->status);
        $this->assertSame('cancelled', $noShowShift->refresh()->status);

        $this->actingAs($this->admin)
            ->put(route('respite.bookings.update', $noShowBooking), ['status' => 'no_show'])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertSame(1, substr_count((string) $noShowShift->refresh()->notes, 'recorded as no show'));

        $occupiedBooking = $this->booking('confirmed');
        $occupiedStay = RespiteStay::query()->create([
            'booking_id' => $occupiedBooking->id,
            'client_id' => $this->client->id,
            'status' => 'active',
            'actual_start' => now(),
        ]);
        $occupiedStay->delete();
        $this->actingAs($this->admin)
            ->put(route('respite.bookings.update', $occupiedBooking), [
                'status' => 'cancelled',
                'start_at' => $occupiedBooking->start_at->format('Y-m-d H:i:s'),
                'end_at' => $occupiedBooking->end_at->copy()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertSame('confirmed', $occupiedBooking->refresh()->status);
        Event::assertNotDispatched(
            RespiteEvent::class,
            fn (RespiteEvent $event) => $event->name === 'respite.booking.updated'
                && ($event->payload['id'] ?? null) === $occupiedBooking->id,
        );
    }

    public function test_capacity_uses_the_canonical_booking_site_under_one_site_lock(): void
    {
        $this->site->update(['respite_capacity' => 1]);
        $otherSite = Site::factory()->create(['respite_capacity' => 1]);
        $bookingAtOtherSite = $this->booking('confirmed');
        $bookingAtOtherSite->update(['location_id' => $otherSite->id]);
        $firstAtHomeSite = $this->booking('pending');
        $secondAtHomeSite = $this->booking('pending');
        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'for update')) {
                $locks[] = $sql;
            }
        });

        $this->actingAs($this->admin)
            ->post(route('respite.bookings.confirm', $firstAtHomeSite), [
                'readiness_override_reason' => 'Authorised readiness override.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $firstAtHomeSite->refresh()->status);

        $this->actingAs($this->admin)
            ->post(route('respite.bookings.confirm', $secondAtHomeSite), [
                'readiness_override_reason' => 'Authorised readiness override.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('capacity');
        $this->assertSame('pending', $secondAtHomeSite->refresh()->status);
        $this->assertTrue(collect($locks)->contains(
            fn (string $sql) => str_contains($sql, 'from ') && str_contains($sql, 'sites'),
        ));
    }

    public function test_stay_lifecycle_is_atomic_and_discharge_is_terminal_until_a_new_episode(): void
    {
        $booking = $this->booking('confirmed');
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'respite_booking_id' => $booking->id,
            'starts_at' => $booking->start_at,
            'ends_at' => $booking->end_at,
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->admin)
            ->post(route('respite.stays.store'), [
                'booking_id' => $booking->id,
                'client_id' => $this->client->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $stay = RespiteStay::query()->where('booking_id', $booking->id)->sole();

        $this->actingAs($this->admin)
            ->post(route('respite.stays.store'), [
                'booking_id' => $booking->id,
                'client_id' => $this->client->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertSame(1, RespiteStay::query()->where('booking_id', $booking->id)->count());

        $this->actingAs($this->admin)
            ->post(route('respite.stays.checkin', $stay))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('active', $stay->refresh()->status);
        $this->assertSame('in_progress', $booking->refresh()->status);
        $this->assertSame('in_progress', $shift->refresh()->status);

        $this->actingAs($this->admin)
            ->post(route('respite.stays.checkin', $stay))
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $extendedEnd = $booking->end_at->copy()->addDay();
        $this->actingAs($this->admin)
            ->post(route('respite.stays.extend', $stay), [
                'new_end' => $extendedEnd->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('extended', $stay->refresh()->status);
        $this->assertTrue($booking->refresh()->end_at->equalTo($extendedEnd));

        $this->actingAs($this->admin)
            ->post(route('respite.stays.extend', $stay), [
                'new_end' => $extendedEnd->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('new_end');

        $this->actingAs($this->admin)
            ->post(route('respite.stays.discharge', $stay), [
                'discharge_summary' => 'Care transferred safely to whānau.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('discharged', $stay->refresh()->status);
        $this->assertSame('completed', $booking->refresh()->status);
        $this->assertSame('completed', $shift->refresh()->status);

        $dischargedAt = $stay->actual_end;
        $completedNotes = $shift->notes;
        $this->actingAs($this->admin)
            ->post(route('respite.stays.discharge', $stay), [
                'discharge_summary' => 'Replay must be rejected.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->actingAs($this->admin)
            ->post(route('respite.stays.checkin', $stay))
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->actingAs($this->admin)
            ->post(route('respite.stays.extend', $stay), [
                'new_end' => $extendedEnd->copy()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->actingAs($this->admin)
            ->post(route('respite.stays.bed-hold', $stay), [
                'bed_hold_status' => 'held',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertTrue($stay->refresh()->actual_end->equalTo($dischargedAt));
        $this->assertNull($stay->bed_hold_status);
        $this->assertSame($completedNotes, $shift->refresh()->notes);

        $newEpisode = $this->booking('confirmed');
        $this->actingAs($this->admin)
            ->post(route('respite.stays.store'), [
                'booking_id' => $newEpisode->id,
                'client_id' => $this->client->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('respite_stays', [
            'booking_id' => $newEpisode->id,
            'status' => 'admitted',
        ]);
    }

    public function test_transition_failure_rolls_back_the_entire_stay_graph_and_can_recover(): void
    {
        Event::fake([RespiteEvent::class]);
        $booking = $this->booking('confirmed');
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'respite_booking_id' => $booking->id,
            'status' => 'scheduled',
        ]);
        $stay = RespiteStay::query()->create([
            'booking_id' => $booking->id,
            'client_id' => $this->client->id,
            'status' => 'admitted',
            'actual_start' => now(),
            'created_by' => $this->admin->id,
        ]);

        $workingSync = $this->app->make(RespiteShiftSync::class);
        $this->app->instance(RespiteShiftSync::class, new class extends RespiteShiftSync
        {
            public function checkInStay(RespiteStay $stay, ?Carbon $checkedInAt = null, ?int $actorId = null): void
            {
                throw new RuntimeException('Simulated shift persistence failure.');
            }
        });

        try {
            $this->withoutExceptionHandling()
                ->actingAs($this->admin)
                ->post(route('respite.stays.checkin', $stay));
            $this->fail('The simulated transition failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated shift persistence failure.', $exception->getMessage());
        }

        $this->assertSame('admitted', $stay->refresh()->status);
        $this->assertSame('confirmed', $booking->refresh()->status);
        $this->assertSame('scheduled', $shift->refresh()->status);
        Event::assertNotDispatched(
            RespiteEvent::class,
            fn (RespiteEvent $event) => $event->name === 'respite.stay.checked_in'
                && ($event->payload['id'] ?? null) === $stay->id,
        );

        $this->app->instance(RespiteShiftSync::class, $workingSync);
        $this->withExceptionHandling()
            ->actingAs($this->admin)
            ->post(route('respite.stays.checkin', $stay))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('active', $stay->refresh()->status);
        $this->assertSame('in_progress', $booking->refresh()->status);
        $this->assertSame('in_progress', $shift->refresh()->status);
    }

    public function test_conflicting_shift_binding_rolls_back_the_entire_stay_transition(): void
    {
        Event::fake([RespiteEvent::class]);
        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $foreignRequest = RespiteBookingRequest::query()->create([
            'client_id' => $foreignClient->id,
            'service_context_id' => $this->serviceContext->id,
            'requested_start' => now()->addWeek(),
            'requested_end' => now()->addWeek()->addDay(),
            'status' => 'approved',
        ]);
        $conflictingSourceBooking = $this->booking('pending');
        $conflictingSourceBooking->update(['booking_request_id' => $foreignRequest->id]);
        $this->actingAs($this->admin)
            ->post(route('respite.bookings.confirm', $conflictingSourceBooking), [
                'readiness_override_reason' => 'Must not bypass source binding.',
            ])
            ->assertNotFound();
        $this->assertSame('pending', $conflictingSourceBooking->refresh()->status);

        $booking = $this->booking('confirmed');
        $stay = RespiteStay::query()->create([
            'booking_id' => $booking->id,
            'client_id' => $this->client->id,
            'status' => 'admitted',
            'actual_start' => now(),
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
            'service_context_id' => $this->serviceContext->id,
            'respite_booking_id' => $booking->id,
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->admin)
            ->post(route('respite.stays.checkin', $stay))
            ->assertRedirect()
            ->assertSessionHasErrors('shift');

        $this->assertSame('admitted', $stay->refresh()->status);
        $this->assertSame('confirmed', $booking->refresh()->status);
        $this->assertSame($foreignClient->id, $shift->refresh()->client_id);
        $this->assertSame($foreignSite->id, $shift->site_id);
        Event::assertNothingDispatched();
    }

    public function test_foreign_direct_objects_are_concealed_and_global_role_remains_explicit(): void
    {
        $foreignSite = Site::factory()->create(['respite_capacity' => 0]);
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $coordinator = $this->siteCoordinator($this->site);
        $foreignCoordinator = $this->siteCoordinator($foreignSite);
        $this->client->supportWorkers()->syncWithoutDetaching([$coordinator->id]);
        $localRequest = $this->bookingRequest('submitted');

        $foreignRequest = RespiteBookingRequest::query()->create([
            'client_id' => $foreignClient->id,
            'service_context_id' => $this->serviceContext->id,
            'requested_start' => now()->addWeek(),
            'requested_end' => now()->addWeek()->addDay(),
            'status' => 'submitted',
        ]);
        $foreignLocationBooking = RespiteBooking::factory()->create([
            'client_id' => $this->client->id,
            'location_id' => $foreignSite->id,
            'status' => 'pending',
        ]);
        $localBooking = $this->booking('pending');
        $globalBooking = RespiteBooking::factory()->create([
            'client_id' => $foreignClient->id,
            'location_id' => $foreignSite->id,
            'status' => 'pending',
        ]);
        $foreignLocationStay = RespiteStay::query()->create([
            'booking_id' => $foreignLocationBooking->id,
            'client_id' => $this->client->id,
            'status' => 'admitted',
            'actual_start' => now(),
        ]);
        $localAgreement = ServiceAgreement::factory()->create([
            'client_id' => $this->client->id,
            'status' => 'active',
        ]);
        $foreignAgreement = ServiceAgreement::factory()->create([
            'client_id' => $foreignClient->id,
            'status' => 'active',
        ]);
        $foreignContext = ServiceContext::factory()->create([
            'site_id' => $foreignSite->id,
            'is_active' => true,
        ]);

        $this->actingAs($coordinator)
            ->get(route('respite.requests.create', ['client_id' => $foreignClient->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('clients', 1)
                ->where('clients.0.id', $this->client->id)
                ->has('serviceContexts', 1)
                ->where('serviceContexts.0.id', $this->serviceContext->id)
                ->has('serviceAgreements', 1)
                ->where('serviceAgreements.0.id', $localAgreement->id)
                ->where('defaultClientId', null));
        $this->actingAs($coordinator)
            ->get(route('respite.bookings.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('pendingRequests', 1)
                ->where('pendingRequests.0.id', $localRequest->id)
                ->has('coordinators', 1)
                ->where('coordinators.0.id', $coordinator->id));
        $requestCount = RespiteBookingRequest::query()->count();
        $this->actingAs($coordinator)
            ->post(route('respite.requests.store'), [
                'client_id' => $this->client->id,
                'service_context_id' => $foreignContext->id,
                'requested_start' => now()->addDays(12)->format('Y-m-d H:i:s'),
                'requested_end' => now()->addDays(13)->format('Y-m-d H:i:s'),
            ])
            ->assertNotFound();
        $this->assertSame($requestCount, RespiteBookingRequest::query()->count());
        $this->actingAs($coordinator)
            ->get(route('respite.requests.show', $foreignRequest))
            ->assertNotFound();
        $this->actingAs($coordinator)
            ->get(route('respite.bookings.show', $foreignLocationBooking))
            ->assertNotFound();
        $this->actingAs($coordinator)
            ->get(route('respite.stays.show', $foreignLocationStay))
            ->assertNotFound();

        $this->actingAs($coordinator)
            ->post(route('respite.requests.approve', $foreignRequest))
            ->assertNotFound();
        $this->actingAs($coordinator)
            ->post(route('respite.bookings.confirm', $foreignLocationBooking), [
                'readiness_override_reason' => 'Must not bypass Site scope.',
            ])
            ->assertNotFound();
        $this->actingAs($coordinator)
            ->put(route('respite.bookings.update', $localBooking), [
                'assigned_coordinator_id' => $foreignCoordinator->id,
            ])
            ->assertNotFound();
        $this->actingAs($coordinator)
            ->put(route('respite.bookings.update', $localBooking), [
                'location_id' => $foreignSite->id,
            ])
            ->assertNotFound();
        $this->actingAs($coordinator)
            ->post(route('respite.bookings.store'), [
                'client_id' => $this->client->id,
                'location_id' => $foreignSite->id,
                'start_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
                'end_at' => now()->addDays(11)->format('Y-m-d H:i:s'),
            ])
            ->assertNotFound();
        $this->actingAs($coordinator)
            ->post(route('respite.stays.checkin', $foreignLocationStay))
            ->assertNotFound();

        $this->assertSame('submitted', $foreignRequest->refresh()->status);
        $this->assertSame('pending', $foreignLocationBooking->refresh()->status);
        $this->assertSame($this->site->id, $localBooking->refresh()->location_id);
        $this->assertSame('admitted', $foreignLocationStay->refresh()->status);
        $this->assertSame(3, RespiteBooking::query()->count());
        $this->assertNull($localBooking->refresh()->assigned_coordinator_id);
        $this->assertNotSame($localAgreement->id, $foreignAgreement->id);

        $this->actingAs($this->admin)
            ->post(route('respite.bookings.confirm', $globalBooking), [
                'readiness_override_reason' => 'Explicit global-role readiness override.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $globalBooking->refresh()->status);
    }

    public function test_two_mysql_workers_serialize_linked_booking_creation_to_one_effect(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $request = $this->bookingRequest('approved');
        $database = $connection->getDatabaseName();
        $token = bin2hex(random_bytes(8));
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."resp-state-booking-go-{$token}";
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."resp-state-booking-ready-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."resp-state-booking-ready-b-{$token}",
        ];
        $processes = [];
        $committed = false;

        $connection->commit();
        $committed = true;

        try {
            foreach ($readyPaths as $readyPath) {
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/RespiteStateBookingCreationWorker.php'),
                    $database,
                    (string) $request->id,
                    (string) $this->admin->id,
                    $readyPath,
                    $releasePath,
                ]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }

            foreach ($readyPaths as $index => $readyPath) {
                $this->waitForWorker($processes[$index], $readyPath);
            }

            file_put_contents($releasePath, 'go', LOCK_EX);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'Concurrent respite booking worker failed.',
                );
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            $this->assertSame(1, collect($results)->where('created', true)->count());
            $this->assertSame(1, collect($results)->where('created', false)->count());
            $this->assertSame('approved', $request->refresh()->status);
            $this->assertSame(1, RespiteBooking::query()->where('booking_request_id', $request->id)->count());
            $booking = RespiteBooking::query()->where('booking_request_id', $request->id)->sole();
            $this->assertSame(1, Shift::query()->where('respite_booking_id', $booking->id)->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([...$readyPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if ($committed) {
                $this->cleanupCommittedWorkerState($connection, $request);
            }
        }
    }

    private function bookingRequest(string $status): RespiteBookingRequest
    {
        return RespiteBookingRequest::query()->create([
            'client_id' => $this->client->id,
            'service_context_id' => $this->serviceContext->id,
            'requested_start' => now()->addDays(7),
            'requested_end' => now()->addDays(9),
            'status' => $status,
            'created_by' => $this->admin->id,
        ]);
    }

    private function booking(string $status): RespiteBooking
    {
        return RespiteBooking::factory()->create([
            'client_id' => $this->client->id,
            'location_id' => $this->site->id,
            'status' => $status,
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(3),
        ]);
    }

    private function siteCoordinator(Site $site): User
    {
        $coordinator = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $role = Role::query()->firstOrCreate(
            ['name' => 'resp_state_site_coordinator'],
            [
                'label' => 'Respite State Site Coordinator',
                'level' => 20,
                'type' => 'custom',
            ],
        );
        $role->permissions()->sync(Permission::query()->whereIn('key', [
            'clients.viewAssigned',
            'respite.create',
            'respite.viewAny',
            'respite.update',
            'respite.bookings.manage',
            'respite.stays.manage',
        ])->pluck('id'));
        $coordinator->roles()->attach($role);
        HrEmployeeProfile::factory()->create([
            'user_id' => $coordinator->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $coordinator;
    }

    private function waitForWorker(Process $process, string $readyPath): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($readyPath)) {
            if (! $process->isRunning()) {
                $this->fail(trim($process->getErrorOutput()) ?: 'Respite worker exited before becoming ready.');
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Respite worker did not reach the concurrency barrier.');
            }

            usleep(20_000);
        }
    }

    private function cleanupCommittedWorkerState(Connection $connection, RespiteBookingRequest $request): void
    {
        try {
            $bookingIds = DB::table('respite_bookings')
                ->where('booking_request_id', $request->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $shiftIds = DB::table('shifts')
                ->whereIn('respite_booking_id', $bookingIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $auditSubjects = [
                (new Shift)->getMorphClass() => $shiftIds,
                (new RespiteBooking)->getMorphClass() => $bookingIds,
                (new RespiteBookingRequest)->getMorphClass() => [$request->id],
                (new Client)->getMorphClass() => [$this->client->id],
                (new ServiceContext)->getMorphClass() => [$this->serviceContext->id],
                (new Site)->getMorphClass() => [$this->site->id],
                (new User)->getMorphClass() => [$this->admin->id],
            ];
            foreach ($auditSubjects as $type => $ids) {
                if ($ids !== []) {
                    DB::table('audit_logs')->where('auditable_type', $type)->whereIn('auditable_id', $ids)->delete();
                }
            }
            DB::table('audit_logs')->where('user_id', $this->admin->id)->delete();
            DB::table('audit_logs')->where('client_id', $this->client->id)->delete();
            DB::table('shifts')->whereIn('id', $shiftIds)->delete();
            DB::table('respite_stays')->whereIn('booking_id', $bookingIds)->delete();
            DB::table('respite_bookings')->whereIn('id', $bookingIds)->delete();
            DB::table('respite_booking_requests')->where('id', $request->id)->delete();
            DB::table('client_user')->where('client_id', $this->client->id)->delete();
            DB::table('clients')->where('id', $this->client->id)->delete();
            DB::table('service_contexts')->where('id', $this->serviceContext->id)->delete();
            DB::table('role_user')->where('user_id', $this->admin->id)->delete();
            DB::table('permission_user')->where('user_id', $this->admin->id)->delete();
            DB::table('users')->where('id', $this->admin->id)->delete();
            DB::table('sites')->where('id', $this->site->id)->delete();
        } finally {
            $connection->beginTransaction();
        }
    }
}
