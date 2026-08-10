<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationAlert;
use App\Models\MedicationReview;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Services\MedicationAlertService;
use App\Services\MedicationReportingService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OneChartGovernanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
            'password' => Hash::make('admin-secret'),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->first());

        $serviceContext = ServiceContext::factory()->create([
            'name' => '1CHART Governance Test',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $site = Site::factory()->create([
            'type' => 'house',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
            'site_id' => $site->id,
            'care_level' => 'hospital',
        ]);
    }

    public function test_attention_alerts_are_exposed_and_med_admin_suppression_skips_overdue_generation(): void
    {
        $this->createMedication([
            'dose_times' => [now()->subHour()->format('H:i')],
        ]);

        ClientMedicationAlert::query()->create([
            'client_id' => $this->client->id,
            'type' => 'paper_prescription',
            'title' => 'Paper prescription in use',
            'detail' => 'Use signed chart in office until pharmacy chart arrives.',
            'prompt_on_open' => true,
            'enabled' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->client->forceFill([
            'suppress_med_admin_alerts' => true,
            'med_alerts_suppressed_reason' => 'Paper chart is temporarily authoritative.',
            'med_alerts_suppressed_by' => $this->admin->id,
            'med_alerts_suppressed_at' => now(),
        ])->save();

        $alerts = app(MedicationAlertService::class)->generateClientAlerts($this->client->fresh());

        $this->assertNotContains('overdue', collect($alerts)->pluck('alert_type')->all());
        $this->assertDatabaseHas('medication_dashboard_alerts', [
            'client_id' => $this->client->id,
            'alert_type' => 'paper_prescription',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('medication_dashboard_alerts', [
            'client_id' => $this->client->id,
            'alert_type' => 'med_admin_alerts_suppressed',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/medications/clients/{$this->client->id}/mar")
            ->assertOk()
            ->assertJsonPath('attention_alerts.0.type', 'paper_prescription');
    }

    public function test_inr_records_are_disabled_not_deleted(): void
    {
        $warfarin = $this->createMedication([
            'name' => 'Warfarin',
            'pharmac_therapeutic_group' => 'Blood and blood forming organs',
        ]);

        $this->actingAs($this->admin)
            ->post("/emar/clients/{$this->client->id}/inr", [
                'client_medication_id' => $warfarin->id,
                'inr_value' => 2.5,
                'target_range_low' => 2.0,
                'target_range_high' => 3.0,
                'dose_mg' => 3,
                'tested_on' => today()->toDateString(),
                'next_test_date' => today()->addWeek()->toDateString(),
                'notes' => 'Within target range.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_inr_records', [
            'client_id' => $this->client->id,
            'client_medication_id' => $warfarin->id,
            'recorded_by' => $this->admin->id,
            'disabled_at' => null,
        ]);

        $recordId = $this->client->inrRecords()->firstOrFail()->id;

        $this->actingAs($this->admin)
            ->post("/emar/inr/{$recordId}/disable")
            ->assertRedirect();

        $this->assertSame(1, $this->client->inrRecords()->count());
        $this->assertNotNull($this->client->inrRecords()->firstOrFail()->disabled_at);
    }

    public function test_syringe_driver_requires_authenticated_second_checker_for_controlled_contents(): void
    {
        $controlled = $this->createMedication([
            'name' => 'Morphine',
            'controlled_drug' => true,
            'witness_required' => true,
        ]);
        $witness = $this->createWitness('witness-secret');

        $payload = [
            'commenced_at' => now()->toIso8601String(),
            'rate' => '2',
            'rate_unit' => 'mL/hr',
            'duration_hours' => 24,
            'contents' => [
                [
                    'client_medication_id' => $controlled->id,
                    'dose' => '10mg',
                    'unit' => 'mg',
                ],
            ],
            'site_of_insertion' => 'Left upper arm',
            'witnessed_by' => $witness->id,
        ];

        $this->actingAs($this->admin)
            ->from('/emar/mar')
            ->post("/emar/clients/{$this->client->id}/syringe-drivers", $payload)
            ->assertRedirect('/emar/mar')
            ->assertSessionHasErrors('witness_credential');

        $this->actingAs($this->admin)
            ->post("/emar/clients/{$this->client->id}/syringe-drivers", [
                ...$payload,
                'witness_credential' => 'witness-secret',
            ])
            ->assertRedirect();

        $driver = $this->client->syringeDrivers()->firstOrFail();
        $this->assertSame($witness->id, $driver->witnessed_by);
        $this->assertSame('password', $driver->witness_method);

        $this->actingAs($this->admin)
            ->post("/emar/syringe-drivers/{$driver->id}/checks", [
                'infusion_running' => true,
                'site_condition' => 'Clean and dry',
                'volume_remaining' => '18mL',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('medication_syringe_driver_checks', [
            'medication_syringe_driver_id' => $driver->id,
            'checked_by' => $this->admin->id,
            'site_condition' => 'Clean and dry',
        ]);
    }

    public function test_review_completion_sets_next_chart_review_date_from_client_interval(): void
    {
        $this->client->forceFill(['chart_review_interval_months' => 2])->save();

        $review = MedicationReview::query()->create([
            'client_id' => $this->client->id,
            'review_type' => 'routine',
            'status' => 'scheduled',
            'scheduled_date' => today(),
            'requested_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/emar/reviews/{$review->id}/complete", [
                'clinical_summary' => 'Medicines reviewed with no changes.',
            ])
            ->assertRedirect();

        $expected = today()->addMonthsNoOverflow(2)->toDateString();
        $this->assertDatabaseHas('medication_reviews', [
            'id' => $review->id,
            'status' => 'completed',
            'next_review_date' => $expected,
        ]);
        $this->assertDatabaseHas('clients', [
            'id' => $this->client->id,
            'next_chart_review_date' => $expected,
        ]);
    }

    public function test_reporting_filters_by_care_level_and_includes_therapeutic_group(): void
    {
        $hospitalMedication = $this->createMedication([
            'name' => 'Amoxicillin',
            'pharmac_therapeutic_group' => 'Antibacterials for systemic use',
        ]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $hospitalMedication->id,
            'status' => 'given',
            'dose_given' => '500mg',
            'administered_by' => $this->admin->id,
            'scheduled_for' => now(),
            'administered_at' => now(),
        ]);

        $restHomeClient = Client::factory()->create([
            'service_context_id' => $this->client->service_context_id,
            'care_level' => 'rest_home',
        ]);
        $restHomeMedication = $this->createMedication([
            'client_id' => $restHomeClient->id,
            'name' => 'Ibuprofen',
            'pharmac_therapeutic_group' => 'Anti-inflammatory products',
        ]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $restHomeClient->id,
            'client_medication_id' => $restHomeMedication->id,
            'status' => 'given',
            'dose_given' => '200mg',
            'administered_by' => $this->admin->id,
            'scheduled_for' => now(),
            'administered_at' => now(),
        ]);

        $report = app(MedicationReportingService::class)->exportMar(
            dateFrom: now()->subDay(),
            dateTo: now()->addDay(),
            careLevel: 'hospital',
        );

        $this->assertCount(1, $report['records']);
        $this->assertSame('hospital', $report['records'][0]['care_level']);
        $this->assertSame('Antibacterials for systemic use', $report['records'][0]['pharmac_therapeutic_group']);
    }

    private function createMedication(array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Paracetamol',
            'dosage' => '500mg',
            'frequency' => 'Once daily',
            'dose_times' => ['09:00'],
            'controlled_drug' => false,
            'witness_required' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ], $overrides));
    }

    private function createWitness(string $password): User
    {
        $witness = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'password' => Hash::make($password),
        ]);

        $permission = Permission::query()->firstOrCreate(
            ['key' => 'medications.controlled.witness'],
            ['description' => 'Witness controlled medications'],
        );

        $witness->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);

        return $witness;
    }
}
