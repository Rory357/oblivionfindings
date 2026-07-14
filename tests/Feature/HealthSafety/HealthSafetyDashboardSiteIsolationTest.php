<?php

declare(strict_types=1);

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\EmergencyDrill;
use App\Models\FleetIncident;
use App\Models\HazardousSubstance;
use App\Models\HsCommittee;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRepresentative;
use App\Models\HsRiskAssessment;
use App\Models\HsTrainingRequirement;
use App\Models\Permission;
use App\Models\PpeAllocation;
use App\Models\PpeInventory;
use App\Models\RestraintEvent;
use App\Models\SafeWorkProcedure;
use App\Models\SafeguardingConcern;
use App\Models\SafetyDataSheet;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\SubstanceStorageLocation;
use App\Models\User;
use App\Models\WorkplaceInjury;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HealthSafetyDashboardSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_site_bound_user_dashboard_kpis_and_incident_trends_exclude_foreign_site_data(): void
    {
        $visibleSite = Site::factory()->create(['name' => 'Accessible Site']);
        $foreignSite = Site::factory()->create(['name' => 'Foreign Site']);
        $viewer = $this->siteBoundUser($visibleSite, ['hazards.view'], 'H&S Viewer');

        $visibleClient = Client::factory()->create(['site_id' => $visibleSite->id]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);

        ClientIncident::factory()->create([
            'client_id' => $visibleClient->id,
            'site_id' => $visibleSite->id,
            'type' => 'near_miss',
            'occurred_at' => now()->subDay(),
        ]);
        ClientIncident::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
            'type' => 'near_miss',
            'occurred_at' => now()->subDay(),
        ]);

        $this->makeOpenHazard($visibleSite, $viewer, 'Accessible overdue hazard');
        $this->makeOpenHazard($foreignSite, $viewer, 'Foreign overdue hazard');

        WorkplaceInjury::factory()->create([
            'site_id' => $visibleSite->id,
            'injury_date' => now()->subDays(5),
            'lost_time_days' => 2,
        ]);
        WorkplaceInjury::factory()->create([
            'site_id' => $foreignSite->id,
            'injury_date' => now()->subDay(),
            'lost_time_days' => 99,
        ]);

        EmergencyDrill::factory()->completed()->create([
            'site_id' => $visibleSite->id,
            'completed_at' => now()->subMonth(),
        ]);

        ControlRoomAlert::factory()->open()->create([
            'site_id' => $visibleSite->id,
            'source' => 'lone_worker',
        ]);
        ControlRoomAlert::factory()->open()->create([
            'site_id' => $foreignSite->id,
            'source' => 'lone_worker',
        ]);

        SafeguardingConcern::factory()->investigating()->withSite($visibleSite)->create();
        SafeguardingConcern::factory()->investigating()->withSite($foreignSite)->create();

        $visibleAsset = Asset::factory()->forSite($visibleSite)->create([
            'created_by_user_id' => $viewer->id,
            'updated_by_user_id' => $viewer->id,
        ]);
        $foreignAsset = Asset::factory()->forSite($foreignSite)->create([
            'created_by_user_id' => $viewer->id,
            'updated_by_user_id' => $viewer->id,
        ]);
        FleetIncident::factory()->create([
            'asset_id' => $visibleAsset->id,
            'reported_by_user_id' => $viewer->id,
            'driver_user_id' => $viewer->id,
            'status' => 'investigating',
            'occurred_at' => now()->subDay(),
        ]);
        FleetIncident::factory()->create([
            'asset_id' => $foreignAsset->id,
            'reported_by_user_id' => $viewer->id,
            'driver_user_id' => $viewer->id,
            'status' => 'investigating',
            'occurred_at' => now()->subDay(),
        ]);

        $visiblePpe = PpeInventory::factory()->inspectionDue()->expiring()->create([
            'site_id' => $visibleSite->id,
        ]);
        $foreignPpe = PpeInventory::factory()->inspectionDue()->expiring()->create([
            'site_id' => $foreignSite->id,
        ]);
        PpeAllocation::factory()->unacknowledged()->create([
            'ppe_inventory_id' => $visiblePpe->id,
            'user_id' => $viewer->id,
        ]);
        PpeAllocation::factory()->unacknowledged()->create([
            'ppe_inventory_id' => $foreignPpe->id,
            'user_id' => $viewer->id,
        ]);

        $this->actingAs($viewer)
            ->get('/health-safety')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.site', $visibleSite->id)
                ->where('kpis.incidents_30d', 1)
                ->where('kpis.near_misses_30d', 1)
                ->where('kpis.open_hazards', 1)
                ->where('kpis.overdue_actions', 1)
                ->where('kpis.workplace_injuries_ytd', 1)
                ->where('kpis.lost_time_days_ytd', 2)
                ->where('kpis.days_since_lti', 5)
                ->where('kpis.drill_compliance_pct', 100)
                ->where('kpis.active_alerts', 1)
                ->where('kpis.open_safeguarding', 1)
                ->where('kpis.fleet_incidents_30d', 1)
                ->where('kpis.fleet_unresolved', 1)
                ->where('kpis.ppe_inspections_overdue', 1)
                ->where('kpis.ppe_expiring', 1)
                ->where('kpis.ppe_unacknowledged', 1)
                ->has('incident_trends', 1)
                ->where('incident_trends.0.count', 1)
                ->where('incident_trends.0.types.near_miss', 1)
            );
    }

    public function test_site_bound_user_dashboard_worklists_exclude_foreign_site_rows(): void
    {
        $visibleSite = Site::factory()->create(['name' => 'Accessible Site']);
        $foreignSite = Site::factory()->create(['name' => 'Foreign Site']);
        $viewer = $this->siteBoundUser($visibleSite, ['hazards.view']);

        $visibleEvent = HsEvent::factory()->create([
            'site_id' => $visibleSite->id,
            'created_by' => $viewer->id,
        ]);
        $foreignEvent = HsEvent::factory()->create([
            'site_id' => $foreignSite->id,
            'created_by' => $viewer->id,
        ]);

        $visibleAction = HsCorrectiveAction::factory()->overdue()->create([
            'hs_event_id' => $visibleEvent->id,
            'created_by' => $viewer->id,
        ]);
        HsCorrectiveAction::factory()->overdue()->create([
            'hs_event_id' => $foreignEvent->id,
            'created_by' => $viewer->id,
        ]);

        $visibleInvestigation = HsInvestigation::factory()->inProgress()->create([
            'hs_event_id' => $visibleEvent->id,
            'created_by' => $viewer->id,
            'target_completion_date' => now()->addDays(7),
        ]);
        HsInvestigation::factory()->inProgress()->create([
            'hs_event_id' => $foreignEvent->id,
            'created_by' => $viewer->id,
            'target_completion_date' => now()->addDays(7),
        ]);

        $visibleNotifiable = HsEvent::factory()->worksafeNotifiable()->create([
            'site_id' => $visibleSite->id,
            'created_by' => $viewer->id,
        ]);
        HsEvent::factory()->worksafeNotifiable()->create([
            'site_id' => $foreignSite->id,
            'created_by' => $viewer->id,
        ]);

        $visibleRisk = HsRiskAssessment::factory()->active()->forSite($visibleSite->id)->create([
            'title' => 'Accessible risk review',
            'review_due_at' => now()->addDays(5),
            'created_by' => $viewer->id,
        ]);
        $foreignRisk = HsRiskAssessment::factory()->active()->forSite($foreignSite->id)->create([
            'title' => 'Foreign risk review',
            'review_due_at' => now()->addDays(5),
            'created_by' => $viewer->id,
        ]);

        $visibleSubstance = HazardousSubstance::factory()->create(['name' => 'Accessible substance']);
        $foreignSubstance = HazardousSubstance::factory()->create(['name' => 'Foreign substance']);
        SubstanceStorageLocation::factory()->create([
            'hazardous_substance_id' => $visibleSubstance->id,
            'site_id' => $visibleSite->id,
        ]);
        SubstanceStorageLocation::factory()->create([
            'hazardous_substance_id' => $foreignSubstance->id,
            'site_id' => $foreignSite->id,
        ]);
        SafetyDataSheet::factory()->expiring()->create([
            'hazardous_substance_id' => $visibleSubstance->id,
        ]);
        SafetyDataSheet::factory()->expiring()->create([
            'hazardous_substance_id' => $foreignSubstance->id,
        ]);

        EmergencyDrill::factory()->scheduled()->create([
            'site_id' => $visibleSite->id,
            'scheduled_at' => now()->addDays(5),
            'created_by' => $viewer->id,
        ]);
        EmergencyDrill::factory()->scheduled()->create([
            'site_id' => $foreignSite->id,
            'scheduled_at' => now()->addDays(5),
            'created_by' => $viewer->id,
        ]);

        $this->actingAs($viewer)
            ->get('/health-safety')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('worklists.overdue_corrective_actions', 1)
                ->where('worklists.overdue_corrective_actions.0.id', $visibleAction->id)
                ->has('worklists.open_investigations', 1)
                ->where('worklists.open_investigations.0.id', $visibleInvestigation->id)
                ->has('worklists.notifiable_events', 1)
                ->where('worklists.notifiable_events.0.id', $visibleNotifiable->id)
                ->where('worklists.expiring', function ($items) use (
                    $visibleRisk,
                    $foreignRisk,
                    $visibleSubstance,
                    $foreignSubstance,
                    $visibleSite,
                ): bool {
                    $rows = collect($items);

                    return $rows->count() === 3
                        && $rows->contains(fn ($row) => $row['type'] === 'risk_assessment' && $row['label'] === $visibleRisk->title)
                        && $rows->doesntContain(fn ($row) => $row['label'] === $foreignRisk->title)
                        && $rows->contains(fn ($row) => $row['type'] === 'sds' && $row['label'] === $visibleSubstance->name)
                        && $rows->doesntContain(fn ($row) => $row['label'] === $foreignSubstance->name)
                        && $rows->contains(fn ($row) => $row['type'] === 'drill' && $row['site'] === $visibleSite->name);
                })
            );
    }

    public function test_site_bound_user_dashboard_site_league_and_open_hazards_exclude_foreign_sites(): void
    {
        $visibleSite = Site::factory()->create(['name' => 'Accessible Site']);
        $foreignSite = Site::factory()->create(['name' => 'Foreign Site']);
        $viewer = $this->siteBoundUser($visibleSite, ['hazards.view']);

        HsEvent::factory()->create([
            'site_id' => $visibleSite->id,
            'occurred_at' => now()->subDay(),
            'created_by' => $viewer->id,
        ]);
        HsEvent::factory()->create([
            'site_id' => $foreignSite->id,
            'occurred_at' => now()->subDay(),
            'created_by' => $viewer->id,
        ]);
        $visibleHazard = $this->makeOpenHazard($visibleSite, $viewer, 'Accessible hazard');
        $this->makeOpenHazard($foreignSite, $viewer, 'Foreign hazard');

        $this->actingAs($viewer)
            ->get('/health-safety')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('site_league', 1)
                ->where('site_league.0.id', $visibleSite->id)
                ->where('site_league.0.incidents', 1)
                ->where('site_league.0.hazards', 1)
                ->has('open_hazards_list', 1)
                ->where('open_hazards_list.0.id', $visibleHazard->id)
                ->where('open_hazards_list.0.site_id', $visibleSite->id)
            );
    }

    public function test_site_bound_user_dashboard_identifiable_options_exclude_foreign_site_people(): void
    {
        $visibleSite = Site::factory()->create(['name' => 'Accessible Site']);
        $foreignSite = Site::factory()->create(['name' => 'Foreign Site']);
        $viewer = $this->siteBoundUser($visibleSite, ['hazards.view'], 'Dashboard Viewer');
        $visibleWorker = $this->siteBoundUser($visibleSite, [], 'Accessible Worker');
        $foreignWorker = $this->siteBoundUser($foreignSite, [], 'Foreign Worker');
        $visibleClient = Client::factory()->create([
            'site_id' => $visibleSite->id,
            'first_name' => 'Accessible',
            'last_name' => 'Client',
        ]);
        Client::factory()->create([
            'site_id' => $foreignSite->id,
            'first_name' => 'Foreign',
            'last_name' => 'Client',
        ]);

        $expectedStaffIds = collect([$viewer->id, $visibleWorker->id])->sort()->values()->all();

        $this->actingAs($viewer)
            ->get('/health-safety')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients', 1)
                ->where('clients.0.id', $visibleClient->id)
                ->where('staff', fn ($staff) => collect($staff)->pluck('id')->sort()->values()->all() === $expectedStaffIds)
            );

        $this->assertNotSame($visibleWorker->id, $foreignWorker->id);
    }

    public function test_site_bound_user_dashboard_summary_sections_do_not_fall_back_to_global_data(): void
    {
        $visibleSite = Site::factory()->create(['name' => 'Accessible Site']);
        $foreignSite = Site::factory()->create(['name' => 'Foreign Site']);
        $viewer = $this->siteBoundUser($visibleSite, ['hazards.view'], 'Dashboard Viewer');
        $foreignWorker = $this->siteBoundUser($foreignSite, [], 'Foreign Worker');

        HsRiskAssessment::factory()->active()->forSite($visibleSite->id)->create([
            'assessed_by_user_id' => $viewer->id,
            'created_by' => $viewer->id,
        ]);
        HsRiskAssessment::factory()->active()->forSite($foreignSite->id)->create([
            'assessed_by_user_id' => $foreignWorker->id,
            'created_by' => $foreignWorker->id,
        ]);

        $hrRequirement = HrComplianceRequirement::factory()->create();
        HsTrainingRequirement::factory()->create([
            'hr_compliance_requirement_id' => $hrRequirement->id,
            'created_by' => $viewer->id,
        ]);
        HsTrainingRequirement::factory()->forSite($foreignSite->id)->create([
            'created_by' => $foreignWorker->id,
        ]);
        HrStaffComplianceStatus::factory()->create([
            'user_id' => $viewer->id,
            'requirement_id' => $hrRequirement->id,
            'status' => 'compliant',
        ]);
        HrStaffComplianceStatus::factory()->expired()->create([
            'user_id' => $foreignWorker->id,
            'requirement_id' => $hrRequirement->id,
        ]);

        HsRepresentative::query()->create([
            'user_id' => $viewer->id,
            'site_id' => $visibleSite->id,
            'election_method' => 'elected',
            'elected_at' => now()->subYear(),
            'status' => 'active',
            'created_by' => $viewer->id,
        ]);
        HsRepresentative::query()->create([
            'user_id' => $foreignWorker->id,
            'site_id' => $foreignSite->id,
            'election_method' => 'elected',
            'elected_at' => now()->subYear(),
            'status' => 'active',
            'created_by' => $viewer->id,
        ]);
        HsCommittee::query()->create([
            'name' => 'Accessible H&S Committee',
            'site_id' => $visibleSite->id,
            'meeting_frequency' => 'monthly',
            'established_at' => now()->subYear(),
            'status' => 'active',
            'members' => [],
            'created_by' => $viewer->id,
        ]);
        HsCommittee::query()->create([
            'name' => 'Foreign H&S Committee',
            'site_id' => $foreignSite->id,
            'meeting_frequency' => 'monthly',
            'established_at' => now()->subYear(),
            'status' => 'active',
            'members' => [],
            'created_by' => $viewer->id,
        ]);

        SafeWorkProcedure::factory()->reviewDue()->create([
            'category' => 'manual_handling',
            'applicable_sites' => [$visibleSite->id],
            'approved_by' => $viewer->id,
            'created_by' => $viewer->id,
        ]);
        SafeWorkProcedure::factory()->reviewDue()->create([
            'category' => 'medication',
            'applicable_sites' => [$foreignSite->id],
            'approved_by' => $foreignWorker->id,
            'created_by' => $foreignWorker->id,
        ]);

        $visibleClient = Client::factory()->create(['site_id' => $visibleSite->id]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $visibleRestraint = RestraintEvent::factory()->create([
            'client_id' => $visibleClient->id,
            'site_id' => $visibleSite->id,
            'started_at' => now()->subDay(),
            'ended_at' => now()->subDay()->addMinutes(5),
            'created_by' => $viewer->id,
        ]);
        RestraintEvent::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
            'started_at' => now()->subDay(),
            'ended_at' => now()->subDay()->addMinutes(5),
            'created_by' => $foreignWorker->id,
        ]);

        $this->actingAs($viewer)
            ->get('/health-safety')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('backbone.risk_assessments.active_assessments', 1)
                ->where('backbone.training.total_requirements', 1)
                ->where('backbone.training.staff_non_compliant', 0)
                ->where('kpis.staff_compliance_pct', 100)
                ->where('worker_participation.pct', 100)
                ->where('worker_participation.committees', 1)
                ->where('procedures.approved', 1)
                ->where('procedures.review_due', 1)
                ->where('procedures.coverage_gap_categories', 3)
                ->where('restraints.summary.events_in_period', 1)
                ->where('restraints.summary.unreviewed', 1)
                ->where('restraints.summary.clients_no_active_bsp', 1)
                ->has('restraints.unreviewed', 1)
                ->where('restraints.unreviewed.0.id', $visibleRestraint->id)
            );
    }

    public function test_site_bound_user_analytics_site_selector_excludes_inaccessible_sites(): void
    {
        $visibleSite = Site::factory()->create(['name' => 'Accessible Site']);
        $foreignSite = Site::factory()->create(['name' => 'Foreign Site']);
        $viewer = $this->siteBoundUser($visibleSite, ['hazards.view']);
        $visibleClient = Client::factory()->create(['site_id' => $visibleSite->id]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        ClientIncident::factory()->create([
            'client_id' => $visibleClient->id,
            'site_id' => $visibleSite->id,
            'occurred_at' => now()->subDay(),
        ]);
        ClientIncident::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
            'occurred_at' => now()->subDay(),
        ]);
        $this->makeOpenHazard($visibleSite, $viewer, 'Accessible analytics hazard');
        $this->makeOpenHazard($foreignSite, $viewer, 'Foreign analytics hazard');

        $this->actingAs($viewer)
            ->get('/health-safety/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sites', 1)
                ->where('sites.0.id', $visibleSite->id)
                ->where('filters.site_id', $visibleSite->id)
                ->where('period_summary.incidents', 1)
                ->where('period_summary.open_hazards', 1)
                ->has('site_comparison', 1)
                ->where('site_comparison.0.id', $visibleSite->id)
                ->where('site_comparison.0.total_incidents', 1)
                ->where('site_comparison.0.open_hazards', 1)
            );
    }

    public function test_site_bound_user_analytics_rejects_an_inaccessible_site_filter(): void
    {
        $visibleSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $viewer = $this->siteBoundUser($visibleSite, ['hazards.view']);

        $this->actingAs($viewer)
            ->get('/health-safety/analytics?site_id='.$foreignSite->id)
            ->assertForbidden();
    }

    public function test_site_bound_user_analytics_export_rejects_an_inaccessible_site_filter(): void
    {
        $visibleSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $viewer = $this->siteBoundUser($visibleSite, ['hazards.view']);

        $this->actingAs($viewer)
            ->get('/health-safety/analytics/export?view=incidents&site_id='.$foreignSite->id)
            ->assertForbidden();
    }

    public function test_site_bound_user_analytics_records_reject_an_inaccessible_site_filter(): void
    {
        $visibleSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $viewer = $this->siteBoundUser($visibleSite, ['hazards.view']);

        $this->actingAs($viewer)
            ->getJson('/health-safety/analytics/records?view=incidents&site_id='.$foreignSite->id)
            ->assertForbidden();
    }

    public function test_global_hs_viewer_retains_all_site_visibility_and_can_filter_any_site(): void
    {
        $firstSite = Site::factory()->create(['name' => 'First Site']);
        $secondSite = Site::factory()->create(['name' => 'Second Site']);
        $viewer = $this->userWithPermissions(['hazards.view', 'healthSafety.viewAllSites'], 'Global H&S Viewer');

        $this->actingAs($viewer)
            ->get('/health-safety?site='.$secondSite->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sites', 2)
                ->where('filters.site', $secondSite->id)
            );

        $this->actingAs($viewer)
            ->get('/health-safety/analytics?site_id='.$secondSite->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sites', 2)
                ->where('filters.site_id', $secondSite->id)
            );

        $this->actingAs($viewer)
            ->get('/health-safety/analytics/export?view=incidents&site_id='.$firstSite->id)
            ->assertOk();

        $this->actingAs($viewer)
            ->getJson('/health-safety/analytics/records?view=incidents&site_id='.$firstSite->id)
            ->assertOk();
    }

    public function test_tenant_hs_all_sites_view_never_aggregates_or_exports_foreign_tenant_data(): void
    {
        $localSiteA = Site::factory()->create(['tenant_id' => 71, 'name' => 'Local Alpha']);
        $localSiteB = Site::factory()->create(['tenant_id' => 71, 'name' => 'Local Bravo']);
        $foreignSite = Site::factory()->create(['tenant_id' => 72, 'name' => 'Foreign Tenant Site']);
        $viewer = $this->userWithPermissions(
            ['hazards.view', 'healthSafety.viewAllSites'],
            'Tenant H&S lead',
        );
        $viewer->update(['organization_id' => 71]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 71,
            'user_id' => $viewer->id,
            'primary_site_id' => $localSiteA->id,
            'secondary_site_ids' => [$foreignSite->id],
            'created_by' => $viewer->id,
            'updated_by' => $viewer->id,
        ]);

        $localClientA = Client::factory()->create([
            'organization_id' => 71,
            'site_id' => $localSiteA->id,
        ]);
        $localClientB = Client::factory()->create([
            'organization_id' => 71,
            'site_id' => $localSiteB->id,
        ]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 72,
            'site_id' => $foreignSite->id,
        ]);
        $localIncidentIds = collect([$localClientA, $localClientB])->map(fn (Client $client) => ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'type' => 'near_miss',
            'occurred_at' => now()->subDay(),
        ])->id)->all();
        $foreignIncident = ClientIncident::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
            'type' => 'near_miss',
            'occurred_at' => now()->subDay(),
        ]);

        $localHazard = $this->makeOpenHazard($localSiteB, $viewer, 'Local tenant hazard');
        $foreignHazard = $this->makeOpenHazard($foreignSite, $viewer, 'Foreign tenant hazard');
        $localInjury = WorkplaceInjury::factory()->create([
            'site_id' => $localSiteA->id,
            'injury_date' => now()->subDays(3),
            'lost_time_days' => 2,
        ]);
        $foreignInjury = WorkplaceInjury::factory()->create([
            'site_id' => $foreignSite->id,
            'injury_date' => now()->subDay(),
            'lost_time_days' => 50,
        ]);

        SafeguardingConcern::factory()->investigating()->withSite($localSiteA)->create();
        SafeguardingConcern::factory()->investigating()->withSite($foreignSite)->create();

        $localAsset = Asset::factory()->forSite($localSiteB)->create([
            'created_by_user_id' => $viewer->id,
            'updated_by_user_id' => $viewer->id,
        ]);
        $foreignAsset = Asset::factory()->forSite($foreignSite)->create([
            'created_by_user_id' => $viewer->id,
            'updated_by_user_id' => $viewer->id,
        ]);
        FleetIncident::factory()->create([
            'asset_id' => $localAsset->id,
            'reported_by_user_id' => $viewer->id,
            'driver_user_id' => $viewer->id,
            'status' => 'investigating',
            'occurred_at' => now()->subDay(),
        ]);
        FleetIncident::factory()->create([
            'asset_id' => $foreignAsset->id,
            'reported_by_user_id' => $viewer->id,
            'driver_user_id' => $viewer->id,
            'status' => 'investigating',
            'occurred_at' => now()->subDay(),
        ]);

        PpeInventory::factory()->inspectionDue()->expiring()->create(['site_id' => $localSiteA->id]);
        PpeInventory::factory()->inspectionDue()->expiring()->create(['site_id' => $foreignSite->id]);

        $localEvent = HsEvent::factory()->create([
            'organization_id' => 71,
            'site_id' => $localSiteB->id,
            'created_by' => $viewer->id,
        ]);
        $foreignEvent = HsEvent::factory()->create([
            'organization_id' => 72,
            'site_id' => $foreignSite->id,
            'created_by' => $viewer->id,
        ]);
        $localAction = HsCorrectiveAction::factory()->overdue()->create([
            'hs_event_id' => $localEvent->id,
            'created_by' => $viewer->id,
        ]);
        HsCorrectiveAction::factory()->overdue()->create([
            'hs_event_id' => $foreignEvent->id,
            'created_by' => $viewer->id,
        ]);

        $this->actingAs($viewer)
            ->get('/health-safety')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.site', null)
                ->has('sites', 2)
                ->where('sites', fn ($sites) => collect($sites)->pluck('id')->sort()->values()->all() === collect([$localSiteA->id, $localSiteB->id])->sort()->values()->all())
                ->where('kpis.incidents_30d', 2)
                ->where('kpis.near_misses_30d', 2)
                ->where('kpis.open_hazards', 1)
                ->where('kpis.workplace_injuries_ytd', 1)
                ->where('kpis.lost_time_days_ytd', 2)
                ->where('kpis.open_safeguarding', 1)
                ->where('kpis.fleet_incidents_30d', 1)
                ->where('kpis.fleet_unresolved', 1)
                ->where('kpis.ppe_inspections_overdue', 1)
                ->where('kpis.ppe_expiring', 1)
                ->has('incident_trends', 1)
                ->where('incident_trends.0.count', 2)
                ->has('worklists.overdue_corrective_actions', 1)
                ->where('worklists.overdue_corrective_actions.0.id', $localAction->id)
                ->has('open_hazards_list', 1)
                ->where('open_hazards_list.0.id', $localHazard->id)
                ->has('site_league', 2)
            );

        $this->actingAs($viewer)
            ->get('/health-safety/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.site_id', null)
                ->has('sites', 2)
                ->has('site_comparison', 2)
                ->where('period_summary.incidents', 2)
                ->where('period_summary.open_hazards', 1)
            );

        foreach ([
            'incidents' => [$localIncidentIds, $foreignIncident->id],
            'injuries' => [[$localInjury->id], $foreignInjury->id],
            'hazards' => [[$localHazard->id], $foreignHazard->id],
        ] as $view => [$visibleIds, $hiddenId]) {
            $this->actingAs($viewer)
                ->getJson('/health-safety/analytics/records?view='.$view)
                ->assertOk()
                ->assertJsonPath('total', count($visibleIds))
                ->assertJson(fn ($json) => $json
                    ->where('rows', fn ($rows) => collect($rows)->pluck(0)->sort()->values()->all() === collect($visibleIds)->sort()->values()->all())
                    ->etc()
                );
            $this->assertNotContains($hiddenId, $visibleIds);
        }

        $export = $this->actingAs($viewer)
            ->get('/health-safety/analytics/export?view=hazards')
            ->assertOk();
        $exportContent = $export->streamedContent();
        $this->assertStringContainsString('Local Bravo', $exportContent);
        $this->assertStringNotContainsString('Foreign Tenant Site', $exportContent);

        $this->actingAs($viewer)
            ->get('/health-safety?site='.$foreignSite->id)
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get('/health-safety/analytics?site_id='.$foreignSite->id)
            ->assertForbidden();
    }

    private function makeOpenHazard(Site $site, User $reporter, string $description): SiteHazard
    {
        return SiteHazard::query()->create([
            'site_id' => $site->id,
            'reported_by_user_id' => $reporter->id,
            'hazard_type' => 'slip_trip_fall',
            'severity' => 'low',
            'likelihood' => 'rare',
            'risk_rating' => 'low',
            'description' => $description,
            'status' => 'open',
            'due_date' => now()->subDay(),
        ]);
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function siteBoundUser(Site $site, array $permissionKeys, string $name = 'Site-bound H&S user'): User
    {
        $user = $this->userWithPermissions($permissionKeys, $name);

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function userWithPermissions(array $permissionKeys, string $name = 'H&S user'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'approved_at' => now(),
        ]);

        $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync(
            $permissions->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])
        );

        return $user;
    }
}
