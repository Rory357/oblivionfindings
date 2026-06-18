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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    private function vehicle(): Asset
    {
        $site = Site::factory()->create();

        return Asset::factory()->create(['category' => 'vehicle', 'site_id' => $site->id]);
    }

    /* -------------------------------------------------------------- */
    /*  Report (store) + regulatory derivation                         */
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
    /*  Observer (Gap F4 fix): severity mapping + alert               */
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
    /*  Transport cascade (Gap F1 direct FK)                          */
    /* -------------------------------------------------------------- */

    public function test_transport_incident_cascades_to_client_incident_with_direct_fk(): void
    {
        Notification::fake();
        $asset = $this->vehicle();
        $client = Client::factory()->create();
        $booking = FleetVehicleBooking::factory()->create(['asset_id' => $asset->id]);
        FleetResidentTransport::query()->create([
            'asset_id' => $asset->id,
            'booking_id' => $booking->id,
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
                'description' => 'Collision with two residents aboard.',
            ])
            ->assertSessionHasNoErrors();

        $incident = FleetIncident::latest('id')->first();

        $clientIncident = ClientIncident::where('fleet_incident_id', $incident->id)->first();
        $this->assertNotNull($clientIncident, 'A transport incident must cascade to a client incident.');
        $this->assertSame('transport_incident', $clientIncident->type);
        $this->assertSame($client->id, $clientIncident->client_id);
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

    /* -------------------------------------------------------------- */
    /*  Lifecycle + closure gate                                       */
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
    /*  Follow-ups                                                     */
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
    /*  Evidence                                                       */
    /* -------------------------------------------------------------- */

    public function test_upload_attachment(): void
    {
        Storage::fake('public');
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
        Storage::disk('public')->assertExists($attachment->path);
    }

    /* -------------------------------------------------------------- */
    /*  Police report (TCR) clears the s22 worklist                    */
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
    /*  VOR (off-road) round-trip + claim                              */
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
    /*  Worklist scopes + read surfaces                                */
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
}
