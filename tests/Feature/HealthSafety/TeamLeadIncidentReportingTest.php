<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Team leads report incidents (Stephan, 30 Oct): they read the clients at their own sites (clients.viewAny, site
 * limited) and report from the standalone page. The incident register stays closed to them.
 */
class TeamLeadIncidentReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_a_team_lead_reports_from_the_standalone_page_for_clients_at_their_sites_only(): void
    {
        [$lead, $site] = $this->teamLeadAt();
        $own = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Aroha', 'last_name' => 'Here']);
        $elsewhere = Client::factory()->create(['site_id' => Site::factory()->create(['is_active' => true])->id]);

        $this->actingAs($lead)
            ->get('/incidents/create?return_to=/my-day&client_id='.$own->id)
            ->assertRedirect(route('incidents.report', ['report' => 'incident', 'return_to' => '/my-day', 'report_client_id' => $own->id]));

        $this->get(route('incidents.report', ['report_client_id' => $own->id, 'return_to' => '/my-day']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('incidents/report')
                ->where('mode', 'incident')
                ->where('canOpenIncident', false)
                ->where('returnUrl', '/my-day')
                ->where('defaults.client_id', $own->id)
                ->where('defaults.site_id', $site->id)
                ->has('clients', 1)
                ->where('clients.0.id', $own->id)
                ->has('sites', 1)
                ->where('sites.0.id', $site->id));

        // A person at another site is neither offered nor prefilled.
        $this->get(route('incidents.report', ['report_client_id' => $elsewhere->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('defaults.client_id', null)
                ->has('clients', 1));

        // The register stays closed.
        $this->get('/incidents')->assertForbidden();
    }

    public function test_a_team_lead_can_save_a_report_for_their_site_but_not_another(): void
    {
        [$lead, $site] = $this->teamLeadAt();
        $own = Client::factory()->create(['site_id' => $site->id]);
        $elsewhere = Client::factory()->create(['site_id' => Site::factory()->create(['is_active' => true])->id]);
        $report = fn (Client $client) => [
            'intent' => 'submit', 'client_id' => $client->id, 'type' => 'fall', 'severity' => 'low',
            'occurred_at' => now()->subHour()->toDateTimeString(), 'description' => 'Slipped in the hallway; no injury.', 'stay' => true,
        ];

        // The wizard saves with stay, so the reporter lands back on the report page.
        $this->actingAs($lead)->from('/incidents/report')->post('/incidents', $report($own))
            ->assertSessionHasNoErrors()->assertRedirect('/incidents/report');
        $this->assertDatabaseHas('client_incidents', ['client_id' => $own->id, 'reported_by' => $lead->id, 'status' => 'submitted']);

        $this->from('/incidents/report')->post('/incidents', $report($elsewhere))->assertForbidden();
        $this->assertDatabaseMissing('client_incidents', ['client_id' => $elsewhere->id]);
    }

    public function test_people_who_can_open_the_register_still_report_there(): void
    {
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $worker->roles()->attach(Role::where('name', 'support_worker')->sole());

        $this->actingAs($worker->fresh())->get('/incidents/create')
            ->assertRedirect(route('incidents.index', ['report' => 'incident']));
    }

    public function test_the_migration_grants_team_leads_exactly_reporting_and_client_reading(): void
    {
        $role = Role::where('name', 'team_lead')->sole();
        $keys = ['incidents.create', 'clients.viewAny'];
        DB::table('role_permission')->where('role_id', $role->id)
            ->whereIn('permission_id', Permission::whereIn('key', $keys)->pluck('id'))->delete();
        $before = DB::table('role_permission')->where('role_id', $role->id)->count();

        (require database_path('migrations/2026_10_30_110000_grant_team_lead_incident_reporting.php'))->up();

        $this->assertSame($before + 2, DB::table('role_permission')->where('role_id', $role->id)->count());
        $granted = $role->permissions()->pluck('key')->all();
        $this->assertContains('incidents.create', $granted);
        $this->assertContains('clients.viewAny', $granted);
        $this->assertNotContains('incidents.viewAny', $granted);
        $this->assertNotContains('incidents.viewAssigned', $granted);
    }

    /** @return array{0: User, 1: Site} */
    private function teamLeadAt(): array
    {
        $site = Site::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', 'team_lead')->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'position_role' => 'team_lead',
            'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);

        return [$user->fresh(), $site];
    }
}
