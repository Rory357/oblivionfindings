<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ControlledDrugLossReport;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\MedicationDashboardAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
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
            'status' => 'scheduled',
        ]);

        return $worker->refresh();
    }
}
