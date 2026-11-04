<?php

namespace Tests\Feature\Sites;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteHazard;
use App\Models\SiteInspectionRecord;
use App\Models\SiteInspectionSchedule;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Site reports (to-do #2): the hub and every report render for an
 * authorised user, the shared period + region filters narrow what is
 * counted, a viewer only ever sees Sites they can access, and a Site in a
 * report opens that Site's report.
 */
class SiteReportsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Site $kowhai;

    protected Site $totara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->sync([Role::query()->where('name', 'admin')->firstOrFail()->id]);

        $this->kowhai = Site::factory()->create([
            'name' => 'Kōwhai House',
            'type' => 'house',
            'region' => 'Auckland',
            'is_active' => true,
        ]);
        $this->totara = Site::factory()->create([
            'name' => 'Tōtara House',
            'type' => 'house',
            'region' => 'Waikato',
            'is_active' => true,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Fixtures                                                           */
    /* ------------------------------------------------------------------ */

    private function nzToday(): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
    }

    private function hazard(Site $site, array $fields = []): SiteHazard
    {
        return SiteHazard::withoutEvents(fn () => SiteHazard::create([
            'site_id' => $site->id,
            'reference_number' => 'HZ-'.Str::uuid(),
            'hazard_type' => 'slip_trip',
            'severity' => 'high',
            'likelihood' => 'likely',
            'risk_rating' => 'high',
            'description' => 'Loose carpet on the stairs at '.$site->name,
            'reported_by_user_id' => $this->admin->id,
            'status' => 'open',
            ...$fields,
        ]));
    }

    private function checklistRun(Site $site, string $scheduledDate, string $status, ?string $completedAt = null): SiteChecklistRun
    {
        $template = SiteChecklistTemplate::create([
            'key' => 'report_test_'.uniqid(),
            'name' => 'Weekly house check',
            'applicable_to_type' => 'house',
            'frequency' => 'weekly',
            'is_active' => true,
        ]);
        $assignment = SiteChecklistAssignment::create([
            'site_id' => $site->id,
            'template_id' => $template->id,
            'frequency' => 'weekly',
            'start_date' => $scheduledDate,
            'is_active' => true,
        ]);

        return SiteChecklistRun::create([
            'assignment_id' => $assignment->id,
            'site_id' => $site->id,
            'template_id' => $template->id,
            'scheduled_date' => $scheduledDate,
            'status' => $status,
            'completed_at' => $completedAt,
        ]);
    }

    /** A user who works at one Site only: no sites.viewAll. */
    private function siteWorker(Site $site, array $permissions = ['reports.sites.view']): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->roles()->sync([Role::query()->where('name', 'support_worker')->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['description' => $key, 'group' => 'reports', 'module' => 'System'],
            );
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $user->fresh();
    }

    /* ------------------------------------------------------------------ */
    /*  The hub and every report render                                   */
    /* ------------------------------------------------------------------ */

    public function test_hub_lists_packs_and_sites_with_the_default_period(): void
    {
        $this->actingAs($this->admin)
            ->get('/sites/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/reports/index')
                ->where('filters.period', '6m')
                ->where('filters.region', null)
                ->has('periods', 5)
                ->has('regions', 2)
                ->has('sites', 2)
                ->has('totals')
                ->has('trend')
                ->has('packs', 3)
                ->where('packs.0.key', 'houses')
                ->where('packs.0.site_count', 2)
                // No facility or head office Sites: the hub explains the empty packs.
                ->where('packs.1.key', 'facilities')
                ->where('packs.1.site_count_all', 0)
                ->where('packs.2.key', 'head-office')
                ->where('packs.2.site_count_all', 0));
    }

    public function test_every_report_renders_for_an_authorised_user(): void
    {
        $this->hazard($this->kowhai, ['due_date' => $this->nzToday()->subDays(5)->toDateString()]);

        $pages = [
            '/sites/reports/houses' => 'sites/reports/pack',
            '/sites/reports/facilities' => 'sites/reports/pack',
            '/sites/reports/head-office' => 'sites/reports/pack',
            '/sites/reports/overdue-actions' => 'sites/reports/overdue-actions',
            '/sites/reports/checklist-trends' => 'sites/reports/checklist-trends',
            '/sites/reports/inspections-maintenance' => 'sites/reports/inspections-maintenance',
            '/sites/reports/asset-condition' => 'sites/reports/asset-condition',
            "/sites/reports/site/{$this->kowhai->id}" => 'sites/reports/site-detail',
        ];

        foreach ($pages as $url => $component) {
            $this->actingAs($this->admin)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                    ->has('filters')
                    ->has('periods')
                    ->has('regions')
                    ->has('can'));
        }
    }

    public function test_pack_lists_its_sites_and_keys_each_row_to_the_site(): void
    {
        $this->actingAs($this->admin)
            ->get('/sites/reports/houses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/reports/pack')
                ->where('pack.key', 'houses')
                ->where('pack.site_count_all', 2)
                ->has('sites', 2)
                ->where('sites.0.id', $this->kowhai->id)
                ->where('sites.0.pack', 'houses')
                ->has('sites.0.metrics')
                ->has('sites.0.extras'));

        $this->actingAs($this->admin)
            ->get('/sites/reports/facilities')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pack.key', 'facilities')
                ->where('pack.site_count_all', 0)
                ->has('sites', 0));
    }

    public function test_site_row_opens_that_sites_report(): void
    {
        $this->hazard($this->kowhai, ['due_date' => $this->nzToday()->subDays(3)->toDateString()]);

        $this->actingAs($this->admin)
            ->get('/sites/reports/houses')
            ->assertInertia(fn (Assert $page) => $page->where('sites.0.id', $this->kowhai->id));

        // The row's target (`/sites/reports/site/{id}`, report-kit siteReportHref) is the Site's report.
        $this->actingAs($this->admin)
            ->get("/sites/reports/site/{$this->kowhai->id}?period=30d")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/reports/site-detail')
                ->where('site.id', $this->kowhai->id)
                ->where('site.name', 'Kōwhai House')
                ->where('site.pack', 'houses')
                ->where('filters.period', '30d')
                ->where('metrics.hazards_open', 1)
                ->where('metrics.actions_overdue', 1)
                ->has('openHazards', 1)
                ->has('trend'));
    }

    /* ------------------------------------------------------------------ */
    /*  Filters narrow the results                                         */
    /* ------------------------------------------------------------------ */

    public function test_region_filter_narrows_sites_and_carries_on_every_report(): void
    {
        $this->hazard($this->kowhai, ['due_date' => $this->nzToday()->subDays(4)->toDateString()]);
        $this->hazard($this->totara, ['due_date' => $this->nzToday()->subDays(4)->toDateString()]);

        $this->actingAs($this->admin)
            ->get('/sites/reports?region=Auckland')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.region', 'Auckland')
                ->has('regions', 2)
                ->has('sites', 1)
                ->where('sites.0.name', 'Kōwhai House')
                ->where('totals.actions_overdue', 1));

        $this->actingAs($this->admin)
            ->get('/sites/reports/overdue-actions?region=Waikato')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.region', 'Waikato')
                ->has('overdueActions', 1)
                ->where('overdueActions.0.site.name', 'Tōtara House'));

        $this->actingAs($this->admin)
            ->get('/sites/reports/houses?region=Waikato')
            ->assertInertia(fn (Assert $page) => $page
                ->has('sites', 1)
                ->where('sites.0.name', 'Tōtara House')
                ->where('pack.site_count_all', 2));
    }

    public function test_date_range_decides_which_checks_count(): void
    {
        $today = $this->nzToday();
        // Due 10 days ago and done that day: on time.
        $this->checklistRun(
            $this->kowhai,
            $today->subDays(10)->toDateString(),
            'completed',
            $today->subDays(10)->setTime(12, 0)->utc()->toDateTimeString(),
        );
        // Due 100 days ago and never done: missed.
        $this->checklistRun($this->kowhai, $today->subDays(100)->toDateString(), 'scheduled');

        $this->actingAs($this->admin)
            ->get('/sites/reports?period=30d')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.period', '30d')
                ->where('totals.checks_due', 1)
                ->where('totals.checks_on_time', 1)
                ->where('totals.checks_missed', 0)
                ->where('totals.checks_on_time_rate', fn ($rate) => (float) $rate === 100.0));

        $this->actingAs($this->admin)
            ->get('/sites/reports?period=6m')
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.checks_due', 2)
                ->where('totals.checks_missed', 1)
                ->where('totals.checks_on_time_rate', fn ($rate) => (float) $rate === 50.0));

        // A custom range that holds only the old check.
        $from = $today->subDays(120)->toDateString();
        $to = $today->subDays(90)->toDateString();
        $this->actingAs($this->admin)
            ->get("/sites/reports/checklist-trends?period=custom&date_from={$from}&date_to={$to}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.period', 'custom')
                ->where('filters.from', $from)
                ->where('filters.to', $to)
                ->where('totals.checklists_due', 1)
                ->where('totals.checklists_missed', 1)
                ->has('byTemplate', 1));
    }

    public function test_overdue_actions_period_counts_by_due_date_and_reports_earlier_ones(): void
    {
        $today = $this->nzToday();
        $this->hazard($this->kowhai, ['due_date' => $today->subDays(10)->toDateString(), 'severity' => 'critical']);
        $this->hazard($this->kowhai, ['due_date' => $today->subDays(200)->toDateString(), 'severity' => 'low']);
        // Closed and not yet due: never overdue.
        $this->hazard($this->kowhai, ['due_date' => $today->subDays(10)->toDateString(), 'status' => 'closed', 'closed_at' => now()]);
        $this->hazard($this->kowhai, ['due_date' => $today->addDays(5)->toDateString()]);

        $this->actingAs($this->admin)
            ->get('/sites/reports/overdue-actions?period=3m')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('overdueActions', 1)
                ->where('overdueActions.0.severity', 'critical')
                ->where('overdueActions.0.days_overdue', 10)
                ->where('older.count', 1)
                ->where('older.oldest_due', $today->subDays(200)->toDateString()));

        $this->actingAs($this->admin)
            ->get('/sites/reports/overdue-actions?period=12m')
            ->assertInertia(fn (Assert $page) => $page
                ->has('overdueActions', 2)
                ->where('older.count', 0));
    }

    public function test_inspections_and_maintenance_report_counts_on_time_overdue_and_slipped_work(): void
    {
        $today = $this->nzToday();
        $schedule = SiteInspectionSchedule::create([
            'site_id' => $this->kowhai->id,
            'inspection_type' => 'fire_safety',
            'title' => 'Fire exit check',
            'frequency' => 'monthly',
            'first_due_date' => $today->subDays(40)->toDateString(),
            'next_due_date' => $today->subDays(6)->toDateString(),
            'is_active' => true,
        ]);
        SiteInspectionRecord::create([
            'schedule_id' => $schedule->id,
            'site_id' => $this->kowhai->id,
            'due_date' => $today->subDays(20)->toDateString(),
            'completed_at' => $today->subDays(21)->setTime(10, 0)->utc()->toDateTimeString(),
            'result' => 'fail',
        ]);

        $asset = Asset::factory()->create([
            'site_id' => $this->kowhai->id,
            'name' => 'Hoist',
            'maintenance_due_at' => $today->subDays(3)->toDateString(),
        ]);
        AssetMaintenanceLog::query()->createQuietly([
            'asset_id' => $asset->id,
            'performed_at' => $today->subDays(60)->setTime(9, 0)->utc()->toDateTimeString(),
            'type' => 'service',
            'next_due_at' => $today->subDays(20)->toDateString(),
        ]);
        AssetMaintenanceLog::query()->createQuietly([
            'asset_id' => $asset->id,
            'performed_at' => $today->subDays(15)->setTime(9, 0)->utc()->toDateTimeString(),
            'type' => 'service',
            'cost' => 84.60,
        ]);

        $this->actingAs($this->admin)
            ->get('/sites/reports/inspections-maintenance?period=30d')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/reports/inspections-maintenance')
                // One done on time, one schedule still past its date.
                ->where('totals.inspections_due', 2)
                ->where('totals.inspections_on_time', 1)
                ->where('totals.inspections_missed', 1)
                ->where('totals.inspections_overdue_now', 1)
                ->where('totals.inspections_failed', 1)
                ->has('overdueInspections', 1)
                ->where('overdueInspections.0.title', 'Fire exit check')
                ->has('failedInspections', 1)
                // Maintenance due 20 days ago, done 15 days ago: late.
                ->where('totals.maintenance_due', 1)
                ->where('totals.maintenance_late', 1)
                ->where('totals.maintenance_done', 1)
                ->where('totals.maintenance_spend', fn ($spend) => (float) $spend === 84.6)
                ->where('totals.maintenance_overdue_now', 1)
                ->has('overdueMaintenance', 1)
                ->has('slippedMaintenance', 1)
                ->where('slippedMaintenance.0.outcome', 'late'));
    }

    /* ------------------------------------------------------------------ */
    /*  Only Sites the viewer can access                                   */
    /* ------------------------------------------------------------------ */

    public function test_a_site_worker_only_sees_their_own_site(): void
    {
        $this->hazard($this->kowhai, ['due_date' => $this->nzToday()->subDays(2)->toDateString()]);
        $this->hazard($this->totara, ['due_date' => $this->nzToday()->subDays(2)->toDateString()]);
        Asset::factory()->create(['site_id' => $this->totara->id, 'name' => 'Tōtara van', 'status' => 'out_of_service']);
        $worker = $this->siteWorker($this->kowhai);

        $this->actingAs($worker)
            ->get('/sites/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sites', 1)
                ->where('sites.0.id', $this->kowhai->id)
                ->where('totals.actions_overdue', 1)
                ->has('regions', 1));

        $this->actingAs($worker)
            ->get('/sites/reports/houses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sites', 1)
                ->where('sites.0.id', $this->kowhai->id));

        $this->actingAs($worker)
            ->get('/sites/reports/overdue-actions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('overdueActions', 1)
                ->where('overdueActions.0.site.id', $this->kowhai->id));

        $this->actingAs($worker)
            ->get('/sites/reports/asset-condition')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.out_of_service', 0)
                ->has('assets', 0));

        // Asking for the other Site by region or by id shows nothing of it.
        $this->actingAs($worker)
            ->get('/sites/reports/overdue-actions?region=Waikato')
            ->assertInertia(fn (Assert $page) => $page->has('overdueActions', 0));
        $this->actingAs($worker)
            ->get("/sites/reports/overdue-actions?site_id={$this->totara->id}")
            ->assertInertia(fn (Assert $page) => $page->has('overdueActions', 0));

        $this->actingAs($worker)
            ->get("/sites/reports/site/{$this->kowhai->id}")
            ->assertOk();
        $this->actingAs($worker)
            ->get("/sites/reports/site/{$this->totara->id}")
            ->assertForbidden();
    }

    public function test_export_only_includes_accessible_sites(): void
    {
        $worker = $this->siteWorker($this->kowhai, ['reports.sites.view', 'reports.sites.export']);

        $response = $this->actingAs($worker)->get('/sites/reports/export?type=houses&format=csv');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('content-type'));
        $csv = $response->getContent();
        $this->assertStringContainsString('site_name', $csv);
        $this->assertStringContainsString('Kōwhai House', $csv);
        $this->assertStringNotContainsString('Tōtara House', $csv);
    }

    public function test_reports_need_the_site_reports_permission(): void
    {
        $worker = $this->siteWorker($this->kowhai, []);

        $this->actingAs($worker)->get('/sites/reports')->assertForbidden();
        $this->actingAs($worker)->get('/sites/reports/inspections-maintenance')->assertForbidden();
        $this->actingAs($worker)->get("/sites/reports/site/{$this->kowhai->id}")->assertForbidden();
    }
}
