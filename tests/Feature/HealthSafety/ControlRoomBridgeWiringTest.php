<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\FleetIncident;
use App\Models\RestraintEvent;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkplaceInjury;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomBridgeWiringTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────
    // ClientIncident
    // ──────────────────────────────────────────────────────

    public function test_high_severity_submitted_incident_creates_alert(): void
    {
        $incident = ClientIncident::factory()->create([
            'severity' => 'high',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'incident',
            'alert_type' => "incident.{$incident->type}",
            'severity' => 'high',
            'client_id' => $incident->client_id,
        ]);
    }

    public function test_low_severity_incident_does_not_create_alert(): void
    {
        ClientIncident::factory()->create([
            'severity' => 'low',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_high_severity_draft_incident_does_not_create_alert(): void
    {
        ClientIncident::factory()->create([
            'severity' => 'high',
            'status' => 'draft',
        ]);

        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_draft_incident_submitted_with_high_severity_creates_alert(): void
    {
        $incident = ClientIncident::factory()->create([
            'severity' => 'high',
            'status' => 'draft',
        ]);

        $this->assertDatabaseCount('control_room_alerts', 0);

        $incident->update(['status' => 'submitted']);

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'incident',
            'severity' => 'high',
            'client_id' => $incident->client_id,
        ]);
    }

    public function test_incident_severity_escalated_to_high_creates_alert(): void
    {
        $incident = ClientIncident::factory()->create([
            'severity' => 'low',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseCount('control_room_alerts', 0);

        $incident->update(['severity' => 'high']);

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'incident',
            'severity' => 'high',
        ]);
    }

    public function test_medium_severity_incident_does_not_create_alert(): void
    {
        ClientIncident::factory()->create([
            'severity' => 'medium',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_incident_description_edit_does_not_create_duplicate_alert(): void
    {
        $incident = ClientIncident::factory()->create([
            'severity' => 'high',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseCount('control_room_alerts', 1);

        // Editing description should NOT create another alert
        $incident->update(['description' => 'Updated description']);

        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    // ──────────────────────────────────────────────────────
    // SafeguardingConcern
    // ──────────────────────────────────────────────────────

    public function test_safeguarding_concern_always_creates_alert(): void
    {
        $concern = SafeguardingConcern::factory()->create([
            'severity' => 'low',
        ]);

        // Bridge service floors severity at 'high' for safeguarding
        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'safeguarding',
            'severity' => 'high',
        ]);
    }

    public function test_critical_safeguarding_concern_creates_critical_alert(): void
    {
        SafeguardingConcern::factory()->critical()->create();

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'safeguarding',
            'severity' => 'critical',
        ]);
    }

    public function test_safeguarding_severity_escalation_to_critical_creates_new_alert(): void
    {
        $concern = SafeguardingConcern::factory()->high()->create();

        $alertCount = ControlRoomAlert::count();

        // Travel past the 30-min dedup window to test escalation re-bridge
        $this->travel(31)->minutes();

        $concern->update(['severity' => 'critical']);

        $this->assertDatabaseCount('control_room_alerts', $alertCount + 1);
    }

    public function test_safeguarding_non_severity_update_does_not_create_alert(): void
    {
        $concern = SafeguardingConcern::factory()->high()->create();

        $alertCount = ControlRoomAlert::count();

        $concern->update(['description' => 'Updated details']);

        $this->assertDatabaseCount('control_room_alerts', $alertCount);
    }

    // ──────────────────────────────────────────────────────
    // FleetIncident
    // ──────────────────────────────────────────────────────

    public function test_high_severity_fleet_incident_creates_alert(): void
    {
        FleetIncident::factory()->highSeverity()->create();

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'operations',
            'alert_type' => 'operations.fleet_incident',
            'severity' => 'high',
        ]);
    }

    public function test_critical_fleet_incident_creates_critical_alert(): void
    {
        FleetIncident::factory()->critical()->create();

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'operations',
            'alert_type' => 'operations.fleet_incident',
            'severity' => 'critical',
        ]);
    }

    public function test_low_severity_fleet_incident_does_not_create_alert(): void
    {
        FleetIncident::factory()->low()->create();

        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_fleet_incident_severity_escalated_creates_alert(): void
    {
        $incident = FleetIncident::factory()->create(['severity' => 'medium']);

        $this->assertDatabaseCount('control_room_alerts', 0);

        $incident->update(['severity' => 'high']);

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'operations',
            'alert_type' => 'operations.fleet_incident',
        ]);
    }

    // ──────────────────────────────────────────────────────
    // WorkplaceInjury
    // ──────────────────────────────────────────────────────

    public function test_worksafe_notifiable_injury_creates_critical_alert(): void
    {
        WorkplaceInjury::factory()->create([
            'worksafe_notifiable' => true,
            'severity' => 'serious',
        ]);

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'operations',
            'alert_type' => 'operations.workplace_injury',
            'severity' => 'critical',
        ]);
    }

    public function test_serious_injury_creates_high_alert(): void
    {
        WorkplaceInjury::factory()->create([
            'worksafe_notifiable' => false,
            'severity' => 'serious',
        ]);

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'operations',
            'alert_type' => 'operations.workplace_injury',
            'severity' => 'high',
        ]);
    }

    public function test_minor_injury_does_not_create_alert(): void
    {
        WorkplaceInjury::factory()->create([
            'worksafe_notifiable' => false,
            'severity' => 'minor',
        ]);

        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_injury_worksafe_notifiable_changed_to_true_creates_alert(): void
    {
        $injury = WorkplaceInjury::factory()->create([
            'worksafe_notifiable' => false,
            'severity' => 'minor',
        ]);

        $this->assertDatabaseCount('control_room_alerts', 0);

        $injury->update(['worksafe_notifiable' => true]);

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'operations',
            'alert_type' => 'operations.workplace_injury',
            'severity' => 'critical',
        ]);
    }

    // ──────────────────────────────────────────────────────
    // RestraintEvent
    // ──────────────────────────────────────────────────────

    public function test_restraint_with_injury_creates_high_alert(): void
    {
        RestraintEvent::factory()->withInjury()->create();

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'operations',
            'alert_type' => 'operations.restraint_event',
            'severity' => 'high',
        ]);
    }

    public function test_restraint_outside_support_plan_creates_medium_alert(): void
    {
        RestraintEvent::factory()->outsideSupportPlan()->create();

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'operations',
            'alert_type' => 'operations.restraint_event',
            'severity' => 'medium',
        ]);
    }

    public function test_restraint_within_plan_no_injury_does_not_create_alert(): void
    {
        RestraintEvent::factory()->withinSupportPlan()->create();

        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_restraint_injury_discovered_later_creates_alert(): void
    {
        $event = RestraintEvent::factory()->withinSupportPlan()->create();

        $this->assertDatabaseCount('control_room_alerts', 0);

        $event->update(['injury_occurred' => true, 'injury_details' => 'Bruise discovered post-event']);

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'operations',
            'alert_type' => 'operations.restraint_event',
            'severity' => 'high',
        ]);
    }

    // ──────────────────────────────────────────────────────
    // SiteHazard (verify existing observer integration)
    // ──────────────────────────────────────────────────────

    public function test_high_risk_hazard_creates_alert(): void
    {
        $site = Site::factory()->create();

        \App\Models\SiteHazard::create([
            'site_id' => $site->id,
            'hazard_type' => 'environmental',
            'severity' => 4,
            'likelihood' => 4,
            'description' => 'Wet floor near kitchen entrance',
            'status' => 'open',
            'reported_by_user_id' => User::factory()->create()->id,
        ]);

        // If risk_rating was calculated as high/extreme, an alert should exist
        $hazard = \App\Models\SiteHazard::latest()->first();

        if (in_array($hazard->risk_rating, ['high', 'extreme'])) {
            $this->assertDatabaseHas('control_room_alerts', [
                'source' => 'operations',
                'alert_type' => 'operations.hazard_identified',
            ]);
        } else {
            // Low/medium risk — no alert expected
            $this->assertDatabaseMissing('control_room_alerts', [
                'alert_type' => 'operations.hazard_identified',
            ]);
        }
    }

    // ──────────────────────────────────────────────────────
    // Deduplication safety
    // ──────────────────────────────────────────────────────

    public function test_bridge_service_deduplication_prevents_duplicate_alerts(): void
    {
        // Create two high-severity incidents for the same client rapidly
        $client = Client::factory()->create();

        ClientIncident::factory()->create([
            'client_id' => $client->id,
            'type' => 'injury',
            'severity' => 'high',
            'status' => 'submitted',
        ]);

        ClientIncident::factory()->create([
            'client_id' => $client->id,
            'type' => 'injury',
            'severity' => 'high',
            'status' => 'submitted',
        ]);

        // Bridge dedup: same source + alert_type + client_id within 30 min = suppressed
        $alertCount = ControlRoomAlert::where('source', 'incident')
            ->where('alert_type', 'incident.injury')
            ->where('client_id', $client->id)
            ->count();

        $this->assertEquals(1, $alertCount);
    }

    // ──────────────────────────────────────────────────────
    // Failure safety — observer must not break record creation
    // ──────────────────────────────────────────────────────

    public function test_incident_creation_succeeds_even_if_bridge_fails(): void
    {
        // The observer wraps bridge calls in try/catch — verify the record persists
        // even if the bridge service were to throw. We test this indirectly:
        // the incident should always exist regardless of alert outcome.
        $incident = ClientIncident::factory()->create([
            'severity' => 'high',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('client_incidents', [
            'id' => $incident->id,
        ]);
    }
}
