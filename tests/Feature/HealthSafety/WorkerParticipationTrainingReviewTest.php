<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\GovernanceSetting;
use App\Domain\Governance\Services\ComplianceEngineService;
use App\Models\HsRepresentative;
use App\Models\HsTrainingReviewSource;
use App\Models\HsTrainingReviewCase;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Services\HealthSafety\WorkerParticipationTrainingReviewAccessService;
use App\Services\HealthSafety\WorkerParticipationTrainingReviewerResolver;
use App\Services\HealthSafety\WorkerParticipationTrainingReviewService;

/** Whole assigned-review lifecycle source regressions. Native UNRUN. */
final class WorkerParticipationTrainingReviewTest extends RestraintEventCommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->staff(['hazards.view', 'governance.compliance.view', 'governance.compliance.manage']);
        $this->actingAs($this->actor);
        GovernanceSetting::set(WorkerParticipationTrainingReviewerResolver::KEY,
            ['default_user_id' => (int) $this->actor->id, 'site_overrides' => []]);
    }

    public function test_cumulative_days_never_suppress_initial_review_and_replay_keeps_one_root(): void
    {
        $representative = $this->representative(30);
        $first = $this->reviews()->syncForViewer($this->actor, $representative->id);
        $again = $this->reviews()->syncForViewer($this->actor, $representative->id);
        $this->assertSame('created', $first['status']);
        $this->assertSame($first['review']['id'], $again['review']['id']);
        $this->assertSame(ComplianceObligation::nzToday(), $first['review']['due_date']);
        $this->assertTrue($first['review']['can_complete']);
        $this->assertSame(1, HsTrainingReviewSource::where('hs_representative_id', $representative->id)->count());
        $this->assertSame(0, StaffCredential::count());
    }

    public function test_dated_attestation_creates_one_anchored_child_and_replay_preserves_evidence_history(): void
    {
        $representative = $this->representative(2);
        $review = $this->reviews()->syncForViewer($this->actor, $representative->id)['review'];
        $result = $this->reviews()->completeForViewer($this->actor, $review['id'], ComplianceObligation::nzToday(), 'Reviewed recorded facts; no course recorded.', $review['version']);
        $this->assertSame('complete', $result['review']['status']);
        $source = HsTrainingReviewSource::where('compliance_obligation_id', $review['id'])->sole();
        $evidenceId = $source->attestation_evidence_id;
        $this->reviews()->completeForViewer($this->actor, $review['id'], ComplianceObligation::nzToday(), 'Replay must not replace history.', $review['version']);
        $this->assertSame($evidenceId, $source->fresh()->attestation_evidence_id);
        $this->assertSame(2, HsTrainingReviewSource::where('root_obligation_id', $review['root_id'])->count());
        $child = HsTrainingReviewSource::where('root_obligation_id', $review['root_id'])->where('compliance_obligation_id', '!=', $review['id'])->sole();
        $this->assertSame($review['due_date'], $child->anniversary_date->toDateString());
        $this->assertNull($child->reviewed_on);
        $this->assertSame($source->case_id, $child->case_id);
        $this->assertSame(0, StaffCredential::count());
    }

    public function test_missing_owner_persists_case_and_later_assignment_keeps_original_due_and_anniversary(): void
    {
        GovernanceSetting::set(WorkerParticipationTrainingReviewerResolver::KEY, ['default_user_id' => null, 'site_overrides' => []]);
        $representative = $this->representative(30);
        $pending = $this->reviews()->syncForViewer($this->actor, $representative->id);
        $this->assertSame('needs_owner', $pending['status']);
        $this->assertNull($pending['review']);
        $case = HsTrainingReviewCase::findOrFail($pending['case_id']);
        $due = $case->initial_due_date->toDateString();
        $this->assertNull($case->root_obligation_id);
        $this->travel(40)->days();
        GovernanceSetting::set(WorkerParticipationTrainingReviewerResolver::KEY, ['default_user_id' => (int) $this->actor->id, 'site_overrides' => []]);
        $activated = $this->reviews()->syncForViewer($this->actor, $representative->id);
        $this->assertSame($pending['case_id'], $activated['case_id']);
        $this->assertSame($due, $activated['review']['due_date']);
        $this->assertSame($due, $case->fresh()->anniversary_date->toDateString());
        $this->assertSame(1, HsTrainingReviewCase::where('hs_representative_id', $representative->id)->count());
    }

    public function test_explicit_null_override_replays_one_pending_identity_without_fallback(): void
    {
        GovernanceSetting::set(WorkerParticipationTrainingReviewerResolver::KEY,
            ['default_user_id' => (int) $this->actor->id, 'site_overrides' => [(string) $this->site->id => null]]);
        $representative = $this->representative(2);
        $first = $this->reviews()->syncForViewer($this->actor, $representative->id);
        $again = $this->reviews()->syncForViewer($this->actor, $representative->id);
        $this->assertSame('needs_owner', $first['status']);
        $this->assertSame($first['case_id'], $again['case_id']);
        $this->assertSame(0, HsTrainingReviewSource::where('hs_representative_id', $representative->id)->count());
    }

    public function test_timely_performed_review_recorded_later_preserves_both_real_dates(): void
    {
        $review = $this->reviews()->syncForViewer($this->actor, $this->representative(2)->id)['review'];
        $this->travel(3)->days();
        $completed = $this->reviews()->completeForViewer($this->actor, $review['id'], $review['due_date'], 'Reviewed on the due date; recording later.', $review['version'])['review'];
        $this->assertSame($review['due_date'], $completed['reviewed_on']);
        $this->assertFalse($completed['performed_late']);
        $this->assertTrue($completed['recorded_late']);
        $this->assertNotNull($completed['recorded_at']);
        $this->assertSame(ComplianceObligation::nzToday(), ComplianceObligation::findOrFail($review['id'])->completed_at
            ->copy()->timezone(config('app.worker_timezone') ?: \App\Domain\Governance\Support\GovernanceLabels::TIMEZONE)->toDateString());
    }

    public function test_system_planning_keeps_named_reviewer_out_of_creation_identity_and_creates_no_governance_obligation(): void
    {
        $representative = $this->representative(30);
        \Illuminate\Support\Facades\Auth::logout();
        $receipt = $this->reviews()->planSystemCases();
        $again = $this->reviews()->planSystemCases();
        $this->assertSame(1, $receipt['created']);
        $this->assertSame(1, $again['existing']);
        $case = HsTrainingReviewCase::where('hs_representative_id', $representative->id)->sole();
        $this->assertSame('system', $case->creation_origin);
        $this->assertNull($case->created_by);
        $this->assertNull($case->root_obligation_id);
        $this->assertSame(0, HsTrainingReviewSource::count());
        $audit = \Illuminate\Support\Facades\DB::table('governance_audit_log')->where('action', 'hsr_training_review.case_planned')->sole();
        $this->assertNull($audit->user_id);
        $this->assertSame((int) $case->id, (int) $audit->resource_id);
    }

    public function test_wrong_site_and_governance_only_viewer_cannot_read_a_bridged_body_but_ordinary_records_remain_readable(): void
    {
        $review = $this->reviews()->syncForViewer($this->actor, $this->representative(3)->id)['review'];
        $other = Site::factory()->create(['is_active' => true]);
        $scoped = $this->staff(['hazards.view', 'governance.compliance.view'], $other);
        $ordinaryViewer = $this->staff(['governance.compliance.view']);
        $ordinary = app(ComplianceEngineService::class)->createObligation('privacy_act', 'Ordinary requirement', 'Ordinary facts', 'annual', $this->actor);
        $access = app(WorkerParticipationTrainingReviewAccessService::class);
        foreach ([$scoped, $ordinaryViewer] as $viewer) {
            $this->assertFalse($access->allows($viewer, $review['id'], 'governance.compliance.view'));
            $this->assertTrue($access->allows($viewer, $ordinary->id, 'governance.compliance.view'));
        }
    }

    public function test_future_date_and_stale_version_leave_attestation_and_history_unchanged(): void
    {
        $review = $this->reviews()->syncForViewer($this->actor, $this->representative(4)->id)['review'];
        $this->invalid(fn () => $this->reviews()->completeForViewer($this->actor, $review['id'], now()->addDay()->toDateString(), 'Future facts', $review['version']), 'attestation');
        try {
            $this->reviews()->completeForViewer($this->actor, $review['id'], ComplianceObligation::nzToday(), 'Actual review', $review['version'] + 1);
            $this->fail('Stale version must refuse.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
        $this->assertNull(HsTrainingReviewSource::where('compliance_obligation_id', $review['id'])->sole()->attestation_evidence_id);
        $this->assertSame(1, HsTrainingReviewSource::where('root_obligation_id', $review['root_id'])->count());
    }

    public function test_legacy_training_series_is_not_adopted_or_rewritten(): void
    {
        $representative = $this->representative(2);
        $legacy = app(ComplianceEngineService::class)->createObligation('hswa', 'Historical training', 'Historical claim retained', 'annual', $this->actor,
            obligationCode: 'HSR-TRAINING-'.$representative->id);
        $before = $legacy->getRawOriginal();
        $this->reviews()->syncForViewer($this->actor, $representative->id);
        $this->assertSame($before, $legacy->fresh()->getRawOriginal());
        $this->assertFalse(HsTrainingReviewSource::where('compliance_obligation_id', $legacy->id)->exists());
    }

    private function representative(int $days): HsRepresentative
    {
        return HsRepresentative::create(['user_id' => $this->actor->id, 'site_id' => $this->site->id,
            'election_method' => 'elected', 'elected_at' => '2026-01-01',
            'status' => 'active', 'training_days_completed' => $days, 'created_by' => $this->actor->id]);
    }

    private function reviews(): WorkerParticipationTrainingReviewService
    {
        return app(WorkerParticipationTrainingReviewService::class);
    }
}
