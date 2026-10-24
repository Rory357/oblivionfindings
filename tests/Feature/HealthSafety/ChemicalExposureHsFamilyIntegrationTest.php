<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ChemicalExposureHsProjection;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureHsEventDeliveryService;
use App\Services\HealthSafety\ChemicalExposureSourceProjectionService;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Whole intended integration suite: all hunks, owner prerequisites and executable checks remain UNAPPLIED/UNRUN. */
final class ChemicalExposureHsFamilyIntegrationTest extends ChemicalExposureCommandTestCase
{
    private function keys(): array
    {
        return [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
            ...ChemicalExposureCommandAccess::PERMISSIONS, ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS];
    }
    private function access(): HsEventAccessService { return app(HsEventAccessService::class); }
    private function delivered(array $facts = []): array
    {
        $root = $this->exposure($facts); $attempt = $this->attempt($root);
        app(ChemicalExposureHsEventDeliveryService::class)->deliver($this->manager, $attempt->id);
        return [$root, HsEvent::findOrFail($attempt->fresh()->hs_event_id), ChemicalExposureHsProjection::where('exposure_id', $root->id)->sole()];
    }
    private function attempt(SubstanceExposureRecord $root): ChemicalExposureSourceDelivery
    {
        return ChemicalExposureSourceDelivery::where('exposure_id', $root->id)->where('kind', 'health_safety')->orderByDesc('id')->firstOrFail();
    }
    private function eventIds(Builder $query, bool $contextual = false): array
    {
        $run = fn ($context = null) => $this->access()->applyReadableScope(clone $query, $this->manager, $context, clone $query)->pluck('hs_events.id')->map(fn ($id) => (int) $id)->all();
        return $contextual ? HealthSafetyReadContext::within($this->manager, $this->keys(), $run) : $run();
    }
    private function action(HsEvent $event): HsCorrectiveAction
    {
        return HsCorrectiveAction::factory()->createQuietly(['hs_event_id' => $event->id, 'created_by' => $this->manager->id]);
    }
    private function risk(array $tuple): HsRiskAssessment
    {
        return HsRiskAssessment::factory()->active()->createQuietly(['created_by' => $this->manager->id,
            'assessed_by_user_id' => $this->manager->id, 'approved_by_user_id' => $this->manager->id, ...$tuple]);
    }
    private function childIds(Builder $query, bool $contextual = false, ?Builder $candidates = null): array
    {
        $run = fn ($context = null) => $this->access()->applyFirstAidChildSourceScope(clone $query,
            'hs_corrective_actions.hs_event_id', $this->manager, $context, $candidates)->pluck('id')->map(fn ($id) => (int) $id)->all();
        return $contextual ? HealthSafetyReadContext::within($this->manager, $this->keys(), $run) : $run();
    }
    private function riskIds(Builder $query, bool $contextual = false): array
    {
        $run = fn ($context = null) => $this->access()->applyFirstAidRiskSourceScope(clone $query, $this->manager, $context)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        return $contextual ? HealthSafetyReadContext::within($this->manager, $this->keys(), $run) : $run();
    }
    private function ordinary(): HsEvent
    {
        return HsEvent::factory()->createQuietly(['site_id' => $this->site->id,
            'created_by' => $this->manager->id, 'idempotency_key' => (string) Str::uuid()]);
    }
    private function noPrivateSql(array $queries): void
    {
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            foreach (['substance_exposure_records', 'chemical_exposure_source_versions', 'chemical_exposure_hs_projections',
                'chemical_exposure_command_receipts', 'chemical_exposure_followups', 'chemical_exposure_evidence'] as $table) {
                $this->assertStringNotContainsString('from `'.$table.'`', $sql);
            }
        }
    }
    public function test_actual_source_and_actual_destination_admit_same_root_child_and_both_risk_bindings_with_context_parity(): void
    {
        [$root, $event] = $this->delivered(); $action = $this->action($event);
        $legacy = $this->risk(['hs_event_id' => $event->id]);
        $modern = $this->risk(['hs_event_id' => null, 'assessable_type' => HsEvent::class, 'assessable_id' => $event->id]);
        foreach ([false, true] as $contextual) {
            $this->assertSame([$event->id], $this->eventIds(HsEvent::whereKey($event->id), $contextual));
            $this->assertSame([$action->id], $this->childIds(HsCorrectiveAction::whereKey($action->id), $contextual));
            $this->assertSame([$legacy->id, $modern->id], $this->riskIds(HsRiskAssessment::whereKey([$legacy->id, $modern->id])->orderBy('id'), $contextual));
        }
        $projection = app(ChemicalExposureSourceProjectionService::class)->forHsEvent($event->id, $this->manager);
        $this->assertSame('recorded', $projection['source']['attribution_state']); $this->assertSame($root->id, $projection['source']['id']);
        $this->assertNull($projection['source']['href']);
    }
    public function test_dual_private_permission_withdrawal_denies_before_private_sql_and_does_not_hide_ordinary_neighbour(): void
    {
        [$root, $event] = $this->delivered(); $ordinary = $this->ordinary();
        $this->manager->load(['permissionOverrides', 'roles.permissions']); $this->allow($this->manager, 'hr.wellbeing.view', false);
        [$ids, $queries] = $this->capturedQueries(fn () => $this->eventIds(HsEvent::whereKey([$event->id, $ordinary->id]), true));
        $this->assertSame([$ordinary->id], $ids); $this->noPrivateSql($queries);
        $this->allow($this->manager, 'hr.wellbeing.view'); $this->assertSame([$event->id], $this->eventIds(HsEvent::whereKey($event->id), true));
    }
    public function test_null_viewer_excludes_known_exposure_children_and_risks_without_private_discovery(): void
    {
        [, $event] = $this->delivered(); $action = $this->action($event); $risk = $this->risk(['hs_event_id' => $event->id]);
        [$result, $queries] = $this->capturedQueries(fn () => [
            $this->access()->applyFirstAidChildSourceScope(HsCorrectiveAction::whereKey($action->id), 'hs_corrective_actions.hs_event_id', null)->count(),
            $this->access()->applyFirstAidRiskSourceScope(HsRiskAssessment::whereKey($risk->id), null)->count(),
        ]);
        $this->assertSame([0, 0], $result); $this->noPrivateSql($queries);
    }
    public function test_loaded_actor_source_permission_and_current_hr_staff_withdrawal_fail_closed_and_restore(): void
    {
        [, $event] = $this->delivered(); $this->manager->load(['permissionOverrides', 'roles.permissions', 'hrEmployeeProfile']);
        $this->allow($this->manager, 'hazards.view', false); $this->assertFalse($this->access()->canView($event, $this->manager));
        $this->allow($this->manager, 'hazards.view'); $this->assertTrue($this->access()->canView($event, $this->manager));
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]);
        $this->assertFalse($this->access()->canView($event, $this->manager));
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => true]);
        $this->assertTrue($this->access()->canView($event, $this->manager));
    }
    public function test_current_approved_site_withdrawal_excludes_before_private_hydration_and_restores(): void
    {
        [, $event] = $this->delivered(); $this->manager->load('hrEmployeeProfile');
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]);
        [$ids, $queries] = $this->capturedQueries(fn () => $this->eventIds(HsEvent::whereKey($event->id), true));
        $this->assertSame([], $ids); $this->noPrivateSql($queries);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertSame([$event->id], $this->eventIds(HsEvent::whereKey($event->id), true));
    }
    public function test_typed_source_without_exact_destination_marker_does_not_make_manual_hse_copy_readable(): void
    {
        $root = $this->exposure(); $event = HsEvent::factory()->createQuietly(['source_type' => SubstanceExposureRecord::class,
            'source_id' => $root->id, 'event_category' => HsEvent::CATEGORY_EXPOSURE, 'site_id' => $this->site->id,
            'staff_id' => $this->worker->id, 'client_id' => null, 'shift_id' => null, 'asset_id' => null]);
        $this->assertSame([], $this->eventIds(HsEvent::whereKey($event->id))); $this->assertFalse($this->access()->canView($event, $this->manager));
        $this->assertSame('unavailable', app(ChemicalExposureSourceProjectionService::class)->forHsEvent($event->id, $this->manager)['access']);
    }
    public function test_actual_source_advance_requires_current_revision_destination_confirmation_before_copied_read(): void
    {
        [$root, $event] = $this->delivered(); $event->updateQuietly(['status' => HsEvent::STATUS_MONITORING, 'owner_user_id' => $this->manager->id]);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Actual corrected fact', 'circumstances' => 'New actual fact']));
        $this->assertSame([], $this->eventIds(HsEvent::whereKey($event->id), true));
        app(ChemicalExposureHsEventDeliveryService::class)->deliver($this->manager, $this->attempt($root)->id);
        $this->assertSame([$event->id], $this->eventIds(HsEvent::whereKey($event->id), true));
        $this->assertSame(HsEvent::STATUS_MONITORING, $event->fresh()->status); $this->assertSame($this->manager->id, $event->fresh()->owner_user_id);
    }
    public function test_marker_digest_mutation_conceals_hse_children_and_source_copy_without_false_recorded(): void
    {
        [, $event, $marker] = $this->delivered(); $action = $this->action($event);
        DB::table('chemical_exposure_hs_projections')->where('id', $marker->id)->update(['projection_digest' => str_repeat('0', 64)]);
        $this->assertSame([], $this->eventIds(HsEvent::whereKey($event->id))); $this->assertSame([], $this->childIds(HsCorrectiveAction::whereKey($action->id)));
        $this->assertNull(app(ChemicalExposureSourceProjectionService::class)->forHsEvent($event->id, $this->manager)['source']);
    }
    public function test_exact_marker_audit_and_recorded_time_are_acceptance_bound_not_self_rehashed(): void
    {
        [, $event, $marker] = $this->delivered(); $time = $marker->getRawOriginal('recorded_at');
        DB::table('chemical_exposure_hs_projections')->where('id', $marker->id)->update(['recorded_at' => '2026-10-12 01:00:00.999999']);
        $this->assertSame([], $this->eventIds(HsEvent::whereKey($event->id)));
        DB::table('chemical_exposure_hs_projections')->where('id', $marker->id)->update(['recorded_at' => $time]);
        DB::table('audit_logs')->where('id', $marker->audit_log_id)->update(['action' => 'chemical.exposure.unrelated']);
        $this->assertSame([], $this->eventIds(HsEvent::whereKey($event->id)));
    }
    public function test_copied_worker_site_category_and_instant_mutations_never_admit_from_source_id_alone(): void
    {
        [, $event] = $this->delivered(); $original = $event->getRawOriginal();
        foreach (['staff_id' => $this->manager->id, 'site_id' => $this->foreignSite->id, 'event_category' => HsEvent::CATEGORY_INCIDENT,
            'occurred_at' => '2026-10-11 23:59:59', 'reported_at' => '2026-10-12 00:59:59'] as $key => $value) {
            DB::table('hs_events')->where('id', $event->id)->update([$key => $value]);
            $this->assertSame([], $this->eventIds(HsEvent::whereKey($event->id), true));
            DB::table('hs_events')->where('id', $event->id)->update([$key => $original[$key]]);
        }
        $this->assertSame([$event->id], $this->eventIds(HsEvent::whereKey($event->id), true));
    }
    public function test_recorded_incident_client_withdrawal_denies_before_current_medical_root_and_hse_body_then_restores(): void
    {
        $this->allow($this->manager, 'incidents.viewAny'); $this->allow($this->manager, 'clients.viewAny');
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $client->id,
            'site_id' => $this->site->id, 'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false]));
        [, $event] = $this->delivered(['related_incident_id' => $incident->id]);
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) {
            $this->manager->canDo($key); $this->allow($this->manager, $key, false);
            [$ids, $queries] = $this->capturedQueries(fn () => $this->eventIds(HsEvent::whereKey($event->id), true));
            $this->assertSame([], $ids);
            foreach ($queries as $query) {
                $this->assertDoesNotMatchRegularExpression('/select\s+\*\s+from\s+[`"]?(?:substance_exposure_records|hs_events)/i', $query['query']);
            }
            $this->allow($this->manager, $key); $this->assertSame([$event->id], $this->eventIds(HsEvent::whereKey($event->id), true));
        }
    }
    public function test_genuine_legacy_without_original_association_stays_unknown_and_never_infers_destination_certification(): void
    {
        $catalogue = $this->catalogue();
        $root = SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::create(['hazardous_substance_id' => $catalogue->id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'created_by' => $this->manager->id,
            'exposed_at' => '2026-10-12 00:00:00', 'exposure_type' => 'skin_contact']));
        $event = HsEvent::factory()->createQuietly(['source_type' => SubstanceExposureRecord::class, 'source_id' => $root->id,
            'event_category' => HsEvent::CATEGORY_EXPOSURE, 'site_id' => $this->site->id, 'staff_id' => $this->worker->id,
            'client_id' => null, 'shift_id' => null, 'asset_id' => null]);
        $dto = app(ChemicalExposureSourceProjectionService::class)->forHsEvent($event->id, $this->manager);
        $this->assertSame('legacy_unknown', $dto['source']['attribution_state']); $this->assertNull($dto['source']['href']);
        $this->assertSame([$event->id], $this->eventIds(HsEvent::whereKey($event->id)));
    }
    public function test_legacy_related_incident_unknown_context_has_no_current_tuple_fallback_or_body_read(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id]));
        $root = SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::create(['hazardous_substance_id' => $this->catalogue()->id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'created_by' => $this->manager->id, 'related_incident_id' => $incident->id,
            'exposed_at' => '2026-10-12 00:00:00', 'exposure_type' => 'skin_contact']));
        $event = HsEvent::factory()->createQuietly(['source_type' => SubstanceExposureRecord::class, 'source_id' => $root->id,
            'event_category' => HsEvent::CATEGORY_EXPOSURE, 'site_id' => $this->site->id, 'staff_id' => $this->worker->id]);
        [$ids, $queries] = $this->capturedQueries(fn () => $this->eventIds(HsEvent::whereKey($event->id), true));
        $this->assertSame([], $ids);
        foreach ($queries as $query) { $this->assertDoesNotMatchRegularExpression('/select\s+\*\s+from\s+[`"]?substance_exposure_records/i', $query['query']); }
    }
    public function test_both_risk_bindings_remain_binding_even_when_one_points_to_an_ordinary_allowed_event(): void
    {
        [, $event] = $this->delivered(); $ordinary = $this->ordinary();
        $left = $this->risk(['hs_event_id' => $event->id, 'assessable_type' => HsEvent::class, 'assessable_id' => $ordinary->id]);
        $right = $this->risk(['hs_event_id' => $ordinary->id, 'assessable_type' => (new HsEvent)->getMorphClass(), 'assessable_id' => $event->id]);
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        $this->assertSame([], $this->riskIds(HsRiskAssessment::whereKey([$left->id, $right->id]), true));
    }
    public function test_other_risk_morph_same_numeric_id_and_unwired_event_child_are_not_private_event_bindings(): void
    {
        [, $event] = $this->delivered(); $risk = $this->risk(['hs_event_id' => null, 'assessable_type' => Client::class, 'assessable_id' => $event->id]);
        $orphan = $this->action($this->ordinary()); // The real child FK is required; its canonical event has no source binding.
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        $this->assertSame([$risk->id], $this->riskIds(HsRiskAssessment::whereKey($risk->id)));
        $this->assertSame([$orphan->id], $this->childIds(HsCorrectiveAction::whereKey($orphan->id)));
    }
    public function test_soft_deleted_private_event_is_still_known_denied_for_child_and_legacy_risk(): void
    {
        [, $event] = $this->delivered(); $action = $this->action($event); $risk = $this->risk(['hs_event_id' => $event->id]);
        HsEvent::withoutEvents(fn () => $event->delete());
        $this->assertSame([], $this->childIds(HsCorrectiveAction::whereKey($action->id), true));
        $this->assertSame([], $this->riskIds(HsRiskAssessment::whereKey($risk->id), true));
    }
    public function test_incomplete_independent_candidate_never_widens_final_child_denial(): void
    {
        [, $event] = $this->delivered(); $action = $this->action($event); $ordinary = $this->ordinary();
        $this->assertSame([], $this->childIds(HsCorrectiveAction::whereKey($action->id), true, HsEvent::whereKey($ordinary->id)));
    }
    public function test_unrelated_filtered_child_preserves_search_and_has_no_private_source_discovery(): void
    {
        $this->delivered(); $action = $this->action($this->ordinary());
        [$ids, $queries] = $this->capturedQueries(fn () => $this->childIds(HsCorrectiveAction::whereKey($action->id)->where('title', $action->title), true));
        $this->assertSame([$action->id], $ids); $this->noPrivateSql($queries);
    }
    public function test_two_disjoint_candidate_sets_in_one_callback_do_not_reuse_a_global_private_whitelist(): void
    {
        [, $first] = $this->delivered(); [, $second] = $this->delivered();
        $ids = HealthSafetyReadContext::within($this->manager, $this->keys(), function ($context) use ($first, $second): array {
            return [$this->access()->applyReadableScope(HsEvent::whereKey($first->id), $this->manager, $context, HsEvent::whereKey($first->id))->pluck('id')->all(),
                $this->access()->applyReadableScope(HsEvent::whereKey($second->id), $this->manager, $context, HsEvent::whereKey($second->id))->pluck('id')->all()];
        });
        $this->assertSame([[$first->id], [$second->id]], $ids);
    }
    public function test_missing_prepared_exposure_union_is_error_not_a_new_viewer_or_grant_fallback(): void
    {
        [, $event] = $this->delivered(); $this->expectException(LogicException::class);
        HealthSafetyReadContext::within($this->manager, array_values(array_diff($this->keys(), ['hr.wellbeing.view'])),
            fn ($context) => $this->access()->applyReadableScope(HsEvent::whereKey($event->id), $this->manager, $context)->count());
    }
    public function test_expired_context_remains_error_without_private_fallback(): void
    {
        [, $event] = $this->delivered(); $escaped = null;
        HealthSafetyReadContext::within($this->manager, $this->keys(), function ($context) use (&$escaped): array { $escaped = $context; return []; });
        $this->expectException(LogicException::class); $this->access()->canView($event, $this->manager, $escaped);
    }
    public function test_direct_permanent_hse_get_has_private_source_label_and_dedicated_record_link_only_after_full_admission(): void
    {
        [$root, $event] = $this->delivered();
        $this->actingAs($this->manager)->get('/health-safety/events/'.$event->id)->assertOk()->assertInertia(fn ($page) => $page
            ->where('detail.source.type', 'SubstanceExposureRecord')->where('detail.source.id', $root->id)
            ->where('detail.source.label', 'Chemical exposure #'.$root->id)
            ->where('detail.source.url', fn ($url) => str_starts_with($url, '/health-safety/chemical-exposures/'.$root->id.'?section=connections')));
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        $this->actingAs($this->manager)->get('/health-safety/events/'.$event->id)->assertNotFound();
    }
    public function test_root_search_and_counts_are_after_source_guard_without_changing_independent_closure_status(): void
    {
        [, $event] = $this->delivered(); $ordinary = $this->ordinary(); $event->updateQuietly(['status' => HsEvent::STATUS_CLOSED]);
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        $query = HsEvent::whereKey([$event->id, $ordinary->id])->where('reference_number', 'like', '%');
        $this->assertSame([$ordinary->id], $this->eventIds($query, true)); $this->assertSame(HsEvent::STATUS_CLOSED, $event->fresh()->status);
        $this->allow($this->manager, 'hr.wellbeing.view'); $this->assertSame([$event->id], $this->eventIds(HsEvent::whereKey($event->id)->where('status', HsEvent::STATUS_CLOSED), true));
    }
    public function test_known_private_root_marker_downgrade_and_missing_acceptance_fail_closed_without_legacy_reclassification(): void
    {
        [$root, $event] = $this->delivered();
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame([], $this->eventIds(HsEvent::whereKey($event->id), true));
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['source_schema_version' => 1, 'source_revision' => 1]);
        $version = ChemicalExposureSourceVersion::where('exposure_id', $root->id)->sole();
        DB::table('chemical_exposure_source_versions')->where('id', $version->id)->update(['content_digest' => str_repeat('f', 64)]);
        $this->assertSame([], $this->eventIds(HsEvent::whereKey($event->id), true));
    }
    public function test_wrong_viewer_cannot_consume_another_actors_prepared_source_context(): void
    {
        [, $event] = $this->delivered(); $other = $this->staff(['hazards.view', 'hr.wellbeing.view']);
        $this->expectException(LogicException::class);
        HealthSafetyReadContext::within($this->manager, $this->keys(), fn ($context) => $this->access()->canView($event, $other, $context));
    }
    public function test_first_aid_restraint_and_factual_injury_source_scopes_remain_independent_after_exposure_append(): void
    {
        $this->allow($this->manager, 'restraints.view'); $this->allow($this->manager, 'clients.viewAny');
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $first = \App\Models\FirstAidRecord::factory()->createQuietly(['site_id' => $this->site->id, 'treated_person_type' => 'visitor',
            'treated_person_id' => null, 'client_id' => null]);
        $restraint = \App\Models\RestraintEvent::factory()->createQuietly(['site_id' => $this->site->id, 'client_id' => $client->id,
            'stay_id' => null, 'created_by' => $this->manager->id, 'source_schema_version' => null,
            'recorded_client_id' => null, 'recorded_site_id' => null, 'recorded_stay_id' => null]);
        $injury = \App\Models\WorkplaceInjury::factory()->createQuietly(['user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'source_schema_version' => 0, 'source_revision' => 0, 'created_by' => $this->manager->id]);
        $events = [];
        foreach ([[$first, HsEvent::CATEGORY_INJURY, null, null], [$restraint, HsEvent::CATEGORY_RESTRAINT, $client->id, $this->manager->id],
            [$injury, HsEvent::CATEGORY_INJURY, null, $this->worker->id]] as [$source, $category, $clientId, $staffId]) {
            $events[] = HsEvent::factory()->createQuietly(['source_type' => $source->getMorphClass(), 'source_id' => $source->id,
                'event_category' => $category, 'site_id' => $this->site->id, 'client_id' => $clientId, 'staff_id' => $staffId,
                'shift_id' => null, 'asset_id' => null, 'created_by' => $this->manager->id, 'idempotency_key' => (string) Str::uuid()])->id;
        }
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        $this->assertSame($events, $this->eventIds(HsEvent::whereKey($events)->orderBy('id'), true));
        $this->allow($this->manager, 'restraints.view', false);
        $this->assertSame([$events[0], $events[2]], $this->eventIds(HsEvent::whereKey($events)->orderBy('id'), true));
    }

    public function test_correlated_investigation_scope_uses_filtered_independent_candidates_without_executing_outer_binding_alone(): void
    {
        [, $event] = $this->delivered(); $ordinary = $this->ordinary();
        $private = HsInvestigation::factory()->createQuietly(['hs_event_id' => $event->id, 'created_by' => $this->manager->id,
            'lead_investigator_id' => $this->manager->id, 'status' => HsInvestigation::STATUS_IN_PROGRESS]);
        $public = HsInvestigation::factory()->createQuietly(['hs_event_id' => $ordinary->id, 'created_by' => $this->manager->id,
            'lead_investigator_id' => $this->manager->id, 'status' => HsInvestigation::STATUS_IN_PROGRESS]);
        $base = HsInvestigation::whereKey([$private->id, $public->id])->where('lead_investigator_id', $this->manager->id);
        $candidates = HsEvent::whereIn('id', (clone $base)->select('hs_event_id'));
        $read = fn () => HealthSafetyReadContext::within($this->manager, $this->keys(), function ($context) use ($base, $candidates): array {
            $query = (clone $base)->whereHas('hsEvent', fn ($related) => $this->access()->applyReadableScope($related, $this->manager, $context, $candidates));
            return ['count' => (clone $query)->count(), 'ids' => $query->orderBy('id')->pluck('id')->all()];
        });
        $this->assertSame(['count' => 2, 'ids' => [$private->id, $public->id]], $read());
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        $this->assertSame(['count' => 1, 'ids' => [$public->id]], $read());
    }
    public function test_root_and_child_count_queries_intersect_same_source_guard_as_rows(): void
    {
        [, $event] = $this->delivered(); $action = $this->action($event); $ordinary = $this->ordinary(); $public = $this->action($ordinary);
        $read = fn () => HealthSafetyReadContext::within($this->manager, $this->keys(), function ($context) use ($event, $ordinary, $action, $public): array {
            $candidate = HsEvent::whereKey([$event->id, $ordinary->id]);
            $root = $this->access()->applyReadableScope(clone $candidate, $this->manager, $context, $candidate);
            $child = $this->access()->applyFirstAidChildSourceScope(HsCorrectiveAction::whereKey([$action->id, $public->id]),
                'hs_corrective_actions.hs_event_id', $this->manager, $context, $candidate);
            return ['root_count' => (clone $root)->count(), 'root_ids' => $root->orderBy('id')->pluck('id')->all(),
                'child_count' => (clone $child)->count(), 'child_ids' => $child->orderBy('id')->pluck('id')->all()];
        });
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        $this->assertSame(['root_count' => 1, 'root_ids' => [$ordinary->id], 'child_count' => 1, 'child_ids' => [$public->id]], $read());
        $this->allow($this->manager, 'hr.wellbeing.view'); $this->assertSame(2, $read()['root_count']); $this->assertSame(2, $read()['child_count']);
    }

    public function test_final_tuple_predicate_blocks_same_connection_hse_rewrite_after_discovery_before_body_query(): void
    {
        [, $event] = $this->delivered(); $original = $event->staff_id;
        $result = HealthSafetyReadContext::within($this->manager, $this->keys(), function ($context) use ($event): array {
            $candidate = HsEvent::whereKey($event->id);
            $query = $this->access()->applyReadableScope(clone $candidate, $this->manager, $context, $candidate);
            DB::table('hs_events')->where('id', $event->id)->update(['staff_id' => $this->manager->id]);
            return $query->get()->all();
        });
        $this->assertSame([], $result);
        DB::table('hs_events')->where('id', $event->id)->update(['staff_id' => $original]);
        $this->assertSame([$event->id], $this->eventIds(HsEvent::whereKey($event->id), true));
    }

}
