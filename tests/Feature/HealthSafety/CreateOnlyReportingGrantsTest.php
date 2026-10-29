<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Create-only reporting grants (MASTER for Stephan, 30 Oct; one yes/no for Stephan): team leads and H&S officers raise
 * safeguarding concerns, support workers report restraints and workplace injuries. Each grant opens a form; none adds
 * reading beyond today's rules. Team leads' incident reporting (with client reading) is TeamLeadIncidentReportingTest.
 */
class CreateOnlyReportingGrantsTest extends TestCase
{
    use RefreshDatabase;

    private const READ_KEYS = ['incidents.viewAny', 'incidents.viewAssigned', 'safeguarding.viewAny', 'restraints.view', 'hr.wellbeing.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_the_seeded_roles_gain_only_create_keys(): void
    {
        $keys = fn (string $role) => Role::where('name', $role)->sole()->permissions()->pluck('key')->all();

        $lead = $keys('team_lead');
        $this->assertContains('safeguarding.create', $lead);
        foreach (['incidents.viewAny', 'incidents.viewAssigned', 'safeguarding.viewAny'] as $notGranted) {
            $this->assertNotContains($notGranted, $lead, 'team_lead must not gain '.$notGranted);
        }

        $officer = $keys('health_safety_officer');
        $this->assertContains('safeguarding.create', $officer);
        $this->assertNotContains('safeguarding.viewAny', $officer);

        $worker = $keys('support_worker');
        $this->assertContains('restraints.report', $worker);
        $this->assertContains('injuries.report', $worker);
        foreach (['restraints.view', 'restraints.create', 'hazards.view', 'hazards.create', 'hr.wellbeing.view'] as $wider) {
            $this->assertNotContains($wider, $worker, 'support_worker must not gain '.$wider);
        }
    }

    public function test_a_team_lead_can_raise_a_safeguarding_concern_but_not_read_the_register(): void
    {
        $lead = $this->staff('team_lead');

        $this->actingAs($lead)->get('/safeguarding/create')->assertOk();
        $this->from('/safeguarding/create')->post('/safeguarding', [
            'subject_type' => 'other', 'subject_name' => 'A visitor', 'concern_type' => 'neglect',
            'severity' => 'medium', 'description' => 'Saw a visitor shouting at a resident in the garden.',
        ])->assertRedirect('/safeguarding/create');
        $this->assertDatabaseHas('safeguarding_concerns', ['reported_by_user_id' => $lead->id]);

        // Raising a concern opens no register.
        $this->get('/safeguarding')->assertForbidden();
        $this->get('/incidents')->assertForbidden();
    }

    public function test_an_hs_officer_can_raise_a_safeguarding_concern_but_not_read_the_register(): void
    {
        $officer = $this->staff('health_safety_officer');

        $this->actingAs($officer)->get('/safeguarding/create')->assertOk();
        $this->get('/safeguarding')->assertForbidden();
    }

    public function test_the_migration_grants_exactly_these_keys_and_adds_no_reading(): void
    {
        $roles = ['support_worker', 'team_lead', 'health_safety_officer'];
        $grants = ['support_worker' => ['restraints.report', 'injuries.report'], 'team_lead' => ['safeguarding.create'], 'health_safety_officer' => ['safeguarding.create']];
        $roleIds = Role::whereIn('name', $roles)->pluck('id', 'name');
        $before = fn () => DB::table('role_permission')->whereIn('role_id', $roleIds)->count();
        // Start from the roles as they were: without the decision's grants.
        foreach ($grants as $role => $keys) {
            DB::table('role_permission')->where('role_id', $roleIds[$role])
                ->whereIn('permission_id', Permission::whereIn('key', $keys)->pluck('id'))->delete();
        }
        $readBefore = DB::table('role_permission')->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', Permission::whereIn('key', self::READ_KEYS)->pluck('id'))->count();
        $countBefore = $before();

        (require database_path('migrations/2026_10_30_090000_grant_create_only_reporting.php'))->up();

        $this->assertSame($countBefore + 4, $before(), 'Four role grants: 2 support worker, 1 team lead, 1 H&S officer.');
        $this->assertSame($readBefore, DB::table('role_permission')->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', Permission::whereIn('key', self::READ_KEYS)->pluck('id'))->count());
    }

    private function staff(string $role): User
    {
        return $this->staffAt($role)[0];
    }

    /** @return array{0: User, 1: Site} */
    private function staffAt(string $role): array
    {
        $site = Site::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'position_role' => $role,
            'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);

        return [$user->fresh(), $site];
    }
}
