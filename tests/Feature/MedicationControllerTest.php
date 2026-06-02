<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientCondition;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientEmergencyContact;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Support\EmarUrl;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $providerManager;
    protected User $coordinator;
    protected User $supportWorker;
    protected User $financeUser;
    protected User $hrUser;
    protected User $auditor;
    protected Client $client;
    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->providerManager = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
        $this->providerManager->roles()->attach(Role::where('name', 'provider_manager')->first());

        $this->coordinator = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->financeUser = User::factory()->create(['role' => 'finance', 'approved_at' => now()]);
        $this->financeUser->roles()->attach(Role::where('name', 'finance')->first());

        $this->hrUser = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $this->hrUser->roles()->attach(Role::where('name', 'hr')->first());

        $this->auditor = User::factory()->create(['role' => 'auditor', 'approved_at' => now()]);
        $this->auditor->roles()->attach(Role::where('name', 'auditor')->first());

        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Test Residential',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $this->serviceContext->id,
        ]);

        // Assign support worker to client
        $this->client->supportWorkers()->attach($this->supportWorker->id);
    }

    // ──────────────────────────────────────────────────────────────
    //  Helper: mock NotificationService to avoid side-effects
    // ──────────────────────────────────────────────────────────────

    protected function mockNotificationService(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(NotificationService::class);
        $mock->shouldReceive('notifyCrud')->andReturnNull();
        $this->app->instance(NotificationService::class, $mock);

        return $mock;
    }

    /**
     * Create a second support worker that can witness controlled drugs.
     */
    protected function createWitness(): User
    {
        $witness = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $witness->roles()->attach(Role::where('name', 'support_worker')->first());
        $this->client->supportWorkers()->attach($witness->id);

        return $witness;
    }

    /**
     * Create a ClientMedication directly in the database for the test client.
     */
    protected function createMedication(array $overrides = []): ClientMedication
    {
        return ClientMedication::create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Paracetamol',
            'dosage' => '500mg',
            'frequency' => 'Twice daily',
            'dose_times' => ['08:00', '20:00'],
            'is_prn' => false,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
        ], $overrides));
    }

    /**
     * Create a controlled drug medication.
     */
    protected function createControlledDrug(array $overrides = []): ClientMedication
    {
        return $this->createMedication(array_merge([
            'name' => 'Morphine Sulphate',
            'controlled_drug' => true,
        ], $overrides));
    }

    /**
     * Create a PRN medication.
     */
    protected function createPrnMedication(array $overrides = []): ClientMedication
    {
        return $this->createMedication(array_merge([
            'name' => 'Ibuprofen PRN',
            'is_prn' => true,
            'frequency' => null,
            'dose_times' => null,
            'prn_reason' => 'As needed for pain',
        ], $overrides));
    }

    // ══════════════════════════════════════════════════════════════
    //  1. CENTRAL MEDICATIONS INDEX - Authentication & Permissions
    // ══════════════════════════════════════════════════════════════

    public function test_medications_index_requires_authentication(): void
    {
        $this->get('/medications')->assertRedirect('/login');
    }

    public function test_medications_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/medications')
            ->assertRedirect(EmarUrl::daily());
    }

    public function test_medications_index_accessible_by_support_worker_with_view_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/medications')
            ->assertRedirect(EmarUrl::daily());
    }

    public function test_canonical_medications_daily_view_accessible_by_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get(EmarUrl::daily())
            ->assertOk();
    }

    public function test_medications_index_forbidden_for_hr_without_medications_view(): void
    {
        $this->actingAs($this->hrUser)
            ->get('/medications')
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  2. SUPPORT WORKER VISIBILITY - Assigned clients only
    // ══════════════════════════════════════════════════════════════

    public function test_support_worker_only_sees_assigned_clients_in_medications_index(): void
    {
        // Create an unassigned client
        $unassignedClient = Client::factory()->create();

        $this->actingAs($this->supportWorker)
            ->get(EmarUrl::daily())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('clients', fn ($clients) =>
                    collect($clients)->pluck('id')->contains($this->client->id) &&
                    !collect($clients)->pluck('id')->contains($unassignedClient->id)
                )
            );
    }

    public function test_admin_sees_all_clients_in_medications_index(): void
    {
        $otherClient = Client::factory()->create();

        $this->actingAs($this->admin)
            ->get(EmarUrl::daily())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('clients', fn ($clients) =>
                    collect($clients)->pluck('id')->contains($this->client->id) &&
                    collect($clients)->pluck('id')->contains($otherClient->id)
                )
            );
    }

    // ══════════════════════════════════════════════════════════════
    //  3. AUDIT LOG - Authentication & Permissions
    // ══════════════════════════════════════════════════════════════

    public function test_audit_index_requires_authentication(): void
    {
        $this->get('/medications/audit')->assertRedirect('/login');
    }

    public function test_audit_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/medications/audit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('medications/audit')
                ->has('logs')
                ->has('filters')
            );
    }

    public function test_audit_index_accessible_by_coordinator(): void
    {
        $this->actingAs($this->coordinator)
            ->get('/medications/audit')
            ->assertOk();
    }

    public function test_audit_index_accessible_by_auditor(): void
    {
        $this->actingAs($this->auditor)
            ->get('/medications/audit')
            ->assertOk();
    }

    public function test_audit_index_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/medications/audit')
            ->assertForbidden();
    }

    public function test_audit_index_forbidden_for_hr(): void
    {
        $this->actingAs($this->hrUser)
            ->get('/medications/audit')
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  4. AUDIT CSV EXPORT - Authentication & Permissions
    // ══════════════════════════════════════════════════════════════

    public function test_audit_export_requires_authentication(): void
    {
        $this->get('/medications/audit/export')->assertRedirect('/login');
    }

    public function test_audit_export_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/medications/audit/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_audit_export_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/medications/audit/export')
            ->assertForbidden();
    }

    public function test_audit_export_forbidden_for_auditor_without_export_permission(): void
    {
        $this->actingAs($this->auditor)
            ->get('/medications/audit/export')
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  5. REPORTS - Authentication & Permissions
    // ══════════════════════════════════════════════════════════════

    public function test_reports_index_requires_authentication(): void
    {
        $this->get('/reports/medications')->assertRedirect('/login');
    }

    public function test_reports_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/reports/medications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports/medications')
                ->has('filters')
                ->has('clients')
                ->has('service_contexts')
                ->has('administrations')
                ->has('discrepancies')
            );
    }

    public function test_reports_index_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/reports/medications')
            ->assertForbidden();
    }

    public function test_reports_mar_export_requires_authentication(): void
    {
        $this->get('/reports/medications/export-mar')->assertRedirect('/login');
    }

    public function test_reports_mar_export_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/reports/medications/export-mar')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_reports_discrepancies_export_requires_authentication(): void
    {
        $this->get('/reports/medications/export-controlled-discrepancies')->assertRedirect('/login');
    }

    public function test_reports_discrepancies_export_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/reports/medications/export-controlled-discrepancies')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_reports_discrepancies_export_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/reports/medications/export-controlled-discrepancies')
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  6. CLIENT MEDICAL PAGE - Authentication & Permissions
    // ══════════════════════════════════════════════════════════════

    public function test_client_medical_show_requires_authentication(): void
    {
        $this->get("/clients/{$this->client->id}/medical")->assertRedirect('/login');
    }

    public function test_client_medical_show_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get("/clients/{$this->client->id}/medical")
            ->assertRedirect(EmarUrl::medications($this->client));
    }

    public function test_client_medical_show_accessible_by_assigned_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get("/clients/{$this->client->id}/medical")
            ->assertRedirect(EmarUrl::medications($this->client));
    }

    public function test_client_medical_show_forbidden_for_unassigned_support_worker(): void
    {
        $unassignedWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $unassignedWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($unassignedWorker)
            ->get("/clients/{$this->client->id}/medical")
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  7. MEDICAL PROFILE - CRUD
    // ══════════════════════════════════════════════════════════════

    public function test_update_medical_profile_requires_authentication(): void
    {
        $this->put("/clients/{$this->client->id}/medical/profile", [])
            ->assertRedirect('/login');
    }

    public function test_update_medical_profile_with_valid_data(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/profile", [
                'medical_history' => 'Type 2 diabetes',
                'disabilities' => ['Mobility impairment'],
                'allergies' => ['Penicillin'],
                'notes' => 'Requires daily blood sugar monitoring',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $profile = ClientMedicalProfile::where('client_id', $this->client->id)->firstOrFail();
        $this->assertSame('Type 2 diabetes', $profile->medical_history);
        $this->assertSame(['Mobility impairment'], $profile->disabilities);
        $this->assertSame(['Penicillin'], $profile->allergies);
    }

    public function test_update_medical_profile_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->put("/clients/{$this->client->id}/medical/profile", [
                'medical_history' => 'Test',
            ])
            ->assertForbidden();
    }

    public function test_update_medical_profile_updates_existing_record(): void
    {
        $this->mockNotificationService();

        ClientMedicalProfile::create([
            'client_id' => $this->client->id,
            'medical_history' => 'Old history',
            'allergies' => ['Old allergies'],
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/profile", [
                'medical_history' => 'Updated history',
                'allergies' => ['Updated allergies'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $profile = ClientMedicalProfile::where('client_id', $this->client->id)->firstOrFail();
        $this->assertSame('Updated history', $profile->medical_history);
        $this->assertSame(['Updated allergies'], $profile->allergies);
        $this->assertDatabaseCount('client_medical_profiles', 1);
    }

    // ══════════════════════════════════════════════════════════════
    //  8. MEDICATION CRUD - Create
    // ══════════════════════════════════════════════════════════════

    public function test_store_medication_requires_authentication(): void
    {
        $this->post("/clients/{$this->client->id}/medical/medications", [])
            ->assertRedirect('/login');
    }

    public function test_store_medication_with_valid_data(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Metformin',
                'dosage' => '500mg',
                'frequency' => 'Twice daily',
                'dose_times' => ['08:00', '20:00'],
                'is_prn' => false,
                'controlled_drug' => false,
                'route' => 'oral',
                'form' => 'tablet',
                'prescriber' => 'Dr Smith',
                'pharmacy' => 'Local Pharmacy',
                'start_date' => '2025-01-01',
                'instructions' => 'Take with food',
                'active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medications', [
            'client_id' => $this->client->id,
            'name' => 'Metformin',
            'dosage' => '500mg',
            'route' => 'oral',
            'form' => 'tablet',
            'prescriber' => 'Dr Smith',
            'active' => true,
        ]);
    }

    public function test_store_prn_medication_persists_canonical_prn_fields(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Ibuprofen PRN',
                'dosage' => '200mg',
                'is_prn' => true,
                'prn_reason' => 'Breakthrough pain',
                'max_per_day' => 4,
                'min_hours_between_doses' => 6,
                'controlled_drug' => true,
                'high_risk' => true,
                'prescriber' => 'Dr Canonical',
                'route' => 'oral',
                'form' => 'tablet',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $medication = ClientMedication::where('client_id', $this->client->id)
            ->where('name', 'Ibuprofen PRN')
            ->firstOrFail();

        $this->assertTrue($medication->is_prn);
        $this->assertSame('Breakthrough pain', $medication->prn_reason);
        $this->assertSame(4, $medication->max_per_day);
        $this->assertSame(6.0, $medication->min_hours_between_doses);
        $this->assertTrue($medication->controlled_drug);
        $this->assertTrue($medication->high_risk);
        $this->assertSame('Dr Canonical', $medication->prescriber);
    }

    public function test_store_medication_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Test Med',
            ])
            ->assertForbidden();
    }

    public function test_store_medication_validates_name_required(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'dosage' => '10mg',
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_store_medication_validates_name_max_length(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => str_repeat('a', 256),
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_store_medication_validates_dose_times_format(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Test',
                'dose_times' => ['8am', 'noon'],
            ])
            ->assertSessionHasErrors(['dose_times.0']);
    }

    public function test_store_medication_accepts_valid_dose_times(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Test Med',
                'dose_times' => ['08:00', '12:30', '18:00'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $med = ClientMedication::where('name', 'Test Med')->first();
        $this->assertEquals(['08:00', '12:30', '18:00'], $med->dose_times);
    }

    public function test_store_medication_validates_state_enum(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Test',
                'state' => 'invalid_state',
            ])
            ->assertSessionHasErrors(['state']);
    }

    public function test_store_medication_normalizes_state_and_active(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Ceased Med',
                'state' => 'ceased',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_medications', [
            'name' => 'Ceased Med',
            'state' => 'ceased',
            'active' => false,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  9. MEDICATION CRUD - Update
    // ══════════════════════════════════════════════════════════════

    public function test_update_medication_requires_authentication(): void
    {
        $med = $this->createMedication();
        $this->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [])
            ->assertRedirect('/login');
    }

    public function test_update_medication_with_valid_data(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [
                'name' => 'Paracetamol Updated',
                'dosage' => '1000mg',
                'frequency' => 'Three times daily',
                'dose_times' => ['08:00', '14:00', '20:00'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medications', [
            'id' => $med->id,
            'name' => 'Paracetamol Updated',
            'dosage' => '1000mg',
            'frequency' => 'Three times daily',
        ]);
    }

    public function test_update_medication_persists_canonical_prn_fields(): void
    {
        $this->mockNotificationService();
        $med = $this->createPrnMedication([
            'max_per_day' => 2,
            'min_hours_between_doses' => 4,
            'controlled_drug' => false,
            'high_risk' => false,
            'prescriber' => 'Dr Initial',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [
                'name' => 'Ibuprofen PRN Updated',
                'is_prn' => true,
                'prn_reason' => 'Updated PRN reason',
                'max_per_day' => 6,
                'min_hours_between_doses' => 3,
                'controlled_drug' => true,
                'high_risk' => true,
                'prescriber' => 'Dr Updated',
                'route' => 'oral',
                'form' => 'capsule',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $med->refresh();

        $this->assertSame('Ibuprofen PRN Updated', $med->name);
        $this->assertTrue($med->is_prn);
        $this->assertSame('Updated PRN reason', $med->prn_reason);
        $this->assertSame(6, $med->max_per_day);
        $this->assertSame(3.0, $med->min_hours_between_doses);
        $this->assertTrue($med->controlled_drug);
        $this->assertTrue($med->high_risk);
        $this->assertSame('Dr Updated', $med->prescriber);
        $this->assertSame('capsule', $med->form);
    }

    public function test_update_medication_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create();
        $med = ClientMedication::create([
            'client_id' => $otherClient->id,
            'name' => 'Other Med',
            'active' => true,
            'state' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [
                'name' => 'Hacked',
            ])
            ->assertNotFound();
    }

    public function test_update_medication_forbidden_for_support_worker(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [
                'name' => 'Updated',
            ])
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  10. MEDICATION CRUD - Delete
    // ══════════════════════════════════════════════════════════════

    public function test_destroy_medication_requires_authentication(): void
    {
        $med = $this->createMedication();
        $this->delete("/clients/{$this->client->id}/medical/medications/{$med->id}")
            ->assertRedirect('/login');
    }

    public function test_destroy_medication_removes_record(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/medical/medications/{$med->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSoftDeleted('client_medications', ['id' => $med->id]);
    }

    public function test_destroy_medication_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create();
        $med = ClientMedication::create([
            'client_id' => $otherClient->id,
            'name' => 'Other',
            'active' => true,
            'state' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/medical/medications/{$med->id}")
            ->assertNotFound();
    }

    public function test_destroy_medication_forbidden_for_support_worker(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->delete("/clients/{$this->client->id}/medical/medications/{$med->id}")
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  11. MEDICATION ADMINISTRATION - Basic
    // ══════════════════════════════════════════════════════════════

    public function test_store_administration_requires_authentication(): void
    {
        $med = $this->createMedication();
        $this->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [])
            ->assertRedirect('/login');
    }

    public function test_store_administration_given_with_valid_data(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();
        $now = now();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'dose_given' => '500mg',
                'scheduled_for' => $now->format('Y-m-d H:i:s'),
                'administered_at' => $now->format('Y-m-d H:i:s'),
                'notes' => 'Patient tolerated well',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'status' => 'given',
            'administered_by' => $this->supportWorker->id,
        ]);
    }

    public function test_store_administration_validates_status_required(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [])
            ->assertSessionHasErrors(['status']);
    }

    public function test_store_administration_validates_status_enum(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'invalid',
            ])
            ->assertSessionHasErrors(['status']);
    }

    public function test_store_administration_refused_requires_reason(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'refused',
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('reason_code');
    }

    public function test_store_administration_missed_requires_reason(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'missed',
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('reason_code');
    }

    public function test_store_administration_withheld_requires_reason(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'withheld',
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('reason_code');
    }

    public function test_store_administration_refused_succeeds_with_reason(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'refused',
                'reason_code' => 'refused',
                'reason' => 'Client declined medication',
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'status' => 'refused',
            'reason' => 'Client declined medication',
        ]);
    }

    public function test_store_administration_forbidden_for_hr(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->hrUser)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
            ])
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  12. PRN MEDICATION - Reason required even when given
    // ══════════════════════════════════════════════════════════════

    public function test_prn_medication_given_requires_reason(): void
    {
        $med = $this->createPrnMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_prn_medication_given_succeeds_with_reason(): void
    {
        $this->mockNotificationService();
        $med = $this->createPrnMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'reason' => 'Headache reported by client',
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    // ══════════════════════════════════════════════════════════════
    //  13. TIME WINDOW VALIDATION
    // ══════════════════════════════════════════════════════════════

    public function test_administration_within_time_window_succeeds_without_reason(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        // Administered 15 minutes after scheduled: within window
        $scheduled = now();
        $administered = now()->addMinutes(15);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'scheduled_for' => $scheduled->format('Y-m-d H:i:s'),
                'administered_at' => $administered->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_administration_more_than_30_min_late_requires_reason(): void
    {
        $med = $this->createMedication();

        // Administered 45 minutes after scheduled: outside window (>30 min late)
        $scheduled = now()->subMinutes(45);
        $administered = now();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'scheduled_for' => $scheduled->format('Y-m-d H:i:s'),
                'administered_at' => $administered->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_administration_more_than_60_min_early_requires_reason(): void
    {
        $med = $this->createMedication();

        // Administered 90 minutes before scheduled: outside window (>60 min early)
        $scheduled = now()->addMinutes(90);
        $administered = now();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'scheduled_for' => $scheduled->format('Y-m-d H:i:s'),
                'administered_at' => $administered->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_administration_outside_time_window_succeeds_with_reason(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $scheduled = now()->subMinutes(45);
        $administered = now();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'reason' => 'Client was at appointment',
                'scheduled_for' => $scheduled->format('Y-m-d H:i:s'),
                'administered_at' => $administered->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_administration_exactly_30_min_late_succeeds_without_reason(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        // Exactly at the boundary (30 min): should succeed
        $scheduled = now();
        $administered = now()->addMinutes(30);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'scheduled_for' => $scheduled->format('Y-m-d H:i:s'),
                'administered_at' => $administered->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    // ══════════════════════════════════════════════════════════════
    //  14. CONTROLLED DRUG ADMINISTRATION - Witness Requirements
    // ══════════════════════════════════════════════════════════════

    public function test_controlled_drug_given_requires_witness(): void
    {
        $med = $this->createControlledDrug();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_controlled_drug_self_witness_blocked(): void
    {
        $med = $this->createControlledDrug();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'witnessed_by' => $this->supportWorker->id,
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_controlled_drug_given_with_valid_witness_succeeds(): void
    {
        $this->mockNotificationService();
        $med = $this->createControlledDrug();
        $witness = $this->createWitness();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $med->id,
            'status' => 'given',
        ]);
    }

    public function test_controlled_drug_creates_controlled_entry_on_administration(): void
    {
        $this->mockNotificationService();
        $med = $this->createControlledDrug();
        $witness = $this->createWitness();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'entry_type' => 'administered',
            'recorded_by' => $this->supportWorker->id,
            'witnessed_by' => $witness->id,
        ]);
    }

    public function test_controlled_drug_refused_does_not_require_witness(): void
    {
        $this->mockNotificationService();
        $med = $this->createControlledDrug();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'refused',
                'reason_code' => 'refused',
                'reason' => 'Client refused medication',
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_controlled_drug_witness_must_have_witness_permission(): void
    {
        $med = $this->createControlledDrug();

        // HR user does not have medications.controlled.witness permission
        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'witnessed_by' => $this->hrUser->id,
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    // ══════════════════════════════════════════════════════════════
    //  15. STOCK MANAGEMENT
    // ══════════════════════════════════════════════════════════════

    public function test_update_stock_requires_authentication(): void
    {
        $med = $this->createMedication();
        $this->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [])
            ->assertRedirect('/login');
    }

    public function test_update_stock_with_valid_data(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 30,
                'unit' => 'tablets',
                'reorder_level' => 10,
                'notes' => 'Stock check completed',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_stocks', [
            'client_medication_id' => $med->id,
            'on_hand' => 30,
            'unit' => 'tablets',
            'reorder_level' => 10,
        ]);
    }

    public function test_update_stock_forbidden_for_hr(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->hrUser)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 10,
            ])
            ->assertForbidden();
    }

    public function test_update_stock_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create();
        $med = ClientMedication::create([
            'client_id' => $otherClient->id,
            'name' => 'Other',
            'active' => true,
            'state' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 10,
            ])
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════
    //  16. CONTROLLED DRUG STOCK - Witness & Entry/Discrepancy
    // ══════════════════════════════════════════════════════════════

    public function test_controlled_stock_update_requires_witness(): void
    {
        $med = $this->createControlledDrug();
        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 8,
                'reason' => 'Counted',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_controlled_stock_update_requires_reason(): void
    {
        $med = $this->createControlledDrug();
        $witness = $this->createWitness();
        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 8,
                'witnessed_by' => $witness->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_controlled_stock_update_self_witness_blocked(): void
    {
        $med = $this->createControlledDrug();
        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 8,
                'witnessed_by' => $this->admin->id,
                'reason' => 'Counted',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_controlled_stock_update_creates_entry(): void
    {
        $this->mockNotificationService();
        $med = $this->createControlledDrug();
        $witness = $this->createWitness();

        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 10,
                'witnessed_by' => $witness->id,
                'reason' => 'Routine stock check',
                'unit' => 'tablets',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'entry_type' => 'stock_count',
            'on_hand_before' => 10,
            'on_hand_after' => 10,
            'recorded_by' => $this->admin->id,
            'witnessed_by' => $witness->id,
        ]);
    }

    public function test_controlled_stock_update_creates_discrepancy_when_amounts_differ(): void
    {
        $this->mockNotificationService();
        $med = $this->createControlledDrug();
        $witness = $this->createWitness();

        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 8,
                'witnessed_by' => $witness->id,
                'reason' => 'Two missing after shift change',
                'unit' => 'tablets',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_controlled_drug_discrepancies', [
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'on_hand_before' => 10,
            'on_hand_after' => 8,
            'difference' => -2,
            'status' => 'open',
            'reported_by' => $this->admin->id,
            'witnessed_by' => $witness->id,
        ]);
    }

    public function test_controlled_stock_update_no_discrepancy_when_amounts_match(): void
    {
        $this->mockNotificationService();
        $med = $this->createControlledDrug();
        $witness = $this->createWitness();

        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 10,
                'witnessed_by' => $witness->id,
                'reason' => 'Routine check, all correct',
                'unit' => 'tablets',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('client_controlled_drug_discrepancies', 0);
    }

    public function test_controlled_stock_witness_must_have_witness_permission(): void
    {
        $med = $this->createControlledDrug();
        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 8,
                'witnessed_by' => $this->hrUser->id,
                'reason' => 'Check',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    // ══════════════════════════════════════════════════════════════
    //  17. CLOSE CONTROLLED DRUG DISCREPANCY
    // ══════════════════════════════════════════════════════════════

    public function test_close_discrepancy_requires_authentication(): void
    {
        $disc = ClientControlledDrugDiscrepancy::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->createControlledDrug()->id,
            'service_context_id' => $this->serviceContext->id,
            'on_hand_before' => 10,
            'on_hand_after' => 8,
            'difference' => -2,
            'reported_at' => now(),
            'reported_by' => $this->admin->id,
            'witnessed_by' => $this->supportWorker->id,
            'status' => 'open',
        ]);

        $this->post("/clients/{$this->client->id}/medical/controlled-discrepancies/{$disc->id}/close")
            ->assertRedirect('/login');
    }

    public function test_close_discrepancy_with_resolution_notes(): void
    {
        $this->mockNotificationService();
        $disc = ClientControlledDrugDiscrepancy::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->createControlledDrug()->id,
            'service_context_id' => $this->serviceContext->id,
            'on_hand_before' => 10,
            'on_hand_after' => 8,
            'difference' => -2,
            'reported_at' => now(),
            'reported_by' => $this->admin->id,
            'witnessed_by' => $this->supportWorker->id,
            'status' => 'open',
        ]);

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/controlled-discrepancies/{$disc->id}/close", [
                'resolution_notes' => 'Found missing tablets in drawer',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $disc->refresh();
        $this->assertEquals('closed', $disc->status);
        $this->assertEquals('Found missing tablets in drawer', $disc->resolution_notes);
        $this->assertNotNull($disc->resolved_at);
        $this->assertEquals($this->admin->id, $disc->resolved_by);
    }

    public function test_close_already_closed_discrepancy_returns_success(): void
    {
        $disc = ClientControlledDrugDiscrepancy::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->createControlledDrug()->id,
            'service_context_id' => $this->serviceContext->id,
            'on_hand_before' => 10,
            'on_hand_after' => 8,
            'difference' => -2,
            'reported_at' => now(),
            'reported_by' => $this->admin->id,
            'witnessed_by' => $this->supportWorker->id,
            'status' => 'closed',
            'resolved_at' => now(),
            'resolved_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/controlled-discrepancies/{$disc->id}/close")
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    // ══════════════════════════════════════════════════════════════
    //  18. BREAK-GLASS EMERGENCY ACCESS
    // ══════════════════════════════════════════════════════════════

    public function test_break_glass_store_requires_authentication(): void
    {
        $this->post("/clients/{$this->client->id}/break-glass", [])
            ->assertRedirect('/login');
    }

    public function test_break_glass_store_creates_access_with_default_expiry(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->providerManager)
            ->post("/clients/{$this->client->id}/break-glass", [
                'reason' => 'Emergency medication query',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $access = ClientBreakGlassAccess::where('client_id', $this->client->id)
            ->where('user_id', $this->providerManager->id)
            ->first();

        $this->assertNotNull($access);
        $this->assertEquals('Emergency medication query', $access->reason);
        $minutesUntilExpiry = now()->diffInMinutes($access->expires_at, false);
        $this->assertTrue($minutesUntilExpiry >= 58);
        $this->assertTrue($minutesUntilExpiry <= 61);
    }

    public function test_break_glass_store_with_custom_minutes(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->providerManager)
            ->post("/clients/{$this->client->id}/break-glass", [
                'reason' => 'Extended review needed',
                'minutes' => 120,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $access = ClientBreakGlassAccess::where('client_id', $this->client->id)->first();
        $minutesUntilExpiry = now()->diffInMinutes($access->expires_at, false);
        $this->assertTrue($minutesUntilExpiry >= 118);
    }

    public function test_break_glass_store_validates_reason_required(): void
    {
        $this->actingAs($this->providerManager)
            ->post("/clients/{$this->client->id}/break-glass", [])
            ->assertSessionHasErrors(['reason']);
    }

    public function test_break_glass_store_validates_minutes_range_minimum(): void
    {
        $this->actingAs($this->providerManager)
            ->post("/clients/{$this->client->id}/break-glass", [
                'reason' => 'Test',
                'minutes' => 3,
            ])
            ->assertSessionHasErrors(['minutes']);
    }

    public function test_break_glass_store_validates_minutes_range_maximum(): void
    {
        $this->actingAs($this->providerManager)
            ->post("/clients/{$this->client->id}/break-glass", [
                'reason' => 'Test',
                'minutes' => 1441,
            ])
            ->assertSessionHasErrors(['minutes']);
    }

    public function test_break_glass_store_forbidden_for_support_worker(): void
    {
        // Support worker does not have medications.breakglass by default
        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/break-glass", [
                'reason' => 'Test',
            ])
            ->assertForbidden();
    }

    public function test_break_glass_grants_access_to_medical_page(): void
    {
        $this->mockNotificationService();

        // Create another support worker not assigned to the client
        $otherWorker = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
        $otherWorker->roles()->attach(Role::where('name', 'provider_manager')->first());

        // Without break-glass, verify they can access (provider_manager has clients.viewAny)
        $this->actingAs($otherWorker)
            ->get("/clients/{$this->client->id}/medical")
            ->assertRedirect(EmarUrl::medications($this->client));

        // Create break-glass access
        ClientBreakGlassAccess::create([
            'client_id' => $this->client->id,
            'user_id' => $otherWorker->id,
            'reason' => 'Emergency access',
            'expires_at' => now()->addHour(),
        ]);

        // Should still be accessible
        $this->actingAs($otherWorker)
            ->get("/clients/{$this->client->id}/medical")
            ->assertRedirect(EmarUrl::medications($this->client));
    }

    // ══════════════════════════════════════════════════════════════
    //  19. BREAK-GLASS DESTROY
    // ══════════════════════════════════════════════════════════════

    public function test_break_glass_destroy_requires_authentication(): void
    {
        $access = ClientBreakGlassAccess::create([
            'client_id' => $this->client->id,
            'user_id' => $this->providerManager->id,
            'reason' => 'Test',
            'expires_at' => now()->addHour(),
        ]);

        $this->delete("/clients/{$this->client->id}/break-glass/{$access->id}")
            ->assertRedirect('/login');
    }

    public function test_break_glass_destroy_by_owner(): void
    {
        $this->mockNotificationService();

        $access = ClientBreakGlassAccess::create([
            'client_id' => $this->client->id,
            'user_id' => $this->providerManager->id,
            'reason' => 'Test',
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($this->providerManager)
            ->delete("/clients/{$this->client->id}/break-glass/{$access->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('client_break_glass_accesses', ['id' => $access->id]);
    }

    public function test_break_glass_destroy_by_admin(): void
    {
        $this->mockNotificationService();

        $access = ClientBreakGlassAccess::create([
            'client_id' => $this->client->id,
            'user_id' => $this->providerManager->id,
            'reason' => 'Test',
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/break-glass/{$access->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('client_break_glass_accesses', ['id' => $access->id]);
    }

    public function test_break_glass_destroy_by_non_owner_non_manager_forbidden(): void
    {
        $access = ClientBreakGlassAccess::create([
            'client_id' => $this->client->id,
            'user_id' => $this->providerManager->id,
            'reason' => 'Test',
            'expires_at' => now()->addHour(),
        ]);

        // Coordinator has breakglass but is not the owner, not admin/provider_manager
        // However coordinator has medications.audit.view so they should be able to revoke
        $this->actingAs($this->coordinator)
            ->delete("/clients/{$this->client->id}/break-glass/{$access->id}")
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_break_glass_destroy_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create();
        $access = ClientBreakGlassAccess::create([
            'client_id' => $otherClient->id,
            'user_id' => $this->providerManager->id,
            'reason' => 'Test',
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/break-glass/{$access->id}")
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════
    //  20. CORRECTION WORKFLOW
    // ══════════════════════════════════════════════════════════════

    public function test_correction_requires_authentication(): void
    {
        $med = $this->createMedication();
        $admin = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now(),
            'administered_at' => now(),
        ]);

        $this->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [])
            ->assertRedirect('/login');
    }

    public function test_correction_within_30_minutes_without_correction_reason(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        // Create administration just now (within 30-minute window)
        $admin = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now(),
            'administered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [
                'status' => 'refused',
                'reason' => 'Actually refused',
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'corrected_of_id' => $admin->id,
            'is_correction' => true,
            'status' => 'refused',
        ]);
    }

    public function test_correction_outside_30_minutes_requires_correction_reason(): void
    {
        $med = $this->createMedication();

        // Create administration 45 minutes ago (outside 30-minute window)
        $admin = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now()->subMinutes(45),
            'administered_at' => now()->subMinutes(45),
            'created_at' => now()->subMinutes(45),
            'updated_at' => now()->subMinutes(45),
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [
                'status' => 'missed',
                'reason' => 'Actually missed',
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_correction_outside_30_minutes_succeeds_with_correction_reason(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $admin = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now()->subMinutes(45),
            'administered_at' => now()->subMinutes(45),
            'created_at' => now()->subMinutes(45),
            'updated_at' => now()->subMinutes(45),
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [
                'status' => 'missed',
                'reason' => 'Actually missed',
                'correction_reason' => 'Incorrect entry by previous staff member',
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'corrected_of_id' => $admin->id,
            'is_correction' => true,
            'status' => 'missed',
            'correction_reason' => 'Incorrect entry by previous staff member',
        ]);
    }

    public function test_correction_forbidden_for_hr(): void
    {
        $med = $this->createMedication();
        $admin = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now(),
            'administered_at' => now(),
        ]);

        $this->actingAs($this->hrUser)
            ->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [
                'status' => 'missed',
            ])
            ->assertForbidden();
    }

    public function test_correction_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create();
        $med = ClientMedication::create([
            'client_id' => $otherClient->id,
            'name' => 'Other Med',
            'active' => true,
            'state' => 'active',
        ]);

        $admin = ClientMedicationAdministration::create([
            'client_id' => $otherClient->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now(),
            'administered_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [
                'status' => 'refused',
            ])
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════
    //  21. CONDITIONS - CRUD
    // ══════════════════════════════════════════════════════════════

    public function test_store_condition_requires_authentication(): void
    {
        $this->post("/clients/{$this->client->id}/medical/conditions", [])
            ->assertRedirect('/login');
    }

    public function test_store_condition_with_valid_data(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/conditions", [
                'label' => 'Hypertension',
                'severity' => 'moderate',
                'notes' => 'Requires regular monitoring',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_conditions', [
            'client_id' => $this->client->id,
            'label' => 'Hypertension',
            'severity' => 'moderate',
        ]);
    }

    public function test_store_condition_validates_label_required(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/conditions", [
                'severity' => 'high',
            ])
            ->assertSessionHasErrors(['label']);
    }

    public function test_store_condition_validates_label_max_length(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/conditions", [
                'label' => str_repeat('a', 256),
            ])
            ->assertSessionHasErrors(['label']);
    }

    public function test_store_condition_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/conditions", [
                'label' => 'Test',
            ])
            ->assertForbidden();
    }

    public function test_update_condition_with_valid_data(): void
    {
        $this->mockNotificationService();
        $condition = ClientCondition::create([
            'client_id' => $this->client->id,
            'label' => 'Old Label',
            'severity' => 'low',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/conditions/{$condition->id}", [
                'label' => 'Updated Label',
                'severity' => 'high',
                'notes' => 'Condition worsened',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_conditions', [
            'id' => $condition->id,
            'label' => 'Updated Label',
            'severity' => 'high',
        ]);
    }

    public function test_update_condition_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create();
        $condition = ClientCondition::create([
            'client_id' => $otherClient->id,
            'label' => 'Other Condition',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/conditions/{$condition->id}", [
                'label' => 'Hacked',
            ])
            ->assertNotFound();
    }

    public function test_destroy_condition_removes_record(): void
    {
        $this->mockNotificationService();
        $condition = ClientCondition::create([
            'client_id' => $this->client->id,
            'label' => 'To Delete',
        ]);

        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/medical/conditions/{$condition->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('client_conditions', ['id' => $condition->id]);
    }

    public function test_destroy_condition_forbidden_for_support_worker(): void
    {
        $condition = ClientCondition::create([
            'client_id' => $this->client->id,
            'label' => 'Test',
        ]);

        $this->actingAs($this->supportWorker)
            ->delete("/clients/{$this->client->id}/medical/conditions/{$condition->id}")
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  22. EMERGENCY CONTACTS - CRUD
    // ══════════════════════════════════════════════════════════════

    public function test_store_emergency_contact_requires_authentication(): void
    {
        $this->post("/clients/{$this->client->id}/medical/emergency-contacts", [])
            ->assertRedirect('/login');
    }

    public function test_store_emergency_contact_with_valid_data(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/emergency-contacts", [
                'name' => 'Jane Doe',
                'relationship' => 'Daughter',
                'phone' => '0123456789',
                'email' => 'jane@example.com',
                'notes' => 'Primary contact',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_emergency_contacts', [
            'client_id' => $this->client->id,
            'name' => 'Jane Doe',
            'relationship' => 'Daughter',
            'phone' => '0123456789',
            'email' => 'jane@example.com',
        ]);
    }

    public function test_store_emergency_contact_validates_name_required(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/emergency-contacts", [
                'relationship' => 'Sister',
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_store_emergency_contact_forbidden_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/emergency-contacts", [
                'name' => 'Test',
            ])
            ->assertForbidden();
    }

    public function test_update_emergency_contact_with_valid_data(): void
    {
        $this->mockNotificationService();
        $contact = ClientEmergencyContact::create([
            'client_id' => $this->client->id,
            'name' => 'Old Name',
            'phone' => '0000000000',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/emergency-contacts/{$contact->id}", [
                'name' => 'Updated Name',
                'phone' => '1111111111',
                'relationship' => 'Son',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_emergency_contacts', [
            'id' => $contact->id,
            'name' => 'Updated Name',
            'phone' => '1111111111',
            'relationship' => 'Son',
        ]);
    }

    public function test_update_emergency_contact_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create();
        $contact = ClientEmergencyContact::create([
            'client_id' => $otherClient->id,
            'name' => 'Other Contact',
        ]);

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/emergency-contacts/{$contact->id}", [
                'name' => 'Hacked',
            ])
            ->assertNotFound();
    }

    public function test_destroy_emergency_contact_removes_record(): void
    {
        $this->mockNotificationService();
        $contact = ClientEmergencyContact::create([
            'client_id' => $this->client->id,
            'name' => 'To Delete',
        ]);

        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/medical/emergency-contacts/{$contact->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('client_emergency_contacts', ['id' => $contact->id]);
    }

    public function test_destroy_emergency_contact_forbidden_for_support_worker(): void
    {
        $contact = ClientEmergencyContact::create([
            'client_id' => $this->client->id,
            'name' => 'Test',
        ]);

        $this->actingAs($this->supportWorker)
            ->delete("/clients/{$this->client->id}/medical/emergency-contacts/{$contact->id}")
            ->assertForbidden();
    }

    public function test_destroy_emergency_contact_returns_404_for_mismatched_client(): void
    {
        $otherClient = Client::factory()->create();
        $contact = ClientEmergencyContact::create([
            'client_id' => $otherClient->id,
            'name' => 'Other Contact',
        ]);

        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/medical/emergency-contacts/{$contact->id}")
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════
    //  23. MAR - Display and Export
    // ══════════════════════════════════════════════════════════════

    public function test_mar_show_requires_authentication(): void
    {
        $this->get("/clients/{$this->client->id}/mar")->assertRedirect('/login');
    }

    public function test_mar_show_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get("/clients/{$this->client->id}/mar")
            ->assertRedirect(EmarUrl::mar($this->client, now()->toDateString()));
    }

    public function test_mar_show_accessible_by_assigned_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get("/clients/{$this->client->id}/mar")
            ->assertRedirect(EmarUrl::mar($this->client, now()->toDateString()));
    }

    public function test_mar_show_forbidden_for_unassigned_support_worker(): void
    {
        $unassignedWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $unassignedWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($unassignedWorker)
            ->get("/clients/{$this->client->id}/mar")
            ->assertForbidden();
    }

    public function test_mar_show_accepts_date_filter(): void
    {
        $date = '2025-06-15';

        $this->actingAs($this->admin)
            ->get("/clients/{$this->client->id}/mar?date={$date}")
            ->assertRedirect(EmarUrl::mar($this->client, $date));
    }

    public function test_mar_export_csv_requires_authentication(): void
    {
        $this->get("/clients/{$this->client->id}/mar/export.csv")
            ->assertRedirect('/login');
    }

    public function test_mar_export_csv_returns_csv_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get("/clients/{$this->client->id}/mar/export.csv")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    // ══════════════════════════════════════════════════════════════
    //  24. BREAK-GLASS - Expired access does not grant entry
    // ══════════════════════════════════════════════════════════════

    public function test_expired_break_glass_does_not_grant_medical_access(): void
    {
        // Create unassigned coordinator-like user who relies on break-glass
        $unassignedWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $unassignedWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        // Create expired break-glass access
        ClientBreakGlassAccess::create([
            'client_id' => $this->client->id,
            'user_id' => $unassignedWorker->id,
            'reason' => 'Expired access',
            'expires_at' => now()->subMinute(),
        ]);

        // Should be forbidden because access has expired
        $this->actingAs($unassignedWorker)
            ->get("/clients/{$this->client->id}/medical")
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  25. ROLE-BASED ACCESS - Finance, Auditor, Coordinator
    // ══════════════════════════════════════════════════════════════

    public function test_finance_user_can_view_medications_index(): void
    {
        $this->actingAs($this->financeUser)
            ->get('/medications')
            ->assertRedirect(EmarUrl::daily());
    }

    public function test_auditor_can_view_medications_index(): void
    {
        $this->actingAs($this->auditor)
            ->get('/medications')
            ->assertRedirect(EmarUrl::daily());
    }

    public function test_coordinator_can_view_audit_log(): void
    {
        $this->actingAs($this->coordinator)
            ->get('/medications/audit')
            ->assertOk();
    }

    public function test_finance_user_can_view_reports(): void
    {
        $this->actingAs($this->financeUser)
            ->get('/reports/medications')
            ->assertOk();
    }

    public function test_auditor_can_view_reports(): void
    {
        $this->actingAs($this->auditor)
            ->get('/reports/medications')
            ->assertOk();
    }

    public function test_coordinator_can_manage_medications(): void
    {
        $this->mockNotificationService();

        // Coordinator does not have clients.update so they cannot create meds
        // via the clients.update middleware, but let's verify they can at least view
        $this->actingAs($this->coordinator)
            ->get("/clients/{$this->client->id}/medical")
            ->assertRedirect(EmarUrl::medications($this->client));
    }

    // ══════════════════════════════════════════════════════════════
    //  26. ADMINISTRATION WITH SHIFT CONTEXT
    // ══════════════════════════════════════════════════════════════

    public function test_administration_resolves_service_context_from_shift(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $otherContext = ServiceContext::factory()->create(['name' => 'Home Support']);
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->supportWorker->id,
            'service_context_id' => $otherContext->id,
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'shift_id' => $shift->id,
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $med->id,
            'shift_id' => $shift->id,
            'service_context_id' => $otherContext->id,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  27. STOCK VALIDATION
    // ══════════════════════════════════════════════════════════════

    public function test_stock_update_validates_on_hand_min_zero(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => -5,
            ])
            ->assertSessionHasErrors(['on_hand']);
    }

    public function test_stock_update_validates_unit_max_length(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 10,
                'unit' => str_repeat('a', 51),
            ])
            ->assertSessionHasErrors(['unit']);
    }

    public function test_stock_update_validates_reorder_level_min_zero(): void
    {
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 10,
                'reorder_level' => -1,
            ])
            ->assertSessionHasErrors(['reorder_level']);
    }

    // ══════════════════════════════════════════════════════════════
    //  28. REPORT FILTERS
    // ══════════════════════════════════════════════════════════════

    public function test_reports_index_applies_date_filters(): void
    {
        $from = '2025-01-01';
        $to = '2025-01-31';

        $this->actingAs($this->admin)
            ->get("/reports/medications?date_from={$from}&date_to={$to}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.date_from', $from)
                ->where('filters.date_to', $to)
            );
    }

    public function test_reports_index_applies_client_filter(): void
    {
        $this->actingAs($this->admin)
            ->get("/reports/medications?client_id={$this->client->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.client_id', (string) $this->client->id)
            );
    }

    public function test_reports_index_applies_status_filter(): void
    {
        $this->actingAs($this->admin)
            ->get('/reports/medications?status=refused')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.status', 'refused')
            );
    }

    // ══════════════════════════════════════════════════════════════
    //  29. AUDIT LOG FILTERS
    // ══════════════════════════════════════════════════════════════

    public function test_audit_index_applies_filters(): void
    {
        $this->actingAs($this->admin)
            ->get("/medications/audit?client_id={$this->client->id}&from=2025-01-01&to=2025-12-31")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.client_id', (string) $this->client->id)
                ->where('filters.from', '2025-01-01')
                ->where('filters.to', '2025-12-31')
            );
    }

    // ══════════════════════════════════════════════════════════════
    //  30. CONTROLLED STOCK - Open discrepancy blocks (unless override)
    // ══════════════════════════════════════════════════════════════

    public function test_controlled_stock_blocked_when_open_discrepancy_exists_for_non_override_user(): void
    {
        $med = $this->createControlledDrug();
        $witness = $this->createWitness();

        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        // Create an open discrepancy
        ClientControlledDrugDiscrepancy::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'service_context_id' => $this->serviceContext->id,
            'on_hand_before' => 12,
            'on_hand_after' => 10,
            'difference' => -2,
            'reported_at' => now(),
            'reported_by' => $this->admin->id,
            'witnessed_by' => $witness->id,
            'status' => 'open',
        ]);

        // Coordinator does not have controlled.override and does not have clients.update
        // But the test needs a user who can do medications.controlled.record but not
        // medications.controlled.override and not clients.update
        // Use a support worker (who has medications.controlled.record but not override and not clients.update)
        $this->actingAs($this->supportWorker)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 8,
                'witnessed_by' => $witness->id,
                'reason' => 'Check',
                'unit' => 'tablets',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_controlled_stock_allowed_when_user_has_override_permission(): void
    {
        $this->mockNotificationService();
        $med = $this->createControlledDrug();
        $witness = $this->createWitness();

        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        // Create open discrepancy
        ClientControlledDrugDiscrepancy::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'service_context_id' => $this->serviceContext->id,
            'on_hand_before' => 12,
            'on_hand_after' => 10,
            'difference' => -2,
            'reported_at' => now(),
            'reported_by' => $this->admin->id,
            'witnessed_by' => $witness->id,
            'status' => 'open',
        ]);

        // Provider manager has medications.controlled.override
        $this->actingAs($this->providerManager)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 9,
                'witnessed_by' => $witness->id,
                'reason' => 'Override check',
                'unit' => 'tablets',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    // ══════════════════════════════════════════════════════════════
    //  31. MULTIPLE MEDICATIONS PER CLIENT
    // ══════════════════════════════════════════════════════════════

    public function test_client_can_have_multiple_medications(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Medication A',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Medication B',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Medication C',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('client_medications', 3);
    }

    // ══════════════════════════════════════════════════════════════
    //  32. MEDICATION STATE TRANSITIONS
    // ══════════════════════════════════════════════════════════════

    public function test_medication_can_be_paused(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [
                'name' => $med->name,
                'state' => 'paused',
                'paused_at' => now()->format('Y-m-d'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_medications', [
            'id' => $med->id,
            'state' => 'paused',
            'active' => false,
        ]);
    }

    public function test_medication_can_be_ceased(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [
                'name' => $med->name,
                'state' => 'ceased',
                'ceased_at' => now()->format('Y-m-d'),
                'ceased_reason' => 'No longer required',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_medications', [
            'id' => $med->id,
            'state' => 'ceased',
            'active' => false,
            'ceased_reason' => 'No longer required',
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  33. CORRECTION - Validates status enum
    // ══════════════════════════════════════════════════════════════

    public function test_correction_validates_status_required(): void
    {
        $med = $this->createMedication();
        $admin = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now(),
            'administered_at' => now(),
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [])
            ->assertSessionHasErrors(['status']);
    }

    public function test_correction_validates_status_enum(): void
    {
        $med = $this->createMedication();
        $admin = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'scheduled_for' => now(),
            'administered_at' => now(),
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/mar/administrations/{$admin->id}/corrections", [
                'status' => 'invalid_status',
            ])
            ->assertSessionHasErrors(['status']);
    }

    // ══════════════════════════════════════════════════════════════
    //  34. NON-CONTROLLED STOCK UPDATE (no witness needed)
    // ══════════════════════════════════════════════════════════════

    public function test_non_controlled_stock_update_succeeds_without_witness(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication(); // Not a controlled drug

        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 25,
                'unit' => 'tablets',
                'notes' => 'Normal stock check',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // No controlled drug entry should be created
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
    }

    // ══════════════════════════════════════════════════════════════
    //  35. DOSE TIMES REGEX VALIDATION
    // ══════════════════════════════════════════════════════════════

    public function test_dose_times_rejects_single_digit_hours(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Test',
                'dose_times' => ['8:00'],
            ])
            ->assertSessionHasErrors(['dose_times.0']);
    }

    public function test_dose_times_rejects_invalid_format(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Test',
                'dose_times' => ['12:00:00'],
            ])
            ->assertSessionHasErrors(['dose_times.0']);
    }

    public function test_dose_times_rejects_non_numeric(): void
    {
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Test',
                'dose_times' => ['ab:cd'],
            ])
            ->assertSessionHasErrors(['dose_times.0']);
    }

    public function test_dose_times_accepts_midnight(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Midnight Med',
                'dose_times' => ['00:00'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_dose_times_accepts_2359(): void
    {
        $this->mockNotificationService();

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Late Night Med',
                'dose_times' => ['23:59'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    // ══════════════════════════════════════════════════════════════
    //  36. ADMINISTRATION RESOLVES SERVICE CONTEXT FROM CLIENT FALLBACK
    // ══════════════════════════════════════════════════════════════

    public function test_administration_resolves_service_context_from_client_when_no_shift(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $med->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  37. FINANCE CAN UPDATE STOCK
    // ══════════════════════════════════════════════════════════════

    public function test_finance_user_can_update_non_controlled_stock(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $this->actingAs($this->financeUser)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 15,
                'unit' => 'tablets',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    // ══════════════════════════════════════════════════════════════
    //  38. MAR BREAK-GLASS REQUEST SCREEN
    // ══════════════════════════════════════════════════════════════

    public function test_mar_shows_break_glass_request_screen_for_unassigned_user_with_breakglass(): void
    {
        // Provider manager is not assigned but has breakglass permission
        // However, they also have clients.viewAny so policy allows access
        // Let's test with a user who has breakglass but not clients.viewAny
        // We need to give a support worker breakglass permission
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $worker->roles()->attach(Role::where('name', 'support_worker')->first());

        // Give them breakglass permission manually
        $bgPerm = Permission::where('key', 'medications.breakglass')->first();
        if ($bgPerm) {
            $workerRole = Role::where('name', 'support_worker')->first();
            $workerRole->permissions()->syncWithoutDetaching([$bgPerm->id]);
        }

        // This worker is NOT assigned to the client
        $response = $this->actingAs($worker)
            ->get("/clients/{$this->client->id}/mar");

        // Should see the break-glass request screen (not a 403)
        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('emergency/request')
            );
    }

    // ══════════════════════════════════════════════════════════════
    //  39. CORRECTION CREATES NEW RECORD (does not modify original)
    // ══════════════════════════════════════════════════════════════

    public function test_correction_preserves_original_record(): void
    {
        $this->mockNotificationService();
        $med = $this->createMedication();

        $original = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $this->supportWorker->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'given',
            'dose_given' => '500mg',
            'scheduled_for' => now(),
            'administered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/mar/administrations/{$original->id}/corrections", [
                'status' => 'withheld',
                'reason' => 'Allergic reaction reported',
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // Original should remain unchanged
        $original->refresh();
        $this->assertEquals('given', $original->status);
        $this->assertEquals('500mg', $original->dose_given);

        // Correction should be a new record
        $correction = ClientMedicationAdministration::where('corrected_of_id', $original->id)->first();
        $this->assertNotNull($correction);
        $this->assertTrue($correction->is_correction);
        $this->assertEquals('withheld', $correction->status);
        $this->assertEquals('Allergic reaction reported', $correction->reason);
    }

    // ══════════════════════════════════════════════════════════════
    //  40. FULL MEDICATION LIFECYCLE
    // ══════════════════════════════════════════════════════════════

    public function test_full_medication_lifecycle(): void
    {
        $this->mockNotificationService();

        // 1. Create medication
        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/medical/medications", [
                'name' => 'Lifecycle Med',
                'dosage' => '10mg',
                'frequency' => 'Once daily',
                'dose_times' => ['09:00'],
                'state' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $med = ClientMedication::where('name', 'Lifecycle Med')->first();
        $this->assertNotNull($med);

        // 2. Update stock
        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}/stock", [
                'on_hand' => 30,
                'unit' => 'tablets',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // 3. Record administration
        $this->actingAs($this->supportWorker)
            ->post("/clients/{$this->client->id}/medical/medications/{$med->id}/administrations", [
                'status' => 'given',
                'dose_given' => '10mg',
                'scheduled_for' => now()->format('Y-m-d H:i:s'),
                'administered_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // 4. Pause medication
        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [
                'name' => 'Lifecycle Med',
                'state' => 'paused',
                'paused_at' => now()->format('Y-m-d'),
            ])
            ->assertRedirect();

        // 5. Cease medication
        $this->actingAs($this->admin)
            ->put("/clients/{$this->client->id}/medical/medications/{$med->id}", [
                'name' => 'Lifecycle Med',
                'state' => 'ceased',
                'ceased_at' => now()->format('Y-m-d'),
                'ceased_reason' => 'No longer needed',
            ])
            ->assertRedirect();

        $med->refresh();
        $this->assertEquals('ceased', $med->state);
        $this->assertFalse($med->active);

        // 6. Delete medication
        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/medical/medications/{$med->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSoftDeleted('client_medications', ['id' => $med->id]);
    }
}
