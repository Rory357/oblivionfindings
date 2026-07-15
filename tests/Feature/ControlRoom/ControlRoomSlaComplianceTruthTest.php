<?php

namespace Tests\Feature\ControlRoom;

use App\Models\Client;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomDeskService;
use App\Services\ControlRoom\ControlRoomReportService;
use Database\Factories\ControlRoomAlertFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ControlRoomSlaComplianceTruthTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

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
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->site = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
        ]);
    }

    public function test_compliance_separates_completed_met_in_progress_and_overdue_work(): void
    {
        $overdueSla = $this->makeTruthfulComplianceFixture();

        $reportService = app(ControlRoomReportService::class);
        $metrics = $reportService->slaCompliance(
            now()->subDay(),
            now()->addDay(),
        );

        $this->assertSame(3, $metrics['total_with_sla']);
        $this->assertSame(2, $metrics['assessed_total']);
        $this->assertSame(1, $metrics['sla_met']);
        $this->assertSame(1, $metrics['sla_breached']);
        $this->assertSame(1, $metrics['sla_in_progress']);
        $this->assertSame(50.0, $metrics['compliance_pct']);
        $this->assertSame([
            'total' => 3,
            'assessed_total' => 2,
            'met' => 1,
            'breached' => 1,
            'in_progress' => 1,
            'compliance_pct' => 50.0,
        ], $metrics['by_severity']['high']);

        $this->assertFalse($overdueSla->fresh()->acknowledge_breached);
        $this->assertSame('breached', $overdueSla->fresh()->getStatus());
        $this->assertSame(1, AlertSla::query()->breached()->count());
    }

    public function test_stats_and_sla_definition_pages_do_not_present_unfinished_clocks_as_met(): void
    {
        $this->makeTruthfulComplianceFixture();

        $this->actingAs($this->admin)
            ->get('/control-room/stats')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where(
                    'kpis.sla_compliance_pct',
                    fn ($value) => is_numeric($value) && (float) $value === 50.0,
                )
            );

        $this->actingAs($this->admin)
            ->get('/control-room/sla')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('slaDefinitions', 1)
                ->where('slaDefinitions.0.total_alerts', 3)
                ->where(
                    'slaDefinitions.0.compliance.acknowledge_pct',
                    fn ($value) => is_numeric($value) && (float) $value === 50.0,
                )
                ->where(
                    'slaDefinitions.0.compliance.response_pct',
                    fn ($value) => is_numeric($value) && (float) $value === 50.0,
                )
                ->where(
                    'slaDefinitions.0.compliance.resolution_pct',
                    fn ($value) => is_numeric($value) && (float) $value === 50.0,
                )
            );
    }

    public function test_pending_only_clocks_report_unavailable_compliance_without_an_attention_alarm(): void
    {
        $definition = SlaDefinition::query()->create([
            'name' => 'Pending-only truth',
            'code' => 'pending-only-truth',
            'acknowledge_target_minutes' => 15,
            'response_target_minutes' => 30,
            'resolution_target_minutes' => 120,
            'is_active' => true,
        ]);
        $pendingAlert = $this->alertFactory()->open()->create([
            'severity' => 'high',
            'triggered_at' => now(),
        ]);
        AlertSla::createFromDefinition($pendingAlert, $definition);

        $reportService = app(ControlRoomReportService::class);
        $metrics = $reportService->slaCompliance(
            now()->subDay(),
            now()->addDay(),
        );

        $this->assertSame(1, $metrics['total_with_sla']);
        $this->assertSame(0, $metrics['assessed_total']);
        $this->assertSame(0, $metrics['sla_met']);
        $this->assertSame(0, $metrics['sla_breached']);
        $this->assertSame(1, $metrics['sla_in_progress']);
        $this->assertNull($metrics['compliance_pct']);
        $this->assertNull($metrics['by_severity']['high']['compliance_pct']);
        $slaAttentionFlag = collect($reportService->attentionFlags())
            ->firstWhere('metric', 'sla_compliance');
        $this->assertNull($slaAttentionFlag);

        $this->actingAs($this->admin)
            ->get('/control-room/stats')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.sla_compliance_pct', null)
            );

        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('analytics')
            );

        $dashboardAnalytics = app(ControlRoomDeskService::class)->analytics($this->admin);
        $this->assertNull(data_get($dashboardAnalytics, 'sla.compliance_pct'));
        $this->assertNull(data_get($dashboardAnalytics, 'sla_daily_trend.0.compliance_pct'));

        $this->actingAs($this->admin)
            ->get('/control-room/sla')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('slaDefinitions.0.compliance.acknowledge_pct', null)
                ->where('slaDefinitions.0.compliance.response_pct', null)
                ->where('slaDefinitions.0.compliance.resolution_pct', null)
            );
    }

    public function test_dismissed_alert_status_excludes_a_legacy_unended_sla_from_live_clock_truth(): void
    {
        $definition = SlaDefinition::query()->create([
            'name' => 'Legacy dismissed truth',
            'code' => 'legacy-dismissed-truth',
            'acknowledge_target_minutes' => 5,
            'response_target_minutes' => 10,
            'resolution_target_minutes' => 30,
            'is_active' => true,
        ]);
        $dismissedAlert = $this->alertFactory()->create([
            'status' => ControlRoomAlert::STATUS_DISMISSED,
            'triggered_at' => now()->subHours(2),
        ]);
        $sla = AlertSla::query()->create([
            'alert_id' => $dismissedAlert->id,
            'sla_definition_id' => $definition->id,
            'acknowledge_deadline' => now()->subHour(),
            'response_deadline' => now()->subMinutes(45),
            'resolution_deadline' => now()->subMinutes(30),
            'acknowledge_breached' => true,
            'response_breached' => true,
            'resolution_breached' => true,
            'ended_as' => null,
        ]);

        $this->assertFalse($sla->isApplicable());
        $this->assertFalse($sla->isBreached());
        $this->assertSame('not_applicable', $sla->getStatus());
        $this->assertSame([], $sla->checkForBreaches());
        $this->assertSame(0, AlertSla::query()->applicable()->count());
        $this->assertSame(0, AlertSla::query()->assessed()->count());
        $this->assertSame(0, AlertSla::query()->breached()->count());
        $this->assertSame(0, AlertSla::query()->atRisk()->count());
        $this->assertSame(0, app(ControlRoomReportService::class)->slaCompliance(
            now()->subDay(),
            now()->addDay(),
        )['total_sla_cycles']);
    }

    public function test_end_as_dismissed_records_the_cycle_after_the_alert_status_is_already_dismissed(): void
    {
        $definition = SlaDefinition::query()->create([
            'name' => 'Dismissal lifecycle truth',
            'code' => 'dismissal-lifecycle-truth',
            'acknowledge_target_minutes' => 5,
            'is_active' => true,
        ]);
        $alert = $this->alertFactory()->open()->create();
        $sla = AlertSla::createFromDefinition($alert, $definition);
        $endedAt = now()->startOfSecond();

        $alert->update(['status' => ControlRoomAlert::STATUS_DISMISSED]);
        $sla->endAsDismissed($endedAt);
        $sla->refresh();

        $this->assertSame(AlertSla::ENDED_DISMISSED, $sla->ended_as);
        $this->assertCount(1, $sla->cycle_history);
        $this->assertSame(AlertSla::ENDED_DISMISSED, $sla->cycle_history[0]['ended_as']);
        $this->assertSame($endedAt->toAtomString(), $sla->cycle_history[0]['ended_at']);
        $this->assertFalse($sla->isApplicable());
    }

    public function test_status_uses_one_immutable_five_minute_warning_boundary_for_every_milestone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 10:00:00'));

        try {
            $alert = new ControlRoomAlert(['status' => ControlRoomAlert::STATUS_OPEN]);
            $sla = new AlertSla([
                'sla_definition_id' => 1,
                'response_deadline' => now()->addMinutes(6),
                'resolution_deadline' => now()->addMinutes(12),
            ]);
            $sla->setRelation('alert', $alert);

            $this->assertSame('on_track', $sla->getStatus());

            $sla->response_deadline = now()->addMinutes(5);

            $this->assertSame('at_risk', $sla->getStatus());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_completed_late_clocks_are_breached_without_waiting_for_persisted_flags(): void
    {
        $definition = SlaDefinition::query()->create([
            'name' => 'Late completion truth',
            'code' => 'late-completion-truth',
            'acknowledge_target_minutes' => 15,
            'is_active' => true,
        ]);
        $alert = $this->alertFactory()->resolved()->create([
            'triggered_at' => now()->subHours(2),
        ]);
        $sla = AlertSla::query()->create([
            'alert_id' => $alert->id,
            'sla_definition_id' => $definition->id,
            'acknowledge_deadline' => now()->subMinutes(90),
            'acknowledged_at' => now()->subMinutes(60),
            'acknowledge_breached' => false,
        ]);

        $this->assertFalse($sla->fresh()->acknowledge_breached);
        $this->assertTrue($sla->fresh()->isBreached());
        $this->assertSame('breached', $sla->fresh()->getStatus());
        $this->assertSame(1, AlertSla::query()->breached()->count());
    }

    public function test_alert_lists_and_dashboard_present_an_unpersisted_overdue_clock_as_breached(): void
    {
        $definition = SlaDefinition::query()->create([
            'name' => 'Live overdue presentation truth',
            'code' => 'live-overdue-presentation-truth',
            'acknowledge_target_minutes' => 5,
            'is_active' => true,
        ]);
        $alert = $this->alertFactory()->open()->create([
            'source' => 'integration_unifi',
            'triggered_at' => now()->subHour(),
        ]);
        AlertSla::query()->create([
            'alert_id' => $alert->id,
            'sla_definition_id' => $definition->id,
            'cycle_started_at' => $alert->triggered_at,
            'acknowledge_deadline' => now()->subMinutes(30),
            'acknowledge_breached' => false,
        ]);

        $this->actingAs($this->admin)
            ->get('/control-room/alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.data.0.id', $alert->id)
                ->where('alerts.data.0.sla.status', 'breached')
            );

        $this->actingAs($this->admin)
            ->get('/control-room/integration-alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.data.0.id', $alert->id)
                ->where('alerts.data.0.sla_status', 'red')
            );

        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.data.0.id', $alert->id)
                ->where('alerts.data.0.sla_status', 'breached')
            );
    }

    public function test_dashboard_does_not_mark_completed_past_milestones_at_risk(): void
    {
        $definition = SlaDefinition::query()->create([
            'name' => 'Completed milestone presentation truth',
            'code' => 'completed-milestone-presentation-truth',
            'acknowledge_target_minutes' => 5,
            'response_target_minutes' => 15,
            'resolution_target_minutes' => 120,
            'is_active' => true,
        ]);
        $alert = $this->alertFactory()->triaging()->create([
            'triggered_at' => now()->subHour(),
        ]);
        AlertSla::query()->create([
            'alert_id' => $alert->id,
            'sla_definition_id' => $definition->id,
            'cycle_started_at' => $alert->triggered_at,
            'acknowledge_deadline' => now()->subMinutes(55),
            'acknowledged_at' => now()->subMinutes(56),
            'response_deadline' => now()->subMinutes(45),
            'responded_at' => now()->subMinutes(46),
            'resolution_deadline' => now()->addHour(),
        ]);

        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alerts.data.0.id', $alert->id)
                ->where('alerts.data.0.sla_status', 'on_track')
            );
    }

    public function test_dashboard_and_stats_use_every_reopened_cycle_for_sla_trends_and_kpis(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00'));

        try {
            $definition = SlaDefinition::query()->create([
                'name' => 'Reopened surface truth',
                'code' => 'reopened-surface-truth',
                'acknowledge_target_minutes' => 10,
                'response_target_minutes' => 30,
                'resolution_target_minutes' => 120,
                'is_active' => true,
            ]);
            $alert = $this->alertFactory()->resolved()->create([
                'severity' => 'high',
                'triggered_at' => Carbon::parse('2026-05-01 09:00:00'),
            ]);
            $firstCycleStartedAt = Carbon::parse('2026-07-10 09:00:00');
            $sla = AlertSla::createFromDefinition($alert, $definition, $firstCycleStartedAt);
            $sla->recordAcknowledge($firstCycleStartedAt->copy()->addMinutes(5));
            $sla->recordResponse($firstCycleStartedAt->copy()->addMinutes(20));
            $sla->recordResolution($firstCycleStartedAt->copy()->addMinutes(90));

            $secondCycleStartedAt = Carbon::parse('2026-07-12 09:00:00');
            $sla->restartForReopen($secondCycleStartedAt, $definition);
            $sla->recordAcknowledge($secondCycleStartedAt->copy()->addMinutes(15));
            $sla->recordResponse($secondCycleStartedAt->copy()->addMinutes(20));
            $sla->recordResolution($secondCycleStartedAt->copy()->addMinutes(60));

            $dashboardAnalytics = app(ControlRoomDeskService::class)->analytics($this->admin);
            $this->assertSame(50.0, data_get($dashboardAnalytics, 'sla.compliance_pct'));
            $this->assertSame([
                ['date' => '2026-07-10', 'compliance_pct' => 100],
                ['date' => '2026-07-12', 'compliance_pct' => 0],
            ], data_get($dashboardAnalytics, 'sla_daily_trend'));

            $this->actingAs($this->admin)
                ->get('/control-room')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->missing('analytics')
                    ->where('hero.last_24_hours.avg_response_minutes', null)
                );

            $this->actingAs($this->admin)
                ->get('/control-room/stats')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('kpis.avg_acknowledge_minutes', fn ($value) => is_numeric($value) && (float) $value === 10.0)
                    ->where('kpis.avg_resolution_hours', fn ($value) => is_numeric($value) && (float) $value === 1.3)
                    ->where('kpis.sla_compliance_pct', fn ($value) => is_numeric($value) && (float) $value === 50.0)
                );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_and_stats_cycle_metrics_reuse_canonical_alert_scope_integrity(): void
    {
        $foreignSite = Site::factory()->create(['tenant_id' => 202]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 202,
            'site_id' => $foreignSite->id,
        ]);
        $definition = SlaDefinition::query()->create([
            'name' => 'Canonical alert scope cycle truth',
            'code' => 'canonical-alert-scope-cycle-truth',
            'acknowledge_target_minutes' => 10,
            'is_active' => true,
        ]);

        $visibleLegacyAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => null,
            'client_id' => null,
            'context' => ['site' => ['id' => $this->site->id]],
            'triggered_at' => now()->subMinutes(30),
        ]);
        $visibleSla = AlertSla::createFromDefinition(
            $visibleLegacyAlert,
            $definition,
            now()->subMinutes(30),
        );
        $visibleSla->recordAcknowledge(now()->subMinutes(25));

        $poisonedAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->site->id,
            'client_id' => $foreignClient->id,
            'triggered_at' => now()->subMinutes(20),
        ]);
        $poisonedSla = AlertSla::createFromDefinition(
            $poisonedAlert,
            $definition,
            now()->subMinutes(20),
        );
        $poisonedSla->recordAcknowledge(now()->subMinutes(5));

        $dashboardAnalytics = app(ControlRoomDeskService::class)->analytics($this->admin);
        $this->assertSame(100.0, data_get($dashboardAnalytics, 'sla.compliance_pct'));
        $this->assertSame([[
            'date' => now()->toDateString(),
            'compliance_pct' => 100,
        ]], data_get($dashboardAnalytics, 'sla_daily_trend'));

        $this->actingAs($this->admin)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('analytics')
            );

        $this->actingAs($this->admin)
            ->get('/control-room/stats')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.avg_acknowledge_minutes', fn ($value) => is_numeric($value) && (float) $value === 5.0)
                ->where('kpis.sla_compliance_pct', fn ($value) => is_numeric($value) && (float) $value === 100.0)
            );
    }

    public function test_task7_final_gap_dashboard_report_surfaces_use_canonical_explicit_site_precedence(): void
    {
        $foreignSite = Site::factory()->create(['tenant_id' => 202]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 202,
            'site_id' => $foreignSite->id,
        ]);
        $definition = SlaDefinition::query()->create([
            'name' => 'Canonical report surface truth',
            'code' => 'canonical-report-surface-truth',
            'acknowledge_target_minutes' => 10,
            'is_active' => true,
        ]);
        $queue = TriageQueue::query()->create([
            'name' => 'Canonical report queue',
            'code' => 'canonical-report-queue',
            'tier' => 1,
            'is_active' => true,
        ]);

        $visibleAlerts = collect(range(1, 3))->map(function (int $index) use ($queue) {
            return ControlRoomAlert::factory()->open()->create([
                'source' => 'context_visible',
                'alert_type' => "Context-visible alert {$index}",
                'severity' => 'critical',
                'site_id' => null,
                'client_id' => null,
                'assigned_to_user_id' => $index === 3 ? null : $this->admin->id,
                'queue_id' => $queue->id,
                'escalation_level' => $index,
                'triggered_at' => now()->subMinutes(30 - $index),
                'context' => ['site' => ['id' => $this->site->id]],
            ]);
        });
        $poisonedAlert = ControlRoomAlert::factory()->open()->create([
            'source' => 'poisoned_foreign_client',
            'alert_type' => 'Foreign client must not enter local reports',
            'severity' => 'critical',
            'site_id' => $this->site->id,
            'client_id' => $foreignClient->id,
            'assigned_to_user_id' => $this->admin->id,
            'queue_id' => $queue->id,
            'escalation_level' => 5,
            'triggered_at' => now()->subMinutes(20),
        ]);

        $playbook = Playbook::factory()->create([
            'name' => 'Canonical report playbook',
            'code' => 'canonical-report-playbook',
        ]);
        foreach ($visibleAlerts as $visibleAlert) {
            PlaybookRun::query()->create([
                'playbook_id' => $playbook->id,
                'alert_id' => $visibleAlert->id,
                'status' => PlaybookRun::STATUS_COMPLETED,
                'started_at' => now()->subMinutes(15),
                'completed_at' => now()->subMinutes(5),
            ]);
        }
        PlaybookRun::query()->create([
            'playbook_id' => $playbook->id,
            'alert_id' => $poisonedAlert->id,
            'status' => PlaybookRun::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(15),
            'completed_at' => now()->subMinutes(5),
        ]);

        foreach ($visibleAlerts as $index => $visibleAlert) {
            $sla = AlertSla::createFromDefinition(
                $visibleAlert,
                $definition,
                now()->subMinutes(30 - $index),
            );
            $sla->recordAcknowledge(now()->subMinutes(25 - $index));
        }
        $poisonedSla = AlertSla::createFromDefinition(
            $poisonedAlert,
            $definition,
            now()->subMinutes(20),
        );
        $poisonedSla->recordAcknowledge(now()->subMinute());

        $reports = app(ControlRoomReportService::class);
        $from = now()->subDay();
        $to = now()->addDay();
        $volume = $reports->alertVolume($from, $to, $this->site->id);
        $escalations = $reports->escalationAnalysis($from, $to, $this->site->id);
        $sla = $reports->slaCompliance($from, $to, $this->site->id);
        $slaTrend = $reports->slaDailyTrend($from, $to, $this->site->id);
        $workload = $reports->workloadDistribution($from, $to, $this->site->id);
        $playbooks = $reports->playbookPerformance($from, $to, $this->site->id);
        $attention = $reports->attentionFlags($this->site->id);
        $comparison = $reports->siteComparison($from, $to, $this->site->id);

        $this->assertSame(3, $volume['total']);
        $this->assertSame(['context_visible' => 3], $volume['by_source']);
        $this->assertSame(3, $escalations['total_alerts']);
        $this->assertSame(3, $escalations['escalated']);
        $this->assertSame(1, $escalations['stuck_at_high_escalation']);
        $this->assertSame(3, $sla['total_sla_cycles']);
        $this->assertSame(100.0, $sla['compliance_pct']);
        $this->assertSame(100, data_get($slaTrend, '0.compliance_pct'));
        $this->assertSame(2, data_get($workload, 'active_per_user.0.active_alerts'));
        $this->assertSame(2, data_get($workload, 'handled_per_user.0.alerts_handled'));
        $this->assertSame(3, data_get($workload, 'per_queue.0.active_alerts'));
        $this->assertSame(1, data_get($workload, 'unassigned'));
        $this->assertSame(3, $playbooks['total_runs']);
        $this->assertSame(3, $playbooks['completed']);
        $this->assertSame(3, data_get($playbooks, 'by_playbook.0.total_runs'));
        $this->assertSame(3, collect($attention)->firstWhere('metric', 'critical_alerts')['value'] ?? null);
        $this->assertNull(collect($attention)->firstWhere('metric', 'sla_compliance'));
        $this->assertSame($this->site->id, data_get($comparison, '0.site_id'));
        $this->assertSame(3, data_get($comparison, '0.total_alerts'));
    }

    public function test_compliance_counts_each_reopened_cycle_in_the_period_using_cycle_timestamps(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00'));

        try {
            $definition = SlaDefinition::query()->create([
                'name' => 'Reopened cycle truth',
                'code' => 'reopened-cycle-truth',
                'acknowledge_target_minutes' => 10,
                'response_target_minutes' => 30,
                'resolution_target_minutes' => 120,
                'is_active' => true,
            ]);
            $alert = $this->alertFactory()->open()->create([
                'severity' => 'high',
                // The alert predates the report. Its SLA cycles do not.
                'triggered_at' => Carbon::parse('2026-05-01 09:00:00'),
            ]);

            $firstCycleStartedAt = Carbon::parse('2026-07-10 09:00:00');
            $sla = AlertSla::createFromDefinition($alert, $definition, $firstCycleStartedAt);
            $sla->recordAcknowledge($firstCycleStartedAt->copy()->addMinutes(5));
            $sla->recordResponse($firstCycleStartedAt->copy()->addMinutes(20));
            $sla->recordResolution($firstCycleStartedAt->copy()->addMinutes(90));

            $secondCycleStartedAt = Carbon::parse('2026-07-12 09:00:00');
            $sla->restartForReopen($secondCycleStartedAt, $definition);
            $sla->recordAcknowledge($secondCycleStartedAt->copy()->addMinutes(15));
            $sla->recordResponse($secondCycleStartedAt->copy()->addMinutes(20));
            $sla->recordResolution($secondCycleStartedAt->copy()->addMinutes(60));

            $metrics = app(ControlRoomReportService::class)->slaCompliance(
                Carbon::parse('2026-07-09 00:00:00'),
                Carbon::parse('2026-07-14 23:59:59'),
            );

            $this->assertSame(2, $metrics['total_with_sla']);
            $this->assertSame(2, $metrics['total_sla_cycles']);
            $this->assertSame(1, $metrics['unique_alerts_with_sla']);
            $this->assertSame(2, $metrics['assessed_total']);
            $this->assertSame(1, $metrics['sla_met']);
            $this->assertSame(1, $metrics['sla_breached']);
            $this->assertSame(50.0, $metrics['compliance_pct']);
            $this->assertSame(1, $metrics['breach_breakdown']['acknowledge']);
            $this->assertSame(0, $metrics['breach_breakdown']['response']);
            $this->assertSame(0, $metrics['breach_breakdown']['resolution']);
            $this->assertSame(10.0, $metrics['avg_acknowledge_minutes']);
            $this->assertSame(20.0, $metrics['avg_response_minutes']);
            $this->assertSame(1.3, $metrics['avg_resolution_hours']);
            $this->assertSame(2, $metrics['by_severity']['high']['total']);
            $this->assertCount(1, $sla->fresh()->cycle_history);

            $latestCycleOnly = app(ControlRoomReportService::class)->slaCompliance(
                Carbon::parse('2026-07-11 00:00:00'),
                Carbon::parse('2026-07-14 23:59:59'),
            );

            $this->assertSame(1, $latestCycleOnly['total_sla_cycles']);
            $this->assertSame(1, $latestCycleOnly['sla_breached']);
            $this->assertSame(0, $latestCycleOnly['sla_met']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_attention_flag_uses_recent_reopen_cycle_time_instead_of_the_original_sla_row_age(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00'));

        try {
            $definition = SlaDefinition::query()->create([
                'name' => 'Reopen attention truth',
                'code' => 'reopen-attention-truth',
                'acknowledge_target_minutes' => 10,
                'is_active' => true,
            ]);
            $alert = $this->alertFactory()->open()->create([
                'severity' => 'high',
                'triggered_at' => Carbon::parse('2026-05-01 09:00:00'),
            ]);

            $originalCycle = Carbon::parse('2026-05-01 09:00:00');
            $sla = AlertSla::createFromDefinition($alert, $definition, $originalCycle);
            $sla->recordAcknowledge($originalCycle->copy()->addMinutes(5));
            $sla->forceFill([
                'created_at' => $originalCycle,
                'updated_at' => $originalCycle,
            ])->saveQuietly();

            $reopenedAt = now()->subDays(2);
            $sla->restartForReopen($reopenedAt, $definition);
            $sla->recordAcknowledge($reopenedAt->copy()->addMinutes(20));

            $flag = collect(app(ControlRoomReportService::class)->attentionFlags())
                ->firstWhere('metric', 'sla_compliance');

            $this->assertNotNull($flag);
            $this->assertSame(0.0, $flag['value']);
            $this->assertStringContainsString('7-day average', $flag['message']);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function makeTruthfulComplianceFixture(): AlertSla
    {
        $definition = SlaDefinition::query()->create([
            'name' => 'Truthful compliance',
            'code' => 'truthful-compliance',
            'acknowledge_target_minutes' => 15,
            'response_target_minutes' => 30,
            'resolution_target_minutes' => 120,
            'is_active' => true,
        ]);

        $metAlert = $this->alertFactory()->resolved()->create([
            'severity' => 'high',
            'triggered_at' => now()->subHours(3),
        ]);
        $metSla = AlertSla::createFromDefinition($metAlert, $definition);
        $metSla->recordAcknowledge($metAlert->triggered_at->copy()->addMinutes(5));
        $metSla->recordResponse($metAlert->triggered_at->copy()->addMinutes(10));
        $metSla->recordResolution($metAlert->triggered_at->copy()->addMinutes(60));

        $pendingAlert = $this->alertFactory()->open()->create([
            'severity' => 'high',
            'triggered_at' => now(),
        ]);
        AlertSla::createFromDefinition($pendingAlert, $definition);

        $overdueAlert = $this->alertFactory()->open()->create([
            'severity' => 'high',
            'triggered_at' => now()->subHours(4),
        ]);
        $overdueSla = AlertSla::createFromDefinition($overdueAlert, $definition);

        $dismissedAlert = $this->alertFactory()->create([
            'status' => ControlRoomAlert::STATUS_DISMISSED,
            'severity' => 'high',
            'triggered_at' => now()->subHours(2),
        ]);
        $dismissedSla = AlertSla::createFromDefinition($dismissedAlert, $definition);
        $dismissedSla->endAsDismissed(now()->subHour());

        $nonApplicableAlert = $this->alertFactory()->open()->create([
            'severity' => 'high',
            'triggered_at' => now()->subHours(2),
        ]);
        AlertSla::query()->create([
            'alert_id' => $nonApplicableAlert->id,
            'sla_definition_id' => null,
            'acknowledge_deadline' => now()->subHour(),
            'acknowledge_breached' => true,
        ]);

        return $overdueSla;
    }

    private function alertFactory(): ControlRoomAlertFactory
    {
        return ControlRoomAlert::factory()->state([
            'site_id' => $this->site->id,
        ]);
    }
}
