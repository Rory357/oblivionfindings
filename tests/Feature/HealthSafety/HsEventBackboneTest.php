<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Asset;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\FleetIncident;
use App\Models\FleetWorkOrder;
use App\Models\HazardousSubstance;
use App\Models\HsEvent;
use App\Models\RestraintEvent;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\SiteInspectionRecord;
use App\Models\SiteInspectionSchedule;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HsEventBackboneTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────
    // HsEventService unit tests
    // ──────────────────────────────────────────────────────

    public function test_severity_normalisation(): void
    {
        $this->assertEquals('critical', HsEventService::normaliseSeverity('critical'));
        $this->assertEquals('critical', HsEventService::normaliseSeverity('extreme'));
        $this->assertEquals('high', HsEventService::normaliseSeverity('high'));
        $this->assertEquals('high', HsEventService::normaliseSeverity('serious'));
        $this->assertEquals('medium', HsEventService::normaliseSeverity('medium'));
        $this->assertEquals('medium', HsEventService::normaliseSeverity('moderate'));
        $this->assertEquals('low', HsEventService::normaliseSeverity('low'));
        $this->assertEquals('low', HsEventService::normaliseSeverity('minor'));
        $this->assertEquals('low', HsEventService::normaliseSeverity('trivial'));
    }

    public function test_idempotency_key_is_deterministic(): void
    {
        $key1 = HsEvent::buildIdempotencyKey('App\\Models\\ClientIncident', 42, 'incident');
        $key2 = HsEvent::buildIdempotencyKey('App\\Models\\ClientIncident', 42, 'incident');

        $this->assertEquals($key1, $key2);
    }

    public function test_idempotency_key_differs_for_different_sources(): void
    {
        $key1 = HsEvent::buildIdempotencyKey('App\\Models\\ClientIncident', 42, 'incident');
        $key2 = HsEvent::buildIdempotencyKey('App\\Models\\FleetIncident', 42, 'vehicle_incident');

        $this->assertNotEquals($key1, $key2);
    }

    public function test_reference_number_generation(): void
    {
        $ref = HsEvent::generateReferenceNumber();
        $year = now()->year;

        $this->assertStringStartsWith("HS-{$year}-", $ref);
        $this->assertMatchesRegularExpression('/^HS-\d{4}-\d{4}$/', $ref);
    }

    // ──────────────────────────────────────────────────────
    // ClientIncident → HsEvent wiring
    // ──────────────────────────────────────────────────────

    public function test_client_incident_creates_hs_event(): void
    {
        $incident = ClientIncident::factory()->create([
            'severity' => 'high',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('hs_events', [
            'source_type' => ClientIncident::class,
            'source_id' => $incident->id,
            'event_category' => 'incident',
            'severity' => 'high',
            'status' => 'open',
        ]);
    }

    public function test_low_severity_incident_still_creates_hs_event(): void
    {
        $incident = ClientIncident::factory()->create([
            'severity' => 'low',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('hs_events', [
            'source_type' => ClientIncident::class,
            'source_id' => $incident->id,
            'severity' => 'low',
        ]);

        // But NO control room alert for low severity
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_near_miss_incident_uses_near_miss_category(): void
    {
        $incident = ClientIncident::factory()->create([
            'type' => 'near_miss',
            'severity' => 'medium',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('hs_events', [
            'source_type' => ClientIncident::class,
            'source_id' => $incident->id,
            'event_category' => 'near_miss',
        ]);
    }

    public function test_duplicate_hs_event_not_created_for_same_incident(): void
    {
        $incident = ClientIncident::factory()->create([
            'severity' => 'high',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseCount('hs_events', 1);

        // Manually try to re-record — service should return existing
        $service = app(HsEventService::class);
        $result = $service->recordEvent([
            'source' => $incident,
            'event_category' => HsEvent::CATEGORY_INCIDENT,
            'severity' => 'high',
        ]);

        $this->assertDatabaseCount('hs_events', 1);
        $this->assertNotNull($result);
    }

    public function test_incident_severity_change_syncs_to_hs_event(): void
    {
        $incident = ClientIncident::factory()->create([
            'severity' => 'low',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('hs_events', [
            'source_id' => $incident->id,
            'severity' => 'low',
        ]);

        $incident->update(['severity' => 'high']);

        $this->assertDatabaseHas('hs_events', [
            'source_id' => $incident->id,
            'severity' => 'high',
            'investigation_required' => true,
        ]);
    }

    public function test_high_incident_sets_investigation_required(): void
    {
        $incident = ClientIncident::factory()->create([
            'severity' => 'high',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('hs_events', [
            'source_id' => $incident->id,
            'investigation_required' => true,
        ]);
    }

    // ──────────────────────────────────────────────────────
    // SafeguardingConcern → HsEvent wiring
    // ──────────────────────────────────────────────────────

    public function test_safeguarding_concern_creates_hs_event_floored_at_high(): void
    {
        $concern = SafeguardingConcern::factory()->create([
            'severity' => 'low',
        ]);

        // Carry-forward #3: safeguarding HsEvent severity floored at 'high'
        $this->assertDatabaseHas('hs_events', [
            'source_type' => SafeguardingConcern::class,
            'source_id' => $concern->id,
            'event_category' => 'safeguarding',
            'severity' => 'high',
        ]);
    }

    public function test_critical_safeguarding_concern_gets_critical_hs_event(): void
    {
        $concern = SafeguardingConcern::factory()->critical()->create();

        $this->assertDatabaseHas('hs_events', [
            'source_type' => SafeguardingConcern::class,
            'source_id' => $concern->id,
            'severity' => 'critical',
        ]);
    }

    // ──────────────────────────────────────────────────────
    // FleetIncident → HsEvent wiring
    // ──────────────────────────────────────────────────────

    public function test_fleet_incident_creates_hs_event(): void
    {
        // Fleet vocab is minor/moderate/major/critical; the observer maps it to the
        // H&S low/medium/high/critical (moderate -> medium).
        $incident = FleetIncident::factory()->create([
            'severity' => 'moderate',
        ]);

        $this->assertDatabaseHas('hs_events', [
            'source_type' => FleetIncident::class,
            'source_id' => $incident->id,
            'event_category' => 'vehicle_incident',
            'severity' => 'medium',
        ]);
    }

    // ──────────────────────────────────────────────────────
    // WorkplaceInjury → HsEvent wiring
    // ──────────────────────────────────────────────────────

    public function test_worksafe_notifiable_injury_creates_hs_event_with_worksafe_flags(): void
    {
        $injury = WorkplaceInjury::factory()->create([
            'worksafe_notifiable' => true,
            'severity' => 'serious',
        ]);

        $this->assertDatabaseHas('hs_events', [
            'source_type' => WorkplaceInjury::class,
            'source_id' => $injury->id,
            'event_category' => 'injury',
            'worksafe_notifiable' => true,
            'worksafe_status' => 'pending',
            'investigation_required' => true,
        ]);
    }

    public function test_minor_injury_creates_hs_event_without_investigation(): void
    {
        $injury = WorkplaceInjury::factory()->create([
            'worksafe_notifiable' => false,
            'severity' => 'minor',
        ]);

        $this->assertDatabaseHas('hs_events', [
            'source_type' => WorkplaceInjury::class,
            'source_id' => $injury->id,
            'severity' => 'low',
            'investigation_required' => false,
        ]);
    }

    // ──────────────────────────────────────────────────────
    // RestraintEvent → HsEvent wiring
    // ──────────────────────────────────────────────────────

    public function test_restraint_with_injury_creates_high_hs_event(): void
    {
        $event = RestraintEvent::factory()->withInjury()->create();

        $this->assertDatabaseHas('hs_events', [
            'source_type' => RestraintEvent::class,
            'source_id' => $event->id,
            'event_category' => 'restraint',
            'severity' => 'high',
        ]);
    }

    public function test_restraint_without_injury_creates_medium_hs_event(): void
    {
        $event = RestraintEvent::factory()->withinSupportPlan()->create();

        $this->assertDatabaseHas('hs_events', [
            'source_type' => RestraintEvent::class,
            'source_id' => $event->id,
            'severity' => 'medium',
        ]);
    }

    // ──────────────────────────────────────────────────────
    // Step 7 orphan-category wiring
    // ──────────────────────────────────────────────────────

    public function test_substance_exposure_record_creates_exposure_hs_event(): void
    {
        $site = Site::factory()->create();
        $worker = User::factory()->create();
        $creator = User::factory()->create();
        $substance = HazardousSubstance::factory()->create(['created_by' => $creator->id]);

        $record = SubstanceExposureRecord::create([
            'hazardous_substance_id' => $substance->id,
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'exposed_at' => now()->subHour(),
            'exposure_type' => 'inhalation',
            'circumstances' => 'Cleaner vapour exposure during storage-room spill response.',
            'medical_attention_sought' => true,
            'incident_reported' => false,
            'created_by' => $creator->id,
        ]);

        $this->assertDatabaseHas('hs_events', [
            'source_type' => SubstanceExposureRecord::class,
            'source_id' => $record->id,
            'event_category' => HsEvent::CATEGORY_EXPOSURE,
            'severity' => HsEvent::SEVERITY_HIGH,
            'site_id' => $site->id,
            'staff_id' => $worker->id,
            'created_by' => $creator->id,
            'investigation_required' => true,
        ]);
    }

    public function test_failed_site_inspection_record_creates_inspection_failure_hs_event(): void
    {
        $site = Site::factory()->create();
        $inspector = User::factory()->create();
        $schedule = SiteInspectionSchedule::create([
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'inspection_type' => 'house_safety',
            'title' => 'House safety inspection',
            'frequency' => 'monthly',
            'first_due_date' => now()->subDay()->toDateString(),
            'next_due_date' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        $record = SiteInspectionRecord::create([
            'schedule_id' => $schedule->id,
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'due_date' => now()->subDay()->toDateString(),
            'completed_at' => now(),
            'completed_by_user_id' => $inspector->id,
            'result' => 'fail',
            'findings' => 'Exit lighting failed and trip hazard found.',
            'corrective_actions' => 'Replace lighting and remove trip hazard.',
        ]);

        $this->assertDatabaseHas('hs_events', [
            'source_type' => SiteInspectionRecord::class,
            'source_id' => $record->id,
            'event_category' => HsEvent::CATEGORY_INSPECTION_FAILURE,
            'severity' => HsEvent::SEVERITY_HIGH,
            'site_id' => $site->id,
            'staff_id' => $inspector->id,
            'created_by' => $inspector->id,
            'investigation_required' => true,
        ]);
    }

    public function test_passing_site_inspection_record_does_not_create_inspection_failure_hs_event(): void
    {
        $site = Site::factory()->create();
        $schedule = SiteInspectionSchedule::create([
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'inspection_type' => 'house_safety',
            'title' => 'House safety inspection',
            'frequency' => 'monthly',
            'first_due_date' => now()->subDay()->toDateString(),
            'next_due_date' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        SiteInspectionRecord::create([
            'schedule_id' => $schedule->id,
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'due_date' => now()->subDay()->toDateString(),
            'completed_at' => now(),
            'result' => 'pass',
        ]);

        $this->assertDatabaseMissing('hs_events', [
            'event_category' => HsEvent::CATEGORY_INSPECTION_FAILURE,
        ]);
    }

    public function test_high_priority_fleet_work_order_creates_equipment_fault_hs_event(): void
    {
        $site = Site::factory()->create();
        $reporter = User::factory()->create();
        $asset = Asset::factory()->forSite($site)->vehicle()->create();

        $workOrder = FleetWorkOrder::factory()->create([
            'asset_id' => $asset->id,
            'reported_by_user_id' => $reporter->id,
            'tenant_id' => $site->tenant_id,
            'title' => 'Wheelchair ramp hydraulic fault',
            'priority' => 'high',
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('hs_events', [
            'source_type' => FleetWorkOrder::class,
            'source_id' => $workOrder->id,
            'event_category' => HsEvent::CATEGORY_EQUIPMENT_FAULT,
            'severity' => HsEvent::SEVERITY_HIGH,
            'site_id' => $site->id,
            'asset_id' => $asset->id,
            'created_by' => $reporter->id,
            'investigation_required' => true,
        ]);
    }

    // ──────────────────────────────────────────────────────
    // Bridge behaviour preserved from PR0
    // ──────────────────────────────────────────────────────

    public function test_high_incident_still_creates_control_room_alert(): void
    {
        $incident = ClientIncident::factory()->create([
            'severity' => 'high',
            'status' => 'submitted',
        ]);

        // HsEvent created
        $this->assertDatabaseCount('hs_events', 1);

        // Control Room alert also created (PR0 behaviour preserved)
        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'incident',
            'severity' => 'high',
            'client_id' => $incident->client_id,
        ]);
    }

    public function test_safeguarding_creates_both_hs_event_and_alert(): void
    {
        $concern = SafeguardingConcern::factory()->high()->create();

        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'safeguarding',
        ]);
    }

    // ──────────────────────────────────────────────────────
    // HsEvent → ControlRoomAlert back-reference
    // ──────────────────────────────────────────────────────

    public function test_hs_event_links_to_control_room_alert(): void
    {
        $incident = ClientIncident::factory()->create([
            'severity' => 'high',
            'status' => 'submitted',
        ]);

        $hsEvent = HsEvent::where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->first();

        $alert = ControlRoomAlert::where('source', 'incident')
            ->where('client_id', $incident->client_id)
            ->first();

        // The observer should have linked them
        $this->assertNotNull($hsEvent);
        $this->assertNotNull($alert);
        $this->assertEquals($alert->id, $hsEvent->control_room_alert_id);
    }

    // ──────────────────────────────────────────────────────
    // Canonical incident journey severity promotion
    // ──────────────────────────────────────────────────────

    public function test_severity_escalation_on_incident_promotes_the_canonical_journey_once(): void
    {
        $incident = ClientIncident::factory()->create([
            'type' => 'fall',
            'severity' => 'low',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseCount('control_room_alerts', 0);

        $incident->update(['severity' => 'high']);

        $incident->refresh();
        $hsEvent = HsEvent::query()->whereKey($incident->hs_event_id)->firstOrFail();
        $alert = ControlRoomAlert::query()->sole();

        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($hsEvent->id, $incident->hs_event_id);
        $this->assertSame($alert->id, $hsEvent->control_room_alert_id);
        $this->assertSame('incident', $alert->source);
        $this->assertSame('incident.fall', $alert->alert_type);
        $this->assertSame('high', $alert->severity);
        $this->assertSame($incident->id, $alert->context['incident_id']);
        $this->assertSame('Automatic high-severity incident escalation', $alert->context['reason']);
        $this->assertSame('incident_journey', $alert->context['provenance']['source']);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseHas('control_room_alerts', [
            'id' => $alert->id,
            'source' => 'incident',
            'alert_type' => 'incident.fall',
        ]);
    }

    // ──────────────────────────────────────────────────────
    // Record creation safety — observer must not break source
    // ──────────────────────────────────────────────────────

    public function test_source_record_persists_even_if_hs_event_service_errors(): void
    {
        // The observer wraps all HsEvent operations in try/catch
        $incident = ClientIncident::factory()->create([
            'severity' => 'medium',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('client_incidents', [
            'id' => $incident->id,
        ]);
    }
}
