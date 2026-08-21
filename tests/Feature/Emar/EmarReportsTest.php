<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
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
        $this->grantPermissions($user, ['medications.view', 'reports.viewAny']);

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
        ClientMedicationStock::query()->create([
            'client_medication_id' => $med->id,
            'on_hand' => 9.5,
            'reorder_level' => 5,
            'unit' => 'tablets',
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
                ->where('stockStatus.list.0.on_hand', 9.5)
                ->has('adminSummary')
                ->has('sites')
            );
    }

    public function test_page_csv_and_api_reports_intersect_requested_site_with_canonical_access(): void
    {
        $this->seed(RbacSeeder::class);
        $siteA = Site::factory()->create(['name' => 'Report Site A', 'is_active' => true]);
        $siteB = Site::factory()->create(['name' => 'Report Site B', 'is_active' => true]);
        $user = $this->makeRoleUser('support_worker');
        $this->grantPermissions($user, ['medications.view', 'medications.reports.export', 'reports.viewAny']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $siteA->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        $clientA = Client::factory()->create(['site_id' => $siteA->id, 'status' => 'active']);
        $clientB = Client::factory()->create(['site_id' => $siteB->id, 'status' => 'active']);
        foreach ([[$clientA, 'Site A medicine'], [$clientB, 'Site B medicine']] as [$client, $name]) {
            $medication = ClientMedication::create([
                'client_id' => $client->id,
                'name' => $name,
                'active' => true,
                'state' => 'active',
                'approval_status' => 'verified',
            ]);
            ClientMedicationAdministration::create([
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'status' => 'given',
                'administered_by' => $user->id,
                'administered_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->get('/emar/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('adminSummary.total', 1)
                ->has('clients', 1)
                ->where('clients.0.id', $clientA->id)
                ->has('sites', 1));
        $this->actingAs($user)
            ->get('/emar/reports?site_id='.$siteB->id)
            ->assertNotFound();
        $this->actingAs($user)
            ->get('/emar/reports/export?report_type=administration&site_id='.$siteB->id)
            ->assertNotFound();
        $this->actingAs($user)
            ->get('/emar/reports/export-mar?site_id='.$siteB->id)
            ->assertNotFound();
        $this->actingAs($user)
            ->get('/emar/reports/export-controlled-discrepancies?site_id='.$siteB->id)
            ->assertNotFound();

        $legacyPage = $this->actingAs($user)
            ->get('/reports/medications')
            ->assertOk();
        $legacyPage->assertInertia(fn (Assert $page) => $page
            ->component('reports/medications')
            ->has('clients', 1)
            ->where('clients.0.id', $clientA->id)
            ->has('administrations', 1));

        $legacyMarCsv = $this->actingAs($user)
            ->get('/reports/medications/export-mar')
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('Site A medicine', $legacyMarCsv);
        $this->assertStringNotContainsString('Site B medicine', $legacyMarCsv);
        $this->actingAs($user)
            ->get('/reports/medications/export-mar?site_id='.$siteB->id)
            ->assertNotFound();
        $this->actingAs($user)
            ->get('/reports/medications/export-controlled-discrepancies?site_id='.$siteB->id)
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/medications/reports?type=mar')
            ->assertOk()
            ->assertJsonPath('meta.total_records', 1)
            ->assertJsonPath('records.0.client_id', $clientA->id);
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/medications/reports?type=mar&site_id='.$siteB->id)
            ->assertNotFound();
    }

    public function test_explicit_global_site_permission_broadens_report_scope_only_with_report_capability(): void
    {
        $this->seed(RbacSeeder::class);
        $siteA = Site::factory()->create(['is_active' => true]);
        $siteB = Site::factory()->create(['is_active' => true]);
        $user = $this->makeRoleUser('support_worker');
        $this->grantPermissions($user, ['medications.view', 'reports.viewAny', 'sites.viewAll']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $siteA->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
        ]);
        Client::factory()->create(['site_id' => $siteA->id]);
        Client::factory()->create(['site_id' => $siteB->id]);

        $this->actingAs($user)
            ->get('/emar/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('sites', 2));
        $this->actingAs($user)
            ->get('/reports/medications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('clients', 2));
    }

    public function test_report_capability_without_medication_view_is_denied_across_page_csv_and_api_routes(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('support_worker');
        $view = Permission::query()->where('key', 'medications.view')->firstOrFail();
        $this->grantPermissions($user, ['reports.viewAny']);
        $user->permissionOverrides()->syncWithoutDetaching([$view->id => ['allowed' => false]]);

        $this->actingAs($user)->get('/emar/reports')->assertForbidden();
        $this->actingAs($user)->get('/reports/medications')->assertForbidden();
        $this->actingAs($user)->get('/emar/reports/export-mar')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/medications/reports')->assertForbidden();
    }

    public function test_report_actor_with_no_allowed_sites_receives_an_empty_scope_not_an_unscoped_report(): void
    {
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $medication = ClientMedication::create([
            'client_id' => $client->id,
            'name' => 'Concealed report medication',
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        ClientMedicationAdministration::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'status' => 'given',
            'administered_by' => User::factory()->create()->id,
            'administered_at' => now(),
        ]);
        $actor = $this->makeRoleUser('support_worker');
        $this->grantPermissions($actor, ['medications.view', 'medications.reports.export']);
        foreach (['clinical.accessAllSites', 'sites.viewAll'] as $globalPermission) {
            $permission = Permission::query()->where('key', $globalPermission)->firstOrFail();
            $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        }

        $this->actingAs($actor)
            ->get('/emar/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('adminSummary.total', 0)
                ->has('clients', 0)
                ->has('sites', 0));
        $this->actingAs($actor)
            ->get('/reports/medications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('administrations', 0)
                ->has('discrepancies', 0)
                ->has('clients', 0));
        $legacyCsv = $this->actingAs($actor)
            ->get('/reports/medications/export-mar')
            ->assertOk()
            ->streamedContent();
        $this->assertStringNotContainsString('Concealed report medication', $legacyCsv);
        $this->actingAs($actor, 'sanctum')
            ->getJson('/api/medications/reports?type=mar')
            ->assertOk()
            ->assertJsonPath('meta.total_records', 0)
            ->assertJsonCount(0, 'records');
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
