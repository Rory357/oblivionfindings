<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\FleetResidentTransport;
use App\Models\FleetVehicleBooking;
use App\Models\MedicationRound;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftCancellationService;
use App\Services\ShiftHandoverService;
use App\Services\ShiftTimelineService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Mockery\MockInterface;
use Tests\TestCase;

class ShiftCancellationCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected Client $client;

    protected Site $site;

    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->serviceContext = ServiceContext::factory()->create();
        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create([
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
        ]);
        $this->staff = $this->makeCurrentSiteStaff();
    }

    public function test_cancelling_shift_returns_linked_timesheets_and_records_reason(): void
    {
        $shift = $this->makeShift();

        $timesheet = Timesheet::factory()->submitted()->create([
            'shift_id' => $shift->id,
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
        ]);

        $this->from('/operations/shifts')
            ->actingAs($this->admin)
            ->patch("/operations/shifts/{$shift->id}/cancel")
            ->assertRedirect('/operations/shifts')
            ->assertSessionHas('success', 'Shift occurrence cancelled.');

        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'status' => 'cancelled',
        ]);

        $timesheet->refresh();
        $this->assertSame('returned', $timesheet->status);
        $this->assertStringContainsString(ShiftCancellationService::TIMESHEET_RETURN_REASON, (string) $timesheet->returned_notes);

        $event = TimelineEvent::query()
            ->where('type', ShiftTimelineService::CANCELLATION_CASCADE_EVENT_TYPE)
            ->where('shift_id', $shift->id)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(1, $event->meta['impact']['timesheets']['count'] ?? null);
    }

    public function test_cancelling_shift_with_approved_timesheet_is_rejected(): void
    {
        $shift = $this->makeShift();

        Timesheet::factory()->approved()->create([
            'shift_id' => $shift->id,
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
        ]);

        $this->from('/operations/shifts')
            ->actingAs($this->admin)
            ->patch("/operations/shifts/{$shift->id}/cancel")
            ->assertRedirect('/operations/shifts')
            ->assertSessionHasErrors(['shift']);

        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_cancelling_shift_flags_linked_medication_records_for_review(): void
    {
        $shift = $this->makeShift();
        $medication = ClientMedication::factory()->create([
            'client_id' => $this->client->id,
        ]);

        $round = MedicationRound::query()->create([
            'service_context_id' => $this->serviceContext->id,
            'name' => 'Morning round',
            'round_type' => 'scheduled',
            'scheduled_time' => '08:00:00',
            'round_date' => now()->toDateString(),
            'status' => 'pending',
            'total_medications' => 1,
        ]);

        $administration = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $medication->id,
            'shift_id' => $shift->id,
            'medication_round_id' => $round->id,
            'administered_by' => $this->staff->id,
            'status' => 'pending',
            'scheduled_for' => now(),
            'notes' => 'Created before cancellation',
        ]);

        $this->actingAs($this->admin)->patch("/operations/shifts/{$shift->id}/cancel");

        $administration->refresh();
        $round->refresh();

        $this->assertTrue((bool) $administration->review_required);
        $this->assertSame(ShiftCancellationService::MEDICATION_REVIEW_REASON, $administration->review_reason);
        $this->assertStringContainsString(ShiftCancellationService::MEDICATION_REVIEW_REASON, (string) $administration->notes);

        $this->assertTrue((bool) $round->review_required);
        $this->assertSame(ShiftCancellationService::MEDICATION_ROUND_REVIEW_REASON, $round->review_reason);
    }

    public function test_cancelling_shift_flags_linked_transport_records_for_review(): void
    {
        $shift = $this->makeShift();
        $asset = Asset::factory()->vehicle()->create();

        $booking = FleetVehicleBooking::factory()->create([
            'asset_id' => $asset->id,
            'user_id' => $this->staff->id,
            'status' => 'approved',
            'notes' => 'Transport booking',
        ]);

        $transport = FleetResidentTransport::query()->create([
            'asset_id' => $asset->id,
            'booking_id' => $booking->id,
            'shift_id' => $shift->id,
            'service_context_id' => $this->serviceContext->id,
            'driver_user_id' => $this->staff->id,
            'resident_id' => $this->client->id,
            'resident_name' => 'Resident Example',
            'transport_type' => 'medical',
            'departed_at' => now(),
            'status' => 'in_progress',
            'notes' => 'Open transport',
        ]);

        $this->actingAs($this->admin)->patch("/operations/shifts/{$shift->id}/cancel");

        $transport->refresh();
        $booking->refresh();

        $this->assertTrue((bool) $transport->review_required);
        $this->assertSame(ShiftCancellationService::TRANSPORT_REVIEW_REASON, $transport->review_reason);
        $this->assertStringContainsString(ShiftCancellationService::TRANSPORT_REVIEW_REASON, (string) $transport->notes);

        $this->assertTrue((bool) $booking->review_required);
        $this->assertSame(ShiftCancellationService::BOOKING_REVIEW_REASON, $booking->review_reason);
        $this->assertStringContainsString(ShiftCancellationService::BOOKING_REVIEW_REASON, (string) $booking->notes);
    }

    public function test_cancelling_shift_preserves_incidents_and_records_timeline_impact(): void
    {
        $shift = $this->makeShift();

        $incident = ClientIncident::factory()->create([
            'client_id' => $this->client->id,
            'shift_id' => $shift->id,
            'reported_by' => $this->staff->id,
        ]);

        $this->actingAs($this->admin)->patch("/operations/shifts/{$shift->id}/cancel");

        $this->assertDatabaseHas('client_incidents', [
            'id' => $incident->id,
            'shift_id' => $shift->id,
        ]);

        $event = TimelineEvent::query()
            ->where('type', ShiftTimelineService::CANCELLATION_CASCADE_EVENT_TYPE)
            ->where('shift_id', $shift->id)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(1, $event->meta['impact']['incidents']['count'] ?? null);
        $this->assertContains($incident->id, $event->meta['impact']['incidents']['ids'] ?? []);
        $this->assertStringContainsString('Incident linkage preserved after cancellation', (string) $event->body);
    }

    public function test_controller_cancellation_path_uses_service(): void
    {
        $shift = $this->makeShift();

        $this->mock(ShiftCancellationService::class, function (MockInterface $mock) use ($shift) {
            $mock->shouldReceive('cancel')
                ->once()
                ->withArgs(fn (Shift $passedShift, User $actor) => $passedShift->is($shift) && $actor->is($this->admin))
                ->andReturn([
                    'shift' => $shift,
                    'already_cancelled' => false,
                    'impact' => [
                        'timesheets' => ['count' => 0, 'ids' => []],
                        'medication_administrations' => ['count' => 0, 'ids' => []],
                        'medication_rounds' => ['count' => 0, 'ids' => []],
                        'resident_transports' => ['count' => 0, 'ids' => []],
                        'fleet_vehicle_bookings' => ['count' => 0, 'ids' => []],
                        'incidents' => ['count' => 0, 'ids' => []],
                    ],
                ]);
        });

        $this->from('/operations/shifts')
            ->actingAs($this->admin)
            ->patch("/operations/shifts/{$shift->id}/cancel")
            ->assertRedirect('/operations/shifts')
            ->assertSessionHas('success', 'Shift occurrence cancelled.');
    }

    // ──────────────────────────────────────────────
    // Incoming handover staff notification
    // ──────────────────────────────────────────────

    public function test_cancelling_shift_notifies_incoming_handover_staff(): void
    {
        $incomingStaff = $this->makeCurrentSiteStaff();

        $shift = $this->makeShift();

        $incomingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $incomingStaff->id,
            'created_by' => $this->admin->id,
            'starts_at' => $shift->ends_at,
            'ends_at' => $shift->ends_at->copy()->addHours(8),
            'status' => 'scheduled',
        ]);

        ShiftHandover::factory()->create([
            'outgoing_shift_id' => $shift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->staff->id,
            'incoming_staff_id' => $incomingStaff->id,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'submitted_at' => now()->subHour(),
            'submitted_by' => $this->staff->id,
        ]);

        $this->actingAs($this->admin)
            ->patch("/operations/shifts/{$shift->id}/cancel");

        $notifications = DatabaseNotification::query()
            ->where('notifiable_id', $incomingStaff->id)
            ->where('notifiable_type', $incomingStaff->getMorphClass())
            ->get();

        $this->assertCount(1, $notifications);

        $data = $notifications->first()->data;
        $this->assertSame('shifts.handover.cancelled', $data['event_key']);
        $this->assertSame('operational', $data['kind']);
        $this->assertSame('handover', $data['subtype']);
        $this->assertStringContainsString('cancelled', $data['body']);
    }

    public function test_cancelling_shift_without_handover_sends_no_notification(): void
    {
        $shift = $this->makeShift();

        $this->actingAs($this->admin)
            ->patch("/operations/shifts/{$shift->id}/cancel");

        $this->assertSame(
            0,
            DatabaseNotification::query()
                ->whereJsonContains('data->event_key', 'shifts.handover.cancelled')
                ->count(),
        );
    }

    public function test_duplicate_cancellation_does_not_send_duplicate_notification(): void
    {
        $incomingStaff = $this->makeCurrentSiteStaff();

        $shift = $this->makeShift();

        ShiftHandover::factory()->create([
            'outgoing_shift_id' => $shift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->staff->id,
            'incoming_staff_id' => $incomingStaff->id,
            'status' => ShiftHandoverService::STATUS_DRAFT,
        ]);

        $this->actingAs($this->admin)
            ->patch("/operations/shifts/{$shift->id}/cancel");

        // Second attempt — shift already cancelled, cancel() returns early
        $this->actingAs($this->admin)
            ->patch("/operations/shifts/{$shift->id}/cancel");

        $count = DatabaseNotification::query()
            ->where('notifiable_id', $incomingStaff->id)
            ->where('notifiable_type', $incomingStaff->getMorphClass())
            ->whereJsonContains('data->event_key', 'shifts.handover.cancelled')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_acknowledged_handover_does_not_trigger_notification(): void
    {
        $incomingStaff = $this->makeCurrentSiteStaff();

        $shift = $this->makeShift();

        $incomingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $incomingStaff->id,
            'created_by' => $this->admin->id,
            'starts_at' => $shift->ends_at,
            'ends_at' => $shift->ends_at->copy()->addHours(8),
            'status' => 'scheduled',
        ]);

        ShiftHandover::factory()->create([
            'outgoing_shift_id' => $shift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->staff->id,
            'incoming_staff_id' => $incomingStaff->id,
            'status' => ShiftHandoverService::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now()->subMinutes(30),
            'acknowledged_by' => $incomingStaff->id,
        ]);

        $this->actingAs($this->admin)
            ->patch("/operations/shifts/{$shift->id}/cancel");

        $count = DatabaseNotification::query()
            ->where('notifiable_id', $incomingStaff->id)
            ->whereJsonContains('data->event_key', 'shifts.handover.cancelled')
            ->count();

        // Acknowledged handovers are excluded — the incoming staff already received the info
        $this->assertSame(0, $count);
    }

    protected function makeShift(): Shift
    {
        return Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'created_by' => $this->admin->id,
            'status' => 'scheduled',
        ]);
    }

    protected function makeCurrentSiteStaff(): User
    {
        $staff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $staff->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());

        HrEmployeeProfile::factory()->create([
            'user_id' => $staff->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);

        return $staff;
    }
}
