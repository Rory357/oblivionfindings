<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
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
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsComplianceExportService;
use App\Services\HealthSafety\HsDashboardService;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\HsGovernanceService;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/** Real current-source reads with quiet persisted fixtures; no command producer or privacy mock. */
class HsDerivedSourceCandidatePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, GovernancePermissionsSeeder::class]);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->viewer = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->viewer->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        foreach (['hazards.view' => true, 'restraints.view' => true, 'clients.viewAny' => true,
            'healthSafety.viewAllSites' => false, 'clinical.accessAllSites' => false, 'sites.viewAll' => false] as $key => $allowed) {
            $this->grant($key, $allowed);
        }
    }

    public function test_filtered_ordinary_child_has_no_unrelated_restraint_source_hydration(): void
    {
        $this->privateEvent();
        $action = $this->action($this->ordinary());
        [$ids, $queries] = $this->recordQueries(fn () => $this->childIds(HsCorrectiveAction::whereKey($action->id)));
        $this->assertSame([$action->id], $ids);
        $this->assertCount(0, $this->sourceQueries($queries));
    }

    public function test_filtered_private_child_proof_stays_constant_when_unrelated_backlog_grows(): void
    {
        [$event, $source] = $this->privateEvent();
        $action = $this->action($event);
        [$beforeIds, $beforeQueries] = $this->recordQueries(fn () => $this->childIds(HsCorrectiveAction::whereKey($action->id)));
        for ($i = 0; $i < 20; $i++) {
            $this->privateEvent();
        }
        [$afterIds, $afterQueries] = $this->recordQueries(fn () => $this->childIds(HsCorrectiveAction::whereKey($action->id)));
        $this->assertSame([$action->id], $beforeIds);
        $this->assertSame($beforeIds, $afterIds);
        $this->assertCount(count($beforeQueries), $afterQueries);
        foreach ([$beforeQueries, $afterQueries] as $queries) {
            $hydration = $this->sourceQueries($queries);
            $this->assertCount(1, $hydration);
            $this->assertSame([$source->id], array_map('intval', $hydration[0]['bindings']));
            $this->assertStringNotContainsString('restraint_description', $hydration[0]['query']);
        }
    }

    public function test_risk_both_event_bindings_are_required_and_unrelated_morph_or_orphan_rows_remain(): void
    {
        [$readable] = $this->privateEvent();
        [$foreign] = $this->privateEvent(Site::factory()->create());
        $modern = $this->risk(['assessable_type' => HsEvent::class, 'assessable_id' => $readable->id]);
        $legacy = $this->risk(['hs_event_id' => $readable->id]);
        $this->risk(['assessable_type' => HsEvent::class, 'assessable_id' => $foreign->id]);
        $this->risk(['hs_event_id' => $foreign->id]);
        $this->risk(['hs_event_id' => $readable->id, 'assessable_type' => HsEvent::class, 'assessable_id' => $foreign->id]);
        $unrelated = $this->risk(['assessable_type' => Site::class, 'assessable_id' => $foreign->id]);
        $orphan = $this->risk(['assessable_type' => HsEvent::class, 'assessable_id' => 999999999]);
        $standalone = $this->risk();
        $expected = [$modern->id, $legacy->id, $unrelated->id, $orphan->id, $standalone->id];
        $this->assertEqualsCanonicalizing($expected, $this->riskIds(HsRiskAssessment::query()));
        $count = DB::transaction(fn () => app(HsEventAccessService::class)->applyFirstAidRiskSourceScope(HsRiskAssessment::query(), $this->viewer)->count());
        $this->assertSame(count($expected), $count);
    }

    public function test_treatment_source_candidates_preserve_current_read_and_wrong_site_denial(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $source = FirstAidRecord::factory()->createQuietly(['site_id' => $this->site->id, 'client_id' => $client->id,
            'treated_person_type' => 'client', 'treated_person_id' => null, 'first_aider_id' => $this->viewer->id]);
        $event = HsEvent::factory()->createQuietly(['source_type' => $source->getMorphClass(), 'source_id' => $source->id,
            'event_category' => HsEvent::CATEGORY_INJURY, 'site_id' => $this->site->id, 'client_id' => $client->id,
            'staff_id' => null, 'created_by' => $this->viewer->id, 'idempotency_key' => (string) Str::uuid()]);
        $action = $this->action($event);
        $risk = $this->risk(['assessable_type' => HsEvent::class, 'assessable_id' => $event->id]);
        $this->assertSame([$action->id], $this->childIds(HsCorrectiveAction::query()));
        $this->assertSame([$risk->id], $this->riskIds(HsRiskAssessment::query()));
        $event->updateQuietly(['site_id' => Site::factory()->create()->id]);
        $this->assertSame([], $this->childIds(HsCorrectiveAction::query()));
        $this->assertSame([], $this->riskIds(HsRiskAssessment::query()));
    }

    public function test_filtered_risk_hydrates_only_its_bound_source_and_preserves_count(): void
    {
        [$event, $source] = $this->privateEvent();
        $this->privateEvent();
        $risk = $this->risk(['hs_event_id' => $event->id]);
        [$ids, $queries] = $this->recordQueries(fn () => $this->riskIds(HsRiskAssessment::whereKey($risk->id)));
        $this->assertSame([$risk->id], $ids);
        $hydration = $this->sourceQueries($queries);
        $this->assertCount(1, $hydration);
        $this->assertSame([$source->id], array_map('intval', $hydration[0]['bindings']));
        $this->assertSame(1, DB::transaction(fn () => app(HsEventAccessService::class)->applyFirstAidRiskSourceScope(HsRiskAssessment::whereKey($risk->id), $this->viewer)->count()));
    }

    public function test_incomplete_child_candidates_cannot_authorize_omitted_private_bindings(): void
    {
        [$event] = $this->privateEvent();
        $private = $this->action($event);
        $ordinary = $this->ordinary();
        $control = $this->action($ordinary);
        $ids = DB::transaction(fn () => app(HsEventAccessService::class)->applyFirstAidChildSourceScope(
            HsCorrectiveAction::whereKey([$private->id, $control->id]), 'hs_corrective_actions.hs_event_id',
            $this->viewer, null, HsEvent::whereKey($ordinary->id),
        )->pluck('id')->all());
        $this->assertSame([$control->id], $ids);
        $this->assertDatabaseHas('hs_corrective_actions', ['id' => $private->id]);
    }

    public function test_incomplete_risk_candidates_deny_either_omitted_legacy_or_modern_binding(): void
    {
        [$included] = $this->privateEvent();
        [$omitted] = $this->privateEvent();
        $good = $this->risk(['hs_event_id' => $included->id]);
        $this->risk(['hs_event_id' => $omitted->id]);
        $this->risk(['assessable_type' => HsEvent::class, 'assessable_id' => $omitted->id]);
        $this->risk(['hs_event_id' => $included->id, 'assessable_type' => HsEvent::class, 'assessable_id' => $omitted->id]);
        $ids = DB::transaction(fn () => app(HsEventAccessService::class)->applyFirstAidRiskSourceScope(
            HsRiskAssessment::query(), $this->viewer, null, HsEvent::whereKey($included->id),
        )->pluck('id')->all());
        $this->assertSame([$good->id], $ids);
    }

    public function test_deleted_private_event_keeps_both_risk_provenance_and_child_denied(): void
    {
        [$event] = $this->privateEvent();
        $this->action($event);
        $this->risk(['hs_event_id' => $event->id]);
        $this->risk(['assessable_type' => HsEvent::class, 'assessable_id' => $event->id]);
        $control = $this->action($this->ordinary());
        $riskControl = $this->risk();
        $event->deleteQuietly();
        $this->assertSame([$control->id], $this->childIds(HsCorrectiveAction::query()));
        $this->assertSame([$riskControl->id], $this->riskIds(HsRiskAssessment::query()));
        $this->assertDatabaseHas('hs_events', ['id' => $event->id]);
    }

    public function test_wrong_derived_site_cannot_substitute_for_actual_private_source_site(): void
    {
        [$event] = $this->privateEvent(Site::factory()->create());
        $event->updateQuietly(['site_id' => $this->site->id]);
        $action = $this->action($event);
        $risk = $this->risk(['hs_event_id' => $event->id]);
        $this->assertSame([], $this->childIds(HsCorrectiveAction::whereKey($action->id)));
        $this->assertSame([], $this->riskIds(HsRiskAssessment::whereKey($risk->id)));
    }

    public function test_warm_viewer_permission_revocation_is_current_for_child_and_risk(): void
    {
        [$event] = $this->privateEvent();
        $action = $this->action($event);
        $risk = $this->risk(['hs_event_id' => $event->id]);
        $this->viewer->load(['permissionOverrides', 'roles.permissions', 'hrEmployeeProfile']);
        $this->assertSame([$action->id], $this->childIds(HsCorrectiveAction::query()));
        $this->assertSame([$risk->id], $this->riskIds(HsRiskAssessment::query()));
        $this->grant('restraints.view', false);
        $this->assertTrue($this->viewer->canDo('restraints.view'));
        $this->assertSame([], $this->childIds(HsCorrectiveAction::query()));
        $this->assertSame([], $this->riskIds(HsRiskAssessment::query()));
    }

    public function test_current_staff_and_current_client_site_changes_remove_previously_readable_work(): void
    {
        [$event, $source] = $this->privateEvent();
        $action = $this->action($event);
        $risk = $this->risk(['hs_event_id' => $event->id]);
        $this->viewer->load('hrEmployeeProfile');
        $this->assertSame([$action->id], $this->childIds(HsCorrectiveAction::query()));
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => false]);
        $this->assertSame([], $this->childIds(HsCorrectiveAction::query()));
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => true]);
        $this->assertSame([$risk->id], $this->riskIds(HsRiskAssessment::query()));
        Client::whereKey($source->client_id)->update(['site_id' => Site::factory()->create()->id]);
        $this->assertSame([], $this->childIds(HsCorrectiveAction::query()));
        $this->assertSame([], $this->riskIds(HsRiskAssessment::query()));
    }

    public function test_correlated_child_scope_uses_explicit_independent_candidates_without_stripping_relation(): void
    {
        [$event] = $this->privateEvent();
        $risk = $this->risk(['hs_event_id' => $event->id]);
        $query = fn () => HsRiskAssessment::whereKey($risk->id)->whereHas('hsEvent', fn (Builder $related) => app(HsEventAccessService::class)->applyFirstAidChildSourceScope($related, 'hs_events.id',
            $this->viewer, null, HsEvent::whereKey($event->id)));
        $this->assertSame(1, DB::transaction(fn () => $query()->count()));
        $this->grant('restraints.view', false);
        $this->assertSame(0, DB::transaction(fn () => $query()->count()));
    }

    public function test_context_child_and_risk_parity_and_escaped_builder_rejection(): void
    {
        [$event] = $this->privateEvent();
        $action = $this->action($event);
        $risk = $this->risk(['hs_event_id' => $event->id]);
        $escaped = null;
        $keys = [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS];
        $actual = HealthSafetyReadContext::within($this->viewer, $keys, function ($context) use (&$escaped): array {
            $access = app(HsEventAccessService::class);
            $escaped = $access->applyFirstAidChildSourceScope(HsCorrectiveAction::query(), 'hs_corrective_actions.hs_event_id', $this->viewer, $context);

            return [$escaped->pluck('id')->all(), $access->applyFirstAidRiskSourceScope(HsRiskAssessment::query(), $this->viewer, $context)->pluck('id')->all()];
        });
        $this->assertSame([[$action->id], [$risk->id]], $actual);
        $this->assertSame([$action->id], $this->childIds(HsCorrectiveAction::query()));
        $this->assertSame([$risk->id], $this->riskIds(HsRiskAssessment::query()));
        $this->expectException(LogicException::class);
        $escaped->count();
    }

    public function test_actual_governance_and_export_correlated_reads_preserve_private_counts_and_site_denial(): void
    {
        [$event] = $this->privateEvent();
        $this->action($event);
        HsInvestigation::factory()->createQuietly(['hs_event_id' => $event->id, 'created_by' => $this->viewer->id,
            'lead_investigator_id' => $this->viewer->id, 'status' => HsInvestigation::STATUS_IN_PROGRESS]);
        $governance = app(HsGovernanceService::class);
        $export = app(HsComplianceExportService::class);
        $this->assertSame(1, $governance->getCorrectiveActionSummary($this->viewer, $this->site->id)['open']);
        $this->assertSame(1, $governance->getInvestigationSummary($this->viewer, $this->site->id)['active']);
        $this->assertSame(1, $export->correctiveActionTraceability(viewer: $this->viewer, siteId: $this->site->id)['item_count']);
        $this->grant('restraints.view', false);
        $this->assertSame(0, $governance->getCorrectiveActionSummary($this->viewer, $this->site->id)['open']);
        $this->assertSame(0, $governance->getInvestigationSummary($this->viewer, $this->site->id)['active']);
        $this->assertSame(0, $export->correctiveActionTraceability(viewer: $this->viewer, siteId: $this->site->id)['item_count']);
    }

    public function test_dashboard_investigation_parent_navigation_is_withdrawn_and_restored_with_actual_source_access(): void
    {
        [$event] = $this->privateEvent();
        $investigation = HsInvestigation::factory()->createQuietly(['hs_event_id' => $event->id,
            'created_by' => $this->viewer->id, 'lead_investigator_id' => $this->viewer->id,
            'status' => HsInvestigation::STATUS_IN_PROGRESS]);
        $dashboard = app(HsDashboardService::class);
        $read = fn () => $dashboard->openInvestigations($this->site->id, viewer: $this->viewer);
        $this->viewer->load(['permissionOverrides', 'roles.permissions', 'hrEmployeeProfile']);
        $rows = $read();
        $this->assertCount(1, $rows);
        $this->assertSame($investigation->id, $rows[0]['id']);
        $this->assertSame($event->id, $rows[0]['event_id']);
        $this->assertSame($event->reference_number, $rows[0]['event_reference']);
        $this->grant('restraints.view', false);
        $this->assertSame([], $read());
        $this->grant('restraints.view', true);
        $this->assertSame($event->id, $read()[0]['event_id']);
        $event->updateQuietly(['site_id' => Site::factory()->create()->id]);
        $this->assertSame([], $read());
        $this->assertDatabaseHas('hs_investigations', ['id' => $investigation->id, 'hs_event_id' => $event->id]);
    }

    private function privateEvent(?Site $site = null): array
    {
        $site ??= $this->site;
        $client = Client::factory()->create(['site_id' => $site->id]);
        $source = RestraintEvent::factory()->createQuietly(['client_id' => $client->id, 'site_id' => $site->id,
            'created_by' => $this->viewer->id, 'source_schema_version' => 1, 'recorded_client_id' => $client->id,
            'recorded_site_id' => $site->id, 'recorded_stay_id' => null, 'stay_id' => null]);
        $event = HsEvent::factory()->createQuietly(['source_type' => $source->getMorphClass(), 'source_id' => $source->id,
            'event_category' => HsEvent::CATEGORY_RESTRAINT, 'site_id' => $site->id, 'client_id' => $client->id,
            'staff_id' => $this->viewer->id, 'created_by' => $this->viewer->id, 'idempotency_key' => (string) Str::uuid()]);

        return [$event, $source];
    }

    private function ordinary(): HsEvent
    {
        return HsEvent::factory()->createQuietly(['source_type' => null, 'source_id' => null, 'site_id' => $this->site->id,
            'created_by' => $this->viewer->id, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function action(HsEvent $event): HsCorrectiveAction
    {
        return HsCorrectiveAction::factory()->createQuietly(['hs_event_id' => $event->id, 'created_by' => $this->viewer->id]);
    }

    private function risk(array $fields = []): HsRiskAssessment
    {
        return HsRiskAssessment::factory()->active()->createQuietly(['created_by' => $this->viewer->id,
            'assessed_by_user_id' => $this->viewer->id, 'approved_by_user_id' => $this->viewer->id, ...$fields]);
    }

    private function childIds(Builder $query): array
    {
        return DB::transaction(fn () => app(HsEventAccessService::class)->applyFirstAidChildSourceScope(
            $query, 'hs_corrective_actions.hs_event_id', $this->viewer,
        )->orderBy('id')->pluck('id')->all());
    }

    private function riskIds(Builder $query): array
    {
        return DB::transaction(fn () => app(HsEventAccessService::class)->applyFirstAidRiskSourceScope($query, $this->viewer)->orderBy('id')->pluck('id')->all());
    }

    private function recordQueries(callable $read): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = $read();

            return [$result, DB::getQueryLog()];
        } finally {
            DB::disableQueryLog();
        }
    }

    private function sourceQueries(array $queries): array
    {
        return array_values(array_filter($queries, fn ($row) => preg_match('/\bfrom\s+(?:`[^`]+`\.)?`?restraint_events`?\s/i', $row['query'])));
    }

    private function grant(string $key, bool $allowed): void
    {
        $this->viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }
}
