<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\EnhancedMarService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the 2026-07 eMAR integrity audit:
 *  - CD administrations decrement stock by the recorded quantity (was
 *    hardcoded to 1) and write that quantity to the CD register entry.
 *  - CD destructions write a 'disposal' register exit entry and cannot
 *    destroy more than is on hand.
 *  - Medication-administrator competency fails closed for absent, incomplete,
 *    failed, and expired assessments.
 *  - Missed-dose incidents fire from the shared EnhancedMarService path, so
 *    every recording surface (My Day, guided rounds, MAR) raises them.
 */
class MedicationIntegrityAuditTest extends TestCase
{
    use RefreshDatabase;

    private function setupClinic(): array
    {
        $this->seed(RbacSeeder::class);

        $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::where('name', 'admin')->first()->id]);
        $this->recordValidCompetency($user);

        $witness = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->grantPermissions($witness, ['medications.controlled.witness']);

        $client = Client::factory()->create(['status' => 'active']);

        return compact('user', 'witness', 'client');
    }

    private function makeControlledMedication(Client $client, float $onHand = 10): ClientMedication
    {
        $medication = ClientMedication::create([
            'client_id' => $client->id,
            'name' => 'Morphine sulfate',
            'dosage' => '10mg',
            'frequency' => 'PRN',
            'is_prn' => true,
            'prn_reason' => 'Breakthrough pain',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => $onHand,
            'unit' => 'tablets',
        ]);

        return $medication->fresh('stock');
    }

    public function test_cd_administration_decrements_stock_by_recorded_quantity(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client] = $this->setupClinic();
        $medication = $this->makeControlledMedication($client, 10);

        $result = app(EnhancedMarService::class)->recordAdministration($client, $medication, [
            'status' => 'given',
            'reason' => 'Breakthrough pain',
            'quantity_administered' => 2,
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
        ], $user->id);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(8.0, (float) $medication->stock->fresh()->on_hand);

        $entry = ClientControlledDrugEntry::where('client_medication_id', $medication->id)->firstOrFail();
        $this->assertSame('administered', $entry->entry_type);
        $this->assertSame(2.0, (float) $entry->quantity);
        $this->assertSame(10.0, (float) $entry->on_hand_before);
        $this->assertSame(8.0, (float) $entry->on_hand_after);
    }

    public function test_cd_destruction_writes_disposal_register_entry(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client] = $this->setupClinic();
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage']);
        $medication = $this->makeControlledMedication($client, 10);
        $witness2 = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);

        $this->actingAs($user)
            ->from('/emar/destructions')
            ->post('/emar/destructions', [
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'medication_name' => 'Morphine sulfate',
                'quantity' => 4,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'denaturing',
                'is_controlled_drug' => true,
                'witness_1_id' => $witness->id,
                'witness_2_id' => $witness2->id,
                'authorised_by_name' => 'Pharmacist Pat',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(6.0, (float) $medication->stock->fresh()->on_hand);

        $entry = ClientControlledDrugEntry::where('client_medication_id', $medication->id)->firstOrFail();
        $this->assertSame('disposal', $entry->entry_type);
        $this->assertSame(4.0, (float) $entry->quantity);
        $this->assertSame(10.0, (float) $entry->on_hand_before);
        $this->assertSame(6.0, (float) $entry->on_hand_after);
        $this->assertSame($witness->id, $entry->witnessed_by);
    }

    public function test_cd_destruction_cannot_exceed_stock_on_hand(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client] = $this->setupClinic();
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage']);
        $medication = $this->makeControlledMedication($client, 3);
        $witness2 = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);

        $this->actingAs($user)
            ->from('/emar/destructions')
            ->post('/emar/destructions', [
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'medication_name' => 'Morphine sulfate',
                'quantity' => 20,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'denaturing',
                'is_controlled_drug' => true,
                'witness_1_id' => $witness->id,
                'witness_2_id' => $witness2->id,
                'authorised_by_name' => 'Pharmacist Pat',
            ])
            ->assertSessionHasErrors('quantity');

        // Rolled back: nothing recorded, stock untouched, no register entry.
        $this->assertSame(3.0, (float) $medication->stock->fresh()->on_hand);
        $this->assertSame(0, ClientControlledDrugEntry::count());
    }

    public function test_expired_competency_blocks_signing_a_dose_as_given(): void
    {
        ['user' => $user, 'client' => $client] = $this->setupClinic();
        $medication = ClientMedication::create([
            'client_id' => $client->id,
            'name' => 'Paracetamol',
            'dosage' => '500mg',
            'frequency' => 'PRN',
            'is_prn' => true,
            'prn_reason' => 'Pain',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        $user->medicationCompetencyAssessments()->delete();
        MedicationCompetencyAssessment::create([
            'user_id' => $user->id,
            'assessment_type' => 'initial',
            'status' => 'passed',
            'assessment_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
        ]);

        $result = app(EnhancedMarService::class)->recordAdministration($client, $medication, [
            'status' => 'given',
            'reason' => 'Pain',
        ], $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('competency expired', $result['error']);

        // Documentation (refused) is never blocked by competency.
        $refusal = app(EnhancedMarService::class)->recordAdministration($client, $medication, [
            'status' => 'refused',
            'reason' => 'Client declined',
            'reason_code' => 'refused',
        ], $user->id);

        $this->assertTrue($refusal['success'], $refusal['error'] ?? '');
    }

    public function test_no_competency_on_file_blocks_signing_a_dose_as_given(): void
    {
        ['user' => $user, 'client' => $client] = $this->setupClinic();
        $user->medicationCompetencyAssessments()->delete();
        $medication = ClientMedication::create([
            'client_id' => $client->id,
            'name' => 'Paracetamol',
            'dosage' => '500mg',
            'frequency' => 'PRN',
            'is_prn' => true,
            'prn_reason' => 'Pain',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        $result = app(EnhancedMarService::class)->recordAdministration($client, $medication, [
            'status' => 'given',
            'reason' => 'Pain',
        ], $user->id);

        $this->assertFalse($result['success']);
        $this->assertSame('unassessed', $result['competency_state']);
        $this->assertStringContainsString('No medication competency assessment', $result['error']);
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_passed_competency_without_expiry_blocks_signing_a_dose_as_given(): void
    {
        ['user' => $user, 'client' => $client] = $this->setupClinic();
        $user->medicationCompetencyAssessments()->delete();
        MedicationCompetencyAssessment::create([
            'user_id' => $user->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->toDateString(),
            'expiry_date' => null,
        ]);
        $medication = ClientMedication::create([
            'client_id' => $client->id,
            'name' => 'Paracetamol',
            'dosage' => '500mg',
            'frequency' => 'PRN',
            'is_prn' => true,
            'prn_reason' => 'Pain',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        $result = app(EnhancedMarService::class)->recordAdministration($client, $medication, [
            'status' => 'given',
            'reason' => 'Pain',
        ], $user->id);

        $this->assertFalse($result['success']);
        $this->assertSame('missing_expiry', $result['competency_state']);
        $this->assertStringContainsString('no expiry date', $result['error']);
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_missed_dose_raises_incident_from_shared_service_path(): void
    {
        ['user' => $user, 'client' => $client] = $this->setupClinic();
        $medication = ClientMedication::create([
            'client_id' => $client->id,
            'name' => 'Metformin',
            'dosage' => '500mg',
            'frequency' => 'Once daily',
            'dose_times' => ['09:00'],
            'controlled_drug' => false,
            'high_risk' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        $result = app(EnhancedMarService::class)->recordAdministration($client, $medication, [
            'status' => 'missed',
            'reason' => 'Client asleep, could not wake safely',
            'reason_code' => 'absent',
        ], $user->id);

        $this->assertTrue($result['success'], $result['error'] ?? '');

        $incident = ClientIncident::where('client_id', $client->id)->first();
        $this->assertNotNull($incident, 'Expected a missed-dose incident from the shared service path.');
        $this->assertStringContainsString('Metformin', $incident->title);
    }

    public function test_expired_covert_authorisation_blocks_administration(): void
    {
        ['user' => $user, 'client' => $client] = $this->setupClinic();
        $medication = ClientMedication::create([
            'client_id' => $client->id,
            'name' => 'Quetiapine',
            'dosage' => '25mg',
            'frequency' => 'Once daily',
            'dose_times' => ['20:00'],
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        \App\Models\MedicationCovertAuthorisation::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'authorised_by_name' => 'Dr Smith',
            'clinical_justification' => 'Best-interest decision, MDT agreed.',
            'authorised_date' => now()->subMonths(6)->toDateString(),
            'review_date' => now()->subDay()->toDateString(),
            'status' => 'active',
            'recorded_by' => $user->id,
        ]);

        $result = app(EnhancedMarService::class)->recordAdministration($client, $medication, [
            'status' => 'given',
        ], $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Covert administration is not authorised', $result['error']);

        // A current covert authorisation permits administration.
        \App\Models\MedicationCovertAuthorisation::query()->update(['review_date' => now()->addMonths(3)->toDateString()]);

        $ok = app(EnhancedMarService::class)->recordAdministration($client, $medication->fresh(), [
            'status' => 'given',
        ], $user->id);

        $this->assertTrue($ok['success'], $ok['error'] ?? '');
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

    private function recordValidCompetency(User $user): void
    {
        MedicationCompetencyAssessment::create([
            'user_id' => $user->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);
    }
}
