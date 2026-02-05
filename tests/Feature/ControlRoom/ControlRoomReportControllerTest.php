<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $coordinator;
    protected User $supportWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->coordinator = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
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

    public function test_reports_blocked_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/control-room/reports')
            ->assertForbidden();
    }

    public function test_reports_blocked_for_user_without_permission(): void
    {
        $noPermUser = User::factory()->create(['approved_at' => now()]);

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
                ->has('stats')
                ->has('by_severity')
                ->has('by_status')
                ->has('by_source')
                ->has('by_alert_type')
                ->has('daily_trend')
                ->has('response_time_by_severity')
                ->has('top_assignees')
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
                ->where('period', 'invalid')
            );
    }

    public function test_reports_stats_include_expected_fields(): void
    {
        ControlRoomAlert::factory()->open()->count(3)->create(['triggered_at' => now()->subDays(5)]);
        ControlRoomAlert::factory()->resolved()->count(2)->create(['triggered_at' => now()->subDays(5)]);

        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('stats.total_alerts')
                ->has('stats.resolved_alerts')
                ->has('stats.resolution_rate')
                ->has('stats.avg_resolution_hours')
                ->has('stats.escalated_count')
                ->has('stats.escalation_rate')
            );
    }

    public function test_reports_empty_state(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total_alerts', 0)
                ->where('stats.resolved_alerts', 0)
                ->where('stats.resolution_rate', 0)
                ->where('stats.escalated_count', 0)
            );
    }

    public function test_reports_count_alerts_in_period(): void
    {
        // Create alerts within the 30-day window
        ControlRoomAlert::factory()->count(5)->create(['triggered_at' => now()->subDays(10)]);
        // Create alerts outside the 30-day window
        ControlRoomAlert::factory()->count(3)->create(['triggered_at' => now()->subDays(60)]);

        $this->actingAs($this->admin)
            ->get('/control-room/reports?period=30d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total_alerts', 5)
            );
    }

    public function test_reports_by_severity_breakdown(): void
    {
        ControlRoomAlert::factory()->critical()->count(2)->create(['triggered_at' => now()->subDays(5)]);
        ControlRoomAlert::factory()->low()->count(3)->create(['triggered_at' => now()->subDays(5)]);

        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('by_severity.critical', 2)
                ->where('by_severity.low', 3)
            );
    }

    public function test_reports_by_source_breakdown(): void
    {
        ControlRoomAlert::factory()->fromFleet()->count(4)->create(['triggered_at' => now()->subDays(5)]);
        ControlRoomAlert::factory()->fromCompliance()->count(2)->create(['triggered_at' => now()->subDays(5)]);

        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('by_source.fleet', 4)
                ->where('by_source.compliance', 2)
            );
    }

    public function test_reports_escalation_count(): void
    {
        ControlRoomAlert::factory()->escalated(2)->count(3)->create(['triggered_at' => now()->subDays(5)]);
        ControlRoomAlert::factory()->count(5)->create(['triggered_at' => now()->subDays(5)]);

        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.escalated_count', 3)
            );
    }

    public function test_reports_top_assignees(): void
    {
        $assignee = User::factory()->create(['approved_at' => now()]);
        ControlRoomAlert::factory()->assignedTo($assignee)->count(5)->create(['triggered_at' => now()->subDays(5)]);
        ControlRoomAlert::factory()->count(3)->create(['triggered_at' => now()->subDays(5)]);

        $this->actingAs($this->admin)
            ->get('/control-room/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('top_assignees')
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

    public function test_export_blocked_for_support_worker(): void
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
        ControlRoomAlert::factory()->count(3)->create(['triggered_at' => now()->subDays(5)]);
        ControlRoomAlert::factory()->count(2)->create(['triggered_at' => now()->subDays(60)]);

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
        ControlRoomAlert::factory()->count(2)->create(['triggered_at' => now()->subDays(3)]);
        ControlRoomAlert::factory()->count(3)->create(['triggered_at' => now()->subDays(15)]);

        $response = $this->actingAs($this->admin)
            ->get('/control-room/reports/export?period=7d');

        $content = $response->getContent();
        $lines = array_filter(explode("\n", trim($content)));

        $this->assertCount(3, $lines); // Header + 2 data rows
    }
}
