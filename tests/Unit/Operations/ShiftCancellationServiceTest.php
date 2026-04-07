<?php

namespace Tests\Unit\Operations;

use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\FleetResidentTransport;
use App\Models\FleetVehicleBooking;
use App\Models\MedicationRound;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShiftCancellationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_returns_impact_summary_and_flags_related_records(): void
    {
        $actor = User::factory()->create();
        $client = Client::factory()->create();
        $serviceContext = ServiceContext::factory()->create();
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $actor->id,
            'created_by' => $actor->id,
        ]);

        $timesheet = Timesheet::factory()->submitted()->create([
            'shift_id' => $shift->id,
            'client_id' => $client->id,
            'user_id' => $actor->id,
        ]);

        $medication = ClientMedication::factory()->create(['client_id' => $client->id]);
        $round = MedicationRound::query()->create([
            'service_context_id' => $serviceContext->id,
            'name' => 'Evening round',
            'round_type' => 'scheduled',
            'scheduled_time' => '18:00:00',
            'round_date' => now()->toDateString(),
            'status' => 'pending',
        ]);
        $administration = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'shift_id' => $shift->id,
            'medication_round_id' => $round->id,
            'administered_by' => $actor->id,
            'status' => 'pending',
            'scheduled_for' => now(),
        ]);

        $asset = Asset::factory()->vehicle()->create();
        $booking = FleetVehicleBooking::factory()->create([
            'asset_id' => $asset->id,
            'user_id' => $actor->id,
            'status' => 'approved',
        ]);
        $transport = FleetResidentTransport::query()->create([
            'asset_id' => $asset->id,
            'booking_id' => $booking->id,
            'shift_id' => $shift->id,
            'service_context_id' => $serviceContext->id,
            'driver_user_id' => $actor->id,
            'resident_id' => $client->id,
            'resident_name' => 'Client Example',
            'transport_type' => 'medical',
            'departed_at' => now(),
            'status' => 'in_progress',
        ]);

        $result = app(ShiftCancellationService::class)->cancel($shift, $actor);

        $this->assertFalse($result['already_cancelled']);
        $this->assertSame('cancelled', $result['shift']->status);
        $this->assertSame(1, $result['impact']['timesheets']['count']);
        $this->assertSame([$timesheet->id], $result['impact']['timesheets']['ids']);
        $this->assertSame([$administration->id], $result['impact']['medication_administrations']['ids']);
        $this->assertSame([$round->id], $result['impact']['medication_rounds']['ids']);
        $this->assertSame([$transport->id], $result['impact']['resident_transports']['ids']);
        $this->assertSame([$booking->id], $result['impact']['fleet_vehicle_bookings']['ids']);

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'status' => 'returned',
        ]);
        $this->assertDatabaseHas('client_medication_administrations', [
            'id' => $administration->id,
            'review_required' => true,
            'review_reason' => ShiftCancellationService::MEDICATION_REVIEW_REASON,
        ]);
        $this->assertDatabaseHas('medication_rounds', [
            'id' => $round->id,
            'review_required' => true,
            'review_reason' => ShiftCancellationService::MEDICATION_ROUND_REVIEW_REASON,
        ]);
        $this->assertDatabaseHas('fleet_resident_transports', [
            'id' => $transport->id,
            'review_required' => true,
            'review_reason' => ShiftCancellationService::TRANSPORT_REVIEW_REASON,
        ]);
        $this->assertDatabaseHas('fleet_vehicle_bookings', [
            'id' => $booking->id,
            'review_required' => true,
            'review_reason' => ShiftCancellationService::BOOKING_REVIEW_REASON,
        ]);
    }

    public function test_service_rejects_cancellation_when_approved_timesheet_exists(): void
    {
        $actor = User::factory()->create();
        $client = Client::factory()->create();
        $serviceContext = ServiceContext::factory()->create();
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $actor->id,
            'created_by' => $actor->id,
        ]);

        Timesheet::factory()->approved()->create([
            'shift_id' => $shift->id,
            'client_id' => $client->id,
            'user_id' => $actor->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('approved timesheet');

        app(ShiftCancellationService::class)->cancel($shift, $actor);
    }
}
