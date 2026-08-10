<?php

namespace Tests\Feature\FleetAssets;

use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\FleetIncident;
use App\Models\FleetResidentTransport;
use App\Models\FleetVehicleBooking;
use App\Models\Role;
use App\Models\SafeguardingAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentJourneyService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Fleet & Asset Incidents redesign — Step 7 backend coverage.
 */
class FleetIncidentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    private function vehicle(): Asset
    {
        $site = Site::factory()->create();

        return Asset::factory()->create(['category' => 'vehicle', 'site_id' => $site->id]);
    }

    /* -------------------------------------------------------------- */
    /*  Report (store) + regulatory derivation */
    /* -------------------------------------------------------------- */

    public function test_report_vehicle_incident_creates_record_and_derives_s22_and_worksafe(): void
    {
        Notification::fake();
        $asset = $this->vehicle();
        $occurred = now()->subHour();

        $response = $this->actingAs($this->admin)
            ->from('/fleet-assets/incidents')
            ->post('/fleet-assets/incidents', [
                'asset_id' => $asset->id,
                'incident_type' => 'collision',
                'severity' => 'major',
                'occurred_at' => $occurred->toDateTimeString(),
                'location' => 'SH1, Penrose',
                'description' => 'Rear-ended at the lights.',
                'immediate_action_taken' => 'Stopped the vehicle, checked everyone for injuries, and called emergency services.',
                'injury_involved' => true,
                'injury_severity' => 'hospitalisation',
            ]);

        $response->assertRedirect('/fleet-assets/incidents')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('created_fleet_incident_id');

        $incident = FleetIncident::latest('id')->first();
        $this->assertNotNull($incident);
        $this->assertSame('reported', $incident->status);
        $this->assertSame($this->admin->id, $incident->reported_by_user_id);
        // Snapshot of the asset category captured at report time.
        $this->assertSame('vehicle', $incident->asset_category);
        // s22: a 24-hour Police-report window from occurred_at.
        $this->assertNotNull($incident->police_report_due_at);
        $this->assertEqualsWithDelta(
            $occurred->copy()->addHours(24)->timestamp,
            $incident->police_report_due_at->timestamp,
            5,
        );
        $this->assertTrue($incident->isPoliceReportDue());
        // WorkSafe: hospitalisation is notifiable (HSWA).
        $this->assertTrue((bool) $incident->is_notifiable);
        $this->assertSame('pending', $incident->worksafe_notification_status);
    }

    public function test_report_without_injury_has_no_police_window_and_is_not_notifiable(): void
    {
        Notification::fake();
        $asset = $this->vehicle();

        $this->actingAs($this->admin)
            ->from('/fleet-assets/incidents')
            ->post('/fleet-assets/incidents', [
                'asset_id' => $asset->id,
                'incident_type' => 'damage',
                'severity' => 'minor',
                'occurred_at' => now()->subHour()->toDateTimeString(),
                'description' => 'Scraped the wing mirror reversing.',
            ])
            ->assertSessionHasNoErrors();

        $incident = FleetIncident::latest('id')->first();
        $this->assertNull($incident->police_report_due_at);
        $this->assertFalse($incident->isPoliceReportDue());
        $this->assertFalse((bool) $incident->is_notifiable);
    }

    /* -------------------------------------------------------------- */
    /*  Observer (Gap F4 fix): severity mapping + alert */
    /* -------------------------------------------------------------- */

    public function test_major_incident_records_high_hs_event_and_raises_control_room_alert(): void
    {
        $incident = FleetIncident::factory()->create([
            'asset_id' => $this->vehicle()->id,
            'severity' => 'major',
        ]);

        $hsEvent = $incident->linkedHsEvent();
        $this->assertNotNull($hsEvent, 'A major fleet incident must record an HsEvent.');
        // The bug: 'major' never matched ['high','critical'] so it recorded as 'low'.
        $this->assertSame('high', $hsEvent->severity);

        // The bug also meant major incidents never raised a Control Room alert.
        $this->assertGreaterThanOrEqual(1, ControlRoomAlert::query()->count());
    }

    public function test_minor_incident_records_low_hs_event_and_no_alert(): void
    {
        $incident = FleetIncident::factory()->create([
            'asset_id' => $this->vehicle()->id,
            'severity' => 'minor',
        ]);

        $hsEvent = $incident->linkedHsEvent();
        $this->assertNotNull($hsEvent);
        $this->assertSame('low', $hsEvent->severity);
        $this->assertSame(0, ControlRoomAlert::query()->count());
    }

    /* -------------------------------------------------------------- */
    /*  Transport cascade (Gap F1 direct FK) */
    /* -------------------------------------------------------------- */

    public function test_transport_incident_cascades_to_client_incident_with_direct_fk(): void
    {
        Notification::fake();
        $asset = $this->vehicle();
        $client = Client::factory()->create(['site_id' => $asset->site_id]);
        $booking = FleetVehicleBooking::factory()->create(['asset_id' => $asset->id]);
        FleetResidentTransport::query()->create([
            'asset_id' => $asset->id,
            'booking_id' => $booking->id,
            'site_id' => $asset->site_id,
            'driver_user_id' => $this->admin->id,
            'resident_id' => $client->id,
            'resident_name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
            'transport_type' => 'medical',
            'departed_at' => now(),
            'status' => 'in_progress',
        ]);

        $immediateActionTaken = 'Stopped the vehicle, moved residents to a safe area, and called the on-call manager.';

        $this->actingAs($this->admin)
            ->from('/fleet-assets/incidents')
            ->post('/fleet-assets/incidents', [
                'asset_id' => $asset->id,
                'booking_id' => $booking->id,
                'incident_type' => 'collision',
                'severity' => 'major',
                'occurred_at' => now()->subHour()->toDateTimeString(),
                'description' => 'Collision with two residents aboard.',
                'immediate_action_taken' => $immediateActionTaken,
            ])
            ->assertSessionHasNoErrors();

        $incident = FleetIncident::latest('id')->first();

        $clientIncident = ClientIncident::where('fleet_incident_id', $incident->id)->first();
        $this->assertNotNull($clientIncident, 'A transport incident must cascade to a client incident.');
        $this->assertSame('transport_incident', $clientIncident->type);
        $this->assertSame($client->id, $clientIncident->client_id);
        $this->assertSame($asset->site_id, $clientIncident->site_id);
        $this->assertNotNull($clientIncident->hs_event_id);
        $this->assertNotNull($clientIncident->control_room_alert_id);
        $this->assertSame($immediateActionTaken, $clientIncident->immediate_action_taken);
        // Reverse relation resolves (Gap F1).
        $this->assertSame($incident->id, $clientIncident->fleetIncident->id);

        // Major/critical also raises a safeguarding alert per resident (severity
        // mapped major->high to satisfy the alert enum).
        $this->assertDatabaseHas('safeguarding_alerts', [
            'alert_type' => 'requires_monitoring',
            'alertable_id' => $client->id,
            'severity' => 'high',
        ]);
    }

    public function test_serious_transport_incident_requires_explicit_immediate_action_before_any_record_is_created(): void
    {
        Notification::fake();
        $asset = $this->vehicle();
        $client = Client::factory()->create(['site_id' => $asset->site_id]);
        $booking = FleetVehicleBooking::factory()->create(['asset_id' => $asset->id]);
        FleetResidentTransport::query()->create([
            'asset_id' => $asset->id,
            'booking_id' => $booking->id,
            'site_id' => $asset->site_id,
            'driver_user_id' => $this->admin->id,
            'resident_id' => $client->id,
            'resident_name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
            'transport_type' => 'medical',
            'departed_at' => now(),
            'status' => 'in_progress',
        ]);

        $this->actingAs($this->admin)
            ->from('/fleet-assets/incidents')
            ->post('/fleet-assets/incidents', [
                'asset_id' => $asset->id,
                'booking_id' => $booking->id,
                'incident_type' => 'collision',
                'severity' => 'major',
                'occurred_at' => now()->subHour()->toDateTimeString(),
                'description' => 'Collision with a resident aboard.',
            ])
            ->assertRedirect('/fleet-assets/incidents')
            ->assertSessionHasErrors('immediate_action_taken');

        $this->assertDatabaseCount('fleet_incidents', 0);
        $this->assertDatabaseCount('client_incidents', 0);
    }

    public function test_transport_site_disagreement_rolls_back_the_fleet_and_canonical_incident_chain(): void
    {
        Notification::fake();
        $asset = $this->vehicle();
        $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
        $booking = FleetVehicleBooking::factory()->create(['asset_id' => $asset->id]);
        FleetResidentTransport::query()->create([
            'asset_id' => $asset->id,
            'booking_id' => $booking->id,
            'site_id' => $asset->site_id,
            'driver_user_id' => $this->admin->id,
            'resident_id' => $client->id,
            'resident_name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
            'transport_type' => 'medical',
            'departed_at' => now(),
            'status' => 'in_progress',
        ]);

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->admin)->post('/fleet-assets/incidents', [
                'asset_id' => $asset->id,
                'booking_id' => $booking->id,
                'incident_type' => 'collision',
                'severity' => 'major',
                'occurred_at' => now()->subHour()->toDateTimeString(),
                'description' => 'Resident transport collision with conflicting Site provenance.',
                'immediate_action_taken' => 'Stopped the vehicle and checked the resident for injury.',
            ]);
            $this->fail('A resident transport Site disagreement must fail closed.');
        } catch (\DomainException $exception) {
            $this->assertSame(
                'Transport incident Site provenance must agree before creating a resident incident.',
                $exception->getMessage(),
            );
        }

        $this->assertNoPartialResidentIncidentJourney();
    }

    public function test_resident_incident_journey_failure_rolls_back_the_fleet_and_client_incident(): void
    {
        Notification::fake();
        $asset = $this->vehicle();
        $client = Client::factory()->create(['site_id' => $asset->site_id]);
        $booking = FleetVehicleBooking::factory()->create(['asset_id' => $asset->id]);
        FleetResidentTransport::query()->create([
            'asset_id' => $asset->id,
            'booking_id' => $booking->id,
            'site_id' => $asset->site_id,
            'driver_user_id' => $this->admin->id,
            'resident_id' => $client->id,
            'resident_name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
            'transport_type' => 'medical',
            'departed_at' => now(),
            'status' => 'in_progress',
        ]);
        $this->mock(IncidentJourneyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ensureForSubmittedIncident')
                ->once()
                ->andThrow(new \RuntimeException('Forced resident journey failure.'));
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->admin)->post('/fleet-assets/incidents', [
                'asset_id' => $asset->id,
                'booking_id' => $booking->id,
                'incident_type' => 'collision',
                'severity' => 'major',
                'occurred_at' => now()->subHour()->toDateTimeString(),
                'description' => 'Resident transport collision requiring a canonical journey.',
                'immediate_action_taken' => 'Stopped the vehicle and called emergency services.',
            ]);
            $this->fail('A resident incident journey failure must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced resident journey failure.', $exception->getMessage());
        }

        $this->assertNoPartialResidentIncidentJourney();
    }

    public function test_safeguarding_cascade_failure_rolls_back_every_canonical_incident_record(): void
    {
        Notification::fake();
        $asset = $this->vehicle();
        $client = Client::factory()->create(['site_id' => $asset->site_id]);
        $booking = FleetVehicleBooking::factory()->create(['asset_id' => $asset->id]);
        FleetResidentTransport::query()->create([
            'asset_id' => $asset->id,
            'booking_id' => $booking->id,
            'site_id' => $asset->site_id,
            'driver_user_id' => $this->admin->id,
            'resident_id' => $client->id,
            'resident_name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
            'transport_type' => 'medical',
            'departed_at' => now(),
            'status' => 'in_progress',
        ]);
        SafeguardingAlert::creating(
            fn (): never => throw new \RuntimeException('Forced safeguarding cascade failure.'),
        );
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->admin)->post('/fleet-assets/incidents', [
                'asset_id' => $asset->id,
                'booking_id' => $booking->id,
                'incident_type' => 'collision',
                'severity' => 'major',
                'occurred_at' => now()->subHour()->toDateTimeString(),
                'description' => 'Resident transport collision requiring safeguarding escalation.',
                'immediate_action_taken' => 'Stopped the vehicle, checked the resident, and called emergency services.',
            ]);
            $this->fail('A safeguarding cascade failure must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced safeguarding cascade failure.', $exception->getMessage());
        }

        $this->assertNoPartialResidentIncidentJourney();
    }

    /* -------------------------------------------------------------- */
    /*  Lifecycle + closure gate */
    /* -------------------------------------------------------------- */

    public function test_closing_requires_resolution_notes(): void
    {
        $incident = FleetIncident::factory()->create(['asset_id' => $this->vehicle()->id, 'status' => 'investigating']);

        $this->actingAs($this->admin)
            ->from("/fleet-assets/incidents?incident={$incident->id}")
            ->post("/fleet-assets/incidents/{$incident->id}/status", ['status' => 'closed'])
            ->assertSessionHasErrors('resolution_notes');

        $this->assertSame('investigating', $incident->fresh()->status);

        $this->actingAs($this->admin)
            ->from("/fleet-assets/incidents?incident={$incident->id}")
            ->post("/fleet-assets/incidents/{$incident->id}/status", ['status' => 'closed', 'resolution_notes' => 'Repaired and back in service.'])
            ->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame('closed', $incident->status);
        $this->assertNotNull($incident->resolved_at);
    }

    /* -------------------------------------------------------------- */
    /*  Follow-ups */
    /* -------------------------------------------------------------- */

    public function test_add_and_complete_followup(): void
    {
        $incident = FleetIncident::factory()->create(['asset_id' => $this->vehicle()->id]);

        $this->actingAs($this->admin)
            ->from("/fleet-assets/incidents?incident={$incident->id}")
            ->post("/fleet-assets/incidents/{$incident->id}/followups", ['notes' => 'Chase the insurer for the excess.'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('fleet_incident_followups', [
            'fleet_incident_id' => $incident->id,
            'notes' => 'Chase the insurer for the excess.',
        ]);

        $followup = $incident->followups()->first();
        $this->assertNull($followup->completed_at);

        $this->actingAs($this->admin)
            ->from("/fleet-assets/incidents?incident={$incident->id}")
            ->post("/fleet-assets/incidents/{$incident->id}/followups/{$followup->id}/complete")
            ->assertSessionHasNoErrors();

        $this->assertNotNull($followup->fresh()->completed_at);
    }

    /* -------------------------------------------------------------- */
    /*  Evidence */
    /* -------------------------------------------------------------- */

    public function test_upload_attachment(): void
    {
        Storage::fake('private');
        $incident = FleetIncident::factory()->create(['asset_id' => $this->vehicle()->id]);

        $this->actingAs($this->admin)
            ->from("/fleet-assets/incidents?incident={$incident->id}")
            ->post("/fleet-assets/incidents/{$incident->id}/attachments", [
                'file' => UploadedFile::fake()->image('scene.jpg'),
                'kind' => 'photo',
                'notes' => 'Front-left damage',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('fleet_incident_attachments', [
            'fleet_incident_id' => $incident->id,
            'original_name' => 'scene.jpg',
            'kind' => 'photo',
        ]);
        $attachment = $incident->attachments()->first();
        // Controller uploads now persist to the PRIVATE disk.
        Storage::disk('private')->assertExists($attachment->path);
    }

    /* -------------------------------------------------------------- */
    /*  Police report (TCR) clears the s22 worklist */
    /* -------------------------------------------------------------- */

    public function test_logging_police_report_clears_the_due_flag(): void
    {
        $incident = FleetIncident::factory()->create([
            'asset_id' => $this->vehicle()->id,
            'severity' => 'major',
            'injury_involved' => true,
            'injury_severity' => 'hospitalisation',
            'occurred_at' => now()->subHours(2),
            'police_report_due_at' => now()->addHours(22),
            'status' => 'investigating',
        ]);
        $this->assertTrue($incident->isPoliceReportDue());

        $this->actingAs($this->admin)
            ->from("/fleet-assets/incidents?incident={$incident->id}")
            ->post("/fleet-assets/incidents/{$incident->id}/police-report", [
                'traffic_crash_report_reference' => 'TCR-12345',
                'attending_officer' => 'Const. Smith',
            ])
            ->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame('TCR-12345', $incident->traffic_crash_report_reference);
        $this->assertNotNull($incident->police_report_logged_at);
        $this->assertTrue((bool) $incident->police_notified);
        $this->assertFalse($incident->isPoliceReportDue());
    }

    /* -------------------------------------------------------------- */
    /*  VOR (off-road) round-trip + claim */
    /* -------------------------------------------------------------- */

    public function test_mark_off_road_then_back_in_service(): void
    {
        $incident = FleetIncident::factory()->create(['asset_id' => $this->vehicle()->id]);

        $this->actingAs($this->admin)
            ->from("/fleet-assets/incidents?incident={$incident->id}")
            ->post("/fleet-assets/incidents/{$incident->id}/off-road", ['off_road_from' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $incident->refresh();
        $this->assertTrue((bool) $incident->vehicle_off_road);
        $this->assertNull($incident->service_resumed_at);
        $this->assertTrue($incident->isOffRoad());

        $this->actingAs($this->admin)
            ->from("/fleet-assets/incidents?incident={$incident->id}")
            ->post("/fleet-assets/incidents/{$incident->id}/back-in-service", ['service_resumed_at' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $incident->refresh();
        $this->assertNotNull($incident->service_resumed_at);
        $this->assertFalse($incident->isOffRoad());
    }

    public function test_log_insurance_claim(): void
    {
        $incident = FleetIncident::factory()->create(['asset_id' => $this->vehicle()->id]);

        $this->actingAs($this->admin)
            ->from("/fleet-assets/incidents?incident={$incident->id}")
            ->post("/fleet-assets/incidents/{$incident->id}/claim", [
                'insurer_name' => 'State',
                'insurance_reference' => 'CLM-99',
                'insurance_excess' => 500,
            ])
            ->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertTrue((bool) $incident->insurance_claimed);
        $this->assertSame('State', $incident->insurer_name);
        $this->assertSame('CLM-99', $incident->insurance_reference);
    }

    /* -------------------------------------------------------------- */
    /*  Worklist scopes + read surfaces */
    /* -------------------------------------------------------------- */

    public function test_worklist_scopes(): void
    {
        $asset = $this->vehicle();
        // Police-report-due: injury, no TCR, not closed.
        FleetIncident::factory()->create(['asset_id' => $asset->id, 'injury_involved' => true, 'status' => 'reported']);
        // Off-road (VOR).
        FleetIncident::factory()->create(['asset_id' => $asset->id, 'vehicle_off_road' => true, 'service_resumed_at' => null]);
        // Near miss.
        FleetIncident::factory()->create(['asset_id' => $asset->id, 'incident_type' => 'near_miss']);
        // A plain closed one that should match none of the above worklists.
        FleetIncident::factory()->create(['asset_id' => $asset->id, 'status' => 'closed']);

        $this->assertSame(1, FleetIncident::query()->policeReportDue()->count());
        $this->assertSame(1, FleetIncident::query()->offRoad()->count());
        $this->assertSame(1, FleetIncident::query()->nearMisses()->count());
    }

    public function test_index_renders_and_detail_json_payload(): void
    {
        $incident = FleetIncident::factory()->create(['asset_id' => $this->vehicle()->id, 'severity' => 'major']);

        $this->actingAs($this->admin)
            ->get('/fleet-assets/incidents?tab=police_report_due')
            ->assertOk();

        $this->actingAs($this->admin)
            ->getJson("/fleet-assets/incidents/{$incident->id}")
            ->assertOk()
            ->assertJsonPath('incident.id', $incident->id)
            ->assertJsonPath('incident.reference', $incident->reference());
    }

    public function test_write_routes_require_permission(): void
    {
        $plain = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
        $incident = FleetIncident::factory()->create(['asset_id' => $this->vehicle()->id]);

        $this->actingAs($plain)
            ->post("/fleet-assets/incidents/{$incident->id}/status", ['status' => 'investigating'])
            ->assertForbidden();
    }

    private function assertNoPartialResidentIncidentJourney(): void
    {
        $this->assertDatabaseCount('fleet_incidents', 0);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('safeguarding_alerts', 0);
        $this->assertDatabaseCount('fleet_signals', 0);
        $this->assertDatabaseCount('fleet_signal_outbox', 0);
        Notification::assertNothingSent();
    }
}
