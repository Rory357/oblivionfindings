<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
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
 * The redesigned medication-handovers page reuses the shared Operations
 * HandoverPresenter (so the payload matches the reused cards/rail/detail/wizard
 * contract), is week-scoped, resolves the active site's brand colour, and
 * enriches the catalogue clients with their active medication orders so the
 * wizard's "Medications due" step is MAR-bound.
 */
class MedicationHandoversTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_serves_brand_colour_and_mar_bound_catalogue(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'handovers.viewAny', 'handovers.create', 'shifts.update', 'shifts.manageAny', 'clients.update']);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active', 'first_name' => 'Aroha']);
        ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Quetiapine', 'dosage' => '25mg', 'frequency' => 'nocte',
            'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);

        $this->actingAs($user)
            ->get('/emar/handovers?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Handovers')
                ->where('site_brand_colour', '#5E35B1')
                ->has('handovers')
                ->has('weekStart')
                ->has('weekEnd')
                ->has('currentUser')
                ->has('catalogue.clients', 1)
                ->has('catalogue.clients.0.medications', 1)
                ->where('catalogue.clients.0.medications.0.name', 'Quetiapine')
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
