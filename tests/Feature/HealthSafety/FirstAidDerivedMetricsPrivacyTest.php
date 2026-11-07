<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\FirstAidRecord;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\Permission;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsAnalyticsService;
use App\Services\HealthSafety\HsDashboardService;
use App\Services\HealthSafety\HsKpiService;
use App\Services\HealthSafety\HsModuleSummaryService;
use App\Services\HealthSafety\RiskAssessmentWorkspaceConnectionsService;
use App\Support\HealthSafety\RiskAssessmentPresenter;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class FirstAidDerivedMetricsPrivacyTest extends TestCase
{
    use \Tests\Support\LoadsHealthSafetyOverview;

    use RefreshDatabase;

    private Site $site;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-08T01:00:00Z'));
        $this->site = Site::factory()->create();
        $this->viewer = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->viewer->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true]);
        foreach (['hazards.view' => true, 'hazards.manage' => false, 'healthSafety.viewAllSites' => false, 'clients.viewAny' => false, 'clients.viewAssigned' => true, 'sites.viewAll' => false, 'clinical.accessAllSites' => false] as $key => $allowed) {
            $this->grant($key, $allowed);
        }
    }

    public function test_actual_dashboard_excludes_denied_first_aid_review_counts_dates_league_and_child_identity_narrative(): void
    {
        $control = $this->event(null, ['worksafe_notifiable' => true, 'worksafe_status' => HsEvent::WORKSAFE_PENDING, 'occurred_at' => now()->subDays(5)]);
        $controlAction = $this->action($control, ['title' => 'Visible action', 'status' => HsCorrectiveAction::STATUS_IN_PROGRESS]);
        $controlInvestigation = $this->investigation($control, 'Visible cause');
        $private = $this->event($this->source(Client::factory()->create(['site_id' => $this->site->id])), ['worksafe_notifiable' => true, 'worksafe_status' => HsEvent::WORKSAFE_PENDING]);
        $privateAction = $this->action($private, ['title' => 'Private clinical action', 'status' => HsCorrectiveAction::STATUS_IN_PROGRESS]);
        $privateInvestigation = $this->investigation($private, 'Private clinical cause');
        $props = $this->dashboard();
        $this->assertSame(5, $props['kpis']['days_since_notifiable']);
        $this->assertSame(1, $props['backbone']['events']['open_events']);
        $this->assertSame(1, $props['backbone']['investigations']['active_investigations']);
        $this->assertSame(1, $props['backbone']['corrective_actions']['open_actions']);
        $this->assertSame(1, $props['worklists']['attention'][0]['count']);
        $this->assertSame([$control->id], array_column($props['worklists']['notifiable_events'], 'id'));
        $this->assertSame([$controlAction->id], array_column($props['worklists']['overdue_corrective_actions'], 'id'));
        $this->assertSame([$controlInvestigation->id], array_column($props['worklists']['open_investigations'], 'id'));
        $this->assertSame(1, collect($props['site_league'])->firstWhere('id', $this->site->id)['incidents']);
        $serialized = json_encode($props['worklists']);
        $this->assertStringNotContainsString('Private clinical', $serialized);
        $this->assertNotContains($private->client_id, array_column($props['worklists']['open_investigations'], 'client_id'));
        $this->assertDatabaseHas('hs_corrective_actions', ['id' => $privateAction->id]);
        $this->assertDatabaseHas('hs_investigations', ['id' => $privateInvestigation->id]);
    }

    public function test_actual_analytics_root_cause_records_csv_and_action_rates_exclude_denied_clinical_children(): void
    {
        $control = $this->event();
        $this->investigation($control, 'Visible procedural cause');
        $this->action($control, ['completed_at' => now()->subDays(4), 'status' => HsCorrectiveAction::STATUS_COMPLETED]);
        $private = $this->event($this->source(Client::factory()->create(['site_id' => $this->site->id])));
        $this->investigation($private, 'Private clinical cause');
        $this->action($private, ['completed_at' => now(), 'status' => HsCorrectiveAction::STATUS_COMPLETED]);
        $props = $this->analytics();
        $this->assertSame(['Visible procedural cause'], array_column($props['root_cause_data'], 'cause'));
        $this->assertSame(100, $props['period_summary']['actions_on_time_pct']);
        $this->assertEquals(100, collect($props['scorecard']['leading'])->firstWhere('key', 'actions_on_time_pct')['value']);
        $this->assertEquals(100, collect($props['trends'])->firstWhere('month', '2026-10')['ca_pct_on_time']);
        $records = $this->getJson('/health-safety/analytics/records?view=root_cause&period=30d')->assertOk()->json();
        $this->assertSame(1, $records['total']);
        $this->assertSame('Visible procedural cause', $records['rows'][0][0]);
        $csv = $this->get('/health-safety/analytics/export?view=root_cause&period=30d')->assertOk()->streamedContent();
        $this->assertStringContainsString('Visible procedural cause', $csv);
        $this->assertStringNotContainsString('Private clinical cause', $csv);
        $this->assertSame([], $props['incident_data']);
        $this->assertSame(['notified' => 0, 'awaiting' => 0], $props['worksafe_notifiable']);
    }

    public function test_exact_client_authority_retains_first_aid_derived_figures_and_root_cause_export(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $client->supportWorkers()->attach($this->viewer->id);
        $event = $this->event($this->source($client), ['worksafe_notifiable' => true, 'worksafe_status' => HsEvent::WORKSAFE_PENDING]);
        $action = $this->action($event, ['title' => 'Authorized source action', 'status' => HsCorrectiveAction::STATUS_IN_PROGRESS]);
        $investigation = $this->investigation($event, 'Authorized source cause');
        $props = $this->dashboard();
        $this->assertSame(1, $props['kpis']['days_since_notifiable']);
        $this->assertSame(1, $props['backbone']['events']['events_period']);
        $this->assertSame(1, $props['backbone']['events']['events_by_category'][HsEvent::CATEGORY_INJURY]);
        $this->assertSame([$action->id], array_column($props['worklists']['overdue_corrective_actions'], 'id'));
        $this->assertSame([$investigation->id], array_column($props['worklists']['open_investigations'], 'id'));
        $this->assertSame($client->id, $props['worklists']['open_investigations'][0]['client_id']);
        $this->assertSame(['Authorized source cause'], array_column($this->analytics()['root_cause_data'], 'cause'));
        $this->assertStringContainsString('Authorized source cause', $this->get('/health-safety/analytics/export?view=root_cause&period=30d')->assertOk()->streamedContent());
    }

    public function test_reused_cached_actor_and_services_cannot_retain_revoked_client_grant_or_assignment(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $client->supportWorkers()->attach($this->viewer->id);
        $event = $this->event($this->source($client));
        $this->investigation($event, 'Revoked source cause');
        $this->action($event, ['completed_at' => now()->subDays(4)]);
        $service = app(HsDashboardService::class);
        $this->viewer->load('permissionOverrides', 'assignedClients', 'hrEmployeeProfile');
        $this->assertTrue($this->viewer->canDo('clients.viewAssigned'));
        $this->assertSame(1, $service->getEventKpis(siteId: $this->site->id, viewer: $this->viewer)['open_events']);
        $this->grant('clients.viewAssigned', false);
        $this->assertSame(0, $service->getEventKpis(siteId: $this->site->id, viewer: $this->viewer)['open_events']);
        $this->assertSame([], $this->analytics()['root_cause_data']);
        $this->assertNull($this->dashboard()['leading_lagging']['leading']['actions_on_time_pct']);
        $this->assertStringNotContainsString('Revoked source cause', $this->get('/health-safety/analytics/export?view=root_cause&period=30d')->assertOk()->streamedContent());
        $this->grant('clients.viewAssigned', true);
        $this->assertSame(1, $service->getEventKpis(siteId: $this->site->id, viewer: $this->viewer)['open_events']);
        $client->supportWorkers()->detach($this->viewer->id);
        $this->assertSame(0, $service->getInvestigationKpis($this->site->id, $this->viewer)['active_investigations']);
        $this->assertSame([], $service->openInvestigations($this->site->id, viewer: $this->viewer));
    }

    public function test_exact_source_tuple_is_required_before_any_derived_child_metrics(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $client->supportWorkers()->attach($this->viewer->id);
        $record = $this->source($client);
        $event = $this->event($record);
        $this->investigation($event, 'Transferred source cause');
        $this->action($event, ['completed_at' => now()->subDays(4)]);
        $this->assertSame(1, app(HsDashboardService::class)->getCorrectiveActionKpis($this->site->id, $this->viewer)['open_actions']);
        $record->forceFill(['client_id' => Client::factory()->create(['site_id' => $this->site->id])->id])->saveQuietly();
        $this->assertSame([], $this->analytics()['root_cause_data']);
        $this->assertSame(0, $this->dashboard()['backbone']['events']['open_events']);
        $record->forceFill(['client_id' => $client->id])->saveQuietly();
        $event->forceFill(['event_category' => HsEvent::CATEGORY_INCIDENT])->saveQuietly();
        $this->assertSame([], app(HsDashboardService::class)->overdueCorrectiveActions($this->site->id, viewer: $this->viewer));
        $event->forceFill(['event_category' => HsEvent::CATEGORY_INJURY, 'staff_id' => $this->viewer->id])->saveQuietly();
        $this->assertSame([], $this->analytics()['root_cause_data']);
        $event->forceFill(['staff_id' => null])->saveQuietly();
        $client->forceFill(['site_id' => Site::factory()->create()->id])->saveQuietly();
        $this->assertSame(0, app(HsDashboardService::class)->getEventKpis(viewer: $this->viewer)['open_events']);
    }

    public function test_cached_current_staff_and_site_revocation_remove_first_aid_derivatives_without_cross_request_audience_cache(): void
    {
        $event = $this->event($this->source());
        $this->investigation($event, 'Staff source cause');
        $this->viewer->load('hrEmployeeProfile', 'permissionOverrides');
        $service = app(HsDashboardService::class);
        $this->assertSame(1, $service->getEventKpis(viewer: $this->viewer)['open_events']);
        $this->viewer->hrEmployeeProfile->forceFill(['is_active' => false])->save();
        $this->assertSame(0, $service->getInvestigationKpis(viewer: $this->viewer)['active_investigations']);
        $this->viewer->hrEmployeeProfile->forceFill(['is_active' => true])->save();
        $this->assertSame(1, $service->getEventKpis(viewer: $this->viewer)['open_events']);
        $this->site->forceFill(['is_active' => false])->saveQuietly();
        $this->assertSame([], $service->openInvestigations(viewer: $this->viewer));
        $this->assertSame([], app(HsAnalyticsService::class)->exportRows('root_cause', null, now()->subDays(30), now(), viewer: $this->viewer)['rows']);
    }

    public function test_actorless_methods_remove_only_first_aid_sources_and_keep_existing_other_source_data(): void
    {
        $control = $this->event();
        $this->investigation($control, 'Other domain cause');
        $this->action($control, ['completed_at' => now()->subDays(4)]);
        $private = $this->event($this->source());
        $this->investigation($private, 'First aid source cause');
        $this->action($private, ['completed_at' => now()]);
        $summary = app(HsDashboardService::class)->getDashboardSummary();
        $this->assertSame(1, $summary['events']['open_events']);
        $this->assertSame(1, $summary['investigations']['active_investigations']);
        $this->assertSame(1, $summary['corrective_actions']['open_actions']);
        $this->assertEquals(100, app(HsKpiService::class)->actionsClosedOnTimePct());
        $rows = app(HsAnalyticsService::class)->exportRows('root_cause', null, now()->subDays(30), now())['rows'];
        $this->assertSame(['Other domain cause'], array_column($rows, 0));
    }

    public function test_archived_source_does_not_silently_hide_independent_pending_review_from_authorized_reader(): void
    {
        $record = $this->source();
        $event = $this->event($record, ['worksafe_notifiable' => true, 'worksafe_status' => HsEvent::WORKSAFE_PENDING]);
        $record->delete();
        $props = $this->dashboard();
        $this->assertSame(0, $props['first_aid']['treatments']);
        $this->assertSame(1, $props['backbone']['events']['worksafe_pending']);
        $this->assertSame([$event->id], array_column($props['worklists']['notifiable_events'], 'id'));
        $this->grant('hazards.view', false);
        $this->assertSame([], app(HsDashboardService::class)->notifiableEvents(viewer: $this->viewer));
    }

    public function test_known_private_first_aid_risk_provenance_is_hidden_in_direct_presenters_connections_and_dashboard_feed(): void
    {
        $event = $this->event($this->source(Client::factory()->create(['site_id' => $this->site->id])));
        $modern = HsRiskAssessment::factory()->active()->forSite($this->site->id)->create(['hs_event_id' => $event->id, 'review_due_at' => now()->subDay(), 'title' => 'Private treatment risk']);
        $legacy = HsRiskAssessment::factory()->active()->create(['assessable_type' => $event->getMorphClass(), 'assessable_id' => $event->id, 'hs_event_id' => null, 'review_due_at' => now()->subDay(), 'title' => 'Private legacy treatment risk']);
        $standalone = HsRiskAssessment::factory()->active()->forSite($this->site->id)->create(['review_due_at' => now()->subDay(), 'title' => 'Standalone Site risk']);
        foreach ([$modern, $legacy] as $assessment) {
            $this->assertRiskProjectionHidden(fn () => RiskAssessmentPresenter::row($assessment, $this->viewer), $assessment);
            $this->assertRiskProjectionHidden(fn () => RiskAssessmentPresenter::detail($assessment, false, $this->viewer), $assessment);
            $connections = app(RiskAssessmentWorkspaceConnectionsService::class)->forViewer($assessment, $this->viewer);
            $this->assertNull($connections['source']);
            $this->assertNull($connections['safety_review']);
            $this->assertSame([], $connections['corrective_actions']);
            $this->actingAs($this->viewer)->getJson('/health-safety/risk-assessments/'.$assessment->id)->assertForbidden();
        }
        $props = $this->dashboard();
        $this->assertSame(1, $props['backbone']['risk_assessments']['active_assessments']);
        $this->assertSame(1, $props['backbone']['risk_assessments']['due_for_review']);
        $this->assertSame(['Standalone Site risk'], array_column(collect($props['worklists']['expiring'])->where('type', 'risk_assessment')->all(), 'label'));
        $this->assertSame($standalone->id, RiskAssessmentPresenter::row($standalone, $this->viewer)['id']);
        $this->assertSame(1, app(HsDashboardService::class)->getRiskAssessmentKpis($this->site->id)['active_assessments']);
        $actorlessFeed = app(HsDashboardService::class)->expiringFeed($this->site->id);
        $this->assertSame(['Standalone Site risk'], array_column(collect($actorlessFeed)->where('type', 'risk_assessment')->all(), 'label'));
        $picker = RiskAssessmentPresenter::siteScopedPickers([$this->site->id], $this->viewer);
        $this->assertNotContains($event->id, $picker['events']->pluck('id')->all());
    }

    public function test_exact_first_aid_risk_reader_retains_projections_but_cached_client_revocation_removes_them(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $client->supportWorkers()->attach($this->viewer->id);
        $event = $this->event($this->source($client));
        $assessment = HsRiskAssessment::factory()->active()->forSite($this->site->id)->create(['hs_event_id' => $event->id, 'review_due_at' => now()->subDay()]);
        $this->viewer->load('permissionOverrides', 'assignedClients', 'hrEmployeeProfile');
        $this->assertSame($assessment->id, RiskAssessmentPresenter::detail($assessment, false, $this->viewer)['id']);
        $this->assertSame($event->id, app(RiskAssessmentWorkspaceConnectionsService::class)->forViewer($assessment, $this->viewer)['safety_review']['id']);
        $this->assertContains($event->id, RiskAssessmentPresenter::siteScopedPickers([$this->site->id], $this->viewer)['events']->pluck('id')->all());
        $this->assertSame(1, $this->dashboard()['backbone']['risk_assessments']['active_assessments']);
        $this->actingAs($this->viewer)->getJson('/health-safety/risk-assessments/'.$assessment->id)->assertOk();
        $this->grant('clients.viewAssigned', false);
        $this->assertRiskProjectionHidden(fn () => RiskAssessmentPresenter::detail($assessment, false, $this->viewer), $assessment);
        $this->assertNull(app(RiskAssessmentWorkspaceConnectionsService::class)->forViewer($assessment, $this->viewer)['safety_review']);
        $this->assertSame(0, $this->dashboard()['backbone']['risk_assessments']['active_assessments']);
        $this->assertNotContains($event->id, RiskAssessmentPresenter::siteScopedPickers([$this->site->id], $this->viewer)['events']->pluck('id')->all());
        $this->getJson('/health-safety/risk-assessments/'.$assessment->id)->assertForbidden();
    }

    public function test_actual_authorized_site_profile_risk_partial_omits_denied_source_without_aborting_visible_rows(): void
    {
        $this->grant('sites.viewAny', true);
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $client->supportWorkers()->attach($this->viewer->id);
        $allowedEvent = $this->event($this->source($client));
        $deniedEvent = $this->event($this->source(Client::factory()->create(['site_id' => $this->site->id])));
        $ordinary = HsRiskAssessment::factory()->forSite($this->site->id)->create(['title' => 'Ordinary Site assessment']);
        $allowed = HsRiskAssessment::factory()->forSite($this->site->id)->create(['hs_event_id' => $allowedEvent->id, 'title' => 'Readable treatment assessment']);
        $denied = HsRiskAssessment::factory()->forSite($this->site->id)->create(['hs_event_id' => $deniedEvent->id, 'title' => 'Denied treatment assessment']);
        $headers = $this->inertiaPartialHeaders('sites/show', 'riskAssessmentsData');
        $response = $this->actingAs($this->viewer)->get('/sites/'.$this->site->id.'?tab=risk_assessments', $headers)->assertOk()
            ->assertJsonPath('component', 'sites/show')->assertJsonPath('props.riskAssessmentsData.locked', false);
        $rows = $response->json('props.riskAssessmentsData.assessments');
        $this->assertEqualsCanonicalizing([$ordinary->id, $allowed->id], array_column($rows, 'id'));
        $this->assertNotContains($denied->id, array_column($rows, 'id'));
        $this->assertStringNotContainsString('Denied treatment assessment', json_encode($rows));
        $this->grant('clients.viewAssigned', false);
        $revoked = $this->get('/sites/'.$this->site->id.'?tab=risk_assessments', $headers)->assertOk()
            ->assertJsonPath('props.riskAssessmentsData.locked', false);
        $this->assertSame([$ordinary->id], array_column($revoked->json('props.riskAssessmentsData.assessments'), 'id'));
    }

    public function test_populated_restraint_children_keep_filtered_metrics_worklists_and_current_source_permission_parity(): void
    {
        $this->grant('restraints.view', true);
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $client->supportWorkers()->attach($this->viewer->id);
        $asset = Asset::factory()->create(['site_id' => $this->site->id]);
        $control = $this->event(null, ['client_id' => $client->id, 'asset_id' => $asset->id, 'staff_id' => $this->viewer->id]);
        $allowed = $this->restraintEvent($client, ['asset_id' => $asset->id]);
        $denied = $this->restraintEvent(Client::factory()->create(['site_id' => $this->site->id]), ['asset_id' => $asset->id]);
        $controlAction = $this->action($control, ['title' => 'Visible ordinary action', 'due_date' => now()->subDays(3), 'completed_at' => now()->subDays(4), 'status' => HsCorrectiveAction::STATUS_COMPLETED]);
        $allowedAction = $this->action($allowed, ['title' => 'Allowed restraint action', 'completed_at' => now(), 'status' => HsCorrectiveAction::STATUS_COMPLETED]);
        $this->action($denied, ['title' => 'Denied restraint action', 'due_date' => now()->subDays(10), 'completed_at' => now(), 'status' => HsCorrectiveAction::STATUS_COMPLETED]);
        $controlInvestigation = $this->investigation($control, 'Visible ordinary cause');
        $allowedInvestigation = $this->investigation($allowed, 'Allowed restraint cause');
        $deniedInvestigation = $this->investigation($denied, 'Denied restraint cause');
        $controlInvestigation->updateQuietly(['target_completion_date' => now()->subDays(3)]);
        $deniedInvestigation->updateQuietly(['target_completion_date' => now()->subDays(10)]);
        $dashboard = app(HsDashboardService::class);
        $module = app(HsModuleSummaryService::class);
        $this->viewer->load('permissionOverrides', 'assignedClients', 'hrEmployeeProfile');
        $this->assertSame(2, $dashboard->getInvestigationKpis($this->site->id, $this->viewer)['active_investigations']);
        $this->assertSame(2, $dashboard->getCorrectiveActionKpis($this->site->id, $this->viewer)['open_actions']);
        $this->assertSame([$controlAction->id], array_column($dashboard->overdueCorrectiveActions($this->site->id, 1, $this->viewer), 'id'));
        $this->assertSame([$controlInvestigation->id], array_column($dashboard->openInvestigations($this->site->id, 1, $this->viewer), 'id'));
        $this->assertEqualsCanonicalizing([$controlAction->id, $allowedAction->id], array_column($dashboard->overdueCorrectiveActions($this->site->id, viewer: $this->viewer), 'id'));
        $this->assertEqualsCanonicalizing([$controlInvestigation->id, $allowedInvestigation->id], array_column($dashboard->openInvestigations($this->site->id, viewer: $this->viewer), 'id'));
        foreach ([$module->forSite($this->site->id, $this->viewer), $module->forClient($client->id, $this->viewer), $module->forAsset($asset->id, $this->viewer)] as $summary) {
            $this->assertSame(2, $summary['open_events']);
            $this->assertSame(2, $summary['open_corrective_actions']);
            $this->assertEqualsCanonicalizing([$control->id, $allowed->id], array_column($summary['recent_events'], 'id'));
        }
        $this->assertSame(2, $module->forStaff($this->viewer->id, $this->viewer)['active_investigations']);
        $analytics = $this->analytics();
        $this->assertEqualsCanonicalizing(['Visible ordinary cause', 'Allowed restraint cause'], array_column($analytics['root_cause_data'], 'cause'));
        $this->assertEquals(50, $analytics['period_summary']['actions_on_time_pct']);
        $this->assertEquals(50, collect($analytics['trends'])->firstWhere('month', '2026-10')['ca_pct_on_time']);
        $this->assertEquals(50, app(HsKpiService::class)->actionsClosedOnTimePct(siteId: $this->site->id, viewer: $this->viewer));
        $this->grant('restraints.view', false);
        $this->assertSame(1, $dashboard->getInvestigationKpis($this->site->id, $this->viewer)['active_investigations']);
        $this->assertSame(1, $dashboard->getCorrectiveActionKpis($this->site->id, $this->viewer)['open_actions']);
        $this->assertSame([$controlAction->id], array_column($dashboard->overdueCorrectiveActions($this->site->id, viewer: $this->viewer), 'id'));
        $this->assertSame([$controlInvestigation->id], array_column($dashboard->openInvestigations($this->site->id, viewer: $this->viewer), 'id'));
        $this->assertSame(1, $module->forSite($this->site->id, $this->viewer)['open_corrective_actions']);
        $this->assertSame(1, $module->forStaff($this->viewer->id, $this->viewer)['active_investigations']);
        $revoked = $this->analytics();
        $this->assertSame(['Visible ordinary cause'], array_column($revoked['root_cause_data'], 'cause'));
        $this->assertEquals(100, $revoked['period_summary']['actions_on_time_pct']);
        $this->assertEquals(100, app(HsKpiService::class)->actionsClosedOnTimePct(siteId: $this->site->id, viewer: $this->viewer));
        $this->assertStringNotContainsString('restraint cause', json_encode($revoked['root_cause_data']));
    }

    public function test_filtered_aggregate_candidates_do_not_hydrate_unrelated_private_restraint_sources(): void
    {
        $this->grant('restraints.view', true);
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $asset = Asset::factory()->create(['site_id' => $this->site->id]);
        $staff = User::factory()->create();
        $ordinary = $this->event(null, ['client_id' => $client->id, 'asset_id' => $asset->id, 'staff_id' => $staff->id]);
        $action = $this->action($ordinary, ['assigned_to_user_id' => $staff->id, 'completed_at' => now()->subDays(4)]);
        $investigation = $this->investigation($ordinary, 'Requested ordinary cause');
        $investigation->updateQuietly(['lead_investigator_id' => $staff->id]);
        $otherClient = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
        $otherClient->supportWorkers()->attach($this->viewer->id);
        $private = $this->restraintEvent($otherClient);
        $this->action($private, ['completed_at' => now()]);
        $this->investigation($private, 'Unrelated private cause');
        $hydrated = [];
        Event::listen('eloquent.retrieved: '.RestraintEvent::class, function (RestraintEvent $source) use (&$hydrated): void {
            $hydrated[] = $source->id;
        });
        $dashboard = app(HsDashboardService::class);
        $module = app(HsModuleSummaryService::class);
        $this->assertSame(1, $dashboard->getInvestigationKpis($this->site->id, $this->viewer)['active_investigations']);
        $this->assertSame(1, $dashboard->getCorrectiveActionKpis($this->site->id, $this->viewer)['open_actions']);
        $this->assertSame([$action->id], array_column($dashboard->overdueCorrectiveActions($this->site->id, viewer: $this->viewer), 'id'));
        $this->assertSame([$investigation->id], array_column($dashboard->openInvestigations($this->site->id, viewer: $this->viewer), 'id'));
        $this->assertEquals(100, app(HsKpiService::class)->actionsClosedOnTimePct(siteId: $this->site->id, viewer: $this->viewer));
        $rows = app(HsAnalyticsService::class)->exportRows('root_cause', $this->site->id, now()->subDays(30), now(), viewer: $this->viewer)['rows'];
        $this->assertSame(['Requested ordinary cause'], array_column($rows, 0));
        foreach ([$module->forSite($this->site->id, $this->viewer), $module->forClient($client->id, $this->viewer), $module->forAsset($asset->id, $this->viewer)] as $summary) {
            $this->assertSame(1, $summary['open_events']);
            $this->assertSame(1, $summary['open_corrective_actions']);
            $this->assertSame([$ordinary->id], array_column($summary['recent_events'], 'id'));
        }
        $staffSummary = $module->forStaff($staff->id, $this->viewer);
        $this->assertSame(1, $staffSummary['involved_events']);
        $this->assertSame(1, $staffSummary['assigned_corrective_actions']);
        $this->assertSame(1, $staffSummary['active_investigations']);
        $this->assertSame([], $hydrated, 'Requested Site/Client/asset/staff child bases must not hydrate the unrelated restraint source.');
    }

    public function test_filtered_risk_and_staff_children_retain_private_deleted_provenance_and_ordinary_orphans(): void
    {
        $this->grant('restraints.view', true);
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $client->supportWorkers()->attach($this->viewer->id);
        $event = $this->restraintEvent($client);
        $this->action($event);
        $this->investigation($event, 'Private source review');
        $risk = HsRiskAssessment::factory()->active()->forClient($client->id)->createQuietly(['hs_event_id' => $event->id]);
        $ordinary = $this->event(null, ['staff_id' => $this->viewer->id]);
        $ordinaryAction = $this->action($ordinary);
        $this->investigation($ordinary, 'Ordinary orphan review');
        $module = app(HsModuleSummaryService::class);
        $dashboard = app(HsDashboardService::class);
        $this->assertSame(1, $module->forClient($client->id, $this->viewer)['active_risk_assessments']);
        $this->assertSame(1, $dashboard->getRiskAssessmentKpis($this->site->id, $this->viewer)['active_assessments']);
        $this->assertSame(2, $module->forStaff($this->viewer->id, $this->viewer)['assigned_corrective_actions']);
        $this->grant('restraints.view', false);
        $this->assertSame(0, $module->forClient($client->id, $this->viewer)['active_risk_assessments']);
        $this->assertSame(0, $dashboard->getRiskAssessmentKpis($this->site->id, $this->viewer)['active_assessments']);
        $this->assertSame(1, $module->forStaff($this->viewer->id, $this->viewer)['assigned_corrective_actions']);
        $this->grant('restraints.view', true);
        $event->deleteQuietly();
        $ordinary->deleteQuietly();
        $staff = $module->forStaff($this->viewer->id, $this->viewer);
        $this->assertSame(0, $staff['involved_events']);
        $this->assertSame(1, $staff['assigned_corrective_actions']);
        $this->assertSame(1, $staff['overdue_corrective_actions']);
        $this->assertSame(1, $staff['led_investigations']);
        $this->assertSame(1, $staff['active_investigations']);
        $this->assertSame(0, $module->forClient($client->id, $this->viewer)['active_risk_assessments']);
        $this->assertDatabaseHas('hs_risk_assessments', ['id' => $risk->id, 'hs_event_id' => $event->id]);
        $this->assertDatabaseHas('hs_corrective_actions', ['id' => $ordinaryAction->id, 'hs_event_id' => $ordinary->id]);
    }

    private function restraintEvent(Client $client, array $fields = []): HsEvent
    {
        $source = RestraintEvent::factory()->createQuietly(['client_id' => $client->id, 'site_id' => $client->site_id, 'created_by' => $this->viewer->id]);

        return $this->event(null, ['source_type' => $source->getMorphClass(), 'source_id' => $source->id, 'event_category' => HsEvent::CATEGORY_RESTRAINT,
            'site_id' => $source->site_id, 'client_id' => $source->client_id, 'staff_id' => $source->created_by, ...$fields]);
    }

    private function assertRiskProjectionHidden(callable $projection, HsRiskAssessment $assessment): void
    {
        try {
            $projection();
            $this->fail('A denied First Aid source must not expose a risk projection.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(HsRiskAssessment::class, $exception->getModel());
            $this->assertSame([$assessment->getKey()], $exception->getIds());
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    private function dashboard(): array
    {
        return $this->actingAs($this->viewer)->getOverview('/health-safety')->assertOk()->inertiaProps();
    }

    private function analytics(): array
    {
        return $this->actingAs($this->viewer)->get('/health-safety/analytics?period=30d')->assertOk()->inertiaProps();
    }

    private function source(?Client $client = null): FirstAidRecord
    {
        return FirstAidRecord::factory()->createQuietly(['site_id' => $this->site->id, 'first_aider_id' => $this->viewer->id, 'created_by' => $this->viewer->id, 'treated_person_type' => $client ? 'client' : 'staff', 'treated_person_id' => null, 'client_id' => $client?->id, 'treatment_date' => now()->subDay()]);
    }

    private function event(?FirstAidRecord $record = null, array $fields = []): HsEvent
    {
        return HsEvent::factory()->createQuietly(['site_id' => $this->site->id, 'created_by' => $this->viewer->id, 'client_id' => $record?->client_id, 'staff_id' => $record?->treated_person_id, 'source_type' => $record ? $record->getMorphClass() : HsEvent::class, 'source_id' => $record?->id ?? random_int(1000000, 2000000), 'idempotency_key' => (string) Str::uuid(), 'event_category' => $record ? HsEvent::CATEGORY_INJURY : HsEvent::CATEGORY_INCIDENT, 'status' => HsEvent::STATUS_OPEN, 'occurred_at' => now()->subDay(), 'reported_at' => now()->subDay(), 'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE, ...$fields]);
    }

    private function investigation(HsEvent $event, string $cause): HsInvestigation
    {
        return HsInvestigation::factory()->createQuietly(['hs_event_id' => $event->id, 'created_by' => $this->viewer->id, 'lead_investigator_id' => $this->viewer->id, 'status' => HsInvestigation::STATUS_IN_PROGRESS, 'target_completion_date' => now()->subDay(), 'findings_summary' => $cause, 'root_causes' => [['category' => $cause, 'description' => $cause]]]);
    }

    private function action(HsEvent $event, array $fields = []): HsCorrectiveAction
    {
        return HsCorrectiveAction::factory()->createQuietly(['hs_event_id' => $event->id, 'created_by' => $this->viewer->id, 'assigned_to_user_id' => $this->viewer->id, 'due_date' => now()->subDays(2), 'created_at' => now()->subDays(6), ...$fields]);
    }

    private function grant(string $key, bool $allowed): void
    {
        $this->viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => $allowed]]);
    }
}
