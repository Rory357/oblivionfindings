<?php

namespace Tests\Feature\Emar;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $client = Client::factory()->create();
        $medication = ClientMedication::create([
            'client_id' => $client->id, 'name' => 'Paracetamol', 'dosage' => '500mg',
            'frequency' => 'Once daily', 'active' => true, 'state' => 'active',
        ]);

        $correction = ClientMedicationAdministration::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $user->id,
            'status' => 'given',
            'is_correction' => true,
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
        $client = Client::factory()->create();
        ClientMedication::create([
            'client_id' => $client->id, 'name' => 'Morphine', 'dosage' => '10mg',
            'frequency' => 'PRN', 'is_prn' => true, 'controlled_drug' => true,
            'active' => true, 'state' => 'active',
        ]);

        // A witness with no controlled-witness permission is rejected.
        $badWitness = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/entries', [
                'client_id' => $client->id,
                'medication_name' => 'Morphine',
                'entry_type' => 'balance_check',
                'quantity' => 5,
                'witnessed_by' => $badWitness->id,
            ])
            ->assertSessionHasErrors('witnessed_by');

        // A properly authorised witness is accepted.
        $goodWitness = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->grantPermissions($goodWitness, ['medications.controlled.witness']);

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/entries', [
                'client_id' => $client->id,
                'medication_name' => 'Morphine',
                'entry_type' => 'balance_check',
                'quantity' => 5,
                'witnessed_by' => $goodWitness->id,
            ])
            ->assertSessionHasNoErrors();
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
        $client = Client::factory()->create();
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
        $followup = \App\Models\MedicationRefusalFollowup::create([
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
        $client = Client::factory()->create();

        $driver = \App\Models\MedicationSyringeDriver::create([
            'client_id' => $client->id,
            'status' => 'running',
            'commenced_at' => now()->subHours(6),
            'commenced_by' => $user->id,
            'contents' => [['medication' => 'Morphine sulfate', 'dose' => '30mg']],
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
        $client = Client::factory()->create();

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
}
