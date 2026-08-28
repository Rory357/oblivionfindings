<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationAdminRule;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OneChartAdministrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private Site $site;

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
            'name' => '1CHART Safety Test',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->site = Site::factory()->create([
            'type' => 'house',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
            'site_id' => $this->site->id,
        ]);

        HrEmployeeProfile::factory()->create([
            'user_id' => $this->admin->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $assessor = User::factory()->create([
            'role' => 'manager',
            'approved_at' => now(),
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->admin->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today()->subMonth(),
            'expiry_date' => today()->addYear(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
        ]);

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'user_id' => $this->admin->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $this->admin->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_not_given_administration_requires_structured_reason_code(): void
    {
        $medication = $this->createMedication();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                'status' => 'refused',
                'reason' => 'Resident declined this morning.',
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_field', 'reason_code');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                'status' => 'refused',
                'reason_code' => 'refused',
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $medication->id,
            'status' => 'refused',
            'reason_code' => 'refused',
        ]);
    }

    public function test_other_not_given_reason_requires_free_text_detail(): void
    {
        $medication = $this->createMedication();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                'status' => 'withheld',
                'reason_code' => 'other',
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_field', 'reason');
    }

    public function test_witness_must_authenticate_before_controlled_drug_is_recorded(): void
    {
        $medication = $this->createMedication([
            'controlled_drug' => true,
            'witness_required' => true,
        ]);
        ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $witness = $this->createWitness('witness-secret');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                'status' => 'given',
                'dose_given' => '5mg',
                'witnessed_by' => $witness->id,
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('witness_credential');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                'status' => 'given',
                'dose_given' => '5mg',
                'witnessed_by' => $witness->id,
                'witness_credential' => 'wrong-secret',
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('witness_credential');

        $this->assertDatabaseMissing('client_medication_administrations', [
            'client_medication_id' => $medication->id,
            'status' => 'given',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                'status' => 'given',
                'dose_given' => '5mg',
                'witnessed_by' => $witness->id,
                'witness_credential' => 'witness-secret',
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $admin = ClientMedicationAdministration::query()
            ->where('client_medication_id', $medication->id)
            ->firstOrFail();

        $this->assertSame($witness->id, $admin->witnessed_by);
        $this->assertSame('password', $admin->witness_method);
        $this->assertNotNull($admin->witnessed_at);
    }

    public function test_facility_rule_requires_pulse_and_mirrors_vitals_observation(): void
    {
        $medication = $this->createMedication([
            'name' => 'Digoxin',
            'route' => 'oral',
        ]);

        MedicationAdminRule::query()->create([
            'site_id' => null,
            'match_type' => 'medicine_name',
            'match_value' => 'digoxin',
            'requires_countersign' => false,
            'required_observations' => ['pulse'],
            'active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                'status' => 'given',
                'dose_given' => '125mcg',
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_field', 'pulse_bpm');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                'status' => 'given',
                'dose_given' => '125mcg',
                'pulse_bpm' => 72,
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $medication->id,
            'pulse_bpm' => 72,
        ]);

        $this->assertDatabaseHas('clinical_observations', [
            'client_id' => $this->client->id,
            'observation_type' => 'vitals',
            'recorded_by' => $this->admin->id,
        ]);
    }

    public function test_pending_verification_order_is_visible_but_not_administrable_until_verified(): void
    {
        $medication = $this->createMedication([
            'approval_status' => 'pending_verification',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/medications/clients/{$this->client->id}/mar")
            ->assertOk()
            ->assertJsonPath('awaiting_verification.0.client_medication_id', $medication->id);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                'status' => 'given',
                'dose_given' => '5mg',
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_field', 'approval_status');

        $this->actingAs($this->admin)
            ->post("/emar/medications/{$medication->id}/verify")
            ->assertRedirect();

        $this->assertDatabaseHas('client_medications', [
            'id' => $medication->id,
            'approval_status' => 'verified',
            'verified_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                'status' => 'given',
                'dose_given' => '5mg',
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_api_scheduled_administration_rejects_incomplete_or_contradictory_offline_provenance(): void
    {
        $medication = $this->createMedication();
        $basePayload = [
            'status' => 'given',
            'dose_given' => '500mg',
            'scheduled_for' => now()->toIso8601String(),
        ];
        $validUuid = '9ef0f6a2-8d62-4d4a-945b-c7a29b6f36ce';
        $validCapturedAt = now()->subMinutes(5)->toIso8601String();
        $nonRfcCapturedAt = now(config('app.worker_timezone', 'Pacific/Auckland'))
            ->subMinutes(5)
            ->format('Y-m-d H:i:s');
        $invalidSubmissions = [
            [[
                'captured_offline_at' => $validCapturedAt,
                'origin_device_id' => 'shift-medication-card',
                'queued_offline' => true,
            ], 'client_request_uuid'],
            [[
                'client_request_uuid' => $validUuid,
                'origin_device_id' => 'shift-medication-card',
                'queued_offline' => true,
            ], 'captured_offline_at'],
            [[
                'client_request_uuid' => $validUuid,
                'captured_offline_at' => $nonRfcCapturedAt,
                'origin_device_id' => 'shift-medication-card',
                'queued_offline' => true,
            ], 'captured_offline_at'],
            [[
                'client_request_uuid' => $validUuid,
                'captured_offline_at' => $validCapturedAt,
                'queued_offline' => true,
            ], 'origin_device_id'],
            [[
                'client_request_uuid' => $validUuid,
                'captured_offline_at' => $validCapturedAt,
                'queued_offline' => false,
            ], 'captured_offline_at'],
        ];

        foreach ($invalidSubmissions as [$submission, $errorField]) {
            $this->actingAs($this->admin, 'sanctum')
                ->postJson($this->administrationUrl($medication), [
                    ...$basePayload,
                    ...$submission,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($errorField);
        }

        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_api_prn_administration_uses_capture_time_only_for_valid_offline_provenance(): void
    {
        $medication = $this->createMedication([
            'frequency' => null,
            'dose_times' => null,
            'is_prn' => true,
            'prn_reason' => 'Breakthrough pain',
            'max_per_day' => 4,
        ]);
        $basePayload = [
            'status' => 'given',
            'dose_given' => '500mg',
            'reason' => 'Breakthrough pain',
            'client_request_uuid' => '9c8567f5-4d2f-41fa-904e-0ad12a259aa5',
        ];
        $capturedAt = now()->subMinutes(5)->utc()->format('Y-m-d\TH:i:s.v\Z');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                ...$basePayload,
                'captured_offline_at' => $capturedAt,
                'queued_offline' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('origin_device_id');
        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                ...$basePayload,
                'captured_offline_at' => $capturedAt,
                'origin_device_id' => 'shift-medication-card',
                'queued_offline' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'captured_offline_at',
                'origin_device_id',
            ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                ...$basePayload,
                'captured_offline_at' => $capturedAt,
                'origin_device_id' => 'shift-medication-card',
                'queued_offline' => true,
            ])
            ->assertOk()
            ->assertJsonPath('sync.status', 'synced');

        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame(
            Carbon::parse($capturedAt)->utc()->format('Y-m-d H:i:s'),
            $administration->getRawOriginal('administered_at'),
        );
    }

    private function createMedication(array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Paracetamol',
            'dosage' => '500mg',
            'frequency' => 'Once daily',
            'dose_times' => [now(config('app.worker_timezone', 'Pacific/Auckland'))->format('H:i')],
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

        HrEmployeeProfile::factory()->create([
            'user_id' => $witness->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
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
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id,
            'user_id' => $witness->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'created_by' => $this->admin->id,
            'status' => 'in_progress',
        ]);

        return $witness;
    }

    private function administrationUrl(ClientMedication $medication): string
    {
        return "/api/medications/clients/{$this->client->id}/medications/{$medication->id}/administrations";
    }
}
