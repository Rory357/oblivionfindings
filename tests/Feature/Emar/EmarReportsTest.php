<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationError;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Canonical reports retain scheduled-dose outcomes, current factual error
 * stages, stock evidence, exact report grants and approved Site scope.
 */
class EmarReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_hub_reports_refusal_error_stages_stock_and_controlled_medicine_picker(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.reports.view', 'medications.reports.export']);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Paracetamol', 'dosage' => '500mg', 'frequency' => 'TDS',
            'active' => true, 'state' => 'active', 'approval_status' => 'verified',
            'controlled_drug' => false, 'is_prn' => false, 'dose_times' => ['08:00'], 'start_date' => '2026-06-01',
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
            'reason_code' => 'R1', 'administered_by' => $user->id,
            'scheduled_for' => Carbon::parse('2026-06-15 08:00', 'Pacific/Auckland')->utc(),
            'administered_at' => Carbon::parse('2026-06-15 08:05', 'Pacific/Auckland')->utc(),
        ]);
        foreach (['reported', 'investigating', 'resolved'] as $status) {
            MedicationError::query()->create([
                'client_id' => $client->id,
                'error_type' => 'documentation',
                'severity' => 'near_miss',
                'description' => 'Report lifecycle metric fixture.',
                'status' => $status,
                'reported_by' => $user->id,
                'reported_at' => now(),
            ]);
        }

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00', 'Pacific/Auckland')->utc());
        $this->actingAs($user)
            ->get(route('emar.reports', ['site_id' => $site->id, 'report' => 'doses', 'period' => 'today']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/reports/hub')
                ->where('data.totals.refused', 1)
                ->has('sites')
            );
        $this->actingAs($user)
            ->get(route('emar.reports', ['site_id' => $site->id, 'report' => 'errors', 'period' => 'today']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('page.data', 3)->where('data.totals.open', 3)
            ->where('page.data', fn ($rows) => collect($rows)->pluck('status')->sort()->values()->all() === ['actions', 'investigating', 'triage']));
        $this->actingAs($user)
            ->get(route('emar.reports', ['site_id' => $site->id, 'report' => 'stock', 'period' => 'today']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('page.data.0.on_hand', 9.5));
        $this->actingAs($user)
            ->getJson(route('emar.reports.medicines', ['client_id' => $client->id]))
            ->assertOk()->assertJsonCount(1, 'medicines')->assertJsonPath('medicines.0.label', 'Oxycodone');
    }

    public function test_page_csv_and_api_reports_intersect_requested_site_with_canonical_access(): void
    {
        // A fixed NZ day from the start, so the worker's HR profile is current
        // on it (C6h: the report's doses are that day's scheduled doses).
        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $siteA = Site::factory()->create(['name' => 'Report Site A', 'is_active' => true]);
        $siteB = Site::factory()->create(['name' => 'Report Site B', 'is_active' => true]);
        $user = $this->makeRoleUser('support_worker');
        $this->grantPermissions($user, ['medications.view', 'medications.reports.view', 'medications.reports.export']);
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
        // One scheduled dose each (08:00), entered at the start of the day and
        // given: the report's dose numbers are the scheduled doses due (C6h).
        foreach ([[$clientA, 'Site A medicine'], [$clientB, 'Site B medicine']] as [$client, $name]) {
            $medication = ClientMedication::create([
                'client_id' => $client->id,
                'name' => $name,
                'dose_times' => ['08:00'],
                'frequency' => 'Daily',
                'is_prn' => false,
                'start_date' => '2026-06-01',
                'active' => true,
                'state' => 'active',
                'approval_status' => 'verified',
            ]);
            ClientMedicationAdministration::create([
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'status' => 'given',
                'administered_by' => $user->id,
                'scheduled_for' => Carbon::parse('2026-06-15 08:00', 'Pacific/Auckland')->utc(),
                'administered_at' => Carbon::parse('2026-06-15 08:05', 'Pacific/Auckland')->utc(),
            ]);
        }
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00', 'Pacific/Auckland')->utc());

        $this->actingAs($user)
            ->get('/emar/reports?period=today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.totals.due', 1)
                ->has('people', 1)
                ->where('people.0.id', $clientA->id)
                ->has('sites', 1));
        $this->actingAs($user)
            ->get('/emar/reports?site_id='.$siteB->id)
            ->assertNotFound();
        $this->actingAs($user)
            ->post('/emar/reports/export', ['type' => 'doses', 'site_id' => $siteB->id, 'purpose' => 'care'])
            ->assertNotFound();
        $this->actingAs($user)
            ->get('/emar/reports/export-mar?purpose=care&site_id='.$siteB->id)
            ->assertNotFound();
        $this->actingAs($user)
            ->get('/emar/reports/export-controlled-discrepancies?purpose=care&site_id='.$siteB->id)
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
            ->get('/reports/medications/export-mar?purpose=care')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Site A medicine', $legacyMarCsv);
        $this->assertStringNotContainsString('Site B medicine', $legacyMarCsv);
        $this->actingAs($user)
            ->get('/reports/medications/export-mar?purpose=care&site_id='.$siteB->id)
            ->assertNotFound();
        $this->actingAs($user)
            ->get('/reports/medications/export-controlled-discrepancies?purpose=care&site_id='.$siteB->id)
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
        $this->grantPermissions($user, ['medications.view', 'medications.reports.view', 'medications.reports.export', 'sites.viewAll']);
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

    public function test_report_capability_is_independent_from_medication_workflow_view_across_page_csv_and_api_routes(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('support_worker');
        $view = Permission::query()->where('key', 'medications.view')->firstOrFail();
        $this->grantPermissions($user, ['medications.reports.view', 'medications.reports.export']);
        $user->permissionOverrides()->syncWithoutDetaching([$view->id => ['allowed' => false]]);

        $this->actingAs($user)->get('/emar')->assertForbidden();
        $this->actingAs($user)
            ->get('/emar/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.totals.due', 0)
                ->has('people', 0)
                ->has('sites', 0));
        $this->actingAs($user)
            ->get('/reports/medications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('administrations', 0)
                ->has('discrepancies', 0)
                ->has('clients', 0));
        $this->actingAs($user)
            ->getJson('/emar/reports/export-mar?purpose=care')
            ->assertUnprocessable();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/medications/reports')
            ->assertOk()
            ->assertJsonPath('meta.total_records', 0)
            ->assertJsonCount(0, 'records');
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
        $this->grantPermissions($actor, ['medications.view', 'medications.reports.view', 'medications.reports.export']);
        foreach (['clinical.accessAllSites', 'sites.viewAll'] as $globalPermission) {
            $permission = Permission::query()->where('key', $globalPermission)->firstOrFail();
            $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        }

        $this->actingAs($actor)
            ->get('/emar/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.totals.due', 0)
                ->has('people', 0)
                ->has('sites', 0));
        $this->actingAs($actor)
            ->get('/reports/medications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('administrations', 0)
                ->has('discrepancies', 0)
                ->has('clients', 0));
        $this->actingAs($actor)
            ->getJson('/reports/medications/export-mar?purpose=care')
            ->assertUnprocessable();
        $this->assertDatabaseCount('medication_events', 0);
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
