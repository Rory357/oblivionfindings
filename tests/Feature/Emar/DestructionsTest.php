<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationDestruction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned Destructions page is an immutable disposal register (MoD Regs
 * 1977): erroneous records are voided — kept, struck through, with a reason —
 * never hard-deleted. Controlled-drug destructions require two distinct
 * witnesses plus authorisation, and the page resolves the active site's brand
 * colour.
 */
class DestructionsTest extends TestCase
{
    use RefreshDatabase;

    private function setupRegister(): array
    {
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage', 'medications.controlled.view', 'medications.controlled.record']);
        $w1 = $this->makeRoleUser('coordinator');
        $w2 = $this->makeRoleUser('coordinator');
        $this->grantPermissions($w1, ['medications.controlled.witness']);
        $this->grantPermissions($w2, ['medications.controlled.witness']);
        foreach ([$user, $w1, $w2] as $staffMember) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $staffMember->id,
                'primary_site_id' => $site->id,
                'is_active' => true,
                'start_date' => now()->subYear()->toDateString(),
                'end_date' => null,
            ]);
        }

        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Oxycodone', 'dosage' => '5mg', 'frequency' => 'PRN',
            'controlled_drug' => true, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);

        return compact('user', 'w1', 'w2', 'site', 'client', 'med');
    }

    private function record(array $overrides = []): MedicationDestruction
    {
        return MedicationDestruction::create(array_merge([
            'client_id' => $overrides['client_id'] ?? Client::factory()->create()->id,
            'medication_name' => 'Oxycodone',
            'quantity' => 4,
            'unit' => 'tablets',
            'reason' => 'expired',
            'disposal_method' => 'denaturing',
            'destroyed_by' => $overrides['destroyed_by'] ?? User::factory()->create()->id,
            'witness_1_id' => $overrides['witness_1_id'] ?? User::factory()->create()->id,
            'destroyed_at' => now(),
            'is_controlled_drug' => true,
        ], $overrides));
    }

    public function test_void_marks_record_voided_not_deleted(): void
    {
        ['user' => $user, 'client' => $client] = $this->setupRegister();
        $rec = $this->record(['client_id' => $client->id]);

        $this->actingAs($user)
            ->from('/emar/destructions')
            ->post("/emar/destructions/{$rec->id}/void", ['void_reason' => 'Duplicate entry'])
            ->assertSessionHasNoErrors();

        // Retained (not deleted) and superseded.
        $this->assertSame(1, MedicationDestruction::count());
        $rec->refresh();
        $this->assertNotNull($rec->voided_at);
        $this->assertSame('Duplicate entry', $rec->void_reason);
        $this->assertSame($user->id, $rec->voided_by);
    }

    public function test_void_is_administrative_only_and_records_explicit_reconciliation_provenance(): void
    {
        ['user' => $user, 'w1' => $witness, 'client' => $client, 'med' => $medication] = $this->setupRegister();
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 8.5,
            'unit' => 'tablets',
        ]);
        $entry = ClientControlledDrugEntry::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'entry_type' => 'destruction',
            'quantity' => 0.5,
            'unit' => 'tablets',
            'on_hand_before' => 9,
            'on_hand_after' => 8.5,
            'recorded_by' => $user->id,
            'witnessed_by' => $witness->id,
            'recorded_at' => now(),
        ]);
        $record = $this->record([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'site_id' => $client->site_id,
            'quantity' => 0.5,
        ]);

        $this->actingAs($user)
            ->post(route('emar.destructions.void', $record), ['void_reason' => 'Duplicate administrative record'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Destruction record voided. Stock and register balances were not changed; record any correction through witnessed reconciliation.');

        $this->assertSame(8.5, (float) $stock->refresh()->on_hand);
        $this->assertDatabaseHas('client_controlled_drug_entries', ['id' => $entry->id]);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $audit = AuditLog::query()->where('action', 'medications.destruction.void')->latest('id')->firstOrFail();
        $this->assertSame(MedicationDestruction::VOID_STOCK_SEMANTICS, $audit->meta['void_stock_semantics'] ?? null);
        $this->assertFalse((bool) ($audit->meta['stock_effect_reversed'] ?? true));
        $this->assertTrue((bool) ($audit->meta['requires_governed_stock_reconciliation'] ?? false));
    }

    public function test_void_requires_a_reason(): void
    {
        ['user' => $user, 'client' => $client] = $this->setupRegister();
        $rec = $this->record(['client_id' => $client->id]);

        $this->actingAs($user)
            ->from('/emar/destructions')
            ->post("/emar/destructions/{$rec->id}/void", ['void_reason' => ''])
            ->assertSessionHasErrors('void_reason');

        $this->assertNull($rec->refresh()->voided_at);
    }

    public function test_cd_destruction_rejects_duplicate_witnesses(): void
    {
        ['user' => $user, 'w1' => $w1, 'client' => $client, 'med' => $med] = $this->setupRegister();

        $this->actingAs($user)
            ->from('/emar/destructions')
            ->post('/emar/destructions', [
                'client_id' => $client->id,
                'client_medication_id' => $med->id,
                'medication_name' => 'Oxycodone',
                'quantity' => 2,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'denaturing',
                'is_controlled_drug' => true,
                'witness_1_id' => $w1->id,
                'witness_1_credential' => 'password',
                'witness_2_id' => $w1->id, // same person twice
                'witness_2_credential' => 'password',
                'authorised_by_name' => 'Pharmacist Pat',
            ])
            ->assertSessionHasErrors('witness_2_id');

        $this->assertSame(0, MedicationDestruction::count());
    }

    public function test_cd_destruction_records_with_two_distinct_witnesses(): void
    {
        ['user' => $user, 'w1' => $w1, 'w2' => $w2, 'client' => $client, 'med' => $med] = $this->setupRegister();

        $this->actingAs($user)
            ->from('/emar/destructions')
            ->post('/emar/destructions', [
                'client_id' => $client->id,
                'client_medication_id' => $med->id,
                'medication_name' => 'Oxycodone',
                'quantity' => 2,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'denaturing',
                'is_controlled_drug' => true,
                'witness_1_id' => $w1->id,
                'witness_1_credential' => 'password',
                'witness_2_id' => $w2->id,
                'witness_2_credential' => 'password',
                'authorised_by_name' => 'Pharmacist Pat',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, MedicationDestruction::count());
    }

    public function test_page_serves_brand_colour_and_payload(): void
    {
        ['user' => $user, 'site' => $site] = $this->setupRegister();

        $this->actingAs($user)
            ->get('/emar/destructions?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Destructions')
                ->where('site_brand_colour', '#5E35B1')
                ->has('medications', 1)
                ->has('destructions')
                ->has('sites')
                ->has('staff')
                // The hero footer Client EntityFilter (Gap A) is driven by `clients`.
                ->has('clients')
            );
    }

    /**
     * The read-only detail modal (Gap B) and the immutable-register UI render
     * entirely from the page payload: it must carry the witnesses, authoriser,
     * controlled-drug flags, human labels and the full void trail so a record can
     * be inspected without an extra round-trip. (Backend gap BK1.)
     */
    public function test_payload_carries_detail_fields_for_voided_cd_record(): void
    {
        ['user' => $user, 'w1' => $w1, 'w2' => $w2, 'client' => $client] = $this->setupRegister();
        $rec = $this->record([
            'client_id' => $client->id,
            'witness_1_id' => $w1->id,
            'witness_2_id' => $w2->id,
            'authorised_by_name' => 'Pharmacist Pat',
            'reason' => 'expired',
            'disposal_method' => 'denaturing',
        ]);

        // Void it so the payload also exercises the void trail (immutable: retained).
        $this->actingAs($user)
            ->from('/emar/destructions')
            ->post("/emar/destructions/{$rec->id}/void", ['void_reason' => 'Wrong quantity recorded'])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->get('/emar/destructions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Destructions')
                ->where('destructions.0.witness_1_name', $w1->name)
                ->where('destructions.0.witness_2_name', $w2->name)
                ->where('destructions.0.authorised_by_name', 'Pharmacist Pat')
                ->where('destructions.0.is_controlled_drug', true)
                ->where('destructions.0.reason_label', 'Expired')
                ->where('destructions.0.disposal_method_label', 'Denaturing')
                ->where('destructions.0.is_voided', true)
                ->where('destructions.0.void_stock_semantics', MedicationDestruction::VOID_STOCK_SEMANTICS)
                ->where('destructions.0.requires_governed_stock_reconciliation', true)
                ->where('destructions.0.void_reason', 'Wrong quantity recorded')
                ->where('destructions.0.voided_by_name', $user->name)
            );
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
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
