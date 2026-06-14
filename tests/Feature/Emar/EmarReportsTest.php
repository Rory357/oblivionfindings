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
 * The redesigned Reports page resolves the active site's brand colour, adds a
 * coded-reason breakdown (refusal / clinical / omission classes) for not-given
 * doses, and exposes controlled medications in the CdMedication shape so the
 * Controlled-drugs tab can reuse the shared Report-CD-loss modal.
 */
class EmarReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_serves_brand_colour_reasons_and_cd_medications(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['reports.viewAny']);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Paracetamol', 'dosage' => '500mg', 'frequency' => 'TDS',
            'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Oxycodone', 'dosage' => '5mg', 'frequency' => 'PRN',
            'controlled_drug' => true, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        // A refused dose with a coded reason → one "refusal" class in the breakdown.
        ClientMedicationAdministration::query()->create([
            'client_id' => $client->id, 'client_medication_id' => $med->id, 'status' => 'refused',
            'reason_code' => 'R1', 'administered_by' => $user->id, 'administered_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/emar/reports?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Reports')
                ->where('site_brand_colour', '#5E35B1')
                ->where('reasonBreakdown.by_class.refusal', 1)
                ->has('cdMedications', 1)
                ->where('cdMedications.0.name', 'Oxycodone')
                ->has('adminSummary')
                ->has('sites')
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
