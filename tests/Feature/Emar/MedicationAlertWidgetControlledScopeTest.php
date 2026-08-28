<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDashboardAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationSignalService;
use App\Services\MedicationAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class MedicationAlertWidgetControlledScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_dashboard_widget_filters_controlled_rows_before_details_limits_and_counts(): void
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreignClient = Client::factory()->create(['status' => 'active']);
        $ordinaryMedication = $this->medication($client, 'VISIBLE ORDINARY WIDGET MEDICATION');
        $controlledMedication = $this->medication($client, 'HIDDEN CONTROLLED WIDGET MEDICATION', true);
        $foreignMedication = $this->medication($foreignClient, 'FORGED FOREIGN WIDGET MEDICATION', true);
        $recorder = User::factory()->create();

        $ordinaryOverdue = $this->alert($client, $ordinaryMedication, 'overdue');
        $controlledOverdue = $this->alert($client, $controlledMedication, 'overdue');
        $ordinaryPrn = $this->alert($client, $ordinaryMedication, 'prn_near_limit');
        $controlledPrn = $this->alert($client, $controlledMedication, 'prn_near_limit');
        $this->administration($client, $ordinaryMedication, $recorder, 'given');
        $this->administration($client, $controlledMedication, $recorder, 'refused');
        $controlledDiscrepancy = $this->discrepancy($client, $controlledMedication, $recorder);
        $forgedDiscrepancy = $this->discrepancy($client, $foreignMedication, $recorder);

        $signalService = Mockery::mock(MedicationSignalService::class);
        $signalService->shouldReceive('emit')->once();
        $discrepancyService = new MedicationAlertService(
            $signalService,
            app(MedicationGovernanceScopeService::class),
        );
        $checkDiscrepancies = new ReflectionMethod($discrepancyService, 'checkControlledDiscrepancies');
        $checkDiscrepancies->setAccessible(true);
        $discrepancyAlert = $checkDiscrepancies->invoke($discrepancyService, $client);
        $this->assertNotNull($discrepancyAlert);
        $this->assertStringContainsString($controlledMedication->name, $discrepancyAlert['message']);
        $this->assertStringNotContainsString($foreignMedication->name, $discrepancyAlert['message']);

        $service = app(MedicationAlertService::class);
        $ordinaryWidgets = $service->getGlobalDashboardWidgets(siteIds: [$site->id]);

        $this->assertArrayNotHasKey('controlled_discrepancies', $ordinaryWidgets);
        $this->assertSame([$ordinaryOverdue->id], collect($ordinaryWidgets['overdue_meds']['items'])->pluck('id')->all());
        $this->assertSame([$ordinaryPrn->id], collect($ordinaryWidgets['prn_near_limits']['items'])->pluck('id')->all());
        $this->assertSame([$ordinaryMedication->id], collect($ordinaryWidgets['expiring_medications']['items'])->pluck('id')->all());
        $this->assertSame([$ordinaryMedication->id], collect($ordinaryWidgets['high_risk_medications']['items'])->pluck('id')->all());
        $this->assertSame(1, $ordinaryWidgets['todays_summary']['total_scheduled']);
        $this->assertSame(1, $ordinaryWidgets['todays_summary']['completed']);
        $this->assertSame(0, $ordinaryWidgets['todays_summary']['refused']);
        $this->assertSame(0, $ordinaryWidgets['todays_summary']['remaining']);

        $controlledWidgets = $service->getGlobalDashboardWidgets(
            siteIds: [$site->id],
            canViewControlled: true,
        );
        $this->assertEqualsCanonicalizing(
            [$ordinaryOverdue->id, $controlledOverdue->id],
            collect($controlledWidgets['overdue_meds']['items'])->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$ordinaryPrn->id, $controlledPrn->id],
            collect($controlledWidgets['prn_near_limits']['items'])->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$ordinaryMedication->id, $controlledMedication->id],
            collect($controlledWidgets['expiring_medications']['items'])->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$ordinaryMedication->id, $controlledMedication->id],
            collect($controlledWidgets['high_risk_medications']['items'])->pluck('id')->all(),
        );
        $this->assertSame(2, $controlledWidgets['todays_summary']['total_scheduled']);
        $this->assertSame(1, $controlledWidgets['todays_summary']['completed']);
        $this->assertSame(1, $controlledWidgets['todays_summary']['refused']);
        $this->assertSame(0, $controlledWidgets['todays_summary']['remaining']);
        $this->assertSame(
            [$controlledDiscrepancy->id],
            collect($controlledWidgets['controlled_discrepancies']['items'])->pluck('id')->all(),
        );
        $this->assertNotContains(
            $forgedDiscrepancy->id,
            collect($controlledWidgets['controlled_discrepancies']['items'])->pluck('id')->all(),
        );
    }

    private function medication(Client $client, string $name, bool $controlled = false): ClientMedication
    {
        return ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => $name,
            'controlled_drug' => $controlled,
            'high_risk' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'is_prn' => false,
            'dose_times' => ['09:00'],
            'frequency' => '09:00',
            'start_date' => today()->subDay(),
            'end_date' => today()->addDays(7),
            'instructions' => $name.' instructions',
        ]);
    }

    private function alert(
        Client $client,
        ClientMedication $medication,
        string $type,
    ): MedicationDashboardAlert {
        return MedicationDashboardAlert::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'alert_type' => $type,
            'severity' => 'critical',
            'message' => $medication->name.' alert',
            'status' => 'active',
        ]);
    }

    private function administration(
        Client $client,
        ClientMedication $medication,
        User $recorder,
        string $status,
    ): ClientMedicationAdministration {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'service_context_id' => $client->service_context_id,
            'administered_by' => $recorder->id,
            'scheduled_for' => now(),
            'administered_at' => now(),
            'status' => $status,
            'dose_given' => '1 tablet',
        ]);
    }

    private function discrepancy(
        Client $client,
        ClientMedication $medication,
        User $recorder,
    ): ClientControlledDrugDiscrepancy {
        return ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'on_hand_before' => '10.00',
            'on_hand_after' => '9.00',
            'difference' => '-1.00',
            'reason' => 'Focused widget proof',
            'reported_by' => $recorder->id,
            'reported_at' => now(),
            'status' => 'open',
        ]);
    }
}
