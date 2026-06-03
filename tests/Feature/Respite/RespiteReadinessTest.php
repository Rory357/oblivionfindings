<?php

namespace Tests\Feature\Respite;

use App\Models\Client;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteStay;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RespiteReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceContext $serviceContext;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->serviceContext = ServiceContext::factory()->create([
            'type' => 'planned_respite',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'service_context_id' => $this->serviceContext->id,
        ]);
    }

    public function test_rbac_seed_includes_all_routed_respite_and_family_portal_permissions(): void
    {
        $expected = [
            'respite.tasks.view',
            'respite.tasks.manage',
            'respite.tasks.approve',
            'respite.handovers.view',
            'respite.handovers.manage',
            'respite.communications.view',
            'respite.communications.manage',
            'respite.daily-notes.view',
            'respite.daily-notes.manage',
            'respite.risk-plans.view',
            'respite.risk-plans.manage',
            'respite.evidence.manage',
            'respite.evidence.seal',
            'respite.procedures.run',
            'family_portal.viewAny',
            'family_portal.manage',
        ];

        $seeded = Permission::query()
            ->whereIn('key', $expected)
            ->pluck('key')
            ->all();

        $this->assertSame([], array_values(array_diff($expected, $seeded)));

        $coordinatorKeys = Role::where('name', 'coordinator')
            ->firstOrFail()
            ->permissions()
            ->pluck('key')
            ->all();

        $this->assertContains('respite.tasks.view', $coordinatorKeys);
        $this->assertContains('respite.daily-notes.view', $coordinatorKeys);
        $this->assertContains('family_portal.viewAny', $coordinatorKeys);
    }

    public function test_unassigned_support_worker_cannot_create_respite_booking_for_unassigned_client(): void
    {
        $worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $worker->roles()->attach(Role::where('name', 'support_worker')->first());

        $permission = Permission::where('key', 'respite.bookings.manage')->firstOrFail();
        $worker->permissionOverrides()->attach($permission->id, ['allowed' => true]);

        $this->actingAs($worker)
            ->post(route('respite.bookings.store'), [
                'client_id' => $this->client->id,
                'start_at' => now()->addDays(7)->setTime(9, 0)->format('Y-m-d H:i:s'),
                'end_at' => now()->addDays(7)->setTime(17, 0)->format('Y-m-d H:i:s'),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('respite_bookings', [
            'client_id' => $this->client->id,
        ]);
    }

    public function test_booking_cancellation_cascades_to_linked_shift_without_touching_completed_shifts(): void
    {
        $booking = RespiteBooking::factory()->create([
            'client_id' => $this->client->id,
            'status' => 'confirmed',
            'start_at' => now()->addDays(5)->setTime(9, 0),
            'end_at' => now()->addDays(5)->setTime(17, 0),
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'service_context_id' => $this->serviceContext->id,
            'respite_booking_id' => $booking->id,
            'status' => 'scheduled',
            'notes' => 'Initial respite notes',
        ]);

        $this->actingAs($this->admin)
            ->put(route('respite.bookings.update', $booking), [
                'start_at' => $booking->start_at->format('Y-m-d H:i:s'),
                'end_at' => $booking->end_at->format('Y-m-d H:i:s'),
                'status' => 'cancelled',
                'cancellation_reason' => 'Family requested cancellation',
            ])
            ->assertRedirect();

        $shift->refresh();
        $this->assertSame('cancelled', $shift->status);
        $this->assertStringContainsString('Family requested cancellation', (string) $shift->notes);

        $completedBooking = RespiteBooking::factory()->create([
            'client_id' => $this->client->id,
            'status' => 'confirmed',
        ]);
        $completedShift = Shift::factory()->completed()->create([
            'client_id' => $this->client->id,
            'service_context_id' => $this->serviceContext->id,
            'respite_booking_id' => $completedBooking->id,
        ]);

        $this->actingAs($this->admin)
            ->put(route('respite.bookings.update', $completedBooking), [
                'start_at' => $completedBooking->start_at->format('Y-m-d H:i:s'),
                'end_at' => $completedBooking->end_at->format('Y-m-d H:i:s'),
                'status' => 'cancelled',
                'cancellation_reason' => 'Late cancellation',
            ])
            ->assertRedirect();

        $this->assertSame('completed', $completedShift->refresh()->status);
    }

    public function test_approving_booking_request_creates_one_linked_shift(): void
    {
        $request = RespiteBookingRequest::create([
            'client_id' => $this->client->id,
            'service_context_id' => $this->serviceContext->id,
            'requested_start' => now()->addDays(10)->setTime(10, 0),
            'requested_end' => now()->addDays(12)->setTime(15, 0),
            'requirements' => ['room' => 'quiet'],
            'status' => 'submitted',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('respite.requests.approve', $request))
            ->assertRedirect();

        $booking = RespiteBooking::where('booking_request_id', $request->id)->first();

        $this->assertNotNull($booking);
        $this->assertSame('approved', $request->refresh()->status);
        $this->assertSame(1, Shift::where('respite_booking_id', $booking->id)->count());
        $this->assertDatabaseHas('shifts', [
            'respite_booking_id' => $booking->id,
            'client_id' => $this->client->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_stay_lifecycle_updates_linked_booking_and_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-03 10:00:00'));

        $booking = RespiteBooking::factory()->create([
            'client_id' => $this->client->id,
            'status' => 'confirmed',
            'start_at' => Carbon::parse('2026-05-02 09:00:00'),
            'end_at' => Carbon::parse('2026-05-04 17:00:00'),
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'service_context_id' => $this->serviceContext->id,
            'respite_booking_id' => $booking->id,
            'starts_at' => $booking->start_at,
            'ends_at' => $booking->end_at,
            'status' => 'scheduled',
            'notes' => null,
        ]);
        $stay = RespiteStay::create([
            'booking_id' => $booking->id,
            'client_id' => $this->client->id,
            'status' => 'admitted',
            'actual_start' => Carbon::parse('2026-05-02 09:30:00'),
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('respite.stays.checkin', $stay))
            ->assertRedirect();

        $shift->refresh();
        $this->assertSame('in_progress', $shift->status);
        $this->assertNotNull($shift->actual_starts_at);

        $newEnd = Carbon::parse('2026-05-02 18:00:00');

        $this->actingAs($this->admin)
            ->post(route('respite.stays.extend', $stay), [
                'new_end' => $newEnd->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($booking->refresh()->end_at->equalTo($newEnd));
        $this->assertTrue($shift->refresh()->ends_at->equalTo($newEnd));

        $this->actingAs($this->admin)
            ->post(route('respite.stays.discharge', $stay), [
                'discharge_summary' => 'Settled well and returned home with family.',
            ])
            ->assertRedirect();

        $shift->refresh();
        $this->assertSame('completed', $shift->status);
        $this->assertNotNull($shift->actual_ends_at);
        $this->assertStringContainsString('Settled well and returned home with family.', (string) $shift->notes);

        Carbon::setTestNow();
    }

    public function test_linked_respite_shift_is_identified_on_shift_and_calendar_surfaces(): void
    {
        $booking = RespiteBooking::factory()->create([
            'client_id' => $this->client->id,
            'status' => 'confirmed',
            'start_at' => now()->addDays(3)->setTime(9, 0),
            'end_at' => now()->addDays(3)->setTime(17, 0),
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'service_context_id' => $this->serviceContext->id,
            'respite_booking_id' => $booking->id,
            'starts_at' => $booking->start_at,
            'ends_at' => $booking->end_at,
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->admin)
            ->get(route('operations.shifts.show', $shift))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shift.respite_booking.id', $booking->id)
            );

        $calendarEvents = $this->actingAs($this->admin)
            ->getJson(route('scheduling.events', [
                'start' => now()->startOfDay()->toIso8601String(),
                'end' => now()->addDays(7)->endOfDay()->toIso8601String(),
            ]))
            ->assertOk()
            ->json();

        $calendarShift = collect($calendarEvents)->firstWhere('id', $shift->id);
        $this->assertNotNull($calendarShift);
        $this->assertTrue($calendarShift['extendedProps']['is_respite']);
        $this->assertSame($booking->id, $calendarShift['extendedProps']['respite_booking_id']);

        $clientCalendarEvents = $this->actingAs($this->admin)
            ->getJson(route('client.calendar.events', [
                'client' => $this->client,
                'start' => now()->startOfDay()->toIso8601String(),
                'end' => now()->addDays(7)->endOfDay()->toIso8601String(),
            ]))
            ->assertOk()
            ->json();

        $clientShift = collect($clientCalendarEvents)->firstWhere('id', 'shift-'.$shift->id);
        $this->assertNotNull($clientShift);
        $this->assertTrue($clientShift['extendedProps']['is_respite']);
        $this->assertSame($booking->id, $clientShift['extendedProps']['respite_booking_id']);
    }
}
