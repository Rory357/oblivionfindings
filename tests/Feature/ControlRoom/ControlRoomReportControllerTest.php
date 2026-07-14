<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Factories\ControlRoomAlertFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $coordinator;

    protected User $supportWorker;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'organization_id' => 1,
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
        ]);

        $this->coordinator = User::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'role' => 'coordinator',
            'approved_at' => now(),
        ]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->supportWorker = User::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    // ──────────────────────────────────────
    // Authentication & Authorization
    // ──────────────────────────────────────

    public function test_reports_require_authentication(): void
    {
        $this->get('/control-room/reports')->assertRedirect('/login');
    }

    public function test_reports_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk();
    }

    public function test_reports_accessible_by_coordinator(): void
    {
        $this->actingAs($this->coordinator)
            ->get('/control-room/reports')
            ->assertOk();
    }

    public function test_reports_blocked_for_support_worker_without_report_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/control-room/reports')
            ->assertForbidden();
    }

    public function test_reports_blocked_for_user_without_permission(): void
    {
        $noPermUser = User::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'approved_at' => now(),
        ]);

        $this->actingAs($noPermUser)
            ->get('/control-room/reports')
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Report Data Rendering
    // ──────────────────────────────────────

    public function test_reports_return_inertia_page_with_expected_props(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/reports')
                ->has('period')
                ->has('site_id')
                ->has('sla')
                ->has('volume')
                ->has('escalation')
                ->has('workload')
                ->has('playbooks')
            );
    }

    public function test_reports_default_period_is_30d(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', '30d')
            );
    }

    public function test_reports_accept_7d_period(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/reports?period=7d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', '7d')
            );
    }

    public function test_reports_accept_90d_period(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/reports?period=90d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', '90d')
            );
    }

    public function test_reports_accept_1y_period(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/reports?period=1y')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', '1y')
            );
    }

    public function test_reports_invalid_period_defaults_to_30d(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/reports?period=invalid')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', '30d')
            );
    }

    public function test_reports_stats_include_expected_fields(): void
    {
        $this->alertFactory()->open()->count(3)->create(['triggered_at' => now()->subDays(5)]);
        $this->alertFactory()->resolved()->count(2)->create(['triggered_at' => now()->subDays(5)]);

        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sla.total_with_sla')
                ->has('sla.compliance_pct')
                ->has('sla.avg_resolution_hours')
                ->has('volume.total')
                ->has('volume.resolved')
                ->has('volume.resolution_rate')
                ->has('escalation.escalated')
                ->has('escalation.escalation_rate')
            );
    }

    public function test_reports_empty_state(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('volume.total', 0)
                ->where('volume.resolved', 0)
                ->where('volume.resolution_rate', 0)
                ->where('escalation.escalated', 0)
            );
    }

    public function test_reports_count_alerts_in_period(): void
    {
        // Create alerts within the 30-day window
        $this->alertFactory()->count(5)->create(['triggered_at' => now()->subDays(10)]);
        // Create alerts outside the 30-day window
        $this->alertFactory()->count(3)->create(['triggered_at' => now()->subDays(60)]);

        $this->actingAs($this->admin)
            ->get('/control-room/reports?period=30d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('volume.total', 5)
            );
    }

    public function test_reports_by_severity_breakdown(): void
    {
        $this->alertFactory()->critical()->count(2)->create(['triggered_at' => now()->subDays(5)]);
        $this->alertFactory()->low()->count(3)->create(['triggered_at' => now()->subDays(5)]);

        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('volume.by_severity.critical', 2)
                ->where('volume.by_severity.low', 3)
            );
    }

    public function test_reports_by_source_breakdown(): void
    {
        $this->alertFactory()->fromFleet()->count(4)->create(['triggered_at' => now()->subDays(5)]);
        $this->alertFactory()->fromCompliance()->count(2)->create(['triggered_at' => now()->subDays(5)]);

        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('volume.by_source.fleet', 4)
                ->where('volume.by_source.compliance', 2)
            );
    }

    public function test_reports_escalation_count(): void
    {
        $this->alertFactory()->escalated(2)->count(3)->create(['triggered_at' => now()->subDays(5)]);
        $this->alertFactory()->count(5)->create(['triggered_at' => now()->subDays(5)]);

        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('escalation.escalated', 3)
            );
    }

    public function test_reports_top_assignees(): void
    {
        $assignee = User::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'approved_at' => now(),
        ]);
        $this->alertFactory()->assignedTo($assignee)->count(5)->create(['triggered_at' => now()->subDays(5)]);
        $this->alertFactory()->count(3)->create(['triggered_at' => now()->subDays(5)]);

        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('workload.handled_per_user')
            );
    }

    // ──────────────────────────────────────
    // Export
    // ──────────────────────────────────────

    public function test_export_requires_authentication(): void
    {
        $this->get('/control-room/reports/export')->assertRedirect('/login');
    }

    public function test_export_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/reports/export')
            ->assertOk();
    }

    public function test_export_blocked_for_support_worker_without_report_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/control-room/reports/export')
            ->assertForbidden();
    }

    public function test_export_returns_csv(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/reports/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_export_csv_has_header_row(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/control-room/reports/export');

        $content = $response->getContent();
        $firstLine = strtok($content, "\n");

        $this->assertStringContainsString('ID', $firstLine);
        $this->assertStringContainsString('Source', $firstLine);
        $this->assertStringContainsString('Severity', $firstLine);
        $this->assertStringContainsString('Status', $firstLine);
    }

    public function test_export_includes_alerts_in_period(): void
    {
        $this->alertFactory()->count(3)->create(['triggered_at' => now()->subDays(5)]);
        $this->alertFactory()->count(2)->create(['triggered_at' => now()->subDays(60)]);

        $response = $this->actingAs($this->admin)
            ->get('/control-room/reports/export?period=30d');

        $content = $response->getContent();
        $lines = array_filter(explode("\n", trim($content)));

        // Header + 3 data rows (not the 2 outside period)
        $this->assertCount(4, $lines);
    }

    public function test_export_csv_content_disposition(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/reports/export')
            ->assertOk()
            ->assertHeader('Content-Disposition');
    }

    public function test_export_respects_7d_period(): void
    {
        $this->alertFactory()->count(2)->create(['triggered_at' => now()->subDays(3)]);
        $this->alertFactory()->count(3)->create(['triggered_at' => now()->subDays(15)]);

        $response = $this->actingAs($this->admin)
            ->get('/control-room/reports/export?period=7d');

        $content = $response->getContent();
        $lines = array_filter(explode("\n", trim($content)));

        $this->assertCount(3, $lines); // Header + 2 data rows
    }

    private function alertFactory(): ControlRoomAlertFactory
    {
        return ControlRoomAlert::factory()->state([
            'site_id' => $this->site->id,
        ]);
    }
}
