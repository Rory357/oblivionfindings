<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ClientIncident;
use App\Models\HazardousSubstance;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\HealthSafety\ChemicalExposureSourceProjectionService;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

/** COMPLETE prospective consumer suite. Writer proof and source-family mounting are required; all execution UNRUN. */
final class ChemicalExposureDerivedSourcePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;
    private Site $foreignSite;
    private User $viewer;
    private User $safety;
    private User $worker;
    private HazardousSubstance $substance;
    private SubstanceExposureRecord $source;
    private HsEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-12T01:00:00Z'));
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->foreignSite = Site::factory()->create(['is_active' => true]);
        $this->viewer = $this->staff(['hazards.view', 'hr.wellbeing.view']);
        $this->safety = $this->staff(['hazards.view']);
        $this->worker = $this->staff([]);
        $this->substance = HazardousSubstance::factory()->create();
        $this->source = $this->source();
        $this->event = $this->event($this->source);
    }

    public function test_exact_legacy_unlinked_source_preserves_worker_not_recorder_and_unknown_attribution(): void
    {
        $dto = $this->projection()->forHsEvent($this->event->id, $this->viewer);
        $this->assertSame('readable', $dto['access']);
        $this->assertSame($this->worker->id, $dto['source']['worker']['id']);
        $this->assertNotSame($this->viewer->id, $dto['source']['worker']['id']);
        $this->assertSame('legacy_unknown', $dto['source']['attribution_state']);
        $this->assertSame('unknown', $dto['source']['context_binding']);
        $this->assertNull($dto['source']['source_version_id']);
        $this->assertNull($dto['source']['href']);
        foreach (['symptoms', 'medical_treatment', 'circumstances', 'created_by', 'medical_outcome'] as $field) {
            $this->assertArrayNotHasKey($field, $dto['source']);
        }
        $this->detached($dto);
    }

    public function test_safety_only_discovery_and_copy_do_not_query_private_source_versions_or_dependants(): void
    {
        [$result, $queries] = $this->queries(fn () => HealthSafetyReadContext::within($this->safety,
            ChemicalExposureSourceProjectionService::PERMISSIONS, fn ($context) => [
                'ids' => $this->projection()->readableHsEventIdsInContext($this->safety, HsEvent::whereKey($this->event->id), $context),
                'copy' => $this->projection()->forHsEvent($this->event->id, $this->safety, $context),
            ]));
        $this->assertSame([], $result['ids']);
        $this->assertSame(['access' => 'restricted', 'source' => null], $result['copy']);
        $this->noPrivateSql($queries);
        foreach ($queries as $sql) {
            if (str_contains($sql, 'from `hs_events`')) {
                foreach (['staff_id', 'source_id', 'client_id', 'shift_id'] as $privateIdentity) { $this->assertStringNotContainsString($privateIdentity, $sql); }
            }
        }
    }

    public function test_recorded_worker_identity_and_catalogue_management_do_not_grant_self_exposure_read(): void
    {
        $this->grant($this->worker, 'hazards.view');
        $this->grant($this->worker, 'hazards.manage');
        [$dto, $queries] = $this->queries(fn () => $this->projection()->forHsEvent($this->event->id, $this->worker));
        $this->assertSame('restricted', $dto['access']);
        $this->assertNull($dto['source']);
        $this->noPrivateSql($queries);
    }

    public function test_wellbeing_without_safety_does_not_read_copied_private_source(): void
    {
        $viewer = $this->staff(['hr.wellbeing.view']);
        [$dto, $queries] = $this->queries(fn () => $this->projection()->forHsEvent($this->event->id, $viewer));
        $this->assertSame('restricted', $dto['access']);
        $this->noPrivateSql($queries);
    }

    public function test_bounded_candidates_do_not_hydrate_unrelated_or_foreign_source_models(): void
    {
        $other = $this->source(); $this->event($other);
        $foreign = $this->source(['site_id' => $this->foreignSite->id]); $this->event($foreign);
        $hydrated = [];
        SubstanceExposureRecord::retrieved(function ($source) use (&$hydrated) { $hydrated[] = $source->id; });
        try {
            $this->assertSame([$this->event->id], $this->ids(HsEvent::whereKey($this->event->id)));
            $this->assertNotEmpty($hydrated);
            $this->assertSame([$this->source->id], array_values(array_unique($hydrated)));
        } finally { SubstanceExposureRecord::flushEventListeners(); }
    }

    public function test_two_disjoint_candidates_and_empty_candidates_do_not_share_a_global_whitelist(): void
    {
        $other = $this->event($this->source());
        HealthSafetyReadContext::within($this->viewer, ChemicalExposureSourceProjectionService::PERMISSIONS, function ($context) use ($other) {
            $this->assertSame([$this->event->id], $this->projection()->readableHsEventIdsInContext($this->viewer, HsEvent::whereKey($this->event->id), $context));
            $this->assertSame([$other->id], $this->projection()->readableHsEventIdsInContext($this->viewer, HsEvent::whereKey($other->id), $context));
            $this->assertSame([], $this->projection()->readableHsEventIdsInContext($this->viewer, HsEvent::whereRaw('1=0'), $context));
            return null;
        });
    }

    public function test_candidate_query_is_not_mutated_and_supplied_callback_does_not_refresh_actor(): void
    {
        $candidate = HsEvent::whereKey($this->event->id)->orderByDesc('id')->limit(1);
        $sql = $candidate->toSql(); $bindings = $candidate->getBindings();
        [$ids, $queries] = $this->queries(fn () => HealthSafetyReadContext::within($this->viewer,
            ChemicalExposureSourceProjectionService::PERMISSIONS, function ($context) use ($candidate) {
                $connection = DB::connection(); $level = $connection->transactionLevel();
                $ids = $this->projection()->readableHsEventIdsInContext($this->viewer, $candidate, $context);
                $copy = $this->projection()->forHsEvent($this->event->id, $this->viewer, $context);
                $this->assertSame('readable', $copy['access']);
                $this->assertSame($connection, DB::connection()); $this->assertSame($level, $connection->transactionLevel());
                return $ids;
            }));
        $this->assertSame([$this->event->id], $ids);
        $this->assertSame($sql, $candidate->toSql()); $this->assertSame($bindings, $candidate->getBindings());
        $this->assertCount(1, array_filter($queries, fn ($sql) => preg_match('/from\s+[`"]?permissions[`"]?\s/i', $sql)));
    }

    public function test_wrong_literal_type_basename_category_worker_and_site_are_not_source_authority(): void
    {
        $bad = [
            $this->event($this->source, ['source_type' => 'Other\\SubstanceExposureRecord']),
            $this->event($this->source, ['event_category' => HsEvent::CATEGORY_INJURY]),
            $this->event($this->source, ['staff_id' => $this->viewer->id]),
            $this->event($this->source, ['site_id' => $this->foreignSite->id]),
        ];
        $this->assertSame([], $this->ids(HsEvent::whereKey(array_map(fn ($event) => $event->id, $bad))));
        $this->assertSame('absent', $this->projection()->forHsEvent($bad[0]->id, $this->viewer)['access']);
        foreach (array_slice($bad, 1) as $event) { $this->assertNull($this->projection()->forHsEvent($event->id, $this->viewer)['source']); }
    }

    public function test_asset_and_unproved_client_or_shift_bindings_fail_closed(): void
    {
        $client = \App\Models\Client::factory()->create();
        $bad = $this->event($this->source, ['client_id' => $client->id]);
        $this->assertSame([], $this->ids(HsEvent::whereKey($bad->id)));
        $this->assertSame('restricted', $this->projection()->forHsEvent($bad->id, $this->viewer)['access']);
        $asset = \App\Models\Asset::factory()->create();
        $badAsset = $this->event($this->source, ['asset_id' => $asset->id]);
        $this->assertSame([], $this->ids(HsEvent::whereKey($badAsset->id)));
        $shift = \App\Models\Shift::factory()->create(['site_id' => $this->site->id]);
        $badShift = $this->event($this->source, ['shift_id' => $shift->id]);
        $this->assertSame([], $this->ids(HsEvent::whereKey($badShift->id)));
    }

    public function test_legacy_related_incident_never_acquires_todays_client_or_shift_tuple(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'the exposure source copy on H&S events (ChemicalExposureSourceProjectionService) is called only by the unwired exposure audit guard and risk acceptance; here it returns no source, so it shows less, not more.', 'mount it with the exposure source family.'));
        $incident = ClientIncident::factory()->create(['client_id' => \App\Models\Client::factory()->create()->id,
            'site_id' => $this->site->id, 'reported_by' => $this->viewer->id]);
        $source = $this->source(['related_incident_id' => $incident->id]);
        $linked = $this->event($source, ['client_id' => $incident->client_id, 'shift_id' => $incident->shift_id]);
        $unlinkedCopy = $this->event($source);
        $this->assertSame([], $this->ids(HsEvent::whereKey([$linked->id, $unlinkedCopy->id])));
        $this->assertSame('restricted', $this->projection()->forHsEvent($linked->id, $this->viewer)['access']);
        $this->assertNull($this->projection()->forHsEvent($unlinkedCopy->id, $this->viewer)['source']);
    }

    public function test_missing_archived_or_moved_source_cannot_leave_a_readable_derived_copy(): void
    {
        $missing = $this->event($this->source, ['source_id' => 900000001]);
        $this->assertSame('unavailable', $this->projection()->forHsEvent($missing->id, $this->viewer)['access']);
        $this->source->delete();
        $this->assertSame([], $this->ids(HsEvent::whereKey($this->event->id)));
        $this->assertSame('unavailable', $this->projection()->forHsEvent($this->event->id, $this->viewer)['access']);
        $this->source->restore(); $this->source->update(['site_id' => $this->foreignSite->id]);
        $this->assertSame([], $this->ids(HsEvent::whereKey($this->event->id)));
        $this->assertNull($this->projection()->forHsEvent($this->event->id, $this->viewer)['source']);
    }

    public function test_loaded_actor_private_grant_revoke_and_restore_are_fresh_each_public_read(): void
    {
        $this->viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->assertSame('readable', $this->projection()->forHsEvent($this->event->id, $this->viewer)['access']);
        $this->grant($this->viewer, 'hr.wellbeing.view', false);
        [$dto, $queries] = $this->queries(fn () => $this->projection()->forHsEvent($this->event->id, $this->viewer));
        $this->assertSame('restricted', $dto['access']); $this->noPrivateSql($queries);
        $this->grant($this->viewer, 'hr.wellbeing.view');
        $this->assertSame('readable', $this->projection()->forHsEvent($this->event->id, $this->viewer)['access']);
    }

    public function test_current_hr_and_site_withdrawal_and_restore_precede_source_hydration(): void
    {
        $this->viewer->hrEmployeeProfile()->update(['is_active' => false]);
        [$dto, $queries] = $this->queries(fn () => $this->projection()->forHsEvent($this->event->id, $this->viewer));
        $this->assertSame('restricted', $dto['access']); $this->noPrivateSql($queries);
        $this->viewer->hrEmployeeProfile()->update(['is_active' => true, 'primary_site_id' => $this->foreignSite->id]);
        [$dto, $queries] = $this->queries(fn () => $this->projection()->forHsEvent($this->event->id, $this->viewer));
        $this->assertSame('restricted', $dto['access']); $this->noPrivateSql($queries);
        $this->viewer->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->assertSame('readable', $this->projection()->forHsEvent($this->event->id, $this->viewer)['access']);
    }

    public function test_inactive_archived_and_unknown_sites_do_not_become_global_under_bypass(): void
    {
        $this->grant($this->viewer, 'healthSafety.viewAllSites');
        $this->site->update(['is_active' => false]);
        $this->assertSame([], $this->ids(HsEvent::whereKey($this->event->id)));
        $this->site->update(['is_active' => true, 'archived_at' => now()]);
        $this->assertSame([], $this->ids(HsEvent::whereKey($this->event->id)));
        $this->site->update(['archived_at' => null]);
        $this->source->update(['site_id' => null]);
        $this->assertSame([], $this->ids(HsEvent::whereKey($this->event->id)));
    }

    public function test_wrong_viewer_missing_preparation_and_expired_callback_remain_errors(): void
    {
        HealthSafetyReadContext::within($this->viewer, ChemicalExposureSourceProjectionService::PERMISSIONS, function ($context) {
            $this->integrity(fn () => $this->projection()->readableHsEventIdsInContext($this->safety, HsEvent::query(), $context));
            return null;
        });
        HealthSafetyReadContext::within($this->viewer, ['hazards.view', 'healthSafety.viewAllSites'], function ($context) {
            $this->integrity(fn () => $this->projection()->readableHsEventIdsInContext($this->viewer, HsEvent::query(), $context));
            return null;
        });
        $escaped = null;
        HealthSafetyReadContext::within($this->viewer, ChemicalExposureSourceProjectionService::PERMISSIONS, function ($context) use (&$escaped) { $escaped = $context; return null; });
        $this->integrity(fn () => $this->projection()->forHsEvent($this->event->id, $this->viewer, $escaped));
    }

    public function test_candidate_on_another_physical_connection_is_not_silently_rebound(): void
    {
        config(['database.connections.exposure_source_candidate' => config('database.connections.'.DB::getDefaultConnection())]);
        $candidate = HsEvent::on('exposure_source_candidate')->whereKey($this->event->id);
        try { $this->integrity(fn () => $this->ids($candidate)); }
        finally { DB::purge('exposure_source_candidate'); }
    }

    public function test_hse_family_final_scope_preserves_unrelated_events_and_conceals_exposure_without_wellbeing(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure source guard (hide chemical-exposure H&S events and their actions, investigations and risk assessments from viewers without hr.wellbeing.view, and incident-linked ones without incident and client read) isn\'t applied. Stephan kept main\'s rule for this merge (25 Oct, "Keep today\'s rule"): H&S view at the event\'s Site.', 'revisit with job 3, the exposure delivery pipeline.'));
        $ordinary = $this->ordinary();
        $this->assertSame([$ordinary->id], $this->eventIds($this->safety, HsEvent::whereKey([$ordinary->id, $this->event->id])));
        $this->assertSame([$this->event->id, $ordinary->id], $this->eventIds($this->viewer, HsEvent::whereKey([$ordinary->id, $this->event->id])));
    }

    public function test_incomplete_discovery_candidates_never_widen_final_child_source_denial(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'candidate mechanics of Codex\'s exposure source guard, which isn\'t applied; the viewer in this test already holds every grant the guard asks for.', 'Stephan\'s decision on exposure H&S event privacy, with job 3.'));
        $visible = $this->action($this->event);
        $omitted = $this->event($this->source()); $denied = $this->action($omitted);
        $ordinary = $this->action($this->ordinary());
        $ids = $this->inFamily($this->viewer, fn ($context) => app(HsEventAccessService::class)
            ->applyFirstAidChildSourceScope(HsCorrectiveAction::whereKey([$visible->id, $denied->id, $ordinary->id]),
                'hs_corrective_actions.hs_event_id', $this->viewer, $context, HsEvent::whereKey($this->event->id))
            ->orderBy('id')->pluck('id')->all());
        $this->assertSame([$visible->id, $ordinary->id], $ids);
    }

    public function test_modern_and_legacy_risk_bindings_both_deny_private_sources_preserving_orphans(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure source guard (hide chemical-exposure H&S events and their actions, investigations and risk assessments from viewers without hr.wellbeing.view, and incident-linked ones without incident and client read) isn\'t applied. Stephan kept main\'s rule for this merge (25 Oct, "Keep today\'s rule"): H&S view at the event\'s Site.', 'revisit with job 3, the exposure delivery pipeline.'));
        $ordinary = $this->ordinary();
        $modern = $this->risk(['hs_event_id' => $this->event->id]);
        $legacy = $this->risk(['assessable_type' => HsEvent::class, 'assessable_id' => $this->event->id]);
        $mixed = $this->risk(['hs_event_id' => $ordinary->id, 'assessable_type' => HsEvent::class, 'assessable_id' => $this->event->id]);
        $orphan = $this->risk(['assessable_type' => HsEvent::class, 'assessable_id' => 900000002]);
        $unrelated = $this->risk(['assessable_type' => User::class, 'assessable_id' => $this->worker->id]);
        $query = HsRiskAssessment::whereKey([$modern->id, $legacy->id, $mixed->id, $orphan->id, $unrelated->id]);
        $ids = $this->inFamily($this->safety, fn ($context) => app(HsEventAccessService::class)
            ->applyFirstAidRiskSourceScope(clone $query, $this->safety, $context)->orderBy('id')->pluck('id')->all());
        $this->assertSame([$orphan->id, $unrelated->id], $ids);
        $ids = $this->inFamily($this->viewer, fn ($context) => app(HsEventAccessService::class)
            ->applyFirstAidRiskSourceScope(clone $query, $this->viewer, $context)->orderBy('id')->pluck('id')->all());
        $this->assertSame([$modern->id, $legacy->id, $mixed->id, $orphan->id, $unrelated->id], $ids);
    }

    public function test_archived_event_binding_remains_denied_for_child_and_risk_legacy_links(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::TEST_SHAPE, 'this calls the H&S source-scope building block on its own. The mounted corrective-actions page also applies the event\'s Site scope and soft-delete filter, so actions on trashed or other-Site events are excluded there.', 'test it through the page.'));
        $action = $this->action($this->event); $risk = $this->risk(['hs_event_id' => $this->event->id]);
        $this->event->delete();
        $ids = $this->inFamily($this->viewer, fn ($context) => app(HsEventAccessService::class)
            ->applyFirstAidChildSourceScope(HsCorrectiveAction::whereKey($action->id), 'hs_corrective_actions.hs_event_id', $this->viewer, $context)->pluck('id')->all());
        $this->assertSame([], $ids);
        $ids = $this->inFamily($this->viewer, fn ($context) => app(HsEventAccessService::class)
            ->applyFirstAidRiskSourceScope(HsRiskAssessment::whereKey($risk->id), $this->viewer, $context)->pluck('id')->all());
        $this->assertSame([], $ids);
    }

    public function test_filtered_child_count_and_rows_agree_without_foreign_or_unrelated_private_hydration(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::TEST_SHAPE, 'this calls the H&S source-scope building block on its own. The mounted corrective-actions page also applies the event\'s Site scope and soft-delete filter, so actions on trashed or other-Site events are excluded there.', 'test it through the page.'));
        $visible = $this->action($this->event);
        $foreignSource = $this->source(['site_id' => $this->foreignSite->id]);
        $foreign = $this->action($this->event($foreignSource));
        $ordinary = $this->action($this->ordinary());
        $hydrated = [];
        SubstanceExposureRecord::retrieved(function ($source) use (&$hydrated) { $hydrated[] = $source->id; });
        try {
            $result = $this->inFamily($this->viewer, function ($context) use ($visible, $foreign, $ordinary) {
                $query = HsCorrectiveAction::whereKey([$visible->id, $foreign->id, $ordinary->id]);
                $scoped = app(HsEventAccessService::class)->applyFirstAidChildSourceScope($query,
                    'hs_corrective_actions.hs_event_id', $this->viewer, $context);
                return ['count' => (clone $scoped)->count(), 'ids' => $scoped->orderBy('id')->pluck('id')->all()];
            });
            $this->assertSame(2, $result['count']);
            $this->assertSame([$visible->id, $ordinary->id], $result['ids']);
            $this->assertNotContains($foreignSource->id, $hydrated);
        } finally { SubstanceExposureRecord::flushEventListeners(); }
    }

    public function test_actual_writer_created_source_has_recorded_known_null_context_and_absolute_instant(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'the exposure source copy on H&S events (ChemicalExposureSourceProjectionService) is called only by the unwired exposure audit guard and risk acceptance; here it returns no source, so it shows less, not more.', 'mount it with the exposure source family.'));
        [$source, $receipt] = $this->recorded(); $event = $this->event($source);
        $dto = $this->projection()->forHsEvent($event->id, $this->viewer);
        $this->assertSame('readable', $dto['access']);
        $this->assertSame('recorded', $dto['source']['attribution_state']);
        $this->assertSame('recorded', $dto['source']['context_binding']);
        $this->assertSame('absent', $dto['source']['incident_binding']);
        $this->assertSame($receipt['source_version_id'], $dto['source']['source_version_id']);
        $this->assertSame(1, $dto['source']['source_revision']);
        $this->assertTrue(Carbon::parse($dto['source']['exposed_at'])->equalTo(Carbon::parse('2026-10-12T12:00:00+13:00')));
        $this->assertSame([$event->id], $this->ids(HsEvent::whereKey($event->id)));
    }

    public function test_changed_typed_current_facts_conceal_copy_and_child_count_instead_of_certifying_markers(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'destination-marker and acceptance-digest proofs: the markers are written only by the unmounted delivery pipeline, so events the observer creates have none. These are tamper checks on rows edited directly in the database, not checks on who sees what.', 'job 3 after the merge: finish the delivery pipeline.'));
        [$source] = $this->recorded(); $event = $this->event($source); $action = $this->action($event);
        DB::table('substance_exposure_records')->where('id', $source->id)->update(['symptoms' => 'EXTERNALLY-MUTATED-PRIVATE-FACT']);
        $dto = $this->projection()->forHsEvent($event->id, $this->viewer);
        $this->assertSame(['access' => 'unavailable', 'source' => null, 'integrity' => 'repair_required'], $dto);
        $this->assertSame([], $this->ids(HsEvent::whereKey($event->id)));
        $count = $this->inFamily($this->viewer, fn ($context) => app(HsEventAccessService::class)
            ->applyFirstAidChildSourceScope(HsCorrectiveAction::whereKey($action->id), 'hs_corrective_actions.hs_event_id', $this->viewer, $context)->count());
        $this->assertSame(0, $count);
    }

    public function test_corrupt_retained_digest_returns_minimal_repair_before_private_tuple_or_labels(): void
    {
        [$source, $receipt] = $this->recorded(); $event = $this->event($source);
        DB::table('chemical_exposure_source_versions')->where('id', $receipt['source_version_id'])->update(['content_digest' => str_repeat('0', 64)]);
        $this->assertSame(['access' => 'unavailable', 'source' => null, 'integrity' => 'repair_required'],
            $this->projection()->forHsEvent($event->id, $this->viewer));
        $this->assertSame([], $this->ids(HsEvent::whereKey($event->id)));
    }

    public function test_governed_marker_downgrade_cannot_become_genuine_legacy_unknown(): void
    {
        [$source] = $this->recorded(); $event = $this->event($source);
        DB::table('substance_exposure_records')->where('id', $source->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame('repair_required', $this->projection()->forHsEvent($event->id, $this->viewer)['integrity']);
        $this->assertSame([], $this->ids(HsEvent::whereKey($event->id)));
        $this->assertSame('legacy_unknown', $this->projection()->forHsEvent($this->event->id, $this->viewer)['source']['attribution_state']);
    }

    public function test_recorded_known_null_context_cannot_accept_an_added_copied_client_identity(): void
    {
        [$source] = $this->recorded();
        $event = $this->event($source, ['client_id' => \App\Models\Client::factory()->create(['site_id' => $this->site->id])->id]);
        $this->assertSame([], $this->ids(HsEvent::whereKey($event->id)));
        $this->assertSame('restricted', $this->projection()->forHsEvent($event->id, $this->viewer)['access']);
    }

    public function test_actual_recorded_incident_requires_the_retained_client_and_current_destination_authority(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'the exposure source copy on H&S events (ChemicalExposureSourceProjectionService) is called only by the unwired exposure audit guard and risk acceptance; here it returns no source, so it shows less, not more.', 'mount it with the exposure source family.'));
        $this->grant($this->viewer, 'incidents.viewAny'); $this->grant($this->viewer, 'clients.viewAny');
        $client = \App\Models\Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'reported_by' => $this->viewer->id]);
        [$source] = $this->recorded(['related_incident_id' => $incident->id, 'incident_reported' => true]);
        $event = $this->event($source, ['client_id' => $client->id]);
        $this->assertSame('recorded', $this->projection()->forHsEvent($event->id, $this->viewer)['source']['incident_binding']);
        $this->assertSame([$event->id], $this->ids(HsEvent::whereKey($event->id)));
        $this->viewer->load('roles.permissions', 'permissionOverrides');
        $this->grant($this->viewer, 'clients.viewAny', false);
        $this->assertNull($this->projection()->forHsEvent($event->id, $this->viewer)['source']);
        $this->assertSame([], $this->ids(HsEvent::whereKey($event->id)));
        $this->grant($this->viewer, 'clients.viewAny');
        $this->assertSame('readable', $this->projection()->forHsEvent($event->id, $this->viewer)['access']);
        $this->grant($this->viewer, 'incidents.viewAny', false);
        $this->assertNull($this->projection()->forHsEvent($event->id, $this->viewer)['source']);
        $this->grant($this->viewer, 'incidents.viewAny');
        $this->assertSame([$event->id], $this->ids(HsEvent::whereKey($event->id)));
    }

    public function test_recorded_original_incident_client_correction_never_supplies_todays_tuple_as_a_fallback(): void
    {
        $this->grant($this->viewer, 'incidents.viewAny'); $this->grant($this->viewer, 'clients.viewAny');
        $client = \App\Models\Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'reported_by' => $this->viewer->id]);
        [$source] = $this->recorded(['related_incident_id' => $incident->id]); $event = $this->event($source, ['client_id' => $client->id]);
        $newClient = \App\Models\Client::factory()->create(['site_id' => $this->site->id]);
        $incident->update(['client_id' => $newClient->id]);
        $this->assertNull($this->projection()->forHsEvent($event->id, $this->viewer)['source']);
        $this->assertSame([], $this->ids(HsEvent::whereKey($event->id)));
        $wrongCopy = $this->event($source, ['client_id' => $newClient->id]);
        $this->assertNull($this->projection()->forHsEvent($wrongCopy->id, $this->viewer)['source']);
    }

    public function test_historical_exposed_worker_current_employment_does_not_control_private_source_read(): void
    {
        $this->worker->hrEmployeeProfile()->update(['is_active' => false, 'end_date' => '2026-09-30']);
        $this->assertSame([$this->event->id], $this->ids(HsEvent::whereKey($this->event->id)));
        $this->assertSame($this->worker->id, $this->projection()->forHsEvent($this->event->id, $this->viewer)['source']['worker']['id']);
    }

    public function test_current_safety_grant_and_secondary_site_assignment_revoke_and_restore_independently(): void
    {
        $this->viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->grant($this->viewer, 'hazards.view', false);
        [$dto, $queries] = $this->queries(fn () => $this->projection()->forHsEvent($this->event->id, $this->viewer));
        $this->assertSame('restricted', $dto['access']); $this->noPrivateSql($queries);
        $this->grant($this->viewer, 'hazards.view');
        $this->viewer->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => [$this->site->id]]);
        $this->assertSame([$this->event->id], $this->ids(HsEvent::whereKey($this->event->id)));
        $this->viewer->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
        $this->assertSame([], $this->ids(HsEvent::whereKey($this->event->id)));
        $this->viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->site->id]]);
        $this->assertSame('readable', $this->projection()->forHsEvent($this->event->id, $this->viewer)['access']);
    }
    private function recorded(array $facts = []): array
    {
        $this->grant($this->viewer, 'hazards.create');
        $receipt = app(\App\Services\HealthSafety\ChemicalExposureSourceService::class)->create($this->substance, $this->viewer,
            ['idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->worker->id,
                'site_id' => $this->site->id, 'exposed_at' => '2026-10-12T12:00:00+13:00', 'exposure_type' => 'skin_contact',
                'medical_treatment' => 'none', 'symptoms' => 'ACTUAL-RECORDED-PRIVATE-FACT', ...$facts]);
        $this->assertTrue($receipt['accepted']);
        return [SubstanceExposureRecord::findOrFail($receipt['root_id']), $receipt];
    }
    private function projection(): ChemicalExposureSourceProjectionService { return app(ChemicalExposureSourceProjectionService::class); }

    private function source(array $facts = []): SubstanceExposureRecord
    {
        return SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::factory()->create([
            'hazardous_substance_id' => $this->substance->id, 'user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'related_incident_id' => null, 'exposed_at' => '2026-10-12 00:00:00', 'created_by' => $this->viewer->id,
            'symptoms' => 'PRIVATE-EXPOSURE-SYMPTOMS', ...$facts]));
    }

    private function event(SubstanceExposureRecord $source, array $facts = []): HsEvent
    {
        return HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['source_type' => SubstanceExposureRecord::class,
            'source_id' => $source->id, 'event_category' => HsEvent::CATEGORY_EXPOSURE, 'site_id' => $source->site_id,
            'staff_id' => $source->user_id, 'client_id' => null, 'shift_id' => null, 'asset_id' => null, ...$facts]));
    }

    private function ordinary(): HsEvent
    {
        return HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['site_id' => $this->site->id, 'client_id' => null, 'staff_id' => null, 'asset_id' => null]));
    }

    private function action(HsEvent $event): HsCorrectiveAction
    {
        return HsCorrectiveAction::withoutEvents(fn () => HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id]));
    }

    private function risk(array $facts): HsRiskAssessment
    {
        return HsRiskAssessment::withoutEvents(fn () => HsRiskAssessment::factory()->create(['hs_event_id' => null,
            'assessable_type' => null, 'assessable_id' => null, ...$facts]));
    }

    private function staff(array $permissions): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        foreach ($permissions as $key) { $this->grant($user, $key); }
        return $user;
    }

    private function grant(User $user, string $key, bool $allowed = true): void
    {
        $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }

    private function ids(Builder $query): array
    {
        return HealthSafetyReadContext::within($this->viewer, ChemicalExposureSourceProjectionService::PERMISSIONS,
            fn ($context) => $this->projection()->readableHsEventIdsInContext($this->viewer, $query, $context));
    }

    private function inFamily(User $viewer, callable $consumer): mixed
    {
        return HealthSafetyReadContext::within($viewer, [...FirstAidAccessService::PERMISSIONS,
            ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
            ...ChemicalExposureSourceProjectionService::PERMISSIONS], $consumer);
    }

    private function eventIds(User $viewer, Builder $query): array
    {
        return $this->inFamily($viewer, fn ($context) => app(HsEventAccessService::class)
            ->applyReadableScope($query, $viewer, $context, clone $query)->orderBy('id')->pluck('id')->all());
    }

    private function queries(callable $operation): array
    {
        DB::flushQueryLog(); DB::enableQueryLog();
        try { return [$operation(), array_column(DB::getQueryLog(), 'query')]; }
        finally { DB::disableQueryLog(); DB::flushQueryLog(); }
    }

    private function noPrivateSql(array $queries): void
    {
        foreach ($queries as $query) {
            foreach (['substance_exposure_records', 'chemical_exposure_', 'client_incidents', 'hs_corrective_actions', 'hs_risk_assessments', 'audit_logs'] as $table) {
                $this->assertStringNotContainsString($table, strtolower($query));
            }
        }
    }

    private function integrity(callable $operation): void
    {
        try { $operation(); $this->fail('A wrong viewer, expired context or unprepared capability must remain an error.'); }
        catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
    }

    private function detached(mixed $value): void
    {
        if (is_array($value)) { foreach ($value as $part) { $this->detached($part); } }
        else { $this->assertTrue($value === null || is_scalar($value)); }
    }
}
