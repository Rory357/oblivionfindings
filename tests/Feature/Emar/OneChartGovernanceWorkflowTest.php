<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationAlert;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationReview;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
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
        $this->assignCurrentSiteStaff($this->admin, $site);
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

        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignWitness = $this->createWitness('foreign-secret', $foreignSite);
        $this->actingAs($this->admin)
            ->post("/emar/clients/{$this->client->id}/syringe-drivers", [
                ...$payload,
                'witnessed_by' => $foreignWitness->id,
                'witness_credential' => 'foreign-secret',
            ])
            ->assertNotFound();

        $staleWitness = $this->createWitness('stale-secret');
        $staleWitness->hrEmployeeProfile->forceFill([
            'is_active' => false,
            'end_date' => today()->subDay(),
        ])->save();
        $this->actingAs($this->admin)
            ->post("/emar/clients/{$this->client->id}/syringe-drivers", [
                ...$payload,
                'witnessed_by' => $staleWitness->id,
                'witness_credential' => 'stale-secret',
            ])
            ->assertNotFound();

        $invalidCompetencyWitnesses = [
            'unassessed-secret' => function (User $candidate): void {
                $candidate->medicationCompetencyAssessments()->delete();
            },
            'failed-secret' => function (User $candidate): void {
                $candidate->medicationCompetencyAssessments()->update(['status' => 'failed']);
            },
            'expired-secret' => function (User $candidate): void {
                $candidate->medicationCompetencyAssessments()->update(['expiry_date' => today()->subDay()]);
            },
            'not-witness-competent-secret' => function (User $candidate): void {
                $candidate->medicationCompetencyAssessments()->update(['can_witness_controlled' => false]);
            },
            'not-present-secret' => function (User $candidate): void {
                Shift::query()->where('user_id', $candidate->id)->delete();
            },
        ];
        foreach ($invalidCompetencyWitnesses as $password => $invalidate) {
            $candidate = $this->createWitness($password);
            $invalidate($candidate);
            $this->actingAs($this->admin)
                ->post("/emar/clients/{$this->client->id}/syringe-drivers", [
                    ...$payload,
                    'witnessed_by' => $candidate->id,
                    'witness_credential' => $password,
                ])
                ->assertNotFound();
        }

        $backdatedWitness = $this->createWitness('backdated-secret');
        $backdatedAt = now()->subDays(3);
        Shift::withoutEvents(function () use ($backdatedWitness, $backdatedAt): void {
            Shift::query()->where('user_id', $backdatedWitness->id)->firstOrFail()->forceFill([
                'starts_at' => $backdatedAt->copy()->subHour(),
                'ends_at' => $backdatedAt->copy()->addHour(),
                'status' => 'in_progress',
            ])->save();
        });
        $this->actingAs($this->admin)
            ->post("/emar/clients/{$this->client->id}/syringe-drivers", [
                ...$payload,
                'commenced_at' => $backdatedAt->toIso8601String(),
                'witnessed_by' => $backdatedWitness->id,
                'witness_credential' => 'backdated-secret',
            ])
            ->assertNotFound();

        $unauthorisedWitness = $this->createWitness('no-capability-secret');
        $witnessPermissionId = Permission::query()
            ->where('key', 'medications.controlled.witness')
            ->value('id');
        $this->assertNotNull($witnessPermissionId);
        $unauthorisedWitness->permissionOverrides()->syncWithoutDetaching([
            $witnessPermissionId => ['allowed' => false],
        ]);
        $unauthorisedWitness->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($this->admin)
            ->post("/emar/clients/{$this->client->id}/syringe-drivers", [
                ...$payload,
                'witnessed_by' => $unauthorisedWitness->id,
                'witness_credential' => 'no-capability-secret',
            ])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post("/emar/clients/{$this->client->id}/syringe-drivers", [
                ...$payload,
                'witnessed_by' => $this->admin->id,
                'witness_credential' => 'admin-secret',
            ])
            ->assertSessionHasErrors('witnessed_by');
        $this->assertDatabaseCount('medication_syringe_drivers', 0);

        $this->actingAs($this->admin)
            ->post("/emar/clients/{$this->client->id}/syringe-drivers", [
                ...$payload,
                'commenced_at' => now()->addMinutes(5)->toIso8601String(),
                'witness_credential' => 'witness-secret',
            ])
            ->assertSessionHasErrors('commenced_at');
        $this->actingAs($this->admin)
            ->post("/emar/clients/{$this->client->id}/syringe-drivers", [
                ...$payload,
                'contents' => [[
                    'name' => 'Unlinked caller-classified Morphine',
                    'dose' => '10mg',
                    'requires_witness' => false,
                ]],
                'witnessed_by' => null,
            ])
            ->assertSessionHasErrors('contents.0.client_medication_id');
        $this->assertDatabaseCount('medication_syringe_drivers', 0);

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
                'checked_at' => $driver->commenced_at->copy()->subMinute()->toIso8601String(),
                'infusion_running' => true,
            ])
            ->assertSessionHasErrors('checked_at');
        $this->actingAs($this->admin)
            ->post("/emar/syringe-drivers/{$driver->id}/checks", [
                'checked_at' => now()->addMinutes(5)->toIso8601String(),
                'infusion_running' => true,
            ])
            ->assertSessionHasErrors('checked_at');
        $this->assertSame(0, $driver->checks()->count());

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

        $this->actingAs($this->admin)
            ->post("/emar/syringe-drivers/{$driver->id}/complete", [
                'status' => 'completed',
                'notes' => 'Driver completed after the final check.',
            ])
            ->assertRedirect();
        $terminal = $driver->fresh();

        $this->actingAs($this->admin)
            ->post("/emar/syringe-drivers/{$driver->id}/checks", [
                'infusion_running' => true,
                'notes' => 'Must not append after completion.',
            ])
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->post("/emar/syringe-drivers/{$driver->id}/complete", [
                'status' => 'stopped',
                'notes' => 'Must not replace terminal evidence.',
            ])
            ->assertNotFound();

        $replayed = $driver->fresh();
        $this->assertSame('completed', $replayed->status);
        $this->assertSame($terminal->notes, $replayed->notes);
        $this->assertSame($terminal->completed_by, $replayed->completed_by);
        $this->assertTrue($terminal->completed_at->equalTo($replayed->completed_at));
        $this->assertSame(1, $driver->checks()->count());
    }

    public function test_syringe_driver_checks_and_completion_require_canonical_controlled_authority(): void
    {
        $controlled = $this->createMedication([
            'name' => 'Controlled syringe-driver aggregate',
            'controlled_drug' => true,
        ]);
        $driver = $this->client->syringeDrivers()->create([
            'site_id' => $this->client->site_id,
            'status' => 'running',
            'commenced_at' => now()->subHour(),
            'commenced_by' => $this->admin->id,
            'contents' => [[
                'client_medication_id' => $controlled->id,
                'name' => $controlled->name,
                'dose' => '5 mg',
            ]],
        ]);
        $recordOnly = $this->syringeMutationActor([
            'medications.orders.manage',
            'medications.controlled.record',
        ]);
        $viewOnly = $this->syringeMutationActor([
            'medications.orders.manage',
            'medications.controlled.view',
        ]);

        foreach ([$recordOnly, $viewOnly] as $unauthorised) {
            $this->actingAs($unauthorised)
                ->post("/emar/syringe-drivers/{$driver->id}/checks", [])
                ->assertNotFound();
            $this->actingAs($unauthorised)
                ->post("/emar/syringe-drivers/{$driver->id}/complete", [])
                ->assertNotFound();
        }
        $this->assertSame('running', $driver->fresh()->status);
        $this->assertSame(0, $driver->checks()->count());

        $foreignClient = Client::factory()->create(['status' => 'active']);
        $foreignMedication = ClientMedication::factory()->create([
            'client_id' => $foreignClient->id,
            'name' => 'FORGED foreign syringe content',
            'controlled_drug' => false,
        ]);
        $forgedDriver = $this->client->syringeDrivers()->create([
            'site_id' => $this->client->site_id,
            'status' => 'running',
            'commenced_at' => now()->subHour(),
            'commenced_by' => $this->admin->id,
            'contents' => [[
                'client_medication_id' => $foreignMedication->id,
                'name' => 'FORGED foreign syringe content',
            ]],
        ]);
        $malformedDriver = $this->client->syringeDrivers()->create([
            'site_id' => $this->client->site_id,
            'status' => 'running',
            'commenced_at' => now()->subHour(),
            'commenced_by' => $this->admin->id,
            'contents' => [[
                'client_medication_id' => 'not-a-medication-id',
                'name' => 'Malformed syringe content',
            ]],
        ]);
        $unlinkedDriver = $this->client->syringeDrivers()->create([
            'site_id' => $this->client->site_id,
            'status' => 'running',
            'commenced_at' => now()->subHour(),
            'commenced_by' => $this->admin->id,
            'contents' => [[
                'client_medication_id' => null,
                'name' => 'Unlinked syringe content',
                'requires_witness' => false,
            ]],
        ]);

        foreach ([$forgedDriver, $malformedDriver, $unlinkedDriver] as $noncanonicalDriver) {
            $this->actingAs($this->admin)
                ->post("/emar/syringe-drivers/{$noncanonicalDriver->id}/checks", [
                    'infusion_running' => true,
                ])
                ->assertNotFound();
            $this->actingAs($this->admin)
                ->post("/emar/syringe-drivers/{$noncanonicalDriver->id}/complete", [
                    'status' => 'completed',
                ])
                ->assertNotFound();
            $this->assertSame('running', $noncanonicalDriver->fresh()->status);
            $this->assertSame(0, $noncanonicalDriver->checks()->count());
        }
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
            'site_id' => $this->client->site_id,
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

        $reporting = app(MedicationReportingService::class);
        $unscopedReport = $reporting->exportMar(
            dateFrom: now()->subDay(),
            dateTo: now()->addDay(),
            careLevel: 'hospital',
        );
        $this->assertSame([], $unscopedReport['records']);

        $report = $reporting->exportMar(
            dateFrom: now()->subDay(),
            dateTo: now()->addDay(),
            careLevel: 'hospital',
            siteIds: [(int) $this->client->site_id],
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

    private function createWitness(string $password, ?Site $site = null): User
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
        $site ??= Site::query()->findOrFail($this->client->site_id);
        $this->assignCurrentSiteStaff($witness, $site);

        MedicationCompetencyAssessment::query()->create([
            'user_id' => $witness->id,
            'assessor_id' => $this->admin->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today()->subMonth(),
            'expiry_date' => today()->addYear(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_witness_controlled' => true,
        ]);
        $presenceClient = (int) $site->id === (int) $this->client->site_id
            ? $this->client
            : Client::factory()->create([
                'site_id' => $site->id,
                'service_context_id' => $this->client->service_context_id,
                'status' => 'active',
            ]);
        Shift::factory()->create([
            'client_id' => $presenceClient->id,
            'site_id' => $site->id,
            'service_context_id' => $presenceClient->service_context_id,
            'user_id' => $witness->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'status' => 'in_progress',
            'created_by' => $this->admin->id,
        ]);

        return $witness;
    }

    private function assignCurrentSiteStaff(User $user, Site $site): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);
    }

    /** @param array<int, string> $permissions */
    private function syringeMutationActor(array $permissions): User
    {
        $actor = User::factory()->create(['approved_at' => now()]);
        $this->assignCurrentSiteStaff(
            $actor,
            Site::query()->findOrFail($this->client->site_id),
        );
        $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $permissionIds);
        $actor->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all(),
        );

        return $actor->refresh();
    }
}
