<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationRefusalFollowup;
use App\Models\MedicationSyringeDriver;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Round-3 governance/export audit (2026-07-02): correction two-person rule,
 * CD-witness authorisation parity, PDF per-client authorisation, and CSV
 * formula-injection neutralisation.
 */
class MedicationGovernanceAuditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::where('name', 'admin')->first()->id]);

        return $user;
    }

    public function test_correction_creator_cannot_approve_their_own_correction(): void
    {
        $user = $this->admin();
        $this->grantPermissions($user, ['medications.administer.correct']);
        [$site, $client] = $this->siteClientFor($user);
        $administrator = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $medication = ClientMedication::create([
            'client_id' => $client->id, 'name' => 'Paracetamol', 'dosage' => '500mg',
            'frequency' => 'Once daily', 'active' => true, 'state' => 'active',
        ]);

        $original = ClientMedicationAdministration::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $administrator->id,
            'status' => 'given',
            'administered_at' => now(),
        ]);
        $correction = ClientMedicationAdministration::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $administrator->id,
            'status' => 'given',
            'is_correction' => true,
            'corrected_of_id' => $original->id,
            'correction_requested_by' => $user->id,
            'correction_status' => 'pending',
            'administered_at' => now(),
        ]);

        // The person who raised the correction is blocked from approving it.
        $this->actingAs($user)
            ->from('/emar/mar')
            ->post("/emar/corrections/{$correction->id}/approve")
            ->assertSessionHas('error');

        $this->assertSame('pending', $correction->fresh()->correction_status);

        // A different authorised approver can approve it.
        $approver = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $approver->roles()->syncWithoutDetaching([Role::where('name', 'admin')->first()->id]);
        $this->grantPermissions($approver, ['medications.administer.correct']);
        $this->assignStaffToSite($approver, $site);

        $this->actingAs($approver)
            ->from('/emar/mar')
            ->post("/emar/corrections/{$correction->id}/approve")
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $correction->fresh()->correction_status);
    }

    public function test_cd_register_entry_rejects_unauthorised_witness(): void
    {
        $user = $this->admin();
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage', 'medications.controlled.record']);
        [$site, $client] = $this->siteClientFor($user);
        $medication = ClientMedication::create([
            'client_id' => $client->id, 'name' => 'Morphine', 'dosage' => '10mg',
            'frequency' => 'PRN', 'is_prn' => true, 'controlled_drug' => true,
            'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        // A witness with no controlled-witness permission is rejected.
        $badWitness = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->assignStaffToSite($badWitness, $site);
        $payload = [
            'client_medication_id' => $medication->id,
            'client_id' => $client->id,
            'medication_name' => $medication->name,
            'entry_type' => 'administration',
            'quantity' => 1,
            'on_hand_before' => 10,
            'on_hand_after' => 9,
            'witness_credential' => 'password',
        ];

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/entries', [
                ...$payload,
                'witnessed_by' => $badWitness->id,
            ])
            ->assertNotFound();
        $this->assertSame('10.00', $stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);

        // A properly authorised witness is accepted.
        $goodWitness = User::factory()->create([
            'role' => 'coordinator',
            'approved_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $this->grantPermissions($goodWitness, ['medications.controlled.witness']);
        $this->assignStaffToSite($goodWitness, $site);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $goodWitness->id,
            'assessor_id' => $user->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today()->subMonth(),
            'expiry_date' => today()->addYear(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_witness_controlled' => true,
        ]);
        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $client->service_context_id,
            'user_id' => $goodWitness->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'status' => 'in_progress',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/entries', [
                ...$payload,
                'witnessed_by' => $goodWitness->id,
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('9.00', $stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
    }

    public function test_csv_cell_neutralises_formula_injection(): void
    {
        $sanitizer = new class
        {
            use SanitizesCsvOutput {
                sanitizeCsvCell as public;
            }
        };

        $this->assertSame("'=1+1", $sanitizer->sanitizeCsvCell('=1+1'));
        $this->assertSame("'+SUM(A1)", $sanitizer->sanitizeCsvCell('+SUM(A1)'));
        $this->assertSame("'-2+3", $sanitizer->sanitizeCsvCell('-2+3'));
        $this->assertSame("'@cmd", $sanitizer->sanitizeCsvCell('@cmd'));
        $this->assertSame("' =danger", $sanitizer->sanitizeCsvCell(' =danger'));
        // Ordinary values pass through untouched.
        $this->assertSame('Paracetamol 500mg', $sanitizer->sanitizeCsvCell('Paracetamol 500mg'));
        $this->assertSame('Smith, John', $sanitizer->sanitizeCsvCell('Smith, John'));
        // Numeric cells (negative numbers, phone numbers) are left as-is.
        $this->assertSame('-5.00', $sanitizer->sanitizeCsvCell('-5.00'));
        $this->assertSame(42, $sanitizer->sanitizeCsvCell(42));
        $this->assertNull($sanitizer->sanitizeCsvCell(null));
    }

    public function test_refusal_followup_completion_requires_and_stores_an_outcome(): void
    {
        $user = $this->admin();
        $this->grantPermissions($user, ['medications.administer.correct']);
        [, $client] = $this->siteClientFor($user);
        $medication = ClientMedication::create([
            'client_id' => $client->id, 'name' => 'Paracetamol', 'dosage' => '500mg',
            'frequency' => 'PRN', 'is_prn' => true, 'active' => true, 'state' => 'active',
        ]);
        $administration = ClientMedicationAdministration::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $user->id,
            'status' => 'refused',
            'administered_at' => now(),
        ]);
        $followup = MedicationRefusalFollowup::create([
            'client_id' => $client->id,
            'client_medication_administration_id' => $administration->id,
            'reason_category' => 'personal_choice',
            'created_by' => $user->id,
        ]);

        // Completion without an outcome is rejected.
        $this->actingAs($user)
            ->from('/emar/prn')
            ->post("/emar/refusal-followups/{$followup->id}/complete", [])
            ->assertSessionHasErrors('outcome');

        $this->assertNull($followup->fresh()->follow_up_completed_at);

        // With an outcome it completes and the outcome is stored.
        $this->actingAs($user)
            ->from('/emar/prn')
            ->post("/emar/refusal-followups/{$followup->id}/complete", [
                'outcome' => 'GP reviewed; dose moved to evening with food.',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $followup->fresh();
        $this->assertNotNull($fresh->follow_up_completed_at);
        $this->assertSame('GP reviewed; dose moved to evening with food.', $fresh->follow_up_outcome);
    }

    public function test_syringe_driver_cannot_complete_without_a_recorded_check(): void
    {
        $user = $this->admin();
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage']);
        [, $client] = $this->siteClientFor($user);
        $medication = ClientMedication::create([
            'client_id' => $client->id,
            'name' => 'Morphine sulfate',
            'dosage' => '30mg',
            'frequency' => 'Continuous',
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        $driver = MedicationSyringeDriver::create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'status' => 'running',
            'commenced_at' => now()->subHours(6),
            'commenced_by' => $user->id,
            'contents' => [[
                'client_medication_id' => $medication->id,
                'name' => $medication->name,
                'dose' => '30mg',
                'requires_witness' => false,
            ]],
        ]);

        // No checks recorded → completion refused.
        $this->actingAs($user)
            ->from('/emar/mar')
            ->post("/emar/syringe-drivers/{$driver->id}/complete", ['status' => 'completed'])
            ->assertSessionHasErrors('status');

        $this->assertSame('running', $driver->fresh()->status);

        // After a check it can complete.
        $this->actingAs($user)
            ->from('/emar/mar')
            ->post("/emar/syringe-drivers/{$driver->id}/checks", ['infusion_running' => true])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->from('/emar/mar')
            ->post("/emar/syringe-drivers/{$driver->id}/complete", ['status' => 'completed'])
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $driver->fresh()->status);
    }

    public function test_alert_suppression_requires_a_structured_basis(): void
    {
        $user = $this->admin();
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage']);
        [, $client] = $this->siteClientFor($user);

        // Suppressing without a basis is rejected.
        $this->actingAs($user)
            ->from('/emar/mar')
            ->post("/emar/clients/{$client->id}/alert-suppression", [
                'suppress_med_admin_alerts' => true,
                'reason' => 'Independent self-manager',
            ])
            ->assertSessionHasErrors('basis');

        // With a basis the suppression stores a structured, auditable reason.
        $this->actingAs($user)
            ->from('/emar/mar')
            ->post("/emar/clients/{$client->id}/alert-suppression", [
                'suppress_med_admin_alerts' => true,
                'reason' => 'Independent self-manager',
                'basis' => 'capacity_assessment',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $client->fresh();
        $this->assertTrue((bool) $fresh->suppress_med_admin_alerts);
        $this->assertSame('[Capacity assessment] Independent self-manager', $fresh->med_alerts_suppressed_reason);
    }

    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }

    /** @return array{0: Site, 1: Client} */
    private function siteClientFor(User $user): array
    {
        $site = Site::factory()->create([
            'type' => 'house',
            'is_active' => true,
        ]);
        $this->assignStaffToSite($user, $site);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);

        return [$site, $client];
    }

    private function assignStaffToSite(User $user, Site $site): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subYear(),
            'end_date' => null,
        ]);
    }
}
