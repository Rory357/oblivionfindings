<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureHsProjection;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalCommandValues;
use App\Services\HealthSafety\ChemicalExposureAccessService;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureHsEventDeliveryService;
use App\Services\HealthSafety\ChemicalExposureLedger;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsEventService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Whole actual-writer/real-marker contract; depends on the UNAPPLIED HSE policy patch and unrun DDL. */
final class ChemicalExposureHsReportedFactsPolicyTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;

    public function test_all_independent_reported_attention_category_pairs_use_selected_operational_floors(): void
    {
        foreach ([true, false, null] as $attention) {
            foreach (['none', 'first_aid', 'medical', 'hospitalisation', 'death', null] as $category) {
                $root = $this->exposure(['medical_attention_sought' => $attention, 'medical_treatment' => $category]);
                $attempt = $this->attempt($root); $this->assertSame('pending', $attempt->state);
                $this->assertSame('delivered', $this->deliver($attempt)['state']); $event = $this->event($attempt);
                $floor = match ($category) { 'none' => 'low', 'first_aid' => 'medium', 'medical', 'hospitalisation' => 'high',
                    'death' => 'critical', default => $attention === true ? 'high' : 'medium' };
                $this->assertSame($floor, $event->severity);
                $this->assertSame(in_array($floor, ['high', 'critical'], true), $event->investigation_required);
                $this->assertSame($attention, $root->fresh()->medical_attention_sought);
                $this->assertSame($category, $root->fresh()->medical_treatment);
                $this->assertNull($event->worksafe_notifiable); $this->assertNull($root->fresh()->medical_outcome);
                $this->assertSame('unassessed', $this->facts($root)['response']['harm_assessment']);
            }
        }
    }

    public function test_unanswered_capture_is_medium_triage_without_reported_negative_or_assessed_harm(): void
    {
        $root = $this->exposure(); $attempt = $this->attempt($root); $this->deliver($attempt);
        $event = $this->event($attempt); $facts = $this->facts($root);
        $this->assertSame('medium', $event->severity); $this->assertFalse($event->investigation_required);
        $this->assertNull($facts['medical_attention_sought']); $this->assertNull($facts['incident_reported']);
        $this->assertSame('unanswered', $facts['fact_states']['medical_treatment']['state']);
        $this->assertSame('operational_triage', $facts['response']['basis']); $this->assertTrue($facts['response']['care_review_needed']);
        $this->assertNull($event->worksafe_notifiable); $this->assertNull($event->closed_at);
    }

    public function test_yes_with_none_or_first_aid_needs_separate_care_review_without_forcing_investigation(): void
    {
        foreach (['none' => 'low', 'first_aid' => 'medium'] as $category => $floor) {
            $root = $this->exposure(['medical_attention_sought' => true, 'medical_treatment' => $category]);
            $attempt = $this->attempt($root); $this->deliver($attempt);
            $this->assertTrue($this->facts($root)['response']['care_review_needed']);
            $this->assertSame($floor, $this->event($attempt)->severity); $this->assertFalse($this->event($attempt)->investigation_required);
        }
    }

    public function test_no_with_high_categories_is_preserved_as_discordant_reported_facts_not_rejected_or_rederived(): void
    {
        foreach (['medical' => 'high', 'hospitalisation' => 'high', 'death' => 'critical'] as $category => $floor) {
            $root = $this->exposure(['medical_attention_sought' => false, 'medical_treatment' => $category]);
            $attempt = $this->attempt($root); $this->deliver($attempt);
            $this->assertFalse($root->fresh()->medical_attention_sought); $this->assertTrue($this->facts($root)['response']['care_review_needed']);
            $this->assertSame($floor, $this->event($attempt)->severity); $this->assertTrue($this->event($attempt)->investigation_required);
        }
    }

    public function test_independent_incident_reported_answer_does_not_invent_client_link_or_response_floor(): void
    {
        foreach ([true, false, null] as $answer) {
            $root = $this->exposure(['incident_reported' => $answer]); $attempt = $this->attempt($root); $this->deliver($attempt);
            $event = $this->event($attempt); $this->assertSame('medium', $event->severity);
            $this->assertNull($event->client_id); $this->assertNull($event->shift_id); $this->assertNull($root->fresh()->related_incident_id);
            $this->assertSame($answer, $root->fresh()->incident_reported);
        }
    }

    public function test_genuine_precontract_derived_category_remains_unconfirmed_after_narrative_correction(): void
    {
        $root = $this->historicalCapture();
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Review narrative only, not historical treatment attribution', 'circumstances' => 'New actual account']));
        $attempt = $this->attempt($root); $this->deliver($attempt); $facts = $this->facts($root);
        $this->assertSame('medical', $root->fresh()->medical_treatment); $this->assertTrue($root->fresh()->medical_attention_sought);
        $this->assertSame('legacy_unconfirmed', $facts['fact_states']['medical_treatment']['state']);
        $this->assertNull($facts['medical_attention_sought']); $this->assertSame('medium', $this->event($attempt)->severity);
        $this->assertFalse($this->event($attempt)->investigation_required);
    }

    public function test_actual_worker_site_correction_does_not_apply_old_context_death_category_to_new_worker(): void
    {
        $root = $this->exposure(['medical_attention_sought' => true, 'medical_treatment' => 'death']);
        $worker = $this->staff([], $this->foreignSite);
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Correct recorded worker and original Site', 'user_id' => $worker->id, 'site_id' => $this->foreignSite->id]));
        $attempt = $this->attempt($root); $this->deliver($attempt); $facts = $this->facts($root);
        $this->assertSame('prior_context', $facts['fact_states']['medical_treatment']['state']);
        $this->assertSame('death', $facts['response']['recorded_category']); $this->assertSame('operational_triage', $facts['response']['basis']);
        $this->assertSame('medium', $this->event($attempt)->severity); $this->assertFalse($this->event($attempt)->investigation_required);
        $this->assertSame($worker->id, (int) $this->event($attempt)->staff_id);
        $this->assertSame($this->foreignSite->id, (int) $this->event($attempt)->site_id);
    }

    public function test_explicit_reaffirmation_in_corrected_context_raises_same_destination_without_claiming_harm_assessment(): void
    {
        $root = $this->exposure(['medical_treatment' => 'hospitalisation']);
        $worker = $this->staff([], $this->foreignSite);
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Correct occurrence context', 'user_id' => $worker->id, 'site_id' => $this->foreignSite->id]));
        $old = $this->attempt($root); $this->deliver($old); $id = $this->event($old)->id;
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Explicitly report category for this corrected occurrence', 'medical_treatment' => 'hospitalisation']));
        $next = $this->attempt($root); $this->deliver($next);
        $this->assertSame($id, $this->event($next)->id); $this->assertSame('high', $this->event($next)->severity);
        $this->assertSame('reported', $this->facts($root)['fact_states']['medical_treatment']['state']);
        $this->assertSame('unassessed', $this->facts($root)['response']['harm_assessment']);
    }

    public function test_retired_historical_exposed_worker_is_not_recertified_or_required_to_have_current_employment(): void
    {
        $root = $this->exposure(['medical_treatment' => 'first_aid']); $attempt = $this->attempt($root);
        $this->worker->hrEmployeeProfile()->update(['is_active' => false, 'end_date' => '2026-10-11']);
        $this->assertSame('delivered', $this->deliver($attempt)['state']); $this->assertSame($this->worker->id, (int) $this->event($attempt)->staff_id);
        $this->assertSame('medium', $this->event($attempt)->severity);
    }

    public function test_lower_reported_category_never_lowers_independent_severity_investigation_owner_or_signed_workflow(): void
    {
        $root = $this->exposure(['medical_treatment' => 'medical']); $old = $this->attempt($root); $this->deliver($old);
        $event = $this->event($old);
        app(HsEventService::class)->recordWorksafeDecision($event, false, 'Explicit independent operational review retained.', $this->manager);
        $this->closedFixture($event->refresh(), ['severity' => 'critical', 'investigation_required' => true,
            'owner_user_id' => $this->manager->id, 'status' => HsEvent::STATUS_CLOSED, 'closed_at' => now(), 'closed_by' => $this->manager->id,
            'handover_status' => HsEvent::HANDOVER_ACCEPTED, 'accepted_by_user_id' => $this->manager->id, 'accepted_at' => now()]);
        $before = $this->workflow($event->fresh());
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Explicitly correct the reported category', 'medical_treatment' => 'none']));
        $next = $this->attempt($root); $this->deliver($next);
        $this->assertSame($before, $this->workflow($this->event($next))); $this->assertSame('critical', $this->event($next)->severity);
        $this->assertTrue($this->event($next)->investigation_required); $this->assertSame($event->id, $this->event($next)->id);
    }

    public function test_reported_high_escalation_retains_exact_event_time_marker_lineage_and_independent_closed_status(): void
    {
        $root = $this->exposure(['medical_treatment' => 'none']); $old = $this->attempt($root); $this->deliver($old);
        $event = $this->event($old); $this->closedFixture($event, ['status' => HsEvent::STATUS_CLOSED, 'closed_at' => now(), 'closed_by' => $this->manager->id,
            'owner_user_id' => $this->manager->id]); $before = $this->workflow($event->fresh());
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Actual reported attention, category remains unknown', 'medical_treatment' => null, 'medical_attention_sought' => true]));
        $next = $this->attempt($root); $this->deliver($next);
        $this->assertSame($before, $this->workflow($this->event($next))); $this->assertSame('high', $this->event($next)->severity);
        $this->assertTrue($this->event($next)->investigation_required);
        $this->assertSame([1, 2], ChemicalExposureHsProjection::where('exposure_id', $root->id)->orderBy('source_revision')->pluck('source_revision')->all());
    }

    public function test_noop_source_acceptance_does_not_make_new_reporting_or_delivery_version(): void
    {
        $root = $this->exposure(['medical_attention_sought' => false]); $attempt = $this->attempt($root); $this->deliver($attempt);
        $r = $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Explicit unchanged answer', 'medical_attention_sought' => false]));
        $this->assertFalse($r['changed']); $this->assertNull($r['source_version_id']);
        $this->assertSame(1, ChemicalExposureSourceDelivery::where('exposure_id', $root->id)->count());
        $this->assertSame(1, ChemicalExposureHsProjection::where('exposure_id', $root->id)->count());
        $this->assertSame('hs_event_present', $this->confirmation($root, $attempt)['destination_confirmation']);
    }

    public function test_saved_delivered_flag_with_deficient_response_is_unconfirmed_and_retry_reconciles_without_new_marker(): void
    {
        $root = $this->exposure(); $attempt = $this->attempt($root); $this->deliver($attempt);
        $event = $this->event($attempt); $event->updateQuietly(['severity' => 'low']);
        $marker = ChemicalExposureHsProjection::where('exposure_id', $root->id)->sole(); $before = $marker->attributesToArray();
        $confirmation = $this->confirmation($root, $attempt);
        $this->assertSame('unconfirmed', $confirmation['destination_confirmation']);
        $this->assertTrue($confirmation['can_deliver']); $this->assertSame('response_floor_pending', $confirmation['blocked_reason']);
        $this->assertSame('delivered', $attempt->fresh()->state); $this->assertSame(1, $attempt->fresh()->attempts);
        $this->assertSame('delivered', $this->deliver($attempt)['state']); $this->assertSame('medium', $event->fresh()->severity);
        $this->assertFalse($event->fresh()->investigation_required); $this->assertSame($before, $marker->fresh()->attributesToArray());
        $this->assertSame(1, ChemicalExposureHsProjection::where('exposure_id', $root->id)->count());
        $this->assertSame('hs_event_present', $this->confirmation($root, $attempt)['destination_confirmation']);
    }

    public function test_reconciliation_audit_anchors_bounded_response_only_and_does_not_modify_source_receipt(): void
    {
        $root = $this->exposure(['medical_treatment' => 'medical', 'symptoms' => 'PRIVATE-RESPONSE-NARRATIVE']);
        $attempt = $this->attempt($root); $this->deliver($attempt); $before = ChemicalExposureCommandReceipt::where('exposure_id', $root->id)->sole()->attributesToArray();
        $this->event($attempt)->updateQuietly(['severity' => 'low', 'investigation_required' => false]); $this->deliver($attempt);
        $audit = AuditLog::where('action', 'chemical.exposure.reconcile_health_safety_response')->where('auditable_id', $root->id)->sole();
        $this->assertSame($this->manager->id, (int) $audit->user_id); $this->assertSame($attempt->source_version_id, $audit->meta['source_version_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $audit->meta['source_tuple_digest']);
        $this->assertArrayNotHasKey('response_floor', $audit->meta); $this->assertArrayNotHasKey('harm_assessment', $audit->meta);
        $this->assertStringNotContainsString('PRIVATE-RESPONSE-NARRATIVE', json_encode($audit->attributesToArray(), JSON_THROW_ON_ERROR));
        $this->assertNull($audit->ip_address); $this->assertNull($audit->user_agent);
        $this->assertSame($before, ChemicalExposureCommandReceipt::where('exposure_id', $root->id)->sole()->attributesToArray());
    }

    public function test_reconciliation_event_save_veto_preserves_old_attempt_and_does_not_falsely_confirm(): void
    {
        [$root, $attempt, $event] = $this->deficient(); $before = $event->getAttributes();
        HsEvent::updating(fn () => false);
        try { $this->integrity(fn () => $this->deliver($attempt)); } finally { HsEvent::flushEventListeners(); }
        $this->assertSame($before, $event->fresh()->getAttributes()); $this->assertSame(1, $attempt->fresh()->attempts);
        $this->assertSame(0, AuditLog::where('action', 'chemical.exposure.reconcile_health_safety_response')->count());
        $this->assertSame('unconfirmed', $this->confirmation($root, $attempt)['destination_confirmation']);
    }

    public function test_response_save_listener_cannot_reassign_independent_owner_during_reconciliation(): void
    {
        [$root, $attempt, $event] = $this->deficient(); $before = $event->getAttributes();
        HsEvent::updating(function ($row): void { $row->owner_user_id = $this->worker->id; });
        try { $this->integrity(fn () => $this->deliver($attempt)); } finally { HsEvent::flushEventListeners(); }
        $this->assertSame($before, $event->fresh()->getAttributes()); $this->assertSame(1, $attempt->fresh()->attempts);
        $this->assertSame(0, AuditLog::where('action', 'chemical.exposure.reconcile_health_safety_response')->count());
    }

    public function test_reconciliation_audit_veto_rolls_back_response_and_keeps_truthful_retry(): void
    {
        [$root, $attempt, $event] = $this->deficient();
        AuditLog::creating(fn ($row) => $row->action === 'chemical.exposure.reconcile_health_safety_response' ? false : null);
        try { $this->integrity(fn () => $this->deliver($attempt)); } finally { AuditLog::flushEventListeners(); }
        $this->assertSame('low', $event->fresh()->severity); $this->assertFalse($event->fresh()->investigation_required);
        $this->assertSame(1, $attempt->fresh()->attempts); $this->assertSame(1, ChemicalExposureHsProjection::where('exposure_id', $root->id)->count());
        $this->assertSame('delivered', $this->deliver($attempt)['state']); $this->assertSame('high', $event->fresh()->severity);
    }

    public function test_outbox_save_veto_rolls_back_reconciled_response_audit_and_attempt_as_one_physical_transaction(): void
    {
        [$root, $attempt, $event] = $this->deficient();
        ChemicalExposureSourceDelivery::updating(fn () => false);
        try { $this->integrity(fn () => $this->deliver($attempt)); } finally { ChemicalExposureSourceDelivery::flushEventListeners(); }
        $this->assertSame('low', $event->fresh()->severity); $this->assertSame(1, $attempt->fresh()->attempts);
        $this->assertSame(0, AuditLog::where('action', 'chemical.exposure.reconcile_health_safety_response')->count());
        $this->assertSame('unconfirmed', $this->confirmation($root, $attempt)['destination_confirmation']);
    }

    public function test_lost_actual_dispatch_response_is_safe_replay_with_one_marker_and_no_duplicate_response_audit(): void
    {
        [$root, $attempt, $event] = $this->deficient();
        $this->deliver($attempt); // Real physical acceptance retained; deliberately discard the returned transport response.
        $this->assertSame('delivered', $this->deliver($attempt)['state']); $this->assertSame(3, $attempt->fresh()->attempts);
        $this->assertSame('high', $event->fresh()->severity);
        $this->assertSame(1, AuditLog::where('action', 'chemical.exposure.reconcile_health_safety_response')->count());
        $this->assertSame(1, ChemicalExposureHsProjection::where('exposure_id', $root->id)->count());
    }

    public function test_damaged_marker_is_not_reconciled_or_rewritten_as_accepted_response(): void
    {
        [$root, $attempt, $event] = $this->deficient(); $marker = ChemicalExposureHsProjection::where('exposure_id', $root->id)->sole();
        ChemicalExposureHsProjection::whereKey($marker->id)->update(['projection_digest' => str_repeat('a', 64)]);
        $this->integrity(fn () => $this->deliver($attempt)); $this->assertSame('low', $event->fresh()->severity);
        $this->assertSame(1, $attempt->fresh()->attempts); $this->assertSame(0, AuditLog::where('action', 'chemical.exposure.reconcile_health_safety_response')->count());
    }

    public function test_current_private_or_original_site_withdrawal_precedes_destination_policy_and_restoration_allows(): void
    {
        $root = $this->exposure(); $attempt = $this->attempt($root);
        $this->manager->load('roles.permissions', 'permissionOverrides'); $this->allow($this->manager, 'hr.wellbeing.view', false);
        [, $queries] = $this->capturedQueries(fn () => $this->denial(fn () => $this->deliver($attempt))); $this->noDestinationSql($queries);
        $this->allow($this->manager, 'hr.wellbeing.view'); $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id]);
        [, $queries] = $this->capturedQueries(fn () => $this->denial(fn () => $this->deliver($attempt), 404)); $this->noDestinationSql($queries);
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]); $this->assertSame('delivered', $this->deliver($attempt)['state']);
    }

    public function test_original_client_withdrawal_after_unlink_prevents_history_and_policy_destination_body(): void
    {
        $this->allow($this->manager, 'incidents.viewAny'); $this->allow($this->manager, 'clients.viewAny');
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create(['client_id' => $client->id,
            'site_id' => $this->site->id, 'reported_by' => $this->manager->id, 'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null]));
        $root = $this->exposure(['related_incident_id' => $incident->id, 'medical_treatment' => 'medical']);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Review association separately', 'related_incident_id' => null])); $attempt = $this->attempt($root);
        $this->allow($this->manager, 'clients.viewAny', false);
        [, $queries] = $this->capturedQueries(fn () => $this->denial(fn () => $this->deliver($attempt))); $this->noDestinationSql($queries);
        foreach ($queries as $query) { $this->assertDoesNotMatchRegularExpression('/select\s+\*\s+from\s+[`"]?chemical_exposure_source_versions/i', $query['query']); }
        $this->allow($this->manager, 'clients.viewAny'); $this->assertSame('delivered', $this->deliver($attempt)['state']);
    }

    public function test_old_pending_attempt_cannot_reapply_previous_reported_category_after_real_source_advance(): void
    {
        $root = $this->exposure(['medical_treatment' => 'death']); $old = $this->attempt($root);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Actual category correction', 'medical_treatment' => 'first_aid'])); $next = $this->attempt($root);
        $this->deliver($next); $before = $this->event($next)->getAttributes();
        $this->assertSame('superseded', $this->deliver($old)['state']); $this->assertSame($before, $this->event($next)->getAttributes());
        $this->assertSame('medium', $this->event($next)->severity);
    }

    public function test_capture_only_receipt_stays_minimal_and_saved_destination_requires_independent_current_authority(): void
    {
        $r = $this->capture(['medical_attention_sought' => true, 'medical_treatment' => null, 'symptoms' => 'PRIVATE-CAPTURE-MEDICAL'], $this->reporter);
        $this->assertCount(12, $r); $this->assertStringNotContainsString('PRIVATE-CAPTURE-MEDICAL', json_encode($r, JSON_THROW_ON_ERROR));
        $root = SubstanceExposureRecord::findOrFail($r['root_id']); $attempt = $this->attempt($root);
        $this->denial(fn () => app(ChemicalExposureHsEventDeliveryService::class)->deliver($this->reporter, $attempt->id));
        $this->assertSame('pending', $attempt->fresh()->state); $this->assertNull($attempt->fresh()->hs_event_id);
        $this->assertSame('delivered', $this->deliver($attempt)['state']); $this->assertSame('high', $this->event($attempt)->severity);
        $this->assertNull($this->event($attempt)->worksafe_notifiable);
    }

    public function test_rehashed_reported_origin_cannot_bypass_original_receipt_digest_before_destination_policy(): void
    {
        $root = $this->exposure(['medical_treatment' => 'none']); $attempt = $this->attempt($root);
        $version = ChemicalExposureSourceVersion::findOrFail($attempt->source_version_id); $content = $version->content;
        $content['facts']['reported_facts']['medical_treatment']['reported_in']['actor_id'] = $this->worker->id;
        ChemicalExposureSourceVersion::whereKey($version->id)->update(['content' => $content, 'content_digest' => ChemicalCommandValues::digest($content)]);
        $this->integrity(fn () => $this->deliver($attempt)); $this->assertSame('pending', $attempt->fresh()->state);
        $this->assertSame(0, ChemicalExposureHsProjection::where('exposure_id', $root->id)->count());
    }

    private function attempt(SubstanceExposureRecord $root): ChemicalExposureSourceDelivery
    {
        return ChemicalExposureSourceDelivery::where('exposure_id', $root->id)->where('kind', 'health_safety')->orderByDesc('id')->firstOrFail();
    }
    private function deliver(ChemicalExposureSourceDelivery $attempt): array
    {
        return app(ChemicalExposureHsEventDeliveryService::class)->deliver($this->manager, $attempt->id, $attempt->exposure_id);
    }
    private function event(ChemicalExposureSourceDelivery $attempt): HsEvent { return HsEvent::findOrFail($attempt->fresh()->hs_event_id); }
    private function facts(SubstanceExposureRecord $root): array
    {
        return app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager,
            fn ($current, $reads, $sites) => $this->exposureService()->reportedFactsForSource($root, $current, $reads, $sites));
    }
    private function confirmation(SubstanceExposureRecord $root, ChemicalExposureSourceDelivery $attempt): array
    {
        return HealthSafetyReadContext::within($this->manager, ChemicalExposureCommandAccess::PERMISSIONS,
            fn ($context) => app(ChemicalExposureHsEventDeliveryService::class)->confirmationInContext($this->manager, $root->id, $attempt->id, $context));
    }
    private function deficient(): array
    {
        $root = $this->exposure(['medical_treatment' => 'medical']); $attempt = $this->attempt($root); $this->deliver($attempt);
        $event = $this->event($attempt); $event->updateQuietly(['severity' => 'low', 'investigation_required' => false]);
        return [$root, $attempt, $event->fresh()];
    }
    /** Fixture only: the closure guard trigger admits this one close, as HsEventClosureService does. */
    private function closedFixture(HsEvent $event, array $attributes): void
    {
        \Illuminate\Support\Facades\DB::statement('SET @hs_canonical_close_event_id = '.(int) $event->id);
        try { $event->forceFill($attributes)->saveQuietly(); }
        finally { \Illuminate\Support\Facades\DB::statement('SET @hs_canonical_close_event_id = NULL'); }
    }
    private function workflow(HsEvent $event): array
    {
        return array_intersect_key($event->attributesToArray(), array_flip(['reference_number', 'owner_user_id', 'status', 'closed_at', 'closed_by',
            'handover_status', 'accepted_by_user_id', 'accepted_at', 'acceptance_notes', 'worksafe_notifiable', 'worksafe_status',
            'worksafe_decided_at', 'worksafe_decided_by_user_id', 'worksafe_decision_reason', 'worksafe_decision_source',
            'worksafe_decision_tree_version', 'worksafe_source_effective_date', 'occurred_at', 'reported_at']));
    }
    private function noDestinationSql(array $queries): void
    {
        foreach ($queries as $query) {
            foreach (['hs_events', 'chemical_exposure_hs_projections'] as $table) { $this->assertStringNotContainsString($table, strtolower($query['query'])); }
        }
    }
    /** Genuine original pre-contract ledger acceptance, then a real Source correction stages its actual outbox. */
    private function historicalCapture(): SubstanceExposureRecord
    {
        $substance = $this->catalogue();
        $id = app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager, function ($current, $reads, $sites) use ($substance): int {
            $access = app(ChemicalExposureCommandAccess::class); $access->assertOperation($current, $reads, 'capture');
            $access->site($this->site->id, $reads, $sites); $access->staff($this->worker->id, $this->site->id, $reads); $access->substance($substance->id, $reads);
            $values = ['idempotency_key' => (string) Str::uuid(), 'user_id' => $this->worker->id, 'site_id' => $this->site->id,
                'exposed_at' => '2026-10-12 00:00:00.000000', 'exposure_type' => 'skin_contact', 'medical_treatment' => 'medical'];
            $ledger = app(ChemicalExposureLedger::class); $intent = $ledger->claim($current, $reads, null, $substance->id, 'create_exposure', $values, $sites);
            $root = new SubstanceExposureRecord([...array_diff_key($values, ['idempotency_key' => true]), 'hazardous_substance_id' => $substance->id,
                'medical_attention_sought' => true, 'incident_reported' => false, 'created_by' => $current->id, 'updated_by' => $current->id]);
            // This former handler supplied a known absent context, but no field-origin evidence.
            $root->forceFill(['source_schema_version' => 1, 'source_revision' => 1, 'original_context' =>
                ['schema_version' => 1, 'site_id' => $this->site->id, 'incident_id' => null, 'client_id' => null,
                    'shift_id' => null, 'service_context_id' => null, 'stay_id' => null]]);
            $this->exposureService()->save($root);
            $version = $ledger->version($intent, $root, $root, 'exposure', $current, $reads, null, null);
            $ledger->accept($intent, $root, $root, 'exposure', $version, $current, $reads, true, $sites);
            return (int) $root->id;
        });
        return SubstanceExposureRecord::findOrFail($id);
    }
}
