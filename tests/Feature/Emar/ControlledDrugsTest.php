<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned Controlled Drugs page resolves the active site's brand colour,
 * and the CD register now enforces balance integrity: for a directional movement
 * the new running balance must reconcile to prior ± the signed quantity (gap 1),
 * on top of the existing mandatory non-self witness.
 */
class ControlledDrugsTest extends TestCase
{
    use RefreshDatabase;

    private function setupCd(): array
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.controlled.record', 'medications.controlled.witness', 'clients.update']);
        $witness = $this->makeRoleUser('coordinator');
        $this->grantPermissions($witness, ['medications.controlled.witness']);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Morphine sulfate', 'dosage' => '10mg', 'frequency' => 'PRN',
            'controlled_drug' => true, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);

        return compact('user', 'witness', 'site', 'client', 'med');
    }

    public function test_cd_entry_rejects_unreconciled_balance(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client] = $this->setupCd();

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/entries', [
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'entry_type' => 'administration',
                'quantity' => 2,
                'on_hand_before' => 10,
                'on_hand_after' => 9, // should be 8
                'witnessed_by' => $witness->id,
            ])
            ->assertSessionHasErrors('on_hand_after');

        $this->assertSame(0, ClientControlledDrugEntry::count());
    }

    public function test_cd_entry_accepts_reconciled_balance(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client] = $this->setupCd();

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/entries', [
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'entry_type' => 'administration',
                'quantity' => 2,
                'on_hand_before' => 10,
                'on_hand_after' => 8,
                'witnessed_by' => $witness->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ClientControlledDrugEntry::count());
    }

    public function test_page_serves_brand_colour(): void
    {
        ['user' => $user, 'site' => $site] = $this->setupCd();

        $this->actingAs($user)
            ->get('/emar/controlled?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/ControlledDrugs')
                ->where('site_brand_colour', '#5E35B1')
                ->has('medications', 1)
                ->has('recentEntries')
                ->has('staff')
            );
    }

    public function test_page_exposes_reconciliation_fields_filters_and_current_user(): void
    {
        ['user' => $user] = $this->setupCd();

        $this->actingAs($user)
            ->get('/emar/controlled')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/ControlledDrugs')
                ->has('medications.0', fn (Assert $m) => $m
                    ->where('controlled_drug', true)
                    ->where('overdue_check', true)
                    ->has('last_balance_check_at')
                    ->has('days_since_check')
                    ->has('stock')
                    ->etc()
                )
                ->where('current_user.id', $user->id)
                ->has('date')
                ->has('today')
                ->where('is_today', true)
                ->has('client_id')
                ->has('q')
            );
    }

    public function test_client_filter_scopes_medications(): void
    {
        ['user' => $user, 'client' => $client] = $this->setupCd();
        $other = Client::factory()->create(['site_id' => $client->site_id, 'status' => 'active']);

        $this->actingAs($user)
            ->get('/emar/controlled?client_id='.$other->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('medications', 0)
                ->where('client_id', $other->id)
            );
    }

    public function test_date_param_scopes_movements_window(): void
    {
        ['user' => $user] = $this->setupCd();

        $this->actingAs($user)
            ->get('/emar/controlled?date=2020-01-01')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('date', '2020-01-01')
                ->where('is_today', false)
                ->has('recentEntries', 0)
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
