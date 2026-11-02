<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\RestraintEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentAccessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Q21 (Stephan, 2 Nov 2026). Real seeded roles, so the RbacSeeder lines are what is proved:
 * - team lead: incidents at their own Site only, read-only;
 * - provider manager: restraints at their own Sites only, review/approval unchanged;
 * - compliance lead: a read-only incident register (all Sites, through their existing H&S all-Sites grant) and reporting.
 */
final class RoleQ21AccessTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;
    private Site $siteB;
    private ClientIncident $incidentA;
    private ClientIncident $incidentB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->siteA = Site::factory()->create(['is_active' => true]);
        $this->siteB = Site::factory()->create(['is_active' => true]);
        $this->incidentA = $this->incident($this->siteA);
        $this->incidentB = $this->incident($this->siteB);
    }

    private function person(string $role, Site $site): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now(), 'email_verified_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);

        return $user->fresh();
    }

    private function incident(Site $site): ClientIncident
    {
        return ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $site->id, 'client_id' => Client::factory()->create(['site_id' => $site->id])->id,
            'status' => 'submitted', 'submitted_at' => now(), 'occurred_at' => now(), 'type' => 'fall', 'is_hr_confidential' => false,
        ]));
    }

    private function restraint(Site $site, User $by): RestraintEvent
    {
        return RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->create([
            'client_id' => Client::factory()->create(['site_id' => $site->id])->id, 'site_id' => $site->id,
            'created_by' => $by->id, 'updated_by' => $by->id, 'injury_occurred' => false, 'within_support_plan' => false,
        ]));
    }

    public function test_a_team_lead_reads_incidents_at_their_own_site_only_and_gains_no_write(): void
    {
        $lead = $this->person('team_lead', $this->siteA);
        $access = app(IncidentAccessService::class);

        $this->assertTrue($lead->canDo('incidents.viewAny'));
        $this->assertTrue($access->canView($lead, $this->incidentA));
        $this->assertFalse($access->canView($lead, $this->incidentB), 'Another Site\'s incident stays hidden.');
        $this->actingAs($lead)->get('/incidents/'.$this->incidentA->id)->assertOk();
        $this->assertContains($this->actingAs($lead)->get('/incidents/'.$this->incidentB->id)->status(), [403, 404]);

        foreach (['incidents.update', 'incidents.approve', 'incidents.owner.assign', 'incidents.owner.hold', 'incidents.reopen', 'incidents.followups.manage'] as $key) {
            $this->assertFalse($lead->canDo($key), "Team leads must not gain {$key}.");
        }
        $this->assertFalse($lead->can('update', $this->incidentA));
        $this->actingAs($lead)->post('/incidents/'.$this->incidentA->id.'/owner', [
            'owner_user_id' => $lead->id, 'expected_revision' => 0, 'request_uuid' => (string) \Illuminate\Support\Str::uuid(), 'reason' => 'Q21 test',
        ])->assertForbidden();
    }

    public function test_a_provider_manager_reads_restraints_at_their_sites_only_and_review_keys_are_unchanged(): void
    {
        $manager = $this->person('provider_manager', $this->siteA);
        $author = $this->person('team_lead', $this->siteA);
        $here = $this->restraint($this->siteA, $author);
        $elsewhere = $this->restraint($this->siteB, $author);

        $this->assertTrue($manager->canDo('restraints.view'));
        $this->assertFalse($manager->canDo('healthSafety.viewAllSites'), 'Only this grant would widen restraint reads.');
        $this->actingAs($manager)->get('/health-safety/restraints/events/'.$here->id)->assertOk();
        $this->assertContains($this->actingAs($manager)->get('/health-safety/restraints/events/'.$elsewhere->id)->status(), [403, 404]);

        foreach (['restraints.review', 'restraints.approve', 'restraints.manage', 'restraints.create'] as $key) {
            $this->assertFalse($manager->canDo($key), "Provider managers must not gain {$key}.");
        }
    }

    public function test_a_compliance_lead_reads_the_incident_register_and_reports_but_cannot_change_an_incident(): void
    {
        $lead = $this->person('compliance_lead', $this->siteA);
        $access = app(IncidentAccessService::class);

        $this->assertTrue($lead->canDo('incidents.viewAny'));
        $this->assertTrue($lead->canDo('incidents.create'));
        $this->assertTrue($access->canView($lead, $this->incidentA));
        $this->assertTrue($access->canView($lead, $this->incidentB), 'Audits read every Site through healthSafety.viewAllSites.');
        $this->actingAs($lead)->get('/incidents')->assertOk();
        $this->actingAs($lead)->get('/incidents/'.$this->incidentB->id)->assertOk();
        $this->actingAs($lead)->get('/incidents/report')->assertOk();

        foreach (['incidents.update', 'incidents.approve', 'incidents.owner.assign', 'incidents.owner.hold', 'incidents.reopen', 'incidents.followups.manage', 'incidents.submit'] as $key) {
            $this->assertFalse($lead->canDo($key), "Compliance leads must not gain {$key}.");
        }
        $this->assertFalse($lead->can('update', $this->incidentA));
        $this->assertFalse($lead->can('review', $this->incidentA));
        $this->actingAs($lead)->post('/incidents/'.$this->incidentA->id.'/owner', [
            'owner_user_id' => $lead->id, 'expected_revision' => 0, 'request_uuid' => (string) \Illuminate\Support\Str::uuid(), 'reason' => 'Q21 test',
        ])->assertForbidden();
    }
}
