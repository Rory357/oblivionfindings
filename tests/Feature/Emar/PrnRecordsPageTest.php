<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned PRN Records page composes the shared meds-board PRN data
 * (prn_medications limits, clients, witnesses) with a flat register of recent
 * PRN-given administrations and the pending-effectiveness queue, plus the
 * active-site brand colour.
 */
class PrnRecordsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_serves_register_prn_meds_and_pending_reviews(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.administer.record', 'medications.controlled.witness']);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#00897B']);
        $client = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $site->id, 'status' => 'active']);
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Paracetamol PRN', 'dosage' => '500mg', 'frequency' => 'As needed',
            'is_prn' => true, 'prn_reason' => 'Pain', 'max_per_day' => 4, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $client->id, 'client_medication_id' => $med->id, 'administered_by' => $user->id,
            'administered_at' => now()->subHour(), 'status' => 'given', 'dose_given' => '500mg', 'reason' => 'Pain',
        ]);

        $this->actingAs($user)
            ->get('/emar/prn?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/PrnRecords')
                ->where('site_brand_colour', '#00897B')
                ->has('administrations', 1)
                ->where('administrations.0.medication_name', 'Paracetamol PRN')
                ->where('administrations.0.client_name', 'Aroha Ngata')
                ->has('prn_medications', 1)
                ->where('prn_medications.0.id', $med->id)
                ->has('pending_reviews', 1)
                ->where('pending_reviews.0.administration_id', fn ($v) => $v > 0)
                ->has('witnesses')
                ->has('clients', 1)
                ->has('sites', 1)
                ->where('sites.0.id', $site->id)
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
