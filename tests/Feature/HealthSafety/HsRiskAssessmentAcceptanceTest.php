<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\HsRiskAssessmentAcceptance;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\ChemicalExposureAuditAccessService;
use App\Services\HealthSafety\ChemicalExposureHsEventDeliveryService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsRiskAssessmentService;
use App\Services\HealthSafety\RiskAssessmentAcceptanceService;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\NotInThisBuild;

/** Prepared complete native suite. Private positive cases require the reviewed recognition patch, never a mock readiness flag. */
class HsRiskAssessmentAcceptanceTest extends ChemicalExposureCommandTestCase
{
    private function acceptance(): RiskAssessmentAcceptanceService { return app(RiskAssessmentAcceptanceService::class); }

    private function inside(callable $consumer, ?User $viewer = null, array $extra = []): mixed
    {
        return HealthSafetyReadContext::within($viewer ?? $this->manager,
            [...RiskAssessmentAcceptanceService::PERMISSIONS, ...$extra], $consumer);
    }

    private function ordinary(): HsRiskAssessment
    {
        return app(HsRiskAssessmentService::class)->create(['title' => 'Actual site risk', 'risk_description' => 'Recorded business fact',
            'assessable_type' => Site::class, 'assessable_id' => $this->site->id, 'likelihood' => 2, 'consequence' => 2], $this->manager);
    }

    private function linked(array $facts = []): HsRiskAssessment
    {
        $root = $this->exposure($facts);
        $delivery = ChemicalExposureSourceDelivery::where('exposure_id', $root->id)->where('kind', 'health_safety')->sole();
        app(ChemicalExposureHsEventDeliveryService::class)->deliver($this->manager, $delivery->id);
        $event = HsEvent::where('source_type', $root->getMorphClass())->where('source_id', $root->id)
            ->where('event_category', HsEvent::CATEGORY_EXPOSURE)->sole();
        // Integration fixture only: the existing writer is deliberately not mounted to this foundation.
        // Actual source acceptance/version/HSE delivery above are genuine commands, not forged ledger rows.
        return HsRiskAssessment::factory()->create(['hs_event_id' => $event->id, 'assessable_type' => Site::class,
            'assessable_id' => $this->site->id, 'title' => 'Retained exposure risk', 'assessed_by_user_id' => $this->manager->id,
            'created_by' => $this->manager->id]);
    }

    private function tuple(HsRiskAssessment $risk): array
    {
        return ['hs_event_id' => $risk->hs_event_id === null ? null : (int) $risk->hs_event_id,
            'assessable_type' => $risk->assessable_type, 'assessable_id' => $risk->assessable_id === null ? null : (int) $risk->assessable_id];
    }

    private function accept(HsRiskAssessment $risk, string $action = 'risk_assessment.created', ?array $before = null): array
    {
        // #13: the risk writer now seals its own changes, so an assessment created by a person already has its sealed
        // creation; the suite's "accept the creation" reads that marker instead of recording a second one.
        if ($action === 'risk_assessment.created'
            && ($sealed = HsRiskAssessmentAcceptance::where('assessment_id', $risk->id)->where('action', $action)->first()) !== null) {
            return ['id' => (int) $sealed->id, 'assessment_id' => (int) $risk->id, 'audit_log_id' => (int) $sealed->audit_log_id,
                'sequence' => (int) $sealed->sequence, 'attribution_state' => 'recorded_business'];
        }
        return $this->record($risk, $action, $before);
    }

    private function record(HsRiskAssessment $risk, string $action = 'risk_assessment.created', ?array $before = null): array
    {
        return DB::transaction(fn () => $this->inside(fn ($context) => $this->acceptance()->record($risk->id, $this->manager,
            $context, $action, $before, 'Retained milestone')));
    }

    private function refusedWith409(callable $operation): void
    {
        try { $operation(); $this->fail('The sealed save must be refused.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $refused) { $this->assertSame(409, $refused->getStatusCode()); }
    }

    private function history(HsRiskAssessment $risk, ?User $viewer = null): array
    {
        $viewer ??= $this->manager;
        return $this->inside(fn ($context) => $this->acceptance()->history($risk->id, $viewer, $context), $viewer);
    }

    public function test_actual_ordinary_writer_and_acceptance_preserve_business_history_without_audit_permission(): void
    {
        $this->allow($this->manager, 'audit.viewAny', false);
        $risk = $this->ordinary(); $accepted = $this->accept($risk);
        $history = $this->history($risk);
        $this->assertSame(1, $accepted['sequence']);
        $this->assertSame('recorded_business', $history['items'][0]['attribution_state']);
        $this->assertSame('Actual site risk', $history['items'][0]['snapshot']['title']);
        $this->assertSame($this->manager->id, $history['items'][0]['actor']['id']);
        // The original writer audit is honestly withheld; prospective acceptance does not certify it.
        $this->assertSame('unconfirmed', $history['history_state']);
        $this->assertGreaterThanOrEqual(1, $history['history_withheld']);
    }

    public function test_two_actual_business_milestones_retain_previous_anchor_and_actor_snapshot(): void
    {
        $risk = $this->ordinary(); $first = $this->accept($risk); $before = $this->tuple($risk);
        // #13: the writer seals the activation itself, chained to the sealed creation.
        app(HsRiskAssessmentService::class)->activate($risk, ['approver_note' => 'Actual approval'], $this->manager);
        $marker = HsRiskAssessmentAcceptance::where('assessment_id', $risk->id)->orderByDesc('sequence')->firstOrFail();
        $this->assertSame($first['id'], $marker->prior_id);
        $originalName = $this->manager->name;
        $this->manager->forceFill(['name' => 'Later display name'])->saveQuietly();
        $history = $this->history($risk);
        $this->assertCount(2, $history['items']);
        $this->assertSame('draft', $history['items'][0]['snapshot']['status']);
        $this->assertSame('active', $history['items'][1]['snapshot']['status']);
        $this->assertSame($originalName, $history['items'][0]['actor']['name']);
    }

    public function test_legacy_history_without_marker_is_unconfirmed_and_never_body_hydrated(): void
    {
        // #13: a person's create is sealed now; an assessment written without a person stays unsealed (legacy).
        $risk = HsRiskAssessment::factory()->create(['assessable_type' => Site::class, 'assessable_id' => $this->site->id,
            'title' => 'Older site risk', 'assessed_by_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
        app(HsRiskAssessmentService::class)->audit('risk_assessment.created', $risk, null);
        [$result, $queries] = $this->capturedQueries(fn () => $this->history($risk));
        $this->assertSame([], $result['items']); $this->assertSame('unconfirmed', $result['history_state']);
        foreach ($queries as $query) {
            if (str_contains(strtolower($query['query']), 'audit_logs')) { $this->assertStringContainsString('count(', strtolower($query['query'])); }
        }
    }

    public function test_audit_save_veto_rolls_back_actual_business_change_and_marker(): void
    {
        $risk = $this->ordinary(); $auditCount = AuditLog::count(); $markerCount = HsRiskAssessmentAcceptance::count();
        $dispatcher = AuditLog::getEventDispatcher(); AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::saving(fn (AuditLog $row) => isset($row->meta['risk_acceptance_token']) ? false : null);
        try {
            // #13: the writer seals in the same save; a refused seal refuses the whole save with a plain 409.
            $this->refusedWith409(fn () => app(HsRiskAssessmentService::class)->activate($risk, [], $this->manager));
        } finally { AuditLog::setEventDispatcher($dispatcher); }
        $this->assertSame('draft', $risk->fresh()->status); $this->assertSame($auditCount, AuditLog::count());
        $this->assertSame($markerCount, HsRiskAssessmentAcceptance::count());
    }

    public function test_marker_save_veto_rolls_back_semantic_audit_and_business_change(): void
    {
        $risk = $this->ordinary(); $auditCount = AuditLog::count(); $markerCount = HsRiskAssessmentAcceptance::count();
        $dispatcher = HsRiskAssessmentAcceptance::getEventDispatcher(); HsRiskAssessmentAcceptance::setEventDispatcher(clone $dispatcher);
        HsRiskAssessmentAcceptance::saving(fn () => false);
        try {
            // #13: the writer seals in the same save; a refused seal refuses the whole save with a plain 409.
            $this->refusedWith409(fn () => app(HsRiskAssessmentService::class)->activate($risk, [], $this->manager));
        } finally { HsRiskAssessmentAcceptance::setEventDispatcher($dispatcher); }
        $this->assertSame('draft', $risk->fresh()->status); $this->assertSame($auditCount, AuditLog::count());
        $this->assertSame($markerCount, HsRiskAssessmentAcceptance::count());
    }

    public function test_audit_payload_mutation_invalidates_retained_business_history(): void
    {
        $risk = $this->ordinary(); $r = $this->accept($risk);
        DB::table('audit_logs')->where('id', $r['audit_log_id'])->update(['meta' => json_encode(['note' => 'Rewritten'])]);
        $this->integrity(fn () => $this->history($risk));
    }

    public function test_marker_payload_mutation_invalidates_history(): void
    {
        $risk = $this->ordinary(); $r = $this->accept($risk);
        DB::table('hs_risk_assessment_acceptances')->where('id', $r['id'])->update(['business' => json_encode(['note' => 'Rewritten'])]);
        $this->integrity(fn () => $this->history($risk));
    }

    public function test_marker_update_and_delete_are_refused(): void
    {
        $risk = $this->ordinary(); $r = $this->accept($risk); $marker = HsRiskAssessmentAcceptance::findOrFail($r['id']);
        $this->integrity(fn () => $marker->forceFill(['action' => 'Rewritten'])->save());
        $this->integrity(fn () => $marker->delete());
        $this->assertDatabaseHas('hs_risk_assessment_acceptances', ['id' => $r['id']]);
    }

    public function test_destructive_migration_rollback_refuses_retained_acceptance(): void
    {
        $risk = $this->ordinary(); $this->accept($risk);
        $migration = require database_path('migrations/2026_10_12_200400_create_hs_risk_assessment_acceptances.php');
        try { $migration->down(); $this->fail('Retained marker must prevent rollback.'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('Retained risk acceptance', $error->getMessage()); }
        $this->assertSame(1, HsRiskAssessmentAcceptance::count());
    }

    public function test_fresh_risk_grant_revocation_and_restoration_override_warmed_actor(): void
    {
        $risk = $this->ordinary(); $this->accept($risk); $this->manager->load(['roles.permissions', 'permissionOverrides']);
        $this->allow($this->manager, 'hazards.view', false); $this->denial(fn () => $this->history($risk));
        $this->allow($this->manager, 'hazards.view'); $this->assertCount(1, $this->history($risk)['items']);
    }

    public function test_original_site_withdrawal_is_concealed_and_restoration_recovers_history(): void
    {
        $risk = $this->ordinary(); $this->accept($risk);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->denial(fn () => $this->history($risk), 404);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertCount(1, $this->history($risk)['items']);
    }

    public function test_incomplete_preparation_wrong_actor_and_expired_context_fail_visibly(): void
    {
        $risk = $this->ordinary(); $other = $this->staff(['hazards.view']); $escaped = null;
        $this->integrity(fn () => HealthSafetyReadContext::within($this->manager, ['hazards.view'],
            fn ($context) => $this->acceptance()->history($risk->id, $this->manager, $context)));
        $this->integrity(fn () => $this->inside(fn ($context) => $this->acceptance()->history($risk->id, $other, $context)));
        $this->inside(function ($context) use (&$escaped): array { $escaped = $context; return []; });
        $this->integrity(fn () => $this->acceptance()->history($risk->id, $this->manager, $escaped));
    }

    public function test_changed_primary_tuple_between_identity_and_body_is_rejected_before_business_hydration(): void
    {
        // #13: an unsealed assessment, so the creation is genuinely recorded here (a person's create is sealed already).
        $risk = HsRiskAssessment::factory()->create(['assessable_type' => Site::class, 'assessable_id' => $this->site->id,
            'title' => 'Unsealed site risk', 'assessed_by_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
        $changed = false; $dispatcher = HsRiskAssessment::getEventDispatcher();
        HsRiskAssessment::setEventDispatcher(clone $dispatcher);
        HsRiskAssessment::retrieved(function (HsRiskAssessment $row) use ($risk, &$changed): void {
            if (! $changed && $row->id === $risk->id && ! array_key_exists('title', $row->getAttributes())) {
                $changed = true; DB::table('hs_risk_assessments')->where('id', $risk->id)->update(['assessable_id' => $this->foreignSite->id]);
            }
        });
        try { $this->denial(fn () => $this->record($risk), 404); }
        finally { HsRiskAssessment::setEventDispatcher($dispatcher); }
        $this->assertTrue($changed); $this->assertSame(0, HsRiskAssessmentAcceptance::count());
    }

    public function test_creation_cannot_replace_existing_chain_and_missing_before_attachment_is_not_guessed(): void
    {
        $risk = $this->ordinary(); $this->accept($risk);
        $this->integrity(fn () => $this->record($risk));
        $this->integrity(fn () => $this->record($risk, 'risk_assessment.draft_updated'));
        $this->assertSame(1, HsRiskAssessmentAcceptance::count());
    }

    public function test_actual_private_source_delivery_risk_and_acceptance_chain_retains_original_version(): void
    {
        $risk = $this->linked(); $r = $this->accept($risk);
        $marker = HsRiskAssessmentAcceptance::findOrFail($r['id']); $ref = $marker->after_binding['events'][0]['exposure'];
        $this->assertSame('recorded', $ref['attribution_state']); $this->assertGreaterThan(0, $ref['source_version_id']);
        $this->assertGreaterThan(0, $ref['receipt_id']);
        $this->assertSame($this->worker->id, $marker->after_binding['events'][0]['tuple']['staff_id']);
        $this->assertCount(1, $this->history($risk)['items']);
    }

    /**
     * Re-pointed (#13): Codex refused a private acceptance until a per-viewer general-audit recognition was mounted.
     * Stephan (2 Nov, "Same rule as job 4") chose instead that the audit page shows a person-linked assessment as who
     * changed what and when only, values hidden for everyone; so the acceptance is sealed and its audit row hides values.
     */
    public function test_private_acceptance_is_sealed_and_its_audit_row_shows_only_who_changed_what_and_when(): void
    {
        $risk = $this->linked(); $r = $this->accept($risk);
        $this->assertSame(1, HsRiskAssessmentAcceptance::count());
        $row = app(\App\Services\Audit\AuditLogViewService::class)->present(AuditLog::findOrFail($r['audit_log_id']));
        $this->assertSame('linked_person', $row['values_hidden']);
        $this->assertSame([], $row['properties']['before']); $this->assertSame([], $row['properties']['after']);
        $this->assertNull($row['client']);
    }

    public function test_private_grant_withdrawal_precedes_risk_business_and_private_version_queries(): void
    {
        $risk = $this->linked(); $this->accept($risk); $this->manager->load(['roles.permissions','permissionOverrides']);
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        [, $queries] = $this->capturedQueries(function () use ($risk): array { $this->denial(fn () => $this->history($risk)); return []; });
        foreach ($queries as $q) {
            $sql = strtolower($q['query']);
            $this->assertStringNotContainsString('chemical_exposure_source_versions', $sql);
            $this->assertFalse(str_contains($sql, 'hs_risk_assessments') && str_contains($sql, 'select *'));
        }
        $this->allow($this->manager, 'hr.wellbeing.view'); $this->assertCount(1, $this->history($risk)['items']);
    }

    public function test_private_current_staff_withdrawal_refuses_history_and_restores(): void
    {
        $risk = $this->linked(); $this->accept($risk);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]);
        // Concealed (404) rather than refused (403): no approved Site is left to prove the source at.
        $this->denial(fn () => $this->history($risk), 404);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => true]);
        $this->assertCount(1, $this->history($risk)['items']);
    }

    public function test_private_detach_and_changed_parent_cannot_replace_original_binding(): void
    {
        $risk = $this->linked(); $this->accept($risk); $before = $this->tuple($risk);
        DB::transaction(function () use ($risk, $before): void {
            DB::table('hs_risk_assessments')->where('id', $risk->id)->update(['hs_event_id' => null]);
            $this->integrity(fn () => $this->accept($risk, 'risk_assessment.draft_updated', $before));
        });
        $this->assertSame(1, HsRiskAssessmentAcceptance::count());
    }

    public function test_legacy_hse_identity_and_dual_same_parent_are_retained_without_normalizing_storage(): void
    {
        $risk = $this->linked();
        $risk->forceFill(['assessable_type' => HsEvent::class, 'assessable_id' => $risk->hs_event_id])->saveQuietly();
        $r = $this->accept($risk); $binding = HsRiskAssessmentAcceptance::findOrFail($r['id'])->after_binding;
        $this->assertSame(HsEvent::class, $binding['attachment']['assessable_type']);
        $this->assertSame(['modern','legacy'], array_column($binding['events'], 'role'));
        $this->assertCount(1, $this->history($risk)['items']);
    }

    public function test_conflicting_dual_parent_site_is_concealed_before_private_risk_facts(): void
    {
        $risk = $this->linked();
        $foreign = HsEvent::factory()->create(['site_id' => $this->foreignSite->id]);
        $risk->forceFill(['assessable_type' => HsEvent::class, 'assessable_id' => $foreign->id])->saveQuietly();
        $this->denial(fn () => $this->accept($risk), 404); $this->assertSame(0, HsRiskAssessmentAcceptance::count());
    }

    public function test_generic_auditor_without_safety_staff_can_read_ordinary_verified_anchor_only(): void
    {
        $risk = $this->ordinary(); $r = $this->accept($risk);
        $auditor = User::factory()->create(['approved_at' => now()]); $this->allow($auditor, 'audit.viewAny');
        foreach (['hazards.view','hr.wellbeing.view'] as $key) { $this->allow($auditor, $key, false); }
        $valid = $this->inside(fn ($context) => $this->acceptance()->auditProofInContext($r['audit_log_id'], $auditor, $context), $auditor, ['audit.viewAny']);
        $this->assertTrue($valid);
    }

    public function test_corrupted_private_flag_cannot_let_generic_auditor_read_private_marker_payload(): void
    {
        $risk = $this->linked(); $r = $this->accept($risk);
        DB::table('hs_risk_assessment_acceptances')->where('id', $r['id'])->update(['has_exposure' => false]);
        $auditor = $this->staff(['audit.viewAny']); $this->allow($auditor, 'hr.wellbeing.view', false);
        $this->denial(fn () => $this->inside(fn ($context) => $this->acceptance()->auditProofInContext($r['audit_log_id'], $auditor, $context), $auditor, ['audit.viewAny']));
    }
    public function test_original_incident_client_withdrawal_and_restore_gate_private_history(): void
    {
        foreach (['incidents.viewAny','clients.viewAny'] as $key) { $this->allow($this->manager, $key); }
        $client = \App\Models\Client::factory()->create(['site_id' => $this->site->id]);
        $incident = \App\Models\ClientIncident::factory()->create(['site_id' => $this->site->id, 'client_id' => $client->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false]);
        $risk = $this->linked(['related_incident_id' => $incident->id]); $this->accept($risk);
        $this->manager->load(['roles.permissions','permissionOverrides']);
        $this->allow($this->manager, 'clients.viewAny', false); $this->denial(fn () => $this->history($risk), 404);
        $this->allow($this->manager, 'clients.viewAny'); $this->assertCount(1, $this->history($risk)['items']);
    }

    public function test_current_incident_context_move_is_not_inferred_as_original_accepted_context(): void
    {
        foreach (['incidents.viewAny','clients.viewAny'] as $key) { $this->allow($this->manager, $key); }
        $client = \App\Models\Client::factory()->create(['site_id' => $this->site->id]);
        $incident = \App\Models\ClientIncident::factory()->create(['site_id' => $this->site->id, 'client_id' => $client->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false]);
        $risk = $this->linked(['related_incident_id' => $incident->id]); $this->accept($risk);
        $other = \App\Models\Client::factory()->create(['site_id' => $this->site->id]);
        DB::table('client_incidents')->where('id', $incident->id)->update(['client_id' => $other->id]);
        $this->denial(fn () => $this->history($risk), 404);
        DB::table('client_incidents')->where('id', $incident->id)->update(['client_id' => $client->id]);
        $this->assertCount(1, $this->history($risk)['items']);
    }

    public function test_general_audit_recognition_count_search_and_page_exclude_corrupt_marker_under_current_callback(): void
    {
        $this->allow($this->manager, 'audit.viewAny'); $risk = $this->ordinary(); $r = $this->accept($risk);
        $audit = app(ChemicalExposureAuditAccessService::class);
        $visible = $audit->materialize($this->manager, fn ($actor, $context) => $audit->scope(AuditLog::whereKey($r['audit_log_id']), $actor, $context)->count());
        $this->assertSame(1, $visible);
        DB::table('hs_risk_assessment_acceptances')->where('id', $r['id'])->update(['acceptance_digest' => str_repeat('0', 64)]);
        $result = $audit->materialize($this->manager, function ($actor, $context) use ($audit, $r): array {
            $query = $audit->scope(AuditLog::whereKey($r['audit_log_id']), $actor, $context)->where('meta->note', 'like', '%Retained%');
            return ['count' => (clone $query)->count(), 'ids' => $query->paginate(10)->pluck('id')->all()];
        });
        $this->assertSame(['count' => 0, 'ids' => []], $result);
    }

    public function test_unknown_binding_metadata_and_no_context_audit_queries_never_certify_a_private_flag(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::DECIDED, 'Stephan (2 Nov, "Same rule as job 4"): no per-viewer audit guard is built; the audit page shows every risk assessment row by reference and changed field names, with values hidden for person-linked ones (AuditLogViewService, RiskAssessmentSealedHistoryTest). This case tests Codex\'s unmounted ChemicalExposureAuditAccessService guard on unknown binding metadata.', 'none: replaced by the job 4 rule.'));
        $risk = $this->ordinary(); $r = $this->accept($risk);
        DB::table('hs_risk_assessment_acceptances')->where('id', $r['id'])->update(['after_binding' => json_encode(['schema_version' => 99]), 'has_exposure' => false]);
        $this->assertFalse(app(ChemicalExposureAuditAccessService::class)->scope(AuditLog::whereKey($r['audit_log_id']))->exists());
        $this->allow($this->manager, 'audit.viewAny');
        $visible = app(ChemicalExposureAuditAccessService::class)->materialize($this->manager,
            fn ($actor, $context) => app(ChemicalExposureAuditAccessService::class)->scope(AuditLog::whereKey($r['audit_log_id']), $actor, $context)->exists());
        $this->assertFalse($visible);
    }
    public function test_ordinary_approved_writer_retains_existing_staff_rule_without_private_medical_gate(): void
    {
        $risk = $this->ordinary(); $this->allow($this->manager, 'hr.wellbeing.view', false);
        // The old risk writer's actual approved User::staff boundary remains decisive for an ordinary Site risk.
        $r = $this->accept($risk);
        $this->assertSame('recorded_business', $r['attribution_state']);
        $this->assertCount(1, $this->history($risk)['items']);
    }
    public function test_missing_marker_partial_null_metadata_is_concealed_while_ordinary_legacy_audit_stays_visible(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::DECIDED, 'Stephan (2 Nov, "Same rule as job 4"): no per-viewer audit guard is built; the audit page shows every risk assessment row by reference and changed field names, with values hidden for person-linked ones (AuditLogViewService, RiskAssessmentSealedHistoryTest). This case tests Codex\'s unmounted ChemicalExposureAuditAccessService guard on malformed rows.', 'none: replaced by the job 4 rule.'));
        $risk = $this->ordinary(); $this->allow($this->manager, 'audit.viewAny');
        $ordinary = AuditLog::where('auditable_type', $risk->getMorphClass())->where('auditable_id', $risk->id)->where('action', 'risk_assessment.created')->sole();
        $audit = app(ChemicalExposureAuditAccessService::class);
        $this->assertTrue($audit->scope(AuditLog::whereKey($ordinary->id))->exists());
        foreach (['risk_acceptance_token' => null, 'risk_source_binding_before' => [], 'risk_source_binding' => 'malformed'] as $key => $value) {
            $unknown = AuditLog::create(['auditable_type' => $risk->getMorphClass(), 'auditable_id' => $risk->id,
                'user_id' => $this->manager->id, 'action' => 'risk_assessment.draft_updated', 'meta' => [$key => $value, 'note' => 'Never a certified ordinary fallback']]);
            $this->assertFalse($audit->scope(AuditLog::whereKey($unknown->id))->exists());
            $this->assertFalse($audit->materialize($this->manager, fn ($actor, $context) => $audit->scope(AuditLog::whereKey($unknown->id), $actor, $context)->exists()));
        }
    }

    public function test_general_audit_allowed_builder_cannot_execute_after_its_callback(): void
    {
        $risk = $this->ordinary(); $r = $this->accept($risk); $this->allow($this->manager, 'audit.viewAny'); $escaped = null;
        $audit = app(ChemicalExposureAuditAccessService::class);
        $audit->materialize($this->manager, function ($actor, $context) use ($audit, $r, &$escaped): array {
            $escaped = $audit->scope(AuditLog::whereKey($r['audit_log_id']), $actor, $context);
            return ['count' => (clone $escaped)->count()];
        });
        $this->integrity(fn () => $escaped->count());
    }

    public function test_retained_original_site_is_required_after_legitimate_ordinary_primary_site_correction(): void
    {
        $risk = $this->ordinary(); $this->accept($risk); $before = $this->tuple($risk);
        HrEmployeeProfile::where('user_id', $this->manager->id)->firstOrFail()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        // #13: the writer seals the corrected draft itself, with the attachment it had before the change.
        app(HsRiskAssessmentService::class)->updateDraft($risk, ['title' => 'Actual corrected Site risk', 'likelihood' => 2,
            'consequence' => 2, 'assessable_type' => Site::class, 'assessable_id' => $this->foreignSite->id], $this->manager);
        HrEmployeeProfile::where('user_id', $this->manager->id)->firstOrFail()->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => []]);
        $this->denial(fn () => $this->history($risk), 404);
        HrEmployeeProfile::where('user_id', $this->manager->id)->firstOrFail()->update(['secondary_site_ids' => [$this->site->id]]);
        $this->assertCount(2, $this->history($risk)['items']);
    }
}
