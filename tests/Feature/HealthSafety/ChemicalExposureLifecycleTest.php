<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureReview;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Domain\Hr\Models\HrEmployeeProfile;
use Illuminate\Support\Facades\DB;

final class ChemicalExposureLifecycleTest extends ChemicalExposureCommandTestCase
{
    private function review($root, array $facts = []): array
    {
        return $this->exposureService()->review($root, $this->manager, $this->privateCommand($root,
            ['reviewed_at' => '2026-10-12T00:30:00Z', 'outcome' => 'recorded', 'notes' => 'Actual recorded review', ...$facts]));
    }

    private function assign($root, $receiver = null, array $facts = []): array
    {
        return $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => ($receiver ?? $this->manager)->id, 'instruction' => 'Contact recorded worker',
                'due_at' => '2026-10-13T01:00:00Z', ...$facts]));
    }

    public function test_review_records_actual_decision_date_without_lifecycle_fitness_or_automatic_followup(): void
    {
        $root = $this->exposure(['medical_treatment' => 'hospitalisation']); $r = $this->review($root, ['outcome' => 'followup_required']);
        $row = ChemicalExposureReview::findOrFail($r['source_id']);
        $this->assertSame(2, $r['version']); $this->assertSame('review', $r['source_type']);
        $this->assertSame('followup_required', $row->outcome); $this->assertSame($this->manager->id, $row->recorded_by);
        $this->assertSame('hospitalisation', $root->fresh()->medical_treatment); $this->assertSame(0, ChemicalExposureFollowup::count());
        foreach (['fitness', 'clearance', 'closed', 'approval'] as $key) { $this->assertArrayNotHasKey($key, $r); }
        $version = ChemicalExposureSourceVersion::findOrFail($r['source_version_id']);
        $this->assertSame($root->id, $version->content['source_after']['exposure_id']);
        $this->assertSame($row->recorder_snapshot['id'], $version->content['source_after']['recorder_snapshot']['id']);
    }

    public function test_review_refuses_blank_unknown_future_or_preoccurrence_facts_and_preserves_version(): void
    {
        $root = $this->exposure();
        foreach ([['notes' => ' ', 'field' => 'notes'], ['outcome' => 'fit_for_work', 'field' => 'outcome'],
            ['reviewed_at' => '2026-10-11T23:59:59Z', 'field' => 'reviewed_at'],
            ['reviewed_at' => '2026-10-12T02:00:00Z', 'field' => 'reviewed_at']] as $facts) {
            $field = $facts['field']; unset($facts['field']); $this->validation(fn () => $this->review($root, $facts), $field);
        }
        $this->assertSame(1, $root->fresh()->source_revision); $this->assertSame(0, ChemicalExposureReview::count());
    }

    public function test_named_followup_retains_receiver_and_planned_date_with_separate_durable_delivery(): void
    {
        $root = $this->exposure(); $receiver = $this->staff(['hazards.view', 'hr.wellbeing.view', 'hazards.manage']);
        $r = $this->assign($root, $receiver); $child = ChemicalExposureFollowup::findOrFail($r['source_id']);
        $this->assertSame($receiver->id, $child->assigned_to); $this->assertSame($receiver->name, $child->assignee_snapshot['name']);
        $this->assertSame('2026-10-13T01:00:00+00:00', $child->due_at->copy()->utc()->toIso8601String());
        $delivery = ChemicalExposureSourceDelivery::where('source_version_id', $r['source_version_id'])->where('kind', 'followup')->sole();
        $this->assertSame('pending', $delivery->state); $this->assertSame($receiver->id, $delivery->recipient_id);
        $this->assertNull($delivery->hs_event_id); $this->assertSame(2, $root->fresh()->source_revision);
    }

    public function test_named_receiver_requires_independent_current_private_read_and_original_site_staff(): void
    {
        $root = $this->exposure();
        $generic = $this->staff(['hazards.manage', 'hazards.view']); $this->allow($generic, 'hr.wellbeing.view', false);
        $this->denial(fn () => $this->assign($root, $generic));
        $foreign = $this->staff(['hazards.manage', 'hazards.view', 'hr.wellbeing.view'], $this->foreignSite);
        $this->denial(fn () => $this->assign($root, $foreign), 404);
        $this->assertSame(0, ChemicalExposureFollowup::count()); $this->assertSame(1, $root->fresh()->source_revision);
    }

    public function test_warmed_named_receiver_permission_or_staff_withdrawal_refuses_assignment_and_retry(): void
    {
        $root = $this->exposure(); $receiver = $this->staff(['hazards.manage', 'hazards.view', 'hr.wellbeing.view']);
        $r = $this->assign($root, $receiver); $receiver->load('permissionOverrides'); $this->allow($receiver, 'hr.wellbeing.view', false);
        $this->denial(fn () => $this->exposureService()->confirmationForKey($this->manager, $r['idempotency_key'], $root->id));
        $this->allow($receiver, 'hr.wellbeing.view'); $this->assertSame($r,
            $this->exposureService()->confirmationForKey($this->manager, $r['idempotency_key'], $root->id));
        HrEmployeeProfile::where('user_id', $receiver->id)->update(['is_active' => false]);
        $this->denial(fn () => $this->assign($root, $receiver), 404); $this->assertSame(1, ChemicalExposureFollowup::count());
    }

    public function test_completion_is_exact_named_executor_actual_fact_and_repeat_uuid_keeps_one_revision(): void
    {
        $root = $this->exposure(); $r = $this->assign($root); $child = ChemicalExposureFollowup::findOrFail($r['source_id']);
        $input = $this->privateCommand($root, ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Actual contact completed']);
        $done = $this->exposureService()->completeFollowup($root, $child->id, $this->manager, $input);
        $this->assertSame(3, $done['version']); $this->assertSame($this->manager->id, $child->fresh()->completed_by);
        $this->assertSame($done, $this->exposureService()->completeFollowup($root, $child->id, $this->manager, $input));
        $this->assertSame(3, $root->fresh()->source_revision); $this->assertSame(1, AuditLog::where('meta->idempotency_key', $input['idempotency_key'])->count());
        $this->validation(fn () => $this->exposureService()->completeFollowup($root, $child->id, $this->manager,
            $this->privateCommand($root, ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Overwritten'])), 'completed_at');
    }

    public function test_other_manager_cannot_complete_or_replay_a_named_followup_and_foreign_child_is_concealed(): void
    {
        $root = $this->exposure(); $r = $this->assign($root); $other = $this->staff(['hazards.manage', 'hazards.view', 'hr.wellbeing.view']);
        $input = $this->privateCommand($root, ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Attempt']);
        $this->denial(fn () => $this->exposureService()->completeFollowup($root, $r['source_id'], $other, $input));
        $foreign = $this->exposure();
        $this->denial(fn () => $this->exposureService()->completeFollowup($foreign, $r['source_id'], $this->manager,
            $this->privateCommand($foreign, ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Attempt'])), 404);
        $this->assertNull(ChemicalExposureFollowup::findOrFail($r['source_id'])->completed_at);
    }

    public function test_completion_cannot_precede_assignment_or_invent_future_date(): void
    {
        $root = $this->exposure(); $r = $this->assign($root);
        foreach (['2026-10-12T00:59:59Z', '2026-10-12T02:00:00Z'] as $date) {
            $this->validation(fn () => $this->exposureService()->completeFollowup($root, $r['source_id'], $this->manager,
                $this->privateCommand($root, ['completed_at' => $date, 'completion_notes' => 'Actual facts only'])), 'completed_at');
        }
        $this->assertSame(2, $root->fresh()->source_revision);
    }

    public function test_current_child_proof_remains_valid_after_unrelated_aggregate_narrative_advance(): void
    {
        $root = $this->exposure(); $r = $this->review($root);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Additional occurrence fact', 'circumstances' => 'Recorded separately']));
        $child = ChemicalExposureReview::findOrFail($r['source_id']);
        $revision = app(\App\Services\HealthSafety\ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager,
            fn ($current, $reads, $sites) => $this->exposureService()->currentVersion($root->fresh(), $child, 'review', $reads, $current, $sites)?->aggregate_revision);
        $this->assertSame(2, $revision); $this->assertSame(3, $root->fresh()->source_revision);
    }

    public function test_retained_review_or_followup_blocks_worker_site_rebinding_without_deleting_history(): void
    {
        foreach (['review', 'followup'] as $kind) {
            $root = $this->exposure(); $kind === 'review' ? $this->review($root) : $this->assign($root);
            $newWorker = $this->staff([]);
            $this->validation(fn () => $this->exposureService()->correct($root, $this->manager,
                $this->privateCommand($root, ['reason' => 'Attempted rebind', 'user_id' => $newWorker->id])), 'site_id');
            $this->assertSame($this->worker->id, $root->fresh()->user_id); $this->assertSame(2, $root->fresh()->source_revision);
        }
    }

    public function test_tampered_or_downgraded_child_is_never_promoted_from_a_fake_latest_legacy_version(): void
    {
        $root = $this->exposure(); $r = $this->assign($root); $child = ChemicalExposureFollowup::findOrFail($r['source_id']);
        DB::table('chemical_exposure_followups')->where('id', $child->id)->update(['instruction' => 'Externally replaced']);
        $this->integrity(fn () => $this->exposureService()->completeFollowup($root, $child->id, $this->manager,
            $this->privateCommand($root, ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Attempt'])));
        DB::table('chemical_exposure_followups')->where('id', $child->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->integrity(fn () => $this->exposureService()->completeFollowup($root, $child->id, $this->manager,
            $this->privateCommand($root, ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Attempt'])));
        $this->assertSame(2, $root->fresh()->source_revision);
    }

    public function test_review_save_or_semantic_audit_veto_rolls_back_child_version_outbox_and_aggregate(): void
    {
        $root = $this->exposure(); $before = ChemicalExposureSourceDelivery::count();
        ChemicalExposureReview::creating(fn () => false);
        try { $this->integrity(fn () => $this->review($root)); }
        finally { ChemicalExposureReview::flushEventListeners(); }
        AuditLog::creating(fn ($row) => $row->action === 'chemical.exposure.review_exposure' ? false : null);
        try { $this->integrity(fn () => $this->review($root)); }
        finally { AuditLog::flushEventListeners(); }
        $this->assertSame(0, ChemicalExposureReview::count()); $this->assertSame(1, $root->fresh()->source_revision);
        $this->assertSame(1, ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
        $this->assertSame($before, ChemicalExposureSourceDelivery::count());
    }

    public function test_occurrence_fractional_seconds_are_refused_without_silent_rounding_or_source_certification(): void
    {
        $this->validation(fn () => $this->capture(['exposed_at' => '2026-10-12T00:00:00.123456Z']), 'exposed_at');
        $this->assertSame(0, \App\Models\SubstanceExposureRecord::count());
    }
}
