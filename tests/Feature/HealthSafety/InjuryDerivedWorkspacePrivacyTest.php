<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Asset;
use App\Models\Client;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\Site;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjurySourceVersion;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsDashboardService;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\HealthSafety\WorkplaceInjurySourceService;
use Database\Seeders\GovernancePermissionsSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/** Actual accepted source facts and real HTTP/aggregate consumers; no invented medical authority. */
class InjuryDerivedWorkspacePrivacyTest extends InjuryWorkspaceReadTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GovernancePermissionsSeeder::class);
        foreach (['hazards.create', 'hazards.manage', 'governance.view'] as $permission) {
            $this->grant($this->viewer, $permission);
        }
        $this->actingAs($this->viewer);
    }

    public function test_accepted_source_has_complete_permitted_event_detail_and_independent_permanent_entry(): void
    {
        [$injury, $event] = $this->acceptedInjury();
        $action = $this->action($event);
        $this->assertSame([$event->id], $this->eventIds(HsEvent::whereKey($event->id)));
        $response = $this->get(route('health-safety.events.show', $event))->assertOk();
        $detail = $response->inertiaProps('detail');
        $this->assertSame($event->id, $detail['id']);
        $this->assertSame($injury->id, $detail['source']['id']);
        $this->assertSame('/health-safety/injuries/'.$injury->id, parse_url($detail['source']['url'], PHP_URL_PATH));
        $this->assertSame($this->worker->id, $detail['staff']['id']);
        $this->assertContains($action->id, array_column($detail['corrective_actions'], 'id'));
        $this->assertStringNotContainsString('Private injury sentinel', json_encode($detail));
        $this->get(route('health-safety.corrective-actions.show', $action))->assertOk();
    }

    public function test_medical_revocation_keeps_factual_source_visibility_without_medical_version_sql(): void
    {
        [, $event] = $this->acceptedInjury();
        $action = $this->action($event);
        $this->viewer->load('permissionOverrides', 'hrEmployeeProfile');
        $this->revoke($this->viewer, 'hr.wellbeing.view');
        [$ids, $queries] = $this->queries(fn () => $this->eventIds(HsEvent::whereKey($event->id)));
        $this->assertSame([$event->id], $ids);
        $this->assertSame([$action->id], $this->childIds(HsCorrectiveAction::whereKey($action->id)));
        $this->assertFactualSourceSql($queries);
        $detail = $this->get(route('health-safety.events.show', $event))->assertOk()->inertiaProps('detail');
        $this->assertNotNull(parse_url($detail['source']['url'], PHP_URL_PATH));
        $this->assertStringNotContainsString('Private injury sentinel', json_encode($detail));
    }

    public function test_current_hazards_read_revocation_overrides_warmed_viewer_and_regrant_is_fresh(): void
    {
        [, $event] = $this->acceptedInjury();
        $this->assertTrue(app(HsEventAccessService::class)->canView($event, $this->viewer));
        $this->viewer->load('roles.permissions', 'permissionOverrides');
        $this->revoke($this->viewer, 'hazards.view');
        $this->assertFalse(app(HsEventAccessService::class)->canView($event, $this->viewer));
        $this->assertSame([], $this->eventIds(HsEvent::whereKey($event->id)));
        $this->get(route('health-safety.events.show', $event))->assertForbidden();
        $this->grant($this->viewer, 'hazards.view');
        $this->assertTrue(app(HsEventAccessService::class)->canView($event, $this->viewer));
    }

    public function test_current_hr_withdrawal_denies_injury_source_and_preserves_independent_ordinary_scope(): void
    {
        [, $private] = $this->acceptedInjury();
        $ordinary = $this->ordinary();
        $control = $this->action($ordinary);
        $this->action($private);
        $this->viewer->load('hrEmployeeProfile');
        $this->viewer->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertSame([$ordinary->id], $this->sourceIds(HsEvent::query()));
        $this->assertSame([$control->id], $this->childIds(HsCorrectiveAction::query()));
    }

    public function test_site_withdrawal_and_regrant_use_current_assignment_not_loaded_profile(): void
    {
        [, $event] = $this->acceptedInjury();
        $action = $this->action($event);
        $other = Site::factory()->create(['is_active' => true]);
        $this->viewer->load('hrEmployeeProfile');
        $this->viewer->hrEmployeeProfile()->update(['primary_site_id' => $other->id, 'secondary_site_ids' => []]);
        $this->assertSame([], $this->eventIds(HsEvent::whereKey($event->id)));
        $this->assertFalse(app(HsEventAccessService::class)->canView($event, $this->viewer));
        $this->assertSame([], $this->childIds(HsCorrectiveAction::whereKey($action->id)));
        $this->get(route('health-safety.events.show', $event))->assertNotFound();
        $this->viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->site->id]]);
        $this->assertSame([$event->id], $this->eventIds(HsEvent::whereKey($event->id)));
        $this->assertTrue(app(HsEventAccessService::class)->canView($event, $this->viewer));
    }

    public function test_foreign_injury_exact_source_id_cannot_be_read_by_rebinding_a_local_derived_site(): void
    {
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $worker = $this->staff([], $foreignSite);
        $this->viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$foreignSite->id]]);
        [$injury, $event] = $this->acceptedInjury(['user_id' => $worker->id, 'site_id' => $foreignSite->id]);
        $access = app(HsEventAccessService::class);
        $this->assertTrue($access->canView($event, $this->viewer));
        $this->viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->viewer->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
        $this->assertFalse($access->canView($event, $this->viewer));
        $event->updateQuietly(['site_id' => $this->site->id]);
        $this->assertFalse($access->canView($event, $this->viewer));
        $this->assertDatabaseHas('workplace_injuries', ['id' => $injury->id, 'site_id' => $foreignSite->id]);
    }

    public function test_inactive_source_site_cannot_survive_valid_staff_membership_elsewhere(): void
    {
        [, $event] = $this->acceptedInjury();
        $other = Site::factory()->create(['is_active' => true]);
        $this->viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$other->id]]);
        $this->site->update(['is_active' => false]);
        $this->assertSame([], $this->sourceIds(HsEvent::whereKey($event->id)));
        $this->get(route('health-safety.events.show', $event))->assertNotFound();
    }

    #[DataProvider('wrongTuples')]
    public function test_exact_derived_identity_components_are_required(string $component): void
    {
        [, $event] = $this->acceptedInjury();
        $action = $this->action($event);
        $value = match ($component) {
            'staff_id' => $this->staff([])->id,
            'site_id' => Site::factory()->create(['is_active' => true])->id,
            'client_id' => Client::factory()->create(['site_id' => $this->site->id])->id,
            'asset_id' => Asset::factory()->create()->id,
            'event_category' => HsEvent::CATEGORY_INCIDENT,
            'source_id' => 999999999,
        };
        $this->assertTrue(app(HsEventAccessService::class)->canView($event, $this->viewer));
        $event->updateQuietly([$component => $value]);
        $this->assertFalse(app(HsEventAccessService::class)->canView($event, $this->viewer));
        $this->assertSame([], $this->sourceIds(HsEvent::whereKey($event->id)));
        $this->assertSame([], $this->childIds(HsCorrectiveAction::whereKey($action->id)));
        $this->get(route('health-safety.events.show', $event))->assertNotFound();
    }

    public static function wrongTuples(): array
    {
        return array_map(fn ($field) => [$field], ['staff_id', 'site_id', 'client_id', 'asset_id', 'event_category', 'source_id']);
    }

    public function test_other_injury_same_site_cannot_replace_exact_recorded_worker_tuple(): void
    {
        [, $first] = $this->acceptedInjury();
        $worker = $this->staff([]);
        [$other] = $this->acceptedInjury(['user_id' => $worker->id]);
        $this->assertTrue(app(HsEventAccessService::class)->canView($first, $this->viewer));
        $first->updateQuietly(['source_id' => $other->id]);
        $this->assertFalse(app(HsEventAccessService::class)->canView($first, $this->viewer));
        $this->assertSame([], $this->sourceIds(HsEvent::whereKey($first->id)));
        $this->get(route('health-safety.events.show', $first))->assertNotFound();
    }

    public function test_actual_class_and_registered_morph_are_exact_while_unrelated_morph_id_collision_stays_independent(): void
    {
        $original = Relation::morphMap();
        Relation::morphMap(['injury_derived_test' => WorkplaceInjury::class], false);
        try {
            [$injury, $event] = $this->acceptedInjury();
            $event->updateQuietly(['source_type' => 'injury_derived_test']);
            $this->assertSame('injury_derived_test', $event->source_type);
            $this->assertSame([$event->id], $this->sourceIds(HsEvent::whereKey($event->id)));
            $event->updateQuietly(['source_type' => WorkplaceInjury::class]);
            $this->assertSame([$event->id], $this->sourceIds(HsEvent::whereKey($event->id)));
            $risk = $this->risk(['assessable_type' => Site::class, 'assessable_id' => $event->id]);
            $injury->deleteQuietly();
            $this->assertSame([$risk->id], $this->riskIds(HsRiskAssessment::whereKey($risk->id)));
        } finally { Relation::morphMap($original, false); }
    }

    public function test_basename_collision_does_not_mint_an_injury_source_link(): void
    {
        [$injury] = $this->acceptedInjury();
        $event = $this->ordinary(['source_type' => 'Unrelated\\WorkplaceInjury', 'source_id' => $injury->id]);
        $this->assertSame([$event->id], $this->sourceIds(HsEvent::whereKey($event->id)));
        $detail = $this->get(route('health-safety.events.show', $event))->assertOk()->inertiaProps('detail');
        $this->assertNotSame('/health-safety/injuries/'.$injury->id, parse_url($detail['source']['url'] ?? '', PHP_URL_PATH));
    }

    public function test_archived_source_and_archived_private_parent_keep_descendants_denied(): void
    {
        [$injury, $event] = $this->acceptedInjury();
        $action = $this->action($event);
        $risk = $this->risk(['hs_event_id' => $event->id]);
        $injury->deleteQuietly();
        $this->assertSame([], $this->childIds(HsCorrectiveAction::whereKey($action->id)));
        $this->assertSame([], $this->riskIds(HsRiskAssessment::whereKey($risk->id)));
        $this->get(route('health-safety.events.show', $event))->assertNotFound();
        $injury->restoreQuietly();
        $event->deleteQuietly();
        $this->assertSame([], $this->childIds(HsCorrectiveAction::whereKey($action->id)));
        $this->assertSame([], $this->riskIds(HsRiskAssessment::whereKey($risk->id)));
        $this->assertDatabaseHas('hs_corrective_actions', ['id' => $action->id]);
    }

    #[DataProvider('proofDamage')]
    public function test_damaged_factual_acceptance_denies_direct_and_private_descendant_reads_without_private_version_sql(string $damage): void
    {
        [$injury, $event] = $this->acceptedInjury();
        $action = $this->action($event);
        $risk = $this->risk(['hs_event_id' => $event->id]);
        $this->assertSame([$event->id], $this->sourceIds(HsEvent::whereKey($event->id)));
        $version = WorkplaceInjurySourceVersion::where('workplace_injury_id', $injury->id)->sole();
        match ($damage) {
            'digest' => DB::table($version->getTable())->where('id', $version->id)->update(['factual_digest' => str_repeat('0', 64)]),
            'missing' => DB::table($version->getTable())->where('id', $version->id)->delete(),
            'worker' => DB::table($version->getTable())->where('id', $version->id)->update(['worker_id' => $this->viewer->id]),
            'site' => DB::table($version->getTable())->where('id', $version->id)->update(['site_id' => Site::factory()->create()->id]),
            'revision' => DB::table($injury->getTable())->where('id', $injury->id)->update(['source_revision' => 99]),
            'facts' => DB::table($injury->getTable())->where('id', $injury->id)->update(['description' => 'Unaccepted changed factual occurrence']),
            'downgrade' => DB::table($injury->getTable())->where('id', $injury->id)->update(['source_schema_version' => 0, 'source_revision' => 0]),
        };
        [$ids, $queries] = $this->queries(fn () => $this->sourceIds(HsEvent::whereKey($event->id)));
        $this->assertSame([], $ids);
        $this->assertFactualSourceSql($queries);
        $this->assertSame([], $this->childIds(HsCorrectiveAction::whereKey($action->id)));
        $this->assertSame([], $this->riskIds(HsRiskAssessment::whereKey($risk->id)));
        $this->get(route('health-safety.events.show', $event))->assertNotFound();
        $this->get(route('health-safety.corrective-actions.show', $action))->assertNotFound();
        $this->getJson(route('health-safety.risk-assessments.show', $risk))->assertForbidden();
    }

    public static function proofDamage(): array
    {
        return array_map(fn ($damage) => [$damage], ['digest', 'missing', 'worker', 'site', 'revision', 'facts', 'downgrade']);
    }

    public function test_true_legacy_source_retains_factual_entry_without_fabricated_version_or_private_copy(): void
    {
        $event = $this->ordinary(['source_type' => $this->injury->getMorphClass(), 'source_id' => $this->injury->id,
            'event_category' => HsEvent::CATEGORY_INJURY, 'staff_id' => $this->worker->id]);
        $this->assertSame([$event->id], $this->eventIds(HsEvent::whereKey($event->id)));
        $detail = $this->get(route('health-safety.events.show', $event))->assertOk()->inertiaProps('detail');
        $this->assertSame('/health-safety/injuries/'.$this->injury->id, parse_url($detail['source']['url'], PHP_URL_PATH));
        $this->assertStringNotContainsString('Private recovery note', json_encode($detail));
        $this->assertDatabaseCount('workplace_injury_source_versions', 0);
    }

    public function test_both_risk_bindings_must_be_readable_and_unrelated_or_orphan_risks_remain_independent(): void
    {
        [, $good] = $this->acceptedInjury();
        [$injury, $bad] = $this->acceptedInjury();
        $modern = $this->risk(['assessable_type' => HsEvent::class, 'assessable_id' => $good->id]);
        $legacy = $this->risk(['hs_event_id' => $good->id]);
        $this->risk(['hs_event_id' => $bad->id]);
        $this->risk(['assessable_type' => HsEvent::class, 'assessable_id' => $bad->id]);
        $this->risk(['hs_event_id' => $good->id, 'assessable_type' => HsEvent::class, 'assessable_id' => $bad->id]);
        $unrelated = $this->risk(['assessable_type' => Site::class, 'assessable_id' => $bad->id]);
        $orphan = $this->risk(['assessable_type' => HsEvent::class, 'assessable_id' => 999999999]);
        $standalone = $this->risk();
        DB::table('workplace_injuries')->where('id', $injury->id)->update(['source_revision' => 99]);
        $this->assertEqualsCanonicalizing([$modern->id, $legacy->id, $unrelated->id, $orphan->id, $standalone->id], $this->riskIds(HsRiskAssessment::query()));
    }

    public function test_correlated_child_callback_uses_independent_exact_candidates_and_current_denial(): void
    {
        [$injury, $event] = $this->acceptedInjury();
        $private = $this->action($event);
        $control = $this->action($this->ordinary());
        $base = HsCorrectiveAction::whereKey([$private->id, $control->id]);
        $read = function () use ($base): array {
            $candidates = HsEvent::whereIn('hs_events.id', (clone $base)->select('hs_corrective_actions.hs_event_id'));
            return DB::transaction(fn () => (clone $base)->whereHas('hsEvent', fn (Builder $parent) =>
                app(HsEventAccessService::class)->applyFirstAidSourceScope($parent, $this->viewer, null, $candidates))
                ->orderBy('id')->pluck('id')->all());
        };
        $this->assertSame([$private->id, $control->id], $read());
        DB::table('workplace_injuries')->where('id', $injury->id)->update(['description' => 'Changed outside accepted command']);
        $this->assertSame([$control->id], $read());
    }

    public function test_incomplete_candidates_never_authorize_an_omitted_private_child(): void
    {
        [, $event] = $this->acceptedInjury();
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

    public function test_filtered_ordinary_child_never_hydrates_an_unrelated_private_injury(): void
    {
        $this->acceptedInjury();
        $action = $this->action($this->ordinary());
        [$ids, $queries] = $this->queries(fn () => $this->childIds(HsCorrectiveAction::whereKey($action->id)));
        $this->assertSame([$action->id], $ids);
        $this->assertCount(0, $this->injuryQueries($queries));
    }

    public function test_bounded_private_child_proof_does_not_grow_with_unrelated_backlog(): void
    {
        [, $event] = $this->acceptedInjury();
        $action = $this->action($event);
        [$before, $beforeQueries] = $this->queries(fn () => $this->childIds(HsCorrectiveAction::whereKey($action->id)));
        for ($i = 0; $i < 8; $i++) { $this->acceptedInjury(); }
        [$after, $afterQueries] = $this->queries(fn () => $this->childIds(HsCorrectiveAction::whereKey($action->id)));
        $this->assertSame([$action->id], $before);
        $this->assertSame($before, $after);
        $this->assertCount(count($this->injuryQueries($beforeQueries)), $this->injuryQueries($afterQueries));
        $this->assertFactualSourceSql($beforeQueries);
        $this->assertFactualSourceSql($afterQueries);
    }

    public function test_actual_dashboard_and_analytics_export_preserve_ordinary_results_after_private_source_damage(): void
    {
        [$injury, $event] = $this->acceptedInjury();
        $this->action($event);
        $this->investigation($event, 'Denied private root cause');
        $ordinary = $this->ordinary();
        $control = $this->action($ordinary);
        $this->investigation($ordinary, 'Healthy ordinary root cause');
        $before = $this->get(route('health-safety.dashboard'))->assertOk()->inertiaProps();
        $this->assertSame(2, $before['backbone']['events']['open_events']);
        DB::table('workplace_injuries')->where('id', $injury->id)->update(['source_revision' => 99]);
        $after = $this->get(route('health-safety.dashboard'))->assertOk()->inertiaProps();
        $this->assertSame(1, $after['backbone']['events']['open_events']);
        $this->assertSame([$control->id], array_column($after['worklists']['overdue_corrective_actions'], 'id'));
        $records = $this->getJson(route('health-safety.analytics.records', ['view' => 'root_cause', 'period' => '30d']))->assertOk();
        $records->assertJsonPath('total', 1);
        $this->assertStringContainsString('Healthy ordinary root cause', $records->getContent());
        $this->assertStringNotContainsString('Denied private root cause', $records->getContent());
        $csv = $this->get(route('health-safety.analytics.export', ['view' => 'root_cause', 'period' => '30d']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Healthy ordinary root cause', $csv);
        $this->assertStringNotContainsString('Denied private root cause', $csv);
    }

    public function test_actual_event_and_action_parent_reference_search_lose_unreadable_source_without_fallback(): void
    {
        [$injury, $event] = $this->acceptedInjury();
        $action = $this->action($event);
        $query = ['q' => $event->reference_number];
        $this->assertSame(1, $this->get(route('health-safety.events.index', $query))->assertOk()->inertiaProps('events.total'));
        $this->assertSame(1, $this->get(route('health-safety.corrective-actions.index', $query))->assertOk()->inertiaProps('actions.total'));
        DB::table('workplace_injuries')->where('id', $injury->id)->update(['source_revision' => 99]);
        $this->assertSame(0, $this->get(route('health-safety.events.index', $query))->assertOk()->inertiaProps('events.total'));
        $this->assertSame(0, $this->get(route('health-safety.corrective-actions.index', $query))->assertOk()->inertiaProps('actions.total'));
        $this->get(route('health-safety.corrective-actions.index', ['event' => $event->id]))->assertNotFound();
        $this->get(route('health-safety.corrective-actions.show', $action))->assertNotFound();
    }

    public function test_actorless_aggregate_excludes_known_injury_family_and_keeps_ordinary_events(): void
    {
        $this->acceptedInjury();
        $this->ordinary();
        $summary = app(HsDashboardService::class)->getEventKpis();
        $this->assertSame(1, $summary['open_events']);
    }

    public function test_full_prepared_context_has_standalone_scalar_scope_parity(): void
    {
        [, $event] = $this->acceptedInjury();
        $ordinary = $this->ordinary();
        $standalone = $this->sourceIds(HsEvent::query());
        $contextual = HealthSafetyReadContext::within($this->viewer, $this->keys(), fn ($context) =>
            app(HsEventAccessService::class)->applyFirstAidSourceScope(HsEvent::query(), $this->viewer, $context)->orderBy('id')->pluck('id')->all());
        $this->assertSame([$event->id, $ordinary->id], $standalone);
        $this->assertSame($standalone, $contextual);
    }

    public function test_unprepared_or_foreign_viewer_context_is_rejected_not_used_as_authority(): void
    {
        $this->acceptedInjury();
        foreach ([[$this->viewer, FirstAidAccessService::PERMISSIONS], [$this->staff(['hazards.view']), $this->keys()]] as [$consumer, $keys]) {
            try {
                HealthSafetyReadContext::within($this->viewer, $keys, fn ($context) =>
                    app(HsEventAccessService::class)->applyInjurySourceScope(HsEvent::query(), $consumer, $context)->count());
                $this->fail('Foreign or unprepared evidence must be rejected.');
            } catch (LogicException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_captured_context_and_builder_cannot_read_after_fresh_callback_closes(): void
    {
        $this->acceptedInjury();
        $captured = null;
        $query = null;
        HealthSafetyReadContext::within($this->viewer, $this->keys(), function ($context) use (&$captured, &$query): array {
            $captured = $context;
            $query = app(HsEventAccessService::class)->applyInjurySourceScope(HsEvent::query(), $this->viewer, $context);
            return $query->pluck('id')->all();
        });
        foreach ([fn () => $captured->assertActive(), fn () => $query->get()] as $read) {
            try { $read(); $this->fail('Authority must remain callback-local.'); }
            catch (LogicException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_actual_governance_and_compliance_reports_exclude_damaged_family_and_keep_healthy_neighbour(): void
    {
        [$injury, $private] = $this->acceptedInjury();
        $ordinary = $this->ordinary();
        foreach ([[$private, 'Denied private report fact'], [$ordinary, 'Healthy ordinary report fact']] as [$event, $marker]) {
            $investigation = $this->investigation($event, $marker);
            $investigation->updateQuietly(['status' => HsInvestigation::STATUS_COMPLETED, 'completed_at' => now()]);
            $this->action($event);
            $this->risk(['hs_event_id' => $event->id, 'assessable_type' => Site::class, 'assessable_id' => $this->site->id, 'risk_description' => $marker]);
        }
        DB::table('workplace_injuries')->where('id', $injury->id)->update(['source_revision' => 99]);
        $this->getJson(route('health-safety.reports.board-summary'))->assertOk()
            ->assertJsonPath('event_summary.total_period', 1)->assertJsonPath('risk_posture.total_active', 1)
            ->assertJsonPath('corrective_action_summary.open', 1)->assertJsonPath('investigation_summary.completed_with_findings', 1);
        $outcomes = $this->getJson(route('health-safety.reports.investigation-outcomes'))->assertOk();
        $this->assertStringContainsString('Healthy ordinary report fact', $outcomes->getContent());
        $this->assertStringNotContainsString('Denied private report fact', $outcomes->getContent());
        $risk = $this->getJson(route('health-safety.reports.risk-assessment-register'))->assertOk();
        $this->assertStringContainsString('Healthy ordinary report fact', $risk->getContent());
        $this->assertStringNotContainsString('Denied private report fact', $risk->getContent());
    }

    private function acceptedInjury(array $changes = []): array
    {
        $receipt = app(WorkplaceInjurySourceService::class)->create($this->viewer, [
            'user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'injury_date' => now('Pacific/Auckland')->subDay()->toIso8601String(),
            'injury_type' => 'strain', 'body_part_affected' => 'Shoulder', 'severity' => 'minor',
            'description' => 'Accepted factual occurrence', 'medical_treatment_type' => 'none',
            'immediate_treatment' => 'Private injury sentinel', 'notes' => 'Private injury sentinel',
            'worksafe_notifiable' => false, 'acc_claim_lodged' => false, 'idempotency_key' => (string) Str::uuid(), ...$changes,
        ]);
        $source = WorkplaceInjury::findOrFail($receipt['injury_id']);
        $event = HsEvent::whereIn('source_type', [WorkplaceInjury::class, $source->getMorphClass()])->where('source_id', $source->id)->sole();
        return [$source, $event];
    }

    private function ordinary(array $changes = []): HsEvent
    {
        return HsEvent::factory()->createQuietly(['site_id' => $this->site->id, 'source_type' => null, 'source_id' => null,
            'event_category' => HsEvent::CATEGORY_INCIDENT, 'staff_id' => null, 'client_id' => null, 'asset_id' => null,
            'status' => HsEvent::STATUS_OPEN, 'occurred_at' => now()->subDay(), 'reported_at' => now()->subDay(),
            'created_by' => $this->viewer->id, 'idempotency_key' => (string) Str::uuid(), ...$changes]);
    }

    private function action(HsEvent $event): HsCorrectiveAction
    {
        return HsCorrectiveAction::factory()->createQuietly(['hs_event_id' => $event->id,
            'assigned_to_user_id' => $this->viewer->id, 'created_by' => $this->viewer->id,
            'status' => HsCorrectiveAction::STATUS_OPEN, 'due_date' => now('Pacific/Auckland')->subDays(2)->toDateString()]);
    }

    private function investigation(HsEvent $event, string $marker): HsInvestigation
    {
        return HsInvestigation::factory()->inProgress()->createQuietly(['hs_event_id' => $event->id,
            'lead_investigator_id' => $this->viewer->id, 'created_by' => $this->viewer->id,
            'target_completion_date' => now('Pacific/Auckland')->subDay()->toDateString(),
            'root_causes' => [['category' => $marker, 'description' => $marker]], 'findings_summary' => $marker]);
    }

    private function risk(array $changes = []): HsRiskAssessment
    {
        return HsRiskAssessment::factory()->active()->createQuietly(['created_by' => $this->viewer->id,
            'assessed_by_user_id' => $this->viewer->id, 'approved_by_user_id' => $this->viewer->id, ...$changes]);
    }

    private function eventIds(Builder $query): array
    {
        return DB::transaction(fn () => app(HsEventAccessService::class)->applyReadableScope($query, $this->viewer)->orderBy('id')->pluck('id')->all());
    }

    private function sourceIds(Builder $query): array
    {
        return DB::transaction(fn () => app(HsEventAccessService::class)->applyFirstAidSourceScope($query, $this->viewer)->orderBy('id')->pluck('id')->all());
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

    private function keys(): array
    {
        return [...FirstAidAccessService::PERMISSIONS, ...SafeWorkProcedureAccessService::PERMISSIONS,
            ...EmergencyDrillAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS];
    }

    private function queries(callable $read): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try { return [$read(), DB::getQueryLog()]; }
        finally { DB::disableQueryLog(); DB::flushQueryLog(); }
    }

    private function injuryQueries(array $queries): array
    {
        return array_values(array_filter($queries, fn ($entry) => preg_match('/\bfrom\s+[`"]?(?:workplace_injuries|workplace_injury_source_versions)[`"]?\b/i', $entry['query'])));
    }

    private function assertFactualSourceSql(array $queries): void
    {
        foreach ($this->injuryQueries($queries) as $entry) {
            $sql = strtolower($entry['query']);
            $this->assertDoesNotMatchRegularExpression('/(?<![a-z_])(?:content|content_digest)(?![a-z_])/i', $sql);
            foreach (['immediate_treatment', 'medical_treatment_type', 'acc_claim_number'] as $private) {
                $this->assertStringNotContainsString($private, $sql);
            }
            $this->assertDoesNotMatchRegularExpression('/^\s*select\s+(?:[`"]?[a-z_]+[`"]?\.)?\*/i', $sql);
        }
    }
}
