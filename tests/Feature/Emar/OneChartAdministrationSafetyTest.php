<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationAdminRule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OneChartAdministrationSafetyTest extends TestCase
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
            'name' => '1CHART Safety Test',
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
        $witness = $this->createWitness('witness-secret');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                'status' => 'given',
                'dose_given' => '5mg',
                'witnessed_by' => $witness->id,
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_field', 'witness_credential');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->administrationUrl($medication), [
                'status' => 'given',
                'dose_given' => '5mg',
                'witnessed_by' => $witness->id,
                'witness_credential' => 'wrong-secret',
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_field', 'witness_credential');

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

    private function administrationUrl(ClientMedication $medication): string
    {
        return "/api/medications/clients/{$this->client->id}/medications/{$medication->id}/administrations";
    }
}
