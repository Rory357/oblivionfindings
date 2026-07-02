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
