<?php

namespace Tests\Feature\Respite;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Events\Respite\RespiteEvent;
use App\Models\Client;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteCalendarEvent;
use App\Models\RespiteStay;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Respite\RespiteShiftSync;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
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
        $this->assertSame('approved', $request->refresh()->status);
        $this->assertSame('pending', $booking->status);
        $this->assertSame(1, Shift::query()->where('respite_booking_id', $booking->id)->count());
        $this->assertTrue(collect($locks)->contains(
            fn (string $sql) => str_contains($sql, 'respite_booking_requests'),
        ));
        Event::assertDispatchedTimes(RespiteEvent::class, 3);
    }

    public function test_terminal_and_bypass_request_and_booking_transitions_fail_without_side_effects(): void
    {
        Event::fake([RespiteEvent::class]);
        $rejected = $this->bookingRequest('rejected');
        $submitted = $this->bookingRequest('submitted');

        $this->actingAs($this->admin)
            ->put(route('respite.requests.update', $submitted), ['status' => 'approved'])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertSame('submitted', $submitted->refresh()->status);

        $this->actingAs($this->admin)
            ->post(route('respite.requests.approve', $rejected))
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertDatabaseMissing('respite_bookings', ['booking_request_id' => $rejected->id]);

        $booking = $this->booking('pending');
        $this->actingAs($this->admin)
            ->put(route('respite.bookings.update', $booking), [
                'status' => 'completed',
                'start_at' => $booking->start_at->format('Y-m-d H:i:s'),
                'end_at' => $booking->end_at->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertSame('pending', $booking->refresh()->status);

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
            ->put(route('respite.bookings.update', $noShowBooking), [
                'status' => 'no_show',
                'start_at' => $noShowBooking->start_at->format('Y-m-d H:i:s'),
                'end_at' => $noShowBooking->end_at->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('no_show', $noShowBooking->refresh()->status);
        $this->assertSame('cancelled', $noShowShift->refresh()->status);

        $this->actingAs($this->admin)
            ->put(route('respite.bookings.update', $noShowBooking), [
                'status' => 'no_show',
                'start_at' => $noShowBooking->start_at->format('Y-m-d H:i:s'),
                'end_at' => $noShowBooking->end_at->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertSame(1, substr_count((string) $noShowShift->refresh()->notes, 'recorded as no show'));

        $occupiedBooking = $this->booking('confirmed');
        RespiteStay::query()->create([
            'booking_id' => $occupiedBooking->id,
            'client_id' => $this->client->id,
            'status' => 'active',
            'actual_start' => now(),
        ]);
        $this->actingAs($this->admin)
            ->put(route('respite.bookings.update', $occupiedBooking), [
                'status' => 'cancelled',
                'start_at' => $occupiedBooking->start_at->format('Y-m-d H:i:s'),
                'end_at' => $occupiedBooking->end_at->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertSame('confirmed', $occupiedBooking->refresh()->status);
    }

    public function test_stay_lifecycle_is_coherent_and_terminal_actions_cannot_replay(): void
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
        $this->actingAs($this->admin)
            ->post(route('respite.stays.discharge', $stay), [
                'discharge_summary' => 'Replay must be rejected.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertTrue($stay->refresh()->actual_end->equalTo($dischargedAt));
    }

    public function test_transition_failure_rolls_back_the_entire_stay_graph_and_can_recover(): void
    {
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

    public function test_foreign_direct_objects_and_foreign_booking_location_are_concealed(): void
    {
        $foreignSite = Site::factory()->create(['respite_capacity' => 0]);
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $coordinator = $this->siteCoordinator($this->site);
        $this->client->supportWorkers()->syncWithoutDetaching([$coordinator->id]);

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
        $localBooking = RespiteBooking::factory()->create([
            'client_id' => $this->client->id,
            'location_id' => $this->site->id,
            'status' => 'pending',
        ]);
        $foreignLocationStay = RespiteStay::query()->create([
            'booking_id' => $foreignLocationBooking->id,
            'client_id' => $this->client->id,
            'status' => 'admitted',
            'actual_start' => now(),
        ]);

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
                'location_id' => $foreignSite->id,
                'start_at' => $localBooking->start_at->format('Y-m-d H:i:s'),
                'end_at' => $localBooking->end_at->format('Y-m-d H:i:s'),
            ])
            ->assertNotFound();
        $this->actingAs($coordinator)
            ->post(route('respite.stays.checkin', $foreignLocationStay))
            ->assertNotFound();

        $this->assertSame('submitted', $foreignRequest->refresh()->status);
        $this->assertSame('pending', $foreignLocationBooking->refresh()->status);
        $this->assertSame($this->site->id, $localBooking->refresh()->location_id);
        $this->assertSame('admitted', $foreignLocationStay->refresh()->status);
    }

    public function test_explicit_global_site_role_can_transition_across_sites(): void
    {
        $foreignSite = Site::factory()->create(['respite_capacity' => 0]);
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $booking = RespiteBooking::factory()->create([
            'client_id' => $foreignClient->id,
            'location_id' => $foreignSite->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->post(route('respite.bookings.confirm', $booking), [
                'readiness_override_reason' => 'Global-role readiness override.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $booking->refresh()->status);
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
        $role = Role::query()->create([
            'name' => 'resp_state_site_coordinator',
            'label' => 'Respite State Site Coordinator',
            'level' => 20,
            'type' => 'custom',
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', [
            'clients.viewAssigned',
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
}
