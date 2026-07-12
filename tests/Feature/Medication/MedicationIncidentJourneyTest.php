<?php

namespace Tests\Feature\Medication;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlledDrugLossReport;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\MedicationRefusalFollowup;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationSignalService;
use App\Services\MedicationIncidentIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationIncidentJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_controlled_discrepancy_signal_enriches_one_official_journey_and_retry_is_exact(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Morphine sulfate',
            'controlled_drug' => true,
            'high_risk' => true,
        ]);
        $reportedAt = now()->subMinutes(10)->startOfSecond();
        $discrepancy = ClientControlledDrugDiscrepancy::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'on_hand_before' => 12,
            'on_hand_after' => 10,
            'difference' => -2,
            'reason' => 'Count did not reconcile at handover.',
            'reported_at' => $reportedAt,
            'reported_by' => $actor->id,
            'status' => 'open',
        ]);

        $service = app(MedicationIncidentIntegrationService::class);
        $first = $service->handleControlledDiscrepancy($discrepancy, $actor->id);

        $this->travel(31)->minutes();
        $second = $service->handleControlledDiscrepancy($discrepancy->fresh(), $actor->id);

        $incident = ClientIncident::query()->sole();
        $alert = ControlRoomAlert::query()->sole();
        $event = HsEvent::query()->sole();
        $signal = Signal::query()->sole();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertTrue($first->is($incident));
        $this->assertTrue($second->is($incident));
        $this->assertSame($incident->id, $discrepancy->fresh()->incident_id);
        $this->assertSame('submitted', $incident->status);
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($event->id, $incident->hs_event_id);
        $this->assertSame($alert->id, $event->control_room_alert_id);
        $this->assertSame(ClientIncident::class, $event->source_type);
        $this->assertSame($incident->id, $event->source_id);
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertSame($incident->id, data_get($alert->context, 'normalized_data.incident_id'));
        $this->assertSame($discrepancy->id, data_get($alert->context, 'normalized_data.discrepancy_id'));
        $this->assertSame($alert->id, $signal->alert_id);
        $this->assertSame('processed', $signal->status);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_signals', 1);

        $service->resolveControlledDiscrepancy(
            $discrepancy->fresh(),
            'Count reconciled and signed off.',
            $actor->id,
        );

        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->fresh()->status);
    }

    public function test_controlled_loss_reuses_its_persisted_official_journey_across_signal_windows(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Oxycodone',
            'controlled_drug' => true,
        ]);
        $discoveredAt = now()->subMinutes(15)->startOfSecond();
        $report = ControlledDrugLossReport::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'medication_name' => $medication->name,
            'quantity_lost' => 2,
            'unit' => 'tablets',
            'circumstances' => 'Count was short during the controlled-drug handover.',
            'discovered_by' => $actor->id,
            'discovered_at' => $discoveredAt,
        ]);

        $service = app(MedicationIncidentIntegrationService::class);
        $first = $service->handleControlledLossReport($report, $actor->id);
        $this->travel(31)->minutes();
        $second = $service->handleControlledLossReport($report->fresh(), $actor->id);

        $incident = ClientIncident::query()->sole();
        $alert = ControlRoomAlert::query()->sole();
        $event = HsEvent::query()->sole();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertTrue($first->is($incident));
        $this->assertTrue($second->is($incident));
        $this->assertSame($incident->id, $report->fresh()->incident_id);
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($event->id, $incident->hs_event_id);
        $this->assertSame($alert->id, $event->control_room_alert_id);
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertSame($report->id, data_get($alert->context, 'normalized_data.loss_report_id'));
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_signals', 1);
    }

    public function test_distinct_draft_incident_signals_never_fuzzy_correlate_to_one_alert(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $source = SignalSource::create([
            'name' => 'Medication / eMAR',
            'slug' => 'medication',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $type = SignalType::create([
            'code' => MedicationSignalService::TYPE_MISSED_DOSE,
            'name' => 'Medication missed dose',
            'category' => 'medication',
            'default_severity' => 'medium',
        ]);
        SignalRule::create([
            'name' => 'Medication missed dose rule',
            'signal_source_id' => $source->id,
            'signal_type_id' => $type->id,
            'conditions' => [],
            'alert_type' => 'Medication missed dose',
            'is_active' => true,
            'priority' => 1,
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
        ]);
        $first = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'type' => 'medication',
            'status' => 'draft',
            'submitted_at' => null,
        ]);
        $second = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'type' => 'medication',
            'status' => 'draft',
            'submitted_at' => null,
        ]);
        $signals = app(MedicationSignalService::class);

        foreach ([$first, $second] as $incident) {
            $signals->emit(
                MedicationSignalService::TYPE_MISSED_DOSE,
                $client->id,
                'medium',
                'Medication dose was missed.',
                [
                    'incident_id' => $incident->id,
                    'site_id' => $site->id,
                ],
            );
        }

        $this->assertDatabaseCount('client_incidents', 2);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_signals', 2);
        $this->assertDatabaseCount('control_room_alerts', 2);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            ControlRoomAlert::query()
                ->get()
                ->map(fn (ControlRoomAlert $alert): int => (int) data_get($alert->context, 'incident_id'))
                ->all(),
        );
    }

    public function test_refusal_escalation_reuses_the_exact_followup_incident_on_retry(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Warfarin',
            'high_risk' => true,
        ]);
        $administration = ClientMedicationAdministration::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $actor->id,
            'status' => 'refused',
            'administered_at' => now()->subHour(),
        ]);
        $followup = MedicationRefusalFollowup::create([
            'client_id' => $client->id,
            'client_medication_administration_id' => $administration->id,
            'reason_category' => 'personal_choice',
            'detailed_reason' => 'Client declined after risks were explained.',
            'client_capacity_at_time' => 'has_capacity',
            'gp_notification_required' => true,
            'follow_up_due_at' => now()->addDay(),
            'created_by' => $actor->id,
        ]);

        $service = app(MedicationIncidentIntegrationService::class);
        $first = $service->handleRefusalEscalation($followup, 3);
        $this->travel(31)->minutes();
        $second = $service->handleRefusalEscalation($followup->fresh(), 3);

        $incident = ClientIncident::query()->sole();
        $alert = ControlRoomAlert::query()->sole();
        $event = HsEvent::query()->sole();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertTrue($first->is($incident));
        $this->assertTrue($second->is($incident));
        $this->assertSame($followup->id, data_get($incident->metadata, 'medication_refusal_followup_id'));
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($event->id, $incident->hs_event_id);
        $this->assertSame($alert->id, $event->control_room_alert_id);
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertSame($followup->id, data_get($alert->context, 'normalized_data.followup_id'));
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_signals', 1);
    }
}
