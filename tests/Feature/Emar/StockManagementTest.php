<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned Stock Management page resolves the active site's brand colour,
 * exposes a controlled-drug reconciliation feed, and supports cold-chain
 * (storage condition) on the stock row.
 */
class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    private function seedStock(): array
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage']);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Morphine sulfate', 'dosage' => '10mg', 'frequency' => 'PRN',
            'controlled_drug' => true, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $med->id, 'on_hand' => 12, 'unit' => 'tablets', 'reorder_level' => 5,
        ]);

        return compact('user', 'site', 'client', 'med', 'stock');
    }

    public function test_page_serves_brand_colour_and_controlled_register(): void
    {
        ['user' => $user, 'site' => $site] = $this->seedStock();

        $this->actingAs($user)
            ->get('/emar/stock?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/StockManagement')
                ->where('site_brand_colour', '#5E35B1')
                ->has('stockItems', 1)
                ->has('controlledRegister', 1)
                ->where('controlledRegister.0.register_balance', 12) // falls back to on-hand when no balance check
                ->has('pharmacyOrders')
                ->has('sites')
            );
    }

    public function test_update_stock_item_sets_storage_condition(): void
    {
        ['user' => $user, 'stock' => $stock] = $this->seedStock();

        $this->actingAs($user)
            ->from('/emar/stock')
            ->patch('/emar/stock/'.$stock->id, ['storage_condition' => 'fridge', 'reorder_level' => 8])
            ->assertSessionHasNoErrors();

        $stock->refresh();
        $this->assertSame('fridge', $stock->storage_condition);
        $this->assertTrue($stock->requiresColdChain());
        $this->assertSame(8, (int) $stock->reorder_level);
    }

    public function test_adjust_stock_updates_on_hand_with_reason(): void
    {
        ['user' => $user, 'med' => $med, 'stock' => $stock] = $this->seedStock();

        $this->actingAs($user)
            ->from('/emar/stock')
            ->post('/emar/stock/adjust', ['client_medication_id' => $med->id, 'new_quantity' => 7, 'reason' => 'Physical stock count'])
            ->assertSessionHasNoErrors();

        $this->assertSame(7, (int) $stock->refresh()->on_hand);
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
