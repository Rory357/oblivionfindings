<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ControlledDrugLossReport;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\FleetMedicationTransitLog;
use App\Models\HsEvent;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationError;
use App\Models\MedicationRefusalFollowup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationSignalService;
use App\Services\MedicationAlertService;
use App\Services\MedicationIncidentIntegrationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EM-12: only medications.controlled.view reveals a controlled medicine.
 * Record authority alone must not surface controlled rows on Meds today, and
 * incidents / Control Room alerts raised from controlled-drug events (readable
 * without controlled view) must not name the medicine.
 */
class ControlledMedicineConcealmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_meds_today_hides_controlled_rows_from_a_worker_with_record_but_not_view(): void
    {
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['is_active' => true]);
        $serviceContext = ServiceContext::factory()->create([
            'type' => 'residential',
            'is_active' => true,
            'site_id' => $site->id,
        ]);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'status' => 'active',
        ]);
        foreach ([['Everyday tablets', false], ['PRIVATE CONTROLLED TODAY', true]] as [$name, $controlled]) {
            ClientMedication::query()->create([
                'client_id' => $client->id,
                'name' => $name,
                'dosage' => '1 tablet',
                'frequency' => 'Twice daily',
                'dose_times' => ['08:00', '20:00'],
                'is_prn' => false,
                'controlled_drug' => $controlled,
                'active' => true,
                'state' => 'active',
            ]);
        }

        $recordOnly = $this->coveringWorker($site, $serviceContext, $client, deny: ['medications.controlled.view']);
        $this->assertTrue($recordOnly->canDo('medications.controlled.record'));
        $this->assertFalse($recordOnly->canDo('medications.controlled.view'));

        $hidden = $this->actingAs($recordOnly)->get('/meds/today')->assertOk();
        $this->assertStringContainsString('Everyday tablets', $hidden->getContent());
        $this->assertStringNotContainsString('PRIVATE CONTROLLED TODAY', $hidden->getContent());

        $reader = $this->coveringWorker($site, $serviceContext, $client);
        $this->assertTrue($reader->canDo('medications.controlled.view'));
        $this->assertStringContainsString(
            'PRIVATE CONTROLLED TODAY',
            $this->actingAs($reader)->get('/meds/today')->assertOk()->getContent(),
        );
    }

    public function test_controlled_discrepancy_and_loss_incidents_name_the_site_not_the_medicine(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create(['name' => 'Kōwhai House']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Morphine sulfate',
            'controlled_drug' => true,
        ]);
        $discrepancy = ClientControlledDrugDiscrepancy::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'on_hand_before' => 12,
            'on_hand_after' => 10,
            'difference' => -2,
            'reason' => 'Count did not reconcile at handover.',
            'immediate_action_taken' => 'Remaining stock was secured and a witnessed recount started.',
            'reported_at' => now()->subMinutes(10),
            'reported_by' => $actor->id,
            'status' => 'open',
        ]);
        $lossReport = ControlledDrugLossReport::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'medication_name' => $medication->name,
            'quantity_lost' => 2,
            'unit' => 'tablets',
            'circumstances' => 'Count was short during the controlled-drug handover.',
            'immediate_action_taken' => 'Remaining stock was secured and the client was checked.',
            'discovered_by' => $actor->id,
            'discovered_at' => now()->subMinutes(5),
        ]);

        $service = app(MedicationIncidentIntegrationService::class);
        $discrepancyIncident = $service->handleControlledDiscrepancy($discrepancy, $actor->id);
        $lossIncident = $service->handleControlledLossReport($lossReport, $actor->id);

        $this->assertSame('Controlled medicine count discrepancy — Kōwhai House', $discrepancyIncident->title);
        $this->assertSame('Controlled medicine loss — Kōwhai House', $lossIncident->title);

        // Nothing readable without controlled view names the medicine: the
        // incidents, their Control Room alerts or their H&S events.
        $incidents = ClientIncident::query()->get();
        $this->assertCount(2, $incidents);
        foreach ($incidents as $incident) {
            $this->assertStringNotContainsStringIgnoringCase(
                'morphine',
                json_encode($incident->toArray(), JSON_UNESCAPED_UNICODE),
            );
            $this->assertSame($medication->id, data_get($incident->metadata, 'medication_id'));
        }
        $journeyRows = ControlRoomAlert::query()->get()->concat(HsEvent::query()->get());
        $this->assertNotEmpty($journeyRows);
        foreach ($journeyRows as $row) {
            $this->assertStringNotContainsStringIgnoringCase(
                'morphine',
                json_encode($row->toArray(), JSON_UNESCAPED_UNICODE),
            );
        }

        // The medicine stays on the restricted register entries and the
        // controlled-only eMAR alerts.
        $this->assertSame($discrepancyIncident->id, $discrepancy->fresh()->incident_id);
        $this->assertSame($medication->id, $discrepancy->fresh()->client_medication_id);
        $this->assertSame('Morphine sulfate', $lossReport->fresh()->medication_name);
        $restrictedAlerts = MedicationDashboardAlert::query()
            ->where('client_id', $client->id)
            ->whereIn('alert_type', ['controlled_discrepancy', 'controlled_loss'])
            ->pluck('message');
        $this->assertCount(2, $restrictedAlerts);
        foreach ($restrictedAlerts as $message) {
            $this->assertStringContainsString('Morphine sulfate', $message);
        }
    }

    public function test_no_medication_event_names_a_controlled_medicine_outside_controlled_view(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create(['name' => 'Kōwhai House']);
        $client = Client::factory()->create(['site_id' => $site->id, 'suppress_med_admin_alerts' => false]);
        // A dose an hour ago on the New Zealand clock. Dose times are NZ wall
        // times; this fixture used the UTC wall time, which only raised the
        // overdue alert while the check misread dose times as UTC (EM-02).
        $overdueSlot = now(config('app.worker_timezone', 'Pacific/Auckland'))->subHour();
        $controlled = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Morphine sulfate',
            'controlled_drug' => true,
            'high_risk' => true,
            'is_prn' => false,
            'dose_times' => [$overdueSlot->format('H:i')],
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'end_date' => null,
        ]);
        ClientMedicationStock::query()->create([
            'client_medication_id' => $controlled->id,
            'on_hand' => 0,
            'reorder_level' => 2,
            'unit' => 'tablets',
        ]);
        $ordinary = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Paracetamol',
            'end_date' => null,
            'controlled_drug' => false,
            'high_risk' => false,
        ]);
        $administration = fn (ClientMedication $medication, array $attributes) => ClientMedicationAdministration::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $actor->id,
            'status' => 'given',
            // Clear of the controlled dose slot an hour ago, so it stays overdue.
            'scheduled_for' => now()->subHours(2),
            'administered_at' => now(),
            ...$attributes,
        ]);
        $original = $administration($controlled, ['administered_at' => now()->subHours(30), 'dose_given' => '5 mg']);
        $original->forceFill(['created_at' => now()->subHours(30)])->saveQuietly();
        $correction = $administration($controlled, [
            'corrected_of_id' => $original->id,
            'is_correction' => true,
            'correction_reason' => 'Late documentation correction.',
        ]);
        $refused = $administration($controlled, ['status' => 'refused', 'reason' => 'Client declined.']);
        $followup = MedicationRefusalFollowup::create([
            'client_id' => $client->id,
            'client_medication_administration_id' => $refused->id,
            'reason_category' => 'personal_choice',
            'detailed_reason' => 'Client declined after risks were explained.',
            'client_capacity_at_time' => 'has_capacity',
            'gp_notification_required' => true,
            'follow_up_action' => 'The prescriber was contacted and the client was monitored.',
            'follow_up_due_at' => now()->addDay(),
            'created_by' => $actor->id,
        ]);
        $error = MedicationError::query()->forceCreate([
            'client_id' => $client->id,
            'client_medication_id' => $controlled->id,
            'error_type' => 'wrong_dose',
            'severity' => 'major',
            'description' => 'Dose drawn up did not match the chart.',
            'immediate_action' => 'Dose withheld and prescriber contacted.',
            'reported_by' => $actor->id,
            'reported_at' => now(),
        ]);
        $transitLog = (new FleetMedicationTransitLog)->forceFill([
            'medication_name' => 'Morphine sulfate',
            'is_controlled_drug' => true,
        ]);
        $transitLog->setRelation('client', $client);
        $transitLog->setRelation('medication', $controlled);

        $service = app(MedicationIncidentIntegrationService::class);
        $service->handleMissedDose($administration($controlled, ['status' => 'missed', 'scheduled_for' => now()->subHours(4)]), $actor->id);
        $service->handleLateDose($administration($controlled, ['scheduled_for' => now()->subHours(6)]), 360);
        $service->handleRefusedDose($refused);
        $service->handlePrnOverLimit($client, $controlled, $actor->id);
        $service->handleUnsafeCorrection($original, ['status' => 'given', 'dose_given' => '5 mg'], $actor->id, $correction);
        $service->handleRefusalEscalation($followup, 3);
        $service->handleTransitException($transitLog);
        app(MedicationSignalService::class)->emitError($error);
        $this->artisan('emar:check-medication-stock')->assertSuccessful();
        app(MedicationAlertService::class)->generateClientAlerts($client->fresh());
        $ordinaryIncident = $service->handleMissedDose(
            $administration($ordinary, ['status' => 'missed', 'scheduled_for' => now()->subHours(4)]),
            $actor->id,
        );

        // Every path produced its record…
        $this->assertSame(7, ClientIncident::query()->count());
        $this->assertGreaterThanOrEqual(9, Signal::query()->count());
        $this->assertTrue(MedicationDashboardAlert::query()->where('alert_type', 'overdue')->exists());
        $this->assertTrue(ControlRoomAlert::query()->exists());
        $this->assertTrue(HsEvent::query()->exists());

        // …and nothing readable without controlled view names the medicine.
        $unrestricted = ClientIncident::query()->whereKeyNot($ordinaryIncident->id)->get()
            ->concat(HsEvent::query()->get())
            ->concat(ControlRoomAlert::query()->get())
            ->concat(Signal::query()->get())
            ->concat(MedicationDashboardAlert::query()->whereNull('client_medication_id')->get());
        foreach ($unrestricted as $row) {
            $this->assertStringNotContainsStringIgnoringCase(
                'morphine',
                json_encode($row->toArray(), JSON_UNESCAPED_UNICODE),
                $row::class.' #'.$row->getKey().' names the controlled medicine.',
            );
        }
        $this->assertSame('Missed medication: Controlled medicine', ClientIncident::query()
            ->where('metadata->medication_incident_source->kind', 'missed_dose')
            ->whereKeyNot($ordinaryIncident->id)
            ->value('title'));

        // Ordinary medicines keep their names; the controlled name stays on
        // the medicine-linked eMAR alerts that require controlled view.
        $this->assertSame('Missed medication: Paracetamol', $ordinaryIncident->title);
        $this->assertTrue(MedicationDashboardAlert::query()
            ->where('client_medication_id', $controlled->id)
            ->where('message', 'like', '%Morphine sulfate%')
            ->exists());
    }

    private function coveringWorker(Site $site, ServiceContext $serviceContext, Client $client, array $deny = []): User
    {
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $worker->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $worker->permissionOverrides()->sync(
            Permission::query()->whereIn('key', $deny)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            // Clocked in: Meds today shows a person's medicines to a worker
            // assigned to them or clocked in to their shift (C6).
            'actual_starts_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);

        return $worker->refresh();
    }
}
