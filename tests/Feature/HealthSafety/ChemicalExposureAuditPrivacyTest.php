<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureReview;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HazardousSubstance;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\Audit\AuditLogViewService;
use App\Services\HealthSafety\ChemicalExposureAuditAccessService;
use App\Services\HealthSafety\ChemicalExposureSourceService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/** Complete prepared suite. Writer audit proof and shared consuming-boundary proposal must be mounted; UNRUN. */
final class ChemicalExposureAuditPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;
    private Site $foreignSite;
    private User $viewer;
    private User $safety;
    private User $worker;
    private User $recorder;
    private HazardousSubstance $substance;
    private SubstanceExposureRecord $source;
    private AuditLog $audit;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-12T01:00:00Z'));
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->foreignSite = Site::factory()->create(['is_active' => true]);
        $this->viewer = $this->staff(['audit.viewAny', 'hazards.view', 'hr.wellbeing.view']);
        $this->safety = $this->staff(['audit.viewAny', 'hazards.view']);
        $this->worker = $this->staff([]);
        $this->recorder = $this->staff([], ['name' => 'EXPOSURE-ONLY-ACTOR']);
        $this->substance = HazardousSubstance::factory()->create();
        $this->source = $this->legacy();
        $this->audit = $this->audit(SubstanceExposureRecord::class, $this->source->id, 'substanceexposurerecord.update',
            ['before' => ['symptoms' => 'PRIVATE-OLD-SYMPTOMS'], 'after' => ['symptoms' => 'PRIVATE-NEW-SYMPTOMS']]);
    }

    public function test_audit_permission_and_safety_without_wellbeing_exclude_private_source_before_body_hydration(): void
    {
        $retrieved = [];
        AuditLog::retrieved(function ($row) use (&$retrieved) { $retrieved[] = $row->id; });
        try {
            [$rows, $sql] = $this->queries(fn () => $this->visible($this->safety, AuditLog::whereKey($this->audit->id)));
            $this->assertSame([], $rows);
            $this->assertNotContains($this->audit->id, $retrieved);
            $this->noPrivateHydration($sql);
        } finally { AuditLog::flushEventListeners(); }
    }

    public function test_wellbeing_only_and_recorded_worker_do_not_gain_saved_exposure_audit_access(): void
    {
        $wellbeing = $this->staff(['audit.viewAny', 'hr.wellbeing.view']);
        $this->grant($this->worker, 'audit.viewAny');
        $this->grant($this->worker, 'hazards.view');
        $this->assertSame([], $this->visible($wellbeing, AuditLog::whereKey($this->audit->id)));
        $this->assertSame([], $this->visible($this->worker, AuditLog::whereKey($this->audit->id)));
    }

    public function test_unrelated_auditor_without_hr_profile_keeps_application_audit_rows(): void
    {
        $viewer = User::factory()->create(['approved_at' => now(), 'external_clinical_account' => false]);
        $this->grant($viewer, 'audit.viewAny');
        $ordinary = $this->audit(User::class, $viewer->id, 'it.change.updated', ['after' => ['status' => 'approved']]);
        $this->assertSame([$ordinary->id], $this->visible($viewer, AuditLog::whereKey([$ordinary->id, $this->audit->id])));
    }

    public function test_granted_legacy_unlinked_private_audit_remains_honestly_unknown_and_detached(): void
    {
        $this->assertSame([$this->audit->id], $this->visible($this->viewer, AuditLog::whereKey($this->audit->id)));
        $dto = $this->guard()->materialize($this->viewer, function ($current, $context) {
            $row = $this->guard()->currentForPresentation($this->audit, $current, $context);
            return ['id' => (int) $row->id, 'action' => $row->action];
        });
        $this->assertSame(['id' => $this->audit->id, 'action' => $this->audit->action], $dto);
    }

    public function test_legacy_null_viewer_or_unscoped_wrappers_exclude_only_private_families(): void
    {
        $ordinary = $this->audit(User::class, $this->viewer->id, 'hr.staff.updated');
        $candidate = AuditLog::whereKey([$this->audit->id, $ordinary->id]);
        $this->assertSame([$ordinary->id], $this->guard()->scope(clone $candidate)->orderBy('id')->pluck('id')->all());
        $this->assertSame([$ordinary->id], $this->guard()->scope(clone $candidate, $this->viewer)->orderBy('id')->pluck('id')->all());
        $this->assertFalse($this->guard()->canPresent($this->audit, $this->viewer));
        $this->assertTrue($this->guard()->canPresent($ordinary));
    }

    public function test_presentation_requeries_current_body_instead_of_certifying_preloaded_private_model(): void
    {
        $preloaded = $this->audit->fresh();
        DB::table('audit_logs')->where('id', $preloaded->id)->update(['meta' => json_encode(['after' => ['status' => 'current']], JSON_THROW_ON_ERROR)]);
        $dto = $this->guard()->materialize($this->viewer, function ($current, $context) use ($preloaded) {
            $fresh = $this->guard()->currentForPresentation($preloaded, $current, $context);
            return $fresh->meta;
        });
        $this->assertSame(['after' => ['status' => 'current']], $dto);
        $this->assertArrayHasKey('symptoms', $preloaded->meta['after']);
    }

    public function test_independent_candidates_are_bounded_immutable_and_do_not_hydrate_foreign_sources(): void
    {
        $other = $this->legacy(['site_id' => $this->foreignSite->id]);
        $foreign = $this->audit(SubstanceExposureRecord::class, $other->id);
        $candidate = AuditLog::whereKey($this->audit->id)->orderByDesc('id')->limit(1);
        $sql = $candidate->toSql(); $bindings = $candidate->getBindings();
        $retrieved = [];
        SubstanceExposureRecord::retrieved(function ($row) use (&$retrieved) { $retrieved[] = $row->id; });
        try {
            $this->assertSame([$this->audit->id], $this->visible($this->viewer, $candidate));
            $this->assertNotContains($other->id, $retrieved);
            $this->assertSame($sql, $candidate->toSql()); $this->assertSame($bindings, $candidate->getBindings());
            $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($foreign->id)));
        } finally { SubstanceExposureRecord::flushEventListeners(); }
    }

    public function test_empty_or_disjoint_scopes_do_not_reuse_a_private_whitelist(): void
    {
        $other = $this->audit(SubstanceExposureRecord::class, $this->legacy()->id);
        $this->guard()->materialize($this->viewer, function ($current, $context) use ($other) {
            $this->assertSame([$this->audit->id], $this->guard()->scope(AuditLog::whereKey($this->audit->id), $current, $context)->pluck('id')->all());
            $this->assertSame([$other->id], $this->guard()->scope(AuditLog::whereKey($other->id), $current, $context)->pluck('id')->all());
            $this->assertSame([], $this->guard()->scope(AuditLog::whereRaw('1=0'), $current, $context)->pluck('id')->all());
            return null;
        });
    }

    public function test_supplied_context_is_reused_and_builder_cannot_escape_its_lifetime(): void
    {
        $escaped = null;
        $this->guard()->materialize($this->viewer, function ($current, $context) use (&$escaped) {
            $level = DB::transactionLevel();
            $this->guard()->materialize($current, function ($actor, $same) use ($context, $level, &$escaped) {
                $this->assertSame($context, $same); $this->assertSame($level, DB::transactionLevel());
                $escaped = $this->guard()->scope(AuditLog::whereKey($this->audit->id), $actor, $same);
                $this->assertSame(1, $escaped->count());
                return null;
            }, $context);
            return null;
        });
        $this->integrity(fn () => $escaped->get());
    }

    public function test_wrong_viewer_missing_preparation_and_escaped_materialized_objects_are_errors(): void
    {
        $this->guard()->materialize($this->viewer, function ($current, $context) {
            $this->integrity(fn () => $this->guard()->scope(AuditLog::query(), $this->safety, $context));
            $this->integrity(fn () => $this->guard()->materialize($current, fn () => AuditLog::query(), $context));
            return null;
        });
        HealthSafetyReadContext::within($this->viewer, ['audit.viewAny'], function ($context) {
            $this->integrity(fn () => $this->guard()->scope(AuditLog::query(), $this->viewer, $context));
            return null;
        });
    }

    public function test_loaded_private_grant_revocation_and_restoration_are_current(): void
    {
        $this->viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->grant($this->viewer, 'hr.wellbeing.view', false);
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($this->audit->id)));
        $this->grant($this->viewer, 'hr.wellbeing.view');
        $this->assertSame([$this->audit->id], $this->visible($this->viewer, AuditLog::whereKey($this->audit->id)));
        $this->grant($this->viewer, 'hazards.view', false);
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($this->audit->id)));
        $this->grant($this->viewer, 'hazards.view');
        $this->assertSame([$this->audit->id], $this->visible($this->viewer, AuditLog::whereKey($this->audit->id)));
    }

    public function test_current_hr_and_site_withdrawal_preserve_unrelated_rows_and_restore_private_rows(): void
    {
        $ordinary = $this->audit(User::class, $this->viewer->id, 'it.change.updated');
        $ids = [$this->audit->id, $ordinary->id];
        $this->viewer->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertSame([$ordinary->id], $this->visible($this->viewer, AuditLog::whereKey($ids)));
        $this->viewer->hrEmployeeProfile()->update(['is_active' => true, 'primary_site_id' => $this->foreignSite->id]);
        $this->assertSame([$ordinary->id], $this->visible($this->viewer, AuditLog::whereKey($ids)));
        $this->viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->site->id]]);
        $this->assertSame($ids, $this->visible($this->viewer, AuditLog::whereKey($ids)));
    }

    public function test_inactive_or_archived_original_site_has_no_bypass_read(): void
    {
        $this->grant($this->viewer, 'healthSafety.viewAllSites');
        $this->site->update(['is_active' => false]);
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($this->audit->id)));
        $this->site->update(['is_active' => true, 'archived_at' => now()]);
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($this->audit->id)));
    }

    public function test_null_original_site_and_missing_source_cannot_infer_worker_current_site(): void
    {
        $unknown = $this->legacy(['site_id' => null]);
        $row = $this->audit(SubstanceExposureRecord::class, $unknown->id);
        $missing = $this->audit(SubstanceExposureRecord::class, 9999999);
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey([$row->id, $missing->id])));
    }

    public function test_legacy_linked_incident_unknown_original_client_is_not_current_or_known_null(): void
    {
        $incident = ClientIncident::factory()->create(['site_id' => $this->site->id]);
        $source = $this->legacy(['related_incident_id' => $incident->id]);
        $row = $this->audit(SubstanceExposureRecord::class, $source->id);
        $this->grant($this->viewer, 'incidents.viewAny'); $this->grant($this->viewer, 'clients.viewAny');
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
    }

    public function test_private_action_without_resolvable_subject_never_becomes_public(): void
    {
        $row = $this->audit(null, null, 'chemical.exposure.review_exposure');
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
        $this->assertSame([], $this->guard()->scope(AuditLog::whereKey($row->id))->pluck('id')->all());
    }

    public function test_copied_hse_and_action_audits_require_exact_current_source_tuple_before_prose(): void
    {
        $event = $this->event($this->source);
        $action = HsCorrectiveAction::withoutEvents(fn () => HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id]));
        $eventAudit = $this->audit(HsEvent::class, $event->id, 'hsevent.update');
        $actionAudit = $this->audit(HsCorrectiveAction::class, $action->id, 'hscorrectiveaction.update');
        $ids = [$eventAudit->id, $actionAudit->id];
        $this->assertSame($ids, $this->visible($this->viewer, AuditLog::whereKey($ids)));
        DB::table('hs_events')->where('id', $event->id)->update(['staff_id' => $this->recorder->id]);
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($ids)));
    }

    public function test_historical_child_parent_binding_cannot_be_hidden_by_reparenting_to_ordinary_event(): void
    {
        $private = $this->event($this->source);
        $ordinary = HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['site_id' => $this->site->id]));
        $child = HsInvestigation::withoutEvents(fn () => HsInvestigation::factory()->create(['hs_event_id' => $ordinary->id]));
        $row = $this->audit(HsInvestigation::class, $child->id, 'hsinvestigation.update', ['before' => ['hs_event_id' => $private->id], 'after' => ['hs_event_id' => $ordinary->id]]);
        $this->assertSame([], $this->visible($this->safety, AuditLog::whereKey($row->id)));
        $this->assertSame([$row->id], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
    }

    public function test_modern_and_legacy_risk_bindings_both_deny_when_either_private_source_is_unavailable(): void
    {
        $private = $this->event($this->source);
        $foreign = $this->event($this->legacy(['site_id' => $this->foreignSite->id]));
        $risk = HsRiskAssessment::withoutEvents(fn () => HsRiskAssessment::factory()->create(['hs_event_id' => $private->id,
            'assessable_type' => HsEvent::class, 'assessable_id' => $foreign->id]));
        $row = $this->audit(HsRiskAssessment::class, $risk->id, 'hsriskassessment.update');
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
        DB::table('hs_risk_assessments')->where('id', $risk->id)->update(['assessable_id' => $private->id]);
        $this->assertSame([$row->id], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
    }

    public function test_archived_private_hse_binding_is_denied_but_unrelated_orphan_risk_stays_visible(): void
    {
        $event = $this->event($this->source);
        $row = $this->audit(HsEvent::class, $event->id, 'hsevent.update');
        DB::table('hs_events')->where('id', $event->id)->update(['deleted_at' => now()]);
        $orphan = HsRiskAssessment::withoutEvents(fn () => HsRiskAssessment::factory()->create(['hs_event_id' => null, 'assessable_type' => null, 'assessable_id' => null]));
        $ordinary = $this->audit(HsRiskAssessment::class, $orphan->id, 'hsriskassessment.update');
        $this->assertSame([$ordinary->id], $this->visible($this->viewer, AuditLog::whereKey([$row->id, $ordinary->id])));
    }

    public function test_copied_historical_source_site_and_worker_tuple_is_not_a_new_authority(): void
    {
        $event = $this->event($this->source);
        $bad = $this->audit(HsEvent::class, $event->id, 'hsevent.update', ['before' => ['source_type' => SubstanceExposureRecord::class,
            'source_id' => $this->source->id, 'site_id' => $this->foreignSite->id, 'staff_id' => $this->worker->id]]);
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($bad->id)));
        $good = $this->audit(HsEvent::class, $event->id, 'hsevent.update', ['before' => ['source_type' => SubstanceExposureRecord::class,
            'source_id' => $this->source->id, 'site_id' => $this->site->id, 'staff_id' => $this->worker->id]]);
        $this->assertSame([$good->id], $this->visible($this->viewer, AuditLog::whereKey($good->id)));
    }

    public function test_copied_child_history_without_literal_source_type_still_proves_exact_site_worker_and_null_context(): void
    {
        $event = $this->event($this->source);
        $child = HsCorrectiveAction::withoutEvents(fn () => HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id]));
        $badSite = $this->audit(HsCorrectiveAction::class, $child->id, 'hscorrectiveaction.update',
            ['before' => ['hs_event_id' => $event->id, 'site_id' => $this->foreignSite->id]]);
        $badWorker = $this->audit(HsCorrectiveAction::class, $child->id, 'hscorrectiveaction.update',
            ['before' => ['staff_id' => $this->recorder->id]]);
        $badNull = $this->audit(HsCorrectiveAction::class, $child->id, 'hscorrectiveaction.update',
            ['after' => ['hs_event_id' => $event->id, 'site_id' => null]]);
        $badContext = $this->audit(HsCorrectiveAction::class, $child->id, 'hscorrectiveaction.update',
            ['before' => ['hs_event_id' => $event->id, 'respite_stay_id' => 99]]);
        $exact = $this->audit(HsCorrectiveAction::class, $child->id, 'hscorrectiveaction.update',
            ['before' => ['hs_event_id' => $event->id, 'site_id' => $this->site->id,
                'staff_id' => $this->worker->id, 'client_id' => null, 'shift_id' => null]]);
        $ids = [$badSite->id, $badWorker->id, $badNull->id, $badContext->id, $exact->id];
        $this->assertSame([$exact->id], $this->visible($this->viewer, AuditLog::whereKey($ids)));
        $this->assertSame([], $this->visible($this->safety, AuditLog::whereKey($ids)));
    }
    public function test_actor_name_search_and_counts_are_scoped_before_pagination_and_filter_options(): void
    {
        $ordinary = $this->audit(User::class, $this->viewer->id, 'it.audit.updated', [], $this->viewer);
        $service = app(AuditLogViewService::class);
        $dto = $this->guard()->materialize($this->safety, function ($current, $context) use ($service, $ordinary) {
            return ['total' => $service->query(['action' => 'substanceexposurerecord'], $current, $context)->count(),
                'search' => $service->query(['search' => 'EXPOSURE-ONLY-ACTOR'], $current, $context)->pluck('id')->all(),
                'ordinary' => $service->query(['action' => 'it.audit.updated'], $current, $context)->pluck('id')->all(),
                'options' => $service->filterOptions($current, $context)];
        });
        $this->assertSame(0, $dto['total']); $this->assertSame([], $dto['search']);
        $this->assertSame([$ordinary->id], $dto['ordinary']);
        $this->assertNotContains($this->recorder->id, array_column($dto['options']['users'], 'id'));
    }

    public function test_real_general_and_settings_pages_csv_and_partial_props_exclude_private_actor_and_rows(): void
    {
        $ordinary = $this->audit(User::class, $this->viewer->id, 'it.audit.updated', [], $this->viewer);
        foreach (['/audit-logs', '/settings/audit-logs'] as $path) {
            $props = $this->actingAs($this->safety)->get($path)->assertOk()->inertiaProps();
            $page = $props[$path === '/audit-logs' ? 'logs' : 'events'];
            $this->assertNotContains($this->audit->id, array_column($page['data'], 'id'));
            $this->assertContains($ordinary->id, array_column($page['data'], 'id'));
            $this->assertStringNotContainsString('EXPOSURE-ONLY-ACTOR', json_encode($props, JSON_THROW_ON_ERROR));
        }
        $partial = $this->actingAs($this->safety)->withHeaders(['X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'settings/audit-logs', 'X-Inertia-Partial-Data' => 'events,stats,users'])
            ->get('/settings/audit-logs?action=substanceexposurerecord')->assertOk()->json('props');
        $this->assertSame(0, $partial['events']['total']);
        $this->assertSame(['today' => 0, 'this_week' => 0, 'this_month' => 0], $partial['stats']);
        $csv = $this->actingAs($this->safety)->get('/settings/audit-logs/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('it.audit.updated', $csv);
        $this->assertStringNotContainsString('EXPOSURE-ONLY-ACTOR', $csv);
        $this->assertStringNotContainsString('substanceexposurerecord', $csv);
    }

    public function test_real_hr_history_and_page_apply_private_guard_without_breaking_hr_rows(): void
    {
        $this->grant($this->safety, 'hr.settings.manage');
        DB::table('audit_logs')->where('id', $this->audit->id)->update(['action' => 'hr.exposure.updated']);
        $ordinary = $this->audit(User::class, $this->viewer->id, 'hr.employee.updated', ['after' => ['status' => 'active']], $this->viewer);
        $props = $this->actingAs($this->safety)->get('/hr/settings/audit-log')->assertOk()->inertiaProps();
        $this->assertContains($ordinary->id, array_column($props['logs']['data'], 'id'));
        $this->assertNotContains($this->audit->id, array_column($props['logs']['data'], 'id'));
        $type = rawurlencode(SubstanceExposureRecord::class);
        $this->actingAs($this->safety)->get('/hr/settings/audit-log/'.$type.'/'.$this->source->id)
            ->assertOk()->assertJsonPath('entries', []);
    }

    public function test_actual_typed_writer_root_version_and_receipt_audits_use_same_current_semantic_proof(): void
    {
        [$source, $receipt] = $this->recorded();
        $version = ChemicalExposureSourceVersion::where('exposure_id', $source->id)->sole();
        $accepted = ChemicalExposureCommandReceipt::where('exposure_id', $source->id)->sole();
        $a = $this->audit(SubstanceExposureRecord::class, $source->id);
        $b = $this->audit(ChemicalExposureSourceVersion::class, $version->id);
        $c = $this->audit(ChemicalExposureCommandReceipt::class, $accepted->id);
        $this->assertSame([$a->id, $b->id, $c->id], $this->visible($this->viewer, AuditLog::whereKey([$a->id, $b->id, $c->id])));
        $this->assertSame($source->id, $receipt['root_id']);
        $this->assertSame([], $this->visible($this->safety, AuditLog::whereKey([$a->id, $b->id, $c->id])));
    }

    public function test_actual_typed_child_audit_uses_child_current_version_not_root_fk_alone(): void
    {
        [$source, $receipt] = $this->recorded();
        $this->grant($this->viewer, 'hazards.manage');
        app(ChemicalExposureSourceService::class)->review($source, $this->viewer, [
            'idempotency_key' => (string) Str::uuid(), 'expected_version' => $receipt['version'],
            'reviewed_at' => '2026-10-12T13:00:00+13:00', 'outcome' => 'recorded', 'notes' => 'Private source-owned review']);
        $child = ChemicalExposureReview::where('exposure_id', $source->id)->sole();
        $row = $this->audit(ChemicalExposureReview::class, $child->id);
        $this->assertSame([$row->id], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
        DB::table('chemical_exposure_reviews')->where('id', $child->id)->update(['notes' => 'Externally changed private fact']);
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
    }

    public function test_damaged_root_or_marker_downgrade_conceals_all_private_audit_copies(): void
    {
        [$source] = $this->recorded();
        $row = $this->audit(SubstanceExposureRecord::class, $source->id);
        DB::table('substance_exposure_records')->where('id', $source->id)->update(['symptoms' => 'Changed outside accepted source']);
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
        [$source] = $this->recorded();
        $row = $this->audit(SubstanceExposureRecord::class, $source->id);
        DB::table('substance_exposure_records')->where('id', $source->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
    }

    public function test_retained_acceptance_damage_is_not_fixed_by_recomputing_local_version_digest(): void
    {
        [$source] = $this->recorded();
        $row = $this->audit(SubstanceExposureRecord::class, $source->id);
        $version = ChemicalExposureSourceVersion::where('exposure_id', $source->id)->sole();
        DB::table('chemical_exposure_command_receipts')->where('exposure_id', $source->id)->update(['source_content_digest' => str_repeat('f', 64)]);
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
        $this->assertNotSame(str_repeat('f', 64), $version->content_digest);
    }

    public function test_audit_grant_is_fresh_and_not_restored_by_private_or_catalogue_permissions(): void
    {
        $this->viewer->load('roles.permissions', 'permissionOverrides');
        $this->grant($this->viewer, 'audit.viewAny', false);
        try { $this->visible($this->viewer, AuditLog::whereKey($this->audit->id)); $this->fail('Current audit denial must remain forbidden.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { $this->assertSame(403, $error->getStatusCode()); }
        $this->grant($this->viewer, 'audit.viewAny');
        $this->assertSame([$this->audit->id], $this->visible($this->viewer, AuditLog::whereKey($this->audit->id)));
    }

    public function test_preloaded_private_model_cannot_be_presented_after_current_site_withdrawal(): void
    {
        $loaded = $this->audit->fresh(['user']);
        $this->viewer->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id]);
        $this->guard()->materialize($this->viewer, function ($current, $context) use ($loaded) {
            $this->assertFalse($this->guard()->canPresent($loaded, $current, $context));
            try { $this->guard()->currentForPresentation($loaded, $current, $context); $this->fail('Stale private body must be concealed.'); }
            catch (ModelNotFoundException) { $this->assertTrue(true); }
            return null;
        });
    }

    public function test_recorded_incident_current_client_and_incident_grants_revoke_restore_without_fallback(): void
    {
        $this->grant($this->viewer, 'incidents.viewAny'); $this->grant($this->viewer, 'clients.viewAny');
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'reported_by' => $this->viewer->id]);
        [$source] = $this->recorded(['related_incident_id' => $incident->id, 'incident_reported' => true]);
        $row = $this->audit(SubstanceExposureRecord::class, $source->id);
        $this->assertSame([$row->id], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
        $this->viewer->load('roles.permissions', 'permissionOverrides');
        foreach (['clients.viewAny', 'incidents.viewAny'] as $key) {
            $this->grant($this->viewer, $key, false);
            $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
            $this->grant($this->viewer, $key);
            $this->assertSame([$row->id], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
        }
        $incident->update(['client_id' => Client::factory()->create(['site_id' => $this->site->id])->id]);
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
    }

    public function test_explicit_historical_null_client_is_not_missing_evidence_or_a_recorded_client(): void
    {
        $this->grant($this->viewer, 'incidents.viewAny'); $this->grant($this->viewer, 'clients.viewAny');
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'reported_by' => $this->viewer->id]);
        [$linked] = $this->recorded(['related_incident_id' => $incident->id, 'incident_reported' => true]);
        $conflict = $this->audit(SubstanceExposureRecord::class, $linked->id, 'chemical.exposure.recorded',
            ['before' => ['client_id' => null]]);
        $exact = $this->audit(SubstanceExposureRecord::class, $linked->id, 'chemical.exposure.recorded',
            ['after' => ['client_id' => $client->id, 'shift_id' => null]]);
        [$unlinked] = $this->recorded();
        $knownNull = $this->audit(SubstanceExposureRecord::class, $unlinked->id, 'chemical.exposure.recorded',
            ['after' => ['client_id' => null, 'shift_id' => null]]);
        $this->assertSame([$exact->id, $knownNull->id], $this->visible($this->viewer,
            AuditLog::whereKey([$conflict->id, $exact->id, $knownNull->id])));
    }
    public function test_foreign_child_audit_resolution_reads_identity_but_not_private_child_body(): void
    {
        [$source, $receipt] = $this->recorded();
        $this->grant($this->viewer, 'hazards.manage');
        app(ChemicalExposureSourceService::class)->review($source, $this->viewer, [
            'idempotency_key' => (string) Str::uuid(), 'expected_version' => $receipt['version'],
            'reviewed_at' => '2026-10-12T13:00:00+13:00', 'outcome' => 'recorded', 'notes' => 'Foreign-private-proof-sentinel']);
        $child = ChemicalExposureReview::where('exposure_id', $source->id)->sole();
        $row = $this->audit(ChemicalExposureReview::class, $child->id);
        $this->viewer->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id]);
        [$ids, $queries] = $this->queries(fn () => $this->visible($this->viewer, AuditLog::whereKey($row->id)));
        $this->assertSame([], $ids);
        foreach ($queries as $sql) {
            if (str_contains($sql, 'from `chemical_exposure_reviews`')) {
                $this->assertStringNotContainsString('select *', strtolower($sql));
                $this->assertStringNotContainsString('`notes`', strtolower($sql));
            }
        }
    }

    public function test_literal_family_identity_is_exact_and_wrong_basename_private_action_stays_concealed(): void
    {
        $row = $this->audit('Unrelated\\SubstanceExposureRecord', $this->source->id, 'substanceexposurerecord.update');
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($row->id)));
        $ordinary = $this->audit('Unrelated\\SubstanceExposureRecord', $this->source->id, 'it.change.updated');
        $this->assertSame([$ordinary->id], $this->visible($this->safety, AuditLog::whereKey($ordinary->id)));
    }

    public function test_page_totals_and_csv_use_the_same_filtered_visible_audit_set(): void
    {
        for ($i = 0; $i < 53; $i++) {
            $this->audit(SubstanceExposureRecord::class, $this->source->id, 'chemical.exposure.test_private');
        }
        $ordinary = [];
        for ($i = 0; $i < 51; $i++) {
            $ordinary[] = $this->audit(User::class, $this->viewer->id, 'it.audit.public_page', [], $this->viewer)->id;
        }
        $props = $this->actingAs($this->safety)->get('/settings/audit-logs?action=test_private')->assertOk()->inertiaProps();
        $this->assertSame(0, $props['events']['total']); $this->assertSame([], $props['events']['data']);
        $props = $this->actingAs($this->safety)->get('/settings/audit-logs?action=public_page&page=2')->assertOk()->inertiaProps();
        $this->assertSame(51, $props['events']['total']); $this->assertCount(1, $props['events']['data']);
        $csv = $this->actingAs($this->safety)->get('/settings/audit-logs/export?action=public_page')->assertOk()->streamedContent();
        $this->assertSame(51, substr_count($csv, 'it.audit.public_page'));
        $this->assertStringNotContainsString('test_private', $csv);
    }
    public function test_existing_board_pack_audit_scope_keeps_its_exact_current_permissions_for_non_hs_auditors(): void
    {
        $this->seed(\Database\Seeders\GovernancePermissionsSeeder::class);
        $viewer = User::factory()->create(['approved_at' => now(), 'external_clinical_account' => false]);
        foreach (['audit.viewAny', 'governance.packs.view', 'governance.packs.manage'] as $key) {
            $this->grant($viewer, $key);
        }
        // Real retained audit identity; the pre-existing pack audit policy does not require a live destination.
        $row = $this->audit(\App\Domain\Governance\Models\BoardPack::class, null, 'boardpack.updated', [], $viewer);
        $service = app(AuditLogViewService::class);
        $read = fn () => $this->guard()->materialize($viewer, fn ($current, $context) => $service
            ->query([], $current, $context)->whereKey($row->id)->pluck('id')->all());
        $this->assertSame([$row->id], $read());
        $viewer->load('roles.permissions', 'permissionOverrides');
        $this->grant($viewer, 'governance.packs.manage', false);
        $this->assertSame([], $read());
        $this->grant($viewer, 'governance.packs.manage');
        $this->assertSame([$row->id], $read());
    }
    public function test_nullable_unrelated_subject_meta_and_identity_leaves_do_not_turn_denial_into_sql_unknown(): void
    {
        $plain = AuditLog::create(['action' => 'custom.unrelated.event', 'auditable_type' => null,
            'auditable_id' => null, 'user_id' => null, 'meta' => null]);
        $empty = $this->audit(User::class, null, 'hr.unrelated.event', ['before' => [], 'after' => ['status' => 'active']]);
        $ids = [$plain->id, $empty->id];
        $this->assertSame($ids, $this->guard()->scope(AuditLog::whereKey($ids))->orderBy('id')->pluck('id')->all());
        $this->assertSame($ids, $this->visible($this->safety, AuditLog::whereKey($ids)));
        $this->assertSame($ids, $this->visible($this->viewer, AuditLog::whereKey($ids)));
    }
    public function test_actual_shared_default_wrappers_exclude_private_rows_even_for_a_saved_dual_reader(): void
    {
        $ordinary = $this->audit(User::class, $this->viewer->id, 'it.audit.shared_default', [], $this->viewer);
        $service = app(AuditLogViewService::class);
        foreach ([null, $this->viewer] as $viewer) {
            $this->assertSame([$ordinary->id], $service->query([], $viewer)
                ->whereKey([$ordinary->id, $this->audit->id])->orderBy('id')->pluck('id')->all());
            $this->assertSame(['today' => 0, 'this_week' => 0, 'this_month' => 0],
                $service->stats(['action' => 'substanceexposurerecord'], $viewer));
            $this->assertNotContains($this->recorder->id, array_column($service->filterOptions($viewer)['users'], 'id'));
        }
        $this->assertSame($ordinary->id, $service->present($ordinary)['id']);
        try {
            $service->present($this->audit, $this->viewer);
            $this->fail('A saved actor does not make default presentation an active private-source read.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_shared_materializer_reuses_the_supplied_callback_and_reloads_presentation_before_escape(): void
    {
        $loaded = $this->audit->fresh();
        DB::table('audit_logs')->where('id', $this->audit->id)->update([
            'meta' => json_encode(['after' => ['status' => 'recorded-current']], JSON_THROW_ON_ERROR),
        ]);
        $service = app(AuditLogViewService::class);
        $escaped = null;
        $result = HealthSafetyReadContext::within($this->viewer, ChemicalExposureAuditAccessService::PERMISSIONS,
            function ($context) use ($service, $loaded, &$escaped): array {
                $level = DB::transactionLevel();
                return $service->materialize($this->viewer, function ($current, $same) use ($context, $service, $loaded, $level, &$escaped): array {
                    $this->assertSame($context, $same);
                    $this->assertSame($level, DB::transactionLevel());
                    $escaped = $service->query(['auditable_type' => SubstanceExposureRecord::class,
                        'auditable_id' => $this->source->id], $current, $same);
                    $ids = (clone $escaped)->pluck('id')->all();
                    $event = $service->present($loaded, $current, $same);
                    return ['ids' => $ids, 'event' => $event];
                }, $context);
            });
        $this->assertSame([$this->audit->id], $result['ids']);
        $this->assertSame(['status' => 'recorded-current'], $result['event']['properties']['after']);
        $this->assertArrayHasKey('symptoms', $loaded->meta['after']);
        $this->integrity(fn () => $escaped->count());
    }

    public function test_private_audit_client_relation_requires_independent_current_client_access(): void
    {
        $this->grant($this->viewer, 'incidents.viewAny');
        $this->grant($this->viewer, 'clients.viewAny');
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $foreign = Client::factory()->create(['site_id' => $this->foreignSite->id]);
        $incident = ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'reported_by' => $this->viewer->id]);
        [$source] = $this->recorded(['related_incident_id' => $incident->id, 'incident_reported' => true]);
        $row = $this->audit(SubstanceExposureRecord::class, $source->id);
        DB::table('audit_logs')->where('id', $row->id)->update(['client_id' => $client->id]);
        $this->assertSame([$row->id], $this->visible($this->viewer, AuditLog::whereKey($row->id)));

        DB::table('audit_logs')->where('id', $row->id)->update(['client_id' => $foreign->id]);
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($row->id)),
            'Exposure admission does not authorize the separately copied foreign Client relation.');
        DB::table('audit_logs')->where('id', $row->id)->update(['client_id' => $client->id]);
        $this->assertSame([$row->id], $this->visible($this->viewer, AuditLog::whereKey($row->id)));

        [$unlinked] = $this->recorded();
        $unbound = $this->audit(SubstanceExposureRecord::class, $unlinked->id);
        DB::table('audit_logs')->where('id', $unbound->id)->update(['client_id' => $client->id]);
        $this->assertSame([$unbound->id], $this->visible($this->viewer, AuditLog::whereKey($unbound->id)));
        $this->viewer->load('roles.permissions', 'permissionOverrides');
        $this->grant($this->viewer, 'clients.viewAny', false);
        $this->grant($this->viewer, 'clients.viewAssigned', false);
        $this->assertSame([], $this->visible($this->viewer, AuditLog::whereKey($unbound->id)),
            'Even an unlinked source cannot authorize a copied Client name without current Client access.');
        $this->grant($this->viewer, 'clients.viewAny');
        $this->assertSame([$unbound->id], $this->visible($this->viewer, AuditLog::whereKey($unbound->id)));
    }

    private function guard(): ChemicalExposureAuditAccessService { return app(ChemicalExposureAuditAccessService::class); }

    private function visible(User $viewer, Builder $query): array
    {
        return $this->guard()->materialize($viewer, fn ($current, $context) => $this->guard()
            ->scope(clone $query, $current, $context)->orderBy('id')->pluck('id')->all());
    }

    private function audit(?string $type, ?int $id, string $action = 'chemical.exposure.recorded', array $meta = [], ?User $actor = null): AuditLog
    {
        return AuditLog::create(['auditable_type' => $type, 'auditable_id' => $id, 'action' => $action,
            'user_id' => ($actor ?? $this->recorder)->id, 'meta' => $meta]);
    }

    private function legacy(array $facts = []): SubstanceExposureRecord
    {
        return SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::factory()->create([
            'hazardous_substance_id' => $this->substance->id, 'user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'related_incident_id' => null, 'created_by' => $this->recorder->id, 'exposed_at' => '2026-10-12 00:00:00',
            'symptoms' => 'PRIVATE-EXPOSURE-SYMPTOMS', ...$facts]));
    }

    private function event(SubstanceExposureRecord $source): HsEvent
    {
        return HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['source_type' => SubstanceExposureRecord::class,
            'source_id' => $source->id, 'site_id' => $source->site_id, 'staff_id' => $source->user_id,
            'client_id' => null, 'shift_id' => null, 'asset_id' => null, 'event_category' => HsEvent::CATEGORY_EXPOSURE]));
    }

    private function recorded(array $facts = []): array
    {
        $this->grant($this->viewer, 'hazards.create');
        $receipt = app(ChemicalExposureSourceService::class)->create($this->substance, $this->viewer, [
            'idempotency_key' => (string) Str::uuid(), 'user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'exposed_at' => '2026-10-12T12:00:00+13:00', 'exposure_type' => 'skin_contact',
            'symptoms' => 'ACTUAL-TYPED-PRIVATE-FACT', ...$facts]);
        $this->assertTrue($receipt['accepted']);
        return [SubstanceExposureRecord::findOrFail($receipt['root_id']), $receipt];
    }

    private function staff(array $permissions, array $facts = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false, ...$facts]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        foreach ($permissions as $key) { $this->grant($user, $key); }
        return $user;
    }

    private function grant(User $user, string $key, bool $allowed = true): void
    {
        $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }

    private function integrity(callable $operation): void
    {
        try { $operation(); $this->fail('Wrong viewer, missing preparation, expired callback or escaped authority must remain an error.'); }
        catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
    }

    private function queries(callable $operation): array
    {
        DB::flushQueryLog(); DB::enableQueryLog();
        try { return [$operation(), array_column(DB::getQueryLog(), 'query')]; }
        finally { DB::disableQueryLog(); DB::flushQueryLog(); }
    }

    private function noPrivateHydration(array $queries): void
    {
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^select\b.*?\bfrom\s+`(?:substance_exposure_records|chemical_exposure_(?:source_versions|command_receipts|reviews|followups|evidence))`/i', $sql);
            if (str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'from `audit_logs`')) {
                $this->assertStringNotContainsString('audit_logs.*', strtolower($sql));
                $this->assertStringNotContainsString(' as `identity_', strtolower($sql));
            }
        }
    }
}
