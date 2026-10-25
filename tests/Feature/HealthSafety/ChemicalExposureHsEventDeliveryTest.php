<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ChemicalExposureHsProjection;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalCommandValues;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureHsEventDeliveryService;
use App\Services\HealthSafety\HsEventService;

/** Whole prospective destination suite. Actual source commands create the facts; no runtime execution is claimed. */
final class ChemicalExposureHsEventDeliveryTest extends ChemicalExposureCommandTestCase
{
    public function test_actual_accepted_source_has_separate_pending_delivery_then_one_exact_destination(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(['medical_treatment' => 'medical', 'symptoms' => 'PRIVATE-SOURCE-SYMPTOMS']);
        $attempt = $this->attempt($root);
        $this->assertSame('pending', $attempt->state);
        $this->assertSame(0, HsEvent::where('source_type', SubstanceExposureRecord::class)->count());
        $result = $this->deliver($attempt);
        $event = HsEvent::findOrFail($attempt->fresh()->hs_event_id);
        $this->assertSame('delivered', $result['state']);
        $this->assertSame($this->worker->id, (int) $event->staff_id);
        $this->assertSame($this->site->id, (int) $event->site_id);
        $this->assertSame($root->id, (int) $event->source_id);
        $this->assertNull($event->client_id); $this->assertNull($event->shift_id);
        $this->assertSame('2026-10-12 00:00:00', $event->occurred_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(HsEvent::SEVERITY_HIGH, $event->severity);
        $this->assertTrue($event->investigation_required);
        $this->assertNull($event->worksafe_notifiable); $this->assertNull($event->worksafe_status);
        $marker = ChemicalExposureHsProjection::where('exposure_id', $root->id)->sole();
        $this->assertSame(1, $marker->source_revision); $this->assertSame($event->id, $marker->hs_event_id);
        $this->assertSame($attempt->source_version_id, $marker->source_version_id);
        $this->assertStringNotContainsString('PRIVATE-SOURCE-SYMPTOMS', json_encode($marker->projection, JSON_THROW_ON_ERROR));
    }

    public function test_capture_only_reporting_never_grants_saved_private_delivery_but_current_manager_can_deliver(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $receipt = $this->capture(['medical_treatment' => 'none'], $this->reporter);
        $root = SubstanceExposureRecord::findOrFail($receipt['root_id']); $attempt = $this->attempt($root);
        $this->denial(fn () => app(ChemicalExposureHsEventDeliveryService::class)->deliver($this->reporter, $attempt->id));
        $this->assertSame('pending', $attempt->fresh()->state);
        $this->assertSame('delivered', $this->deliver($attempt)['state']);
        $this->assertSame($this->reporter->id, (int) HsEvent::findOrFail($attempt->fresh()->hs_event_id)->created_by);
    }

    public function test_retry_preserves_reference_owner_stage_handover_and_actual_signed_worksafe_decision(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(['medical_treatment' => 'none']); $attempt = $this->attempt($root);
        $this->deliver($attempt); $event = HsEvent::findOrFail($attempt->fresh()->hs_event_id);
        app(HsEventService::class)->recordWorksafeDecision($event, false, 'Qualified review retained independently of chemical delivery.', $this->manager);
        $event->refresh()->updateQuietly(['owner_user_id' => $this->manager->id, 'status' => HsEvent::STATUS_MONITORING,
            'severity' => HsEvent::SEVERITY_CRITICAL, 'handover_status' => HsEvent::HANDOVER_ACCEPTED,
            'accepted_by_user_id' => $this->manager->id, 'accepted_at' => now(), 'acceptance_notes' => 'Independent acceptance']);
        $before = $event->fresh()->attributesToArray();
        $this->assertSame('delivered', $this->deliver($attempt)['state']);
        $this->assertSame($before, $event->fresh()->attributesToArray());
        $this->assertSame(1, ChemicalExposureHsProjection::where('exposure_id', $root->id)->count());
        $this->assertSame(2, $attempt->fresh()->attempts);
    }

    public function test_actual_later_treatment_escalates_same_event_without_replacing_independent_workflow(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(['medical_treatment' => 'none']); $initial = $this->attempt($root);
        $this->deliver($initial); $event = HsEvent::findOrFail($initial->fresh()->hs_event_id);
        $event->updateQuietly(['owner_user_id' => $this->manager->id, 'status' => HsEvent::STATUS_CORRECTIVE_ACTION]);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Correct the recorded treatment.', 'medical_treatment' => 'hospitalisation']));
        $next = $this->attempt($root); $this->deliver($next);
        $this->assertSame($event->id, $next->fresh()->hs_event_id);
        $event->refresh(); $this->assertSame(HsEvent::SEVERITY_HIGH, $event->severity);
        $this->assertSame(HsEvent::STATUS_CORRECTIVE_ACTION, $event->status);
        $this->assertSame($this->manager->id, (int) $event->owner_user_id);
        $this->assertTrue($event->investigation_required); $this->assertNull($event->worksafe_notifiable);
        $this->assertSame([1, 2], ChemicalExposureHsProjection::where('exposure_id', $root->id)->orderBy('source_revision')->pluck('source_revision')->all());
    }

    public function test_skipped_delivery_revisions_keep_exact_accepted_history_and_cannot_overwrite_latest(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(['medical_treatment' => 'none']); $first = $this->attempt($root);
        $this->deliver($first);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Record correction one.', 'circumstances' => 'Recorded first correction']));
        $second = $this->attempt($root);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Record correction two.', 'exposed_at' => '2026-10-11T23:00:00Z']));
        $third = $this->attempt($root); $this->deliver($third);
        [$result, $queries] = $this->capturedQueries(fn () => $this->deliver($second));
        $this->assertSame('superseded', $result['state']); $this->noDestinationSql($queries);
        $event = HsEvent::findOrFail($third->fresh()->hs_event_id);
        $this->assertSame('2026-10-11 23:00:00', $event->occurred_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame([1, 3], ChemicalExposureHsProjection::where('exposure_id', $root->id)->orderBy('source_revision')->pluck('source_revision')->all());
    }

    public function test_previously_delivered_flag_does_not_certify_a_changed_destination_tuple(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(); $attempt = $this->attempt($root); $this->deliver($attempt);
        $event = HsEvent::findOrFail($attempt->fresh()->hs_event_id);
        $event->updateQuietly(['staff_id' => $this->manager->id]);
        $this->integrity(fn () => $this->deliver($attempt));
        $this->assertSame($this->manager->id, (int) $event->fresh()->staff_id);
        $this->assertSame(1, $attempt->fresh()->attempts);
    }

    public function test_rehashed_destination_marker_cannot_replace_original_accepted_source_context(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(); $attempt = $this->attempt($root); $this->deliver($attempt);
        $marker = ChemicalExposureHsProjection::where('exposure_id', $root->id)->sole();
        $projection = [...$marker->projection, 'site_id' => $this->foreignSite->id];
        ChemicalExposureHsProjection::whereKey($marker->id)->update(['projection' => $projection, 'projection_digest' => ChemicalCommandValues::digest($projection)]);
        $this->integrity(fn () => $this->deliver($attempt));
        $this->assertSame(1, $attempt->fresh()->attempts);
    }

    public function test_marker_audit_admission_requires_every_current_destination_projection_field(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(); $attempt = $this->attempt($root); $this->deliver($attempt);
        $marker = ChemicalExposureHsProjection::where('exposure_id', $root->id)->sole();
        $event = HsEvent::findOrFail($marker->hs_event_id); $original = $event->getAttributes();
        $admit = fn () => app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager,
            fn ($current, $reads, $sites) => app(ChemicalExposureHsEventDeliveryService::class)
                ->sourceProofForAuditSubject($marker->id, $current, $reads, $sites));
        $this->assertSame('recorded', $admit()['attribution_state']);
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $shift = \App\Models\Shift::withoutEvents(fn () => \App\Models\Shift::factory()->create([
            'client_id' => $client->id, 'site_id' => $this->site->id, 'user_id' => $this->worker->id,
            'service_context_id' => null, 'created_by' => $this->manager->id]));
        $asset = \App\Models\Asset::withoutEvents(fn () => \App\Models\Asset::factory()->create([
            'site_id' => $this->site->id, 'created_by_user_id' => $this->manager->id,
            'updated_by_user_id' => $this->manager->id]));
        foreach (['staff_id' => $this->manager->id, 'site_id' => $this->foreignSite->id,
            'client_id' => $client->id, 'shift_id' => $shift->id, 'asset_id' => $asset->id,
            'occurred_at' => '2026-10-11 00:00:00', 'reported_at' => '2026-10-11 00:00:00',
            'idempotency_key' => 'changed-exposure-source-key'] as $field => $value) {
            HsEvent::whereKey($event->id)->update([$field => $value]);
            [$proof, $queries] = $this->capturedQueries($admit);
            $this->assertSame(['attribution_state' => 'repair_required', 'source_id' => $root->id], $proof, $field);
            foreach ($queries as $query) {
                if (preg_match('/\bfrom\s+[`"]?hs_events\b/i', $query['query'])) {
                    $this->assertStringNotContainsString('select *', strtolower($query['query']), $field);
                    $this->assertStringNotContainsString('acceptance_notes', strtolower($query['query']), $field);
                }
            }
            HsEvent::whereKey($event->id)->update([$field => $original[$field]]);
        }
        $this->assertSame('recorded', $admit()['attribution_state']);
    }

    public function test_marker_audit_does_not_admit_a_soft_deleted_destination(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(); $attempt = $this->attempt($root); $this->deliver($attempt);
        $marker = ChemicalExposureHsProjection::where('exposure_id', $root->id)->sole();
        HsEvent::whereKey($marker->hs_event_id)->update(['deleted_at' => now()]);
        $proof = app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager,
            fn ($current, $reads, $sites) => app(ChemicalExposureHsEventDeliveryService::class)
                ->sourceProofForAuditSubject($marker->id, $current, $reads, $sites));
        $this->assertSame(['attribution_state' => 'repair_required', 'source_id' => $root->id], $proof);
    }

    public function test_rehashed_source_version_is_refused_by_original_acceptance_not_its_local_digest(): void
    {
        $root = $this->exposure(); $attempt = $this->attempt($root);
        $version = ChemicalExposureSourceVersion::findOrFail($attempt->source_version_id);
        $content = $version->content; $content['facts'] = ['substitution' => 'PRIVATE-FORGED-HISTORY'];
        ChemicalExposureSourceVersion::whereKey($version->id)->update(['content' => $content, 'content_digest' => ChemicalCommandValues::digest($content)]);
        $this->integrity(fn () => $this->deliver($attempt));
        $this->assertSame('pending', $attempt->fresh()->state); $this->assertSame(0, ChemicalExposureHsProjection::count());
    }

    public function test_event_save_veto_keeps_accepted_source_but_rolls_back_destination_and_confirmation(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(); $attempt = $this->attempt($root);
        HsEvent::creating(fn () => false);
        try { $this->integrity(fn () => $this->deliver($attempt)); }
        finally { HsEvent::flushEventListeners(); }
        $this->assertSame(1, $root->fresh()->source_revision); $this->assertSame('pending', $attempt->fresh()->state);
        $this->assertSame(0, HsEvent::where('source_type', SubstanceExposureRecord::class)->count());
        $this->assertSame(0, ChemicalExposureHsProjection::count());
    }

    public function test_marker_save_veto_rolls_back_event_and_outbox_confirmation_together(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(); $attempt = $this->attempt($root);
        ChemicalExposureHsProjection::creating(fn () => false);
        try { $this->integrity(fn () => $this->deliver($attempt)); }
        finally { ChemicalExposureHsProjection::flushEventListeners(); }
        $this->assertSame('pending', $attempt->fresh()->state); $this->assertNull($attempt->fresh()->hs_event_id);
        $this->assertSame(0, HsEvent::where('source_type', SubstanceExposureRecord::class)->count());
        $this->assertSame(1, ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
    }

    public function test_destination_audit_veto_retains_source_and_rolls_back_event_marker_and_delivery(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(); $attempt = $this->attempt($root);
        \App\Models\AuditLog::creating(fn ($audit) => $audit->action === 'chemical.exposure.deliver_health_safety' ? false : null);
        try { $this->integrity(fn () => $this->deliver($attempt)); }
        finally { \App\Models\AuditLog::flushEventListeners(); }
        $this->assertSame('pending', $attempt->fresh()->state); $this->assertNull($attempt->fresh()->hs_event_id);
        $this->assertSame(0, ChemicalExposureHsProjection::count());
        $this->assertSame(0, HsEvent::where('source_type', SubstanceExposureRecord::class)->count());
        $this->assertSame(1, ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
    }

    public function test_destination_audit_anchor_is_private_and_required_again_on_retry(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(['symptoms' => 'PRIVATE-DESTINATION-AUDIT-NARRATIVE']);
        $attempt = $this->attempt($root); $this->deliver($attempt);
        $marker = ChemicalExposureHsProjection::where('exposure_id', $root->id)->sole();
        $audit = \App\Models\AuditLog::findOrFail($marker->audit_log_id);
        $this->assertSame('chemical.exposure.deliver_health_safety', $audit->action);
        $this->assertSame($this->manager->id, (int) $audit->user_id);
        $this->assertStringNotContainsString('PRIVATE-DESTINATION-AUDIT-NARRATIVE', json_encode($audit->attributesToArray(), JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('projection', $audit->meta);
        $this->assertNull($audit->ip_address); $this->assertNull($audit->user_agent);
        $meta = [...$audit->meta, 'source_tuple_digest' => str_repeat('a', 64)];
        \App\Models\AuditLog::whereKey($audit->id)->update(['meta' => $meta]);
        $this->integrity(fn () => $this->deliver($attempt));
        $this->assertSame(1, $attempt->fresh()->attempts);
    }

    public function test_destination_markers_refuse_model_update_and_delete(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(); $attempt = $this->attempt($root); $this->deliver($attempt);
        $marker = ChemicalExposureHsProjection::where('exposure_id', $root->id)->sole();
        $this->integrity(fn () => $marker->update(['projection_digest' => str_repeat('b', 64)]));
        $this->integrity(fn () => $marker->fresh()->delete());
        $this->assertSame(1, ChemicalExposureHsProjection::count());
    }

    public function test_direct_marker_timestamp_corruption_breaks_original_destination_acceptance(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(); $attempt = $this->attempt($root); $this->deliver($attempt);
        $marker = ChemicalExposureHsProjection::where('exposure_id', $root->id)->sole();
        ChemicalExposureHsProjection::whereKey($marker->id)->update(['recorded_at' => '2026-10-11 00:00:00.123456']);
        $this->integrity(fn () => $this->deliver($attempt));
        $this->assertSame(1, $attempt->fresh()->attempts);
    }

    public function test_warmed_wellbeing_grant_withdrawal_denies_before_private_destination_sql_then_restoration_allows(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(); $attempt = $this->attempt($root);
        $this->manager->load('roles.permissions', 'permissionOverrides'); $this->allow($this->manager, 'hr.wellbeing.view', false);
        [, $queries] = $this->capturedQueries(fn () => $this->denial(fn () => $this->deliver($attempt)));
        $this->noDestinationSql($queries);
        foreach ($queries as $query) { $this->assertStringNotContainsString('chemical_exposure_source_deliveries', strtolower($query['query'])); }
        $this->allow($this->manager, 'hr.wellbeing.view'); $this->assertSame('delivered', $this->deliver($attempt)['state']);
    }

    public function test_current_staff_and_original_site_remain_required_before_destination_sql(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(); $attempt = $this->attempt($root);
        $this->manager->hrEmployeeProfile()->update(['is_active' => false]);
        [, $queries] = $this->capturedQueries(fn () => $this->denial(fn () => $this->deliver($attempt)));
        $this->noDestinationSql($queries);
        $this->manager->hrEmployeeProfile()->update(['is_active' => true]); $this->site->update(['archived_at' => now()]);
        [, $queries] = $this->capturedQueries(fn () => $this->denial(fn () => $this->deliver($attempt), 404));
        $this->noDestinationSql($queries); $this->assertSame('pending', $attempt->fresh()->state);
    }

    public function test_explicit_worker_site_correction_uses_retained_history_and_current_access_to_both_sites(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(); $attempt = $this->attempt($root); $this->deliver($attempt);
        $otherWorker = $this->staff([], $this->foreignSite);
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Correct the occurrence worker and Site.', 'user_id' => $otherWorker->id,
                'site_id' => $this->foreignSite->id, 'related_incident_id' => null]));
        $next = $this->attempt($root); $this->deliver($next);
        $event = HsEvent::findOrFail($next->fresh()->hs_event_id);
        $this->assertSame($attempt->fresh()->hs_event_id, $event->id);
        $this->assertSame($otherWorker->id, (int) $event->staff_id); $this->assertSame($this->foreignSite->id, (int) $event->site_id);
        $markers = ChemicalExposureHsProjection::where('exposure_id', $root->id)->orderBy('source_revision')->get();
        $this->assertSame($this->site->id, $markers[0]->projection['site_id']);
        $this->assertSame($this->foreignSite->id, $markers[1]->projection['site_id']);
    }

    public function test_unproved_existing_legacy_copy_is_not_adopted_or_overwritten(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(); $attempt = $this->attempt($root);
        $copy = HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['source_type' => SubstanceExposureRecord::class,
            'source_id' => $root->id, 'event_category' => HsEvent::CATEGORY_EXPOSURE,
            'idempotency_key' => HsEvent::buildIdempotencyKey(SubstanceExposureRecord::class, $root->id, HsEvent::CATEGORY_EXPOSURE),
            'site_id' => $this->site->id, 'staff_id' => $this->worker->id, 'client_id' => null, 'shift_id' => null, 'asset_id' => null]));
        $before = $copy->attributesToArray(); $this->integrity(fn () => $this->deliver($attempt));
        $this->assertSame($before, $copy->fresh()->attributesToArray());
        $this->assertSame(0, ChemicalExposureHsProjection::count()); $this->assertSame('pending', $attempt->fresh()->state);
    }

    public function test_old_site_withdrawal_after_explicit_correction_denies_historical_body_then_restoration_allows(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(); $old = $this->attempt($root); $this->deliver($old);
        $other = $this->staff([], $this->foreignSite);
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Correct the occurrence Site and worker.', 'site_id' => $this->foreignSite->id,
                'user_id' => $other->id, 'related_incident_id' => null]));
        $next = $this->attempt($root);
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => []]);
        [, $queries] = $this->capturedQueries(fn () => $this->denial(fn () => $this->deliver($next), 404));
        $this->noDestinationSql($queries); $this->noFullHistoricalBodySql($queries);
        $this->assertSame('pending', $next->fresh()->state);
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->site->id]]);
        $this->assertSame('delivered', $this->deliver($next)['state']);
    }

    public function test_readable_latest_facts_do_not_admit_an_older_site_version_during_destination_history(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure();
        $original = $this->attempt($root); $this->deliver($original);
        $other = $this->staff([], $this->foreignSite);
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Correct the recorded worker and occurrence Site.', 'site_id' => $this->foreignSite->id,
                'user_id' => $other->id, 'related_incident_id' => null]));
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Retain additional observations at the corrected Site.', 'circumstances' => 'PRIVATE-CURRENT-SITE-FACTS']));
        $next = $this->attempt($root);
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => []]);
        // The latest before/after tuple is still independently readable; only
        // the consumer's retained older original context has lost authority.
        $this->assertSame('recorded', $this->proof($root)['attribution_state']);
        [, $queries] = $this->capturedQueries(fn () => $this->denial(fn () => $this->deliver($next), 404));
        $this->noDestinationSql($queries);
        foreach ($queries as $query) {
            if (preg_match('/select\s+\*\s+from\s+[`"]?chemical_exposure_source_versions/i', $query['query']) !== 1) { continue; }
            $matched = preg_match('/[`"]?chemical_exposure_source_versions[`"]?\.[`"]?id[`"]?\s*=\s*\?/', $query['query'], $match, PREG_OFFSET_CAPTURE);
            $this->assertSame(1, $matched, 'Private version body must bind one exact admitted identity.');
            $binding = substr_count(substr($query['query'], 0, $match[0][1]), '?');
            $this->assertNotSame((int) $original->source_version_id, (int) $query['bindings'][$binding]);
        }
        $this->assertSame('pending', $next->fresh()->state);
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->site->id]]);
        $this->assertSame('delivered', $this->deliver($next)['state']);
    }

    public function test_old_client_permission_withdrawal_after_unlink_denies_historical_body_before_destination(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $incident = $this->incident();
        $root = $this->exposure(['related_incident_id' => $incident->id]);
        $old = $this->attempt($root); $this->deliver($old);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Correct the explicit original association.', 'related_incident_id' => null]));
        $next = $this->attempt($root); $this->allow($this->manager, 'clients.viewAny', false);
        [, $queries] = $this->capturedQueries(fn () => $this->denial(fn () => $this->deliver($next)));
        $this->noDestinationSql($queries); $this->noFullHistoricalBodySql($queries);
        $this->allow($this->manager, 'clients.viewAny'); $this->assertSame('delivered', $this->deliver($next)['state']);
        $this->assertNull(HsEvent::findOrFail($next->fresh()->hs_event_id)->client_id);
    }

    public function test_old_incident_context_change_is_not_filled_from_todays_service_context_after_unlink(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $incident = $this->incident();
        $root = $this->exposure(['related_incident_id' => $incident->id]);
        $old = $this->attempt($root); $this->deliver($old);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Remove the reviewed association.', 'related_incident_id' => null]));
        $incident->updateQuietly(['service_context_id' => \App\Models\ServiceContext::factory()->create(['is_active' => true, 'site_id' => $this->site->id])->id]);
        $next = $this->attempt($root);
        [, $queries] = $this->capturedQueries(fn () => $this->integrity(fn () => $this->deliver($next)));
        $this->noDestinationSql($queries); $this->noFullHistoricalBodySql($queries);
        $this->assertSame('pending', $next->fresh()->state);
    }

    public function test_private_followup_purpose_cannot_be_marked_delivered_by_the_hs_event_consumer(): void
    {
        $root = $this->exposure();
        $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $this->manager->id, 'instruction' => 'Private review follow-up', 'due_at' => '2026-10-13T00:00:00Z']));
        $followup = ChemicalExposureSourceDelivery::where('exposure_id', $root->id)->where('kind', 'followup')->sole();
        $this->integrity(fn () => $this->deliver($followup)); $this->assertSame('pending', $followup->fresh()->state);
        $this->assertSame(0, ChemicalExposureHsProjection::count());
    }

    public function test_lowered_response_requirement_is_not_confirmed_by_a_delivered_flag(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure H&S delivery consumer has no route, job or command, and it refuses the H&S event the restored exposure observer creates on save. Exposures reach the H&S register and WorkSafe review through the observer, as on main.', 'job 3 after the merge: finish the delivery pipeline (Stephan, 25 Oct, "Restore now, finish later").'));
        $root = $this->exposure(['medical_treatment' => 'medical']); $attempt = $this->attempt($root); $this->deliver($attempt);
        $event = HsEvent::findOrFail($attempt->fresh()->hs_event_id);
        $event->updateQuietly(['severity' => HsEvent::SEVERITY_LOW, 'investigation_required' => false]);
        $this->assertSame('delivered', $this->deliver($attempt)['state']);
        $this->assertSame(HsEvent::SEVERITY_HIGH, $event->fresh()->severity);
        $this->assertTrue($event->fresh()->investigation_required);
        $this->assertSame(2, $attempt->fresh()->attempts);
        $this->assertSame(1, ChemicalExposureHsProjection::where('exposure_id', $root->id)->count());
    }

    private function attempt(SubstanceExposureRecord $root): ChemicalExposureSourceDelivery
    {
        return ChemicalExposureSourceDelivery::where('exposure_id', $root->id)->where('kind', 'health_safety')->orderByDesc('id')->firstOrFail();
    }

    private function deliver(ChemicalExposureSourceDelivery $attempt): array
    {
        return app(ChemicalExposureHsEventDeliveryService::class)->deliver($this->manager, $attempt->id);
    }

    private function noDestinationSql(array $queries): void
    {
        foreach ($queries as $query) {
            foreach (['hs_events', 'chemical_exposure_hs_projections'] as $table) {
                $this->assertStringNotContainsString($table, strtolower($query['query']));
            }
        }
    }

    private function noFullHistoricalBodySql(array $queries): void
    {
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/select\s+\*\s+from\s+[`"]?chemical_exposure_source_versions/i', $query['query']);
        }
    }

    private function incident(): ClientIncident
    {
        $this->allow($this->manager, 'incidents.viewAny'); $this->allow($this->manager, 'clients.viewAny');
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        return ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'client_id' => $client->id, 'site_id' => $this->site->id, 'reported_by' => $this->manager->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null]));
    }
}
