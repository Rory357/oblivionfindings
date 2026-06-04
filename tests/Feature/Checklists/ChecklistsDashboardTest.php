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

    public function test_admin_can_create_template_with_items_via_builder(): void
    {
        $key = 'builder_test_'.uniqid();
        $payload = [
            'key' => $key,
            'name' => 'Builder Test Checklist',
            'description' => 'Created via the in-page builder',
            'category' => 'health_safety',
            'applicable_to_type' => 'house',
            'frequency' => 'weekly',
            'is_active' => true,
            'requires_photo' => true,
            'requires_signature' => false,
            'items' => [
                ['question' => 'Alarms sound', 'response_type' => 'yes_no', 'is_required' => true, 'failure_creates_hazard' => true, 'response_config' => null, 'guidance' => null],
                ['question' => 'Fridge temp', 'response_type' => 'numeric', 'is_required' => true, 'failure_creates_hazard' => true, 'response_config' => ['min' => 2, 'max' => 8, 'unit' => '°C'], 'guidance' => 'Record the reading'],
            ],
        ];

        $this->actingAs($this->admin)->post('/sites/checklists/templates', $payload)->assertRedirect();

        $template = SiteChecklistTemplate::where('key', $key)->firstOrFail();
        $this->assertSame('health_safety', $template->category);
        $this->assertTrue((bool) ($template->settings['requires_photo'] ?? false));
        $this->assertSame(2, $template->items()->count());
        $numeric = $template->items()->where('response_type', 'numeric')->firstOrFail();
        $this->assertSame(8.0, (float) ($numeric->response_config['max'] ?? null));
        $this->assertTrue($numeric->failure_creates_hazard);
    }

    public function test_admin_can_update_template_and_sync_items(): void
    {
        $template = SiteChecklistTemplate::where('key', 'quality_home_checklist')->firstOrFail();
        $firstItem = $template->items()->orderBy('sort_order')->firstOrFail();

        $this->actingAs($this->admin)->put('/sites/checklists/templates/'.$template->id, [
            'name' => 'Renamed Quality Checklist',
            'category' => 'property_facilities',
            'applicable_to_type' => 'house',
            'frequency' => 'monthly',
            'is_active' => true,
            'requires_photo' => true,
            'requires_signature' => true,
            'items' => [
                ['id' => $firstItem->id, 'question' => 'Updated question', 'response_type' => $firstItem->response_type, 'is_required' => true, 'failure_creates_hazard' => false, 'response_config' => null, 'guidance' => null],
                ['question' => 'A brand new item', 'response_type' => 'text', 'is_required' => false, 'failure_creates_hazard' => false, 'response_config' => null, 'guidance' => null],
            ],
        ])->assertRedirect();

        $template->refresh();
        $this->assertSame('Renamed Quality Checklist', $template->name);
        $this->assertSame(2, $template->items()->count());
        $this->assertDatabaseHas('site_checklist_template_items', ['id' => $firstItem->id, 'question' => 'Updated question']);
    }

    public function test_template_query_param_returns_template_detail(): void
    {
        $template = SiteChecklistTemplate::where('key', 'quality_home_checklist')->firstOrFail();

        $this->actingAs($this->admin)
            ->get('/checklists?template='.$template->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('templateDetail.id', $template->id)
                ->where('templateDetail.key', 'quality_home_checklist')
                ->has('templateDetail.items', $template->items()->count()));
    }

    public function test_retired_template_pages_are_gone(): void
    {
        // The standalone template GET pages are retired — only the mutation
        // endpoints (POST store, PUT/DELETE on {template}) remain on these URIs,
        // so a GET is "method not allowed", never a rendered page.
        $this->actingAs($this->admin)->get('/sites/checklists/templates')->assertStatus(405);
        $this->actingAs($this->admin)->get('/sites/checklists/templates/create')->assertStatus(405);
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
