<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
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
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage']);
        $w1 = $this->makeRoleUser('coordinator');
        $w2 = $this->makeRoleUser('coordinator');

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Oxycodone', 'dosage' => '5mg', 'frequency' => 'PRN',
            'controlled_drug' => true, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);

        return compact('user', 'w1', 'w2', 'site', 'client');
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
        ['user' => $user, 'w1' => $w1, 'client' => $client] = $this->setupRegister();

        $this->actingAs($user)
            ->from('/emar/destructions')
            ->post('/emar/destructions', [
                'client_id' => $client->id,
                'medication_name' => 'Oxycodone',
                'quantity' => 2,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'denaturing',
                'is_controlled_drug' => true,
                'witness_1_id' => $w1->id,
                'witness_2_id' => $w1->id, // same person twice
                'authorised_by_name' => 'Pharmacist Pat',
            ])
            ->assertSessionHasErrors('witness_2_id');

        $this->assertSame(0, MedicationDestruction::count());
    }

    public function test_cd_destruction_records_with_two_distinct_witnesses(): void
    {
        ['user' => $user, 'w1' => $w1, 'w2' => $w2, 'client' => $client] = $this->setupRegister();

        $this->actingAs($user)
            ->from('/emar/destructions')
            ->post('/emar/destructions', [
                'client_id' => $client->id,
                'medication_name' => 'Oxycodone',
                'quantity' => 2,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'denaturing',
                'is_controlled_drug' => true,
                'witness_1_id' => $w1->id,
                'witness_2_id' => $w2->id,
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
