<?php

namespace Tests\Feature\Compliance;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $coordinator;

    protected User $supportWorker;

    protected User $hr;

    protected User $auditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->coordinator = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $this->hr->roles()->attach(Role::where('name', 'hr')->first());

        $this->auditor = User::factory()->create(['role' => 'auditor', 'approved_at' => now()]);
        $this->auditor->roles()->attach(Role::where('name', 'auditor')->first());
    }

    // ──────────────────────────────────────
    // Authentication & Authorization
    // ──────────────────────────────────────

    public function test_compliance_dashboard_requires_authentication(): void
    {
        $this->get('/compliance')->assertRedirect('/login');
    }

    public function test_compliance_dashboard_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/compliance')
            ->assertOk();
    }

    public function test_compliance_dashboard_accessible_by_hr(): void
    {
        $this->actingAs($this->hr)
            ->get('/compliance')
            ->assertOk();
    }

    public function test_compliance_dashboard_accessible_by_auditor(): void
    {
        $this->actingAs($this->auditor)
            ->get('/compliance')
            ->assertOk();
    }

    public function test_compliance_dashboard_blocked_for_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/compliance')
            ->assertForbidden();
    }

    public function test_compliance_dashboard_blocked_for_user_without_permission(): void
    {
        $noPermUser = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($noPermUser)
            ->get('/compliance')
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Dashboard Data
    // ──────────────────────────────────────

    public function test_compliance_dashboard_returns_inertia_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/compliance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('compliance/index')
            );
    }

    public function test_compliance_dashboard_includes_control_room_data(): void
    {
        ControlRoomAlert::factory()->open()->count(3)->create();
        ControlRoomAlert::factory()->critical()->open()->count(2)->create();

        $this->actingAs($this->admin)
            ->get('/compliance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('controlRoom')
            );
    }

    public function test_compliance_dashboard_control_room_stats(): void
    {
        ControlRoomAlert::factory()->open()->count(5)->create();
        ControlRoomAlert::factory()->critical()->open()->count(2)->create();
        ControlRoomAlert::factory()->escalated(2)->open()->count(1)->create();
        ControlRoomAlert::factory()->resolved()->count(3)->create();

        $this->actingAs($this->admin)
            ->get('/compliance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('controlRoom.open')
                ->has('controlRoom.critical')
                ->has('controlRoom.escalated')
            );
    }

    public function test_compliance_dashboard_empty_state(): void
    {
        $this->actingAs($this->admin)
            ->get('/compliance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('controlRoom.open', 0)
                ->where('controlRoom.critical', 0)
                ->where('controlRoom.escalated', 0)
            );
    }

    // ──────────────────────────────────────
    // Metrics service (KPIs / what's due / trends)
    // ──────────────────────────────────────

    /**
     * Smoke-tests every query in ComplianceMetricsService against the real schema:
     * a single GET fans out to incidents / CD / MAR / break-glass / obligations /
     * audit KPIs, the what's-due register and the three trend series. A bad column
     * or scope would throw here even with empty tables.
     */
    public function test_compliance_dashboard_exposes_all_metrics_props(): void
    {
        $this->actingAs($this->admin)
            ->get('/compliance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('period')
                ->has('kpis', 6)
                ->has('kpis.0.key')
                ->has('kpis.0.spark')
                ->has('whatsDue.obligations')
                ->has('whatsDue.reviews')
                ->has('charts.incidentBySeverity')
                ->has('charts.marTrend')
                ->has('charts.cdTrend')
                ->has('frameworks')
                ->has('can.manage')
                ->has('can.triage')
                ->has('can.viewControlRoom')
                ->has('can.viewAudit')
                ->has('can.viewReports')
            );
    }

    public function test_compliance_dashboard_period_normalises(): void
    {
        $this->actingAs($this->admin)
            ->get('/compliance?period=90d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('period', '90d'));

        // An unknown period falls back to the 30-day default.
        $this->actingAs($this->admin)
            ->get('/compliance?period=not-a-period')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('period', '30d'));
    }

    public function test_compliance_dashboard_surfaces_due_obligation(): void
    {
        $due = now()->addDays(10)->toDateString();
        ComplianceObligation::create([
            'framework' => 'nga_paerewa',
            'obligation_code' => 'NP-1',
            'obligation_title' => 'Annual self-assessment',
            'description' => 'Yearly Ngā Paerewa self-assessment',
            'frequency' => 'annual',
            'priority' => 'high',
            'due_date' => $due,
            'next_due_date' => $due,
            'reminder_days' => [30, 14, 7],
            'owner_id' => $this->admin->id,
            'status' => 'due_soon',
            'evidence_required' => true,
        ]);

        $this->actingAs($this->admin)
            ->get('/compliance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('whatsDue.obligations', 1)
                ->where('whatsDue.obligations.0.title', 'Annual self-assessment')
                ->where('whatsDue.obligations.0.framework', 'Ngā Paerewa NZS 8134:2021')
                ->where('whatsDue.obligations.0.type', 'obligation')
            );
    }
}
