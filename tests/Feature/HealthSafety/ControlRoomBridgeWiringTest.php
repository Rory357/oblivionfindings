<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\FleetIncident;
use App\Models\HsEvent;
use App\Models\RestraintEvent;
use App\Models\SafeguardingConcern;
use App\Models\Shift;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
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

    public function test_incident_alert_carries_site_and_shift_context_for_control_room_triage(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'status' => 'scheduled',
        ]);

        $incident = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'shift_id' => $shift->id,
            'severity' => 'high',
            'status' => 'submitted',
        ]);

        $alert = ControlRoomAlert::query()
            ->where('source', 'incident')
            ->where('client_id', $incident->client_id)
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame($site->id, $alert->site_id);
        $this->assertSame($shift->id, data_get($alert->context, 'shift_id'));
        $this->assertSame($site->id, data_get($alert->context, 'site_id'));
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
        $incident = ClientIncident::factory()->create([
            'severity' => 'high',
            'status' => 'draft',
            'submitted_at' => null,
        ]);

        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertNull($incident->fresh()->control_room_alert_id);
        $this->assertNull($incident->fresh()->hs_event_id);
    }

    public function test_draft_submission_builds_one_exact_high_journey_and_repeated_updates_are_stable(): void
    {
        $incident = ClientIncident::factory()->create([
            'severity' => 'high',
            'status' => 'draft',
            'submitted_at' => null,
        ]);

        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('hs_events', 0);

        $incident->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $incident = $incident->fresh();
        $alert = ControlRoomAlert::query()->sole();
        $event = HsEvent::query()->sole();

        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($event->id, $incident->hs_event_id);
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertSame($alert->id, $event->control_room_alert_id);
        $this->assertSame(ClientIncident::class, $event->source_type);
        $this->assertSame($incident->id, $event->source_id);
        $this->assertSame('high', $alert->severity);
        $this->assertSame('high', $event->severity);

        $incident->update(['description' => 'Clarified without duplicating the journey.']);

        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertSame($alert->id, $incident->fresh()->control_room_alert_id);
        $this->assertSame($event->id, $incident->fresh()->hs_event_id);
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

    public function test_incident_severity_escalation_promotes_the_same_exact_journey(): void
    {
        $incident = ClientIncident::factory()->create([
            'severity' => 'low',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('hs_events', 1);
        $eventId = $incident->fresh()->hs_event_id;

        $incident->update(['severity' => 'high']);
        $incident = $incident->fresh();
        $alert = ControlRoomAlert::query()->sole();

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'incident',
            'alert_type' => "incident.{$incident->type}",
            'severity' => 'high',
        ]);
        $this->assertSame($eventId, $incident->hs_event_id);
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($alert->id, $incident->hsEvent->control_room_alert_id);
        $this->assertSame('high', $incident->hsEvent->severity);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
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
        $incident = FleetIncident::factory()->create(['severity' => 'moderate']);

        $this->assertDatabaseCount('control_room_alerts', 0);

        // Escalate into the high band (fleet vocab: major maps to H&S high).
        $incident->update(['severity' => 'major']);

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

        SiteHazard::create([
            'site_id' => $site->id,
            'hazard_type' => 'environmental',
            'severity' => 4,
            'likelihood' => 4,
            'description' => 'Wet floor near kitchen entrance',
            'status' => 'open',
            'reported_by_user_id' => User::factory()->create()->id,
        ]);

        // If risk_rating was calculated as high/extreme, an alert should exist
        $hazard = SiteHazard::latest()->first();

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

    public function test_distinct_incidents_do_not_fuzzy_deduplicate_each_others_alerts(): void
    {
        $client = Client::factory()->create();

        $first = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'type' => 'injury',
            'severity' => 'high',
            'status' => 'submitted',
        ]);

        $second = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'type' => 'injury',
            'severity' => 'high',
            'status' => 'submitted',
        ]);

        $alertCount = ControlRoomAlert::where('source', 'incident')
            ->where('alert_type', 'incident.injury')
            ->where('client_id', $client->id)
            ->count();

        $this->assertSame(2, $alertCount);
        $this->assertNotNull($first->fresh()->control_room_alert_id);
        $this->assertNotNull($second->fresh()->control_room_alert_id);
        $this->assertNotSame(
            $first->fresh()->control_room_alert_id,
            $second->fresh()->control_room_alert_id,
        );
        $this->assertNotNull($first->fresh()->hs_event_id);
        $this->assertNotNull($second->fresh()->hs_event_id);
        $this->assertDatabaseCount('hs_events', 2);
    }

    // ──────────────────────────────────────────────────────
    // Failure safety — observer must not break record creation
    // ──────────────────────────────────────────────────────

    public function test_incident_creation_survives_journey_failure_and_logs_structured_repair_context(): void
    {
        Log::spy();
        $this->mock(ComprehensiveAlertBridgeService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('bridgeClientIncident')
                ->once()
                ->andThrow(new \RuntimeException('Forced journey failure'));
        });

        $incident = ClientIncident::factory()->create([
            'severity' => 'high',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('client_incidents', [
            'id' => $incident->id,
        ]);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'incident_journey_repair_required'
                && $context['incident_id'] === $incident->id
                && $context['status'] === 'submitted'
                && $context['exception'] === \RuntimeException::class
                && $context['error'] === 'Forced journey failure'
                && is_array($context['changed_fields']),
        );
    }
}
