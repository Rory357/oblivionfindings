<?php

namespace Tests\Feature\Checklists;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChecklistsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supportWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->seed(\Database\Seeders\SitesModuleSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    public function test_seeder_builds_full_category_library(): void
    {
        $this->assertSame(9, SiteChecklistTemplate::whereNotNull('category')->distinct('category')->count('category'));
        $this->assertGreaterThanOrEqual(38, SiteChecklistTemplate::count());
    }

    public function test_org_dashboard_returns_expected_props(): void
    {
        $this->actingAs($this->admin)
            ->get('/checklists')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('checklists/index')
                ->has('categories', 9)
                ->has('templates')
                ->has('activeRuns')
                ->has('reports.trend', 8)
                ->has('reports.complianceByCategory', 9)
                ->has('stats.onTrack')
                ->where('can.view', true)
                ->where('runDetail', null));
    }

    public function test_org_dashboard_requires_view_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/checklists')
            ->assertForbidden();
    }

    public function test_per_site_dashboard_scopes_runs_to_the_site(): void
    {
        $siteA = Site::factory()->create(['type' => 'house']);
        $siteB = Site::factory()->create(['type' => 'house']);
        $template = SiteChecklistTemplate::where('key', 'quality_home_checklist')->firstOrFail();

        $this->makeRun($siteA, $template);
        $this->makeRun($siteB, $template);

        $this->actingAs($this->admin)
            ->get("/sites/{$siteA->id}/checklists")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sites/checklists/index')
                ->where('site.id', $siteA->id)
                ->has('categories', 9)
                ->has('activeRuns', 1)
                ->where('activeRuns.0.site.id', $siteA->id));
    }

    public function test_run_query_param_returns_run_detail(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $template = SiteChecklistTemplate::where('key', 'quality_home_checklist')->firstOrFail();
        $run = $this->makeRun($site, $template);

        $this->actingAs($this->admin)
            ->get("/checklists?run={$run->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('runDetail.id', $run->id)
                ->where('runDetail.template.name', $template->name)
                ->has('runDetail.items', $template->items()->count()));
    }

    private function makeRun(Site $site, SiteChecklistTemplate $template): SiteChecklistRun
    {
        $assignment = SiteChecklistAssignment::create([
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'template_id' => $template->id,
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        return SiteChecklistRun::create([
            'assignment_id' => $assignment->id,
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'template_id' => $template->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);
    }
}
