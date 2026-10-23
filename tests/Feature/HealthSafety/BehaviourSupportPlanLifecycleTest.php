<?php

namespace Tests\Feature\HealthSafety;

use App\Models\BehaviourSupportPlanReview;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\RestraintCommandReceipt;
use PHPUnit\Framework\Attributes\DataProvider;

final class BehaviourSupportPlanLifecycleTest extends BehaviourSupportPlanTestCase
{
    public function test_capture_only_creates_draft_and_cannot_select_other_lifecycle_states(): void
    {
        $this->assertSame('draft', $this->makePlan(actor: $this->planCapturer)->status);
        foreach (['active', 'under_review', 'archived'] as $status) {
            $this->denied(fn () => $this->makePlan(['status' => $status, 'expected_policy_revision' => 0], $this->planCapturer));
        }
        $this->assertSame(1, RestraintCommandReceipt::where('source_type', 'plan')->count());
    }

    public function test_manager_requested_active_creation_remains_pending_without_fabricated_approval(): void
    {
        $plan = $this->makePlan(['status' => 'active', 'expected_policy_revision' => 0]);
        $this->assertSame('draft', $plan->status);
        $this->assertNull($plan->current_published_version_id);
        $receipt = RestraintCommandReceipt::where('source_id', $plan->id)->sole()->accepted_result;
        $this->assertSame('active', $receipt['requested_status']);
        $this->assertSame('approval_pending', $receipt['disposition']);
    }

    public function test_manage_activation_checks_same_policy_and_exact_candidate_as_review_activation(): void
    {
        $plan = $this->makePlan();
        $receipt = $this->plans()->activate($plan, $this->planManager, $this->command($plan, $this->targetPayload($plan)));
        $this->assertSame('approval_pending', $receipt['disposition']);
        $this->assertSame('draft', $plan->fresh()->status);
        $this->assertNull($plan->fresh()->current_published_version_id);
        $this->denied(fn () => $this->plans()->activate($plan, $this->planReviewer, $this->command($plan, $this->targetPayload($plan))));
    }

    #[DataProvider('reviewOutcomes')]
    public function test_every_existing_review_outcome_and_resulting_status_remains_real_review_authority(string $outcome, string $status): void
    {
        $plan = $this->makePlan();
        $receipt = $this->plans()->review($plan, $this->planReviewer, $this->command($plan, ['outcome' => $outcome, 'reviewed_at' => '2026-10-10T00:00:00Z', 'resulting_status' => $status, 'next_review_date' => '2026-12-01']));
        $row = BehaviourSupportPlanReview::findOrFail($receipt['review_id']);
        $this->assertSame($outcome, $row->outcome);
        $this->assertSame($status, $row->resulting_status);
        $this->assertSame($status, $row->requested_resulting_status);
        $this->assertSame($this->planReviewer->id, $row->reviewed_by);
        $this->assertSame($status, $plan->fresh()->status);
        $this->assertSame(2, $plan->fresh()->lock_version);
        $this->assertFalse($this->planReviewer->canDo('restraints.manage'));
    }

    public static function reviewOutcomes(): array
    {
        return [['continued', 'draft'], ['modified', 'under_review'], ['reduced', 'draft'], ['discontinued', 'archived'], ['escalated', 'under_review']];
    }

    public function test_submit_archive_retain_versions_and_exact_replay_precedes_terminal_state(): void
    {
        $plan = $this->makePlan();
        $submitted = $this->plans()->submitReview($plan, $this->planManager, $this->command($plan));
        $this->assertSame('under_review', $plan->fresh()->status);
        $archive = $this->command($plan);
        $accepted = $this->plans()->archive($plan, $this->planManager, $archive);
        $replay = $this->plans()->archive($plan, $this->planManager, $archive);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($accepted['receipt_id'], $replay['receipt_id']);
        $this->assertSame(1, BehaviourSupportPlanVersion::where('behaviour_support_plan_id', $plan->id)->count());
        $this->validation(fn () => $this->plans()->update($plan, $this->planManager, $this->command($plan, ['title' => 'Changed'])), 'status');
    }

    public function test_stale_parent_command_creates_no_extra_version_review_or_receipt(): void
    {
        $plan = $this->makePlan();
        $stale = $this->command($plan, ['outcome' => 'continued', 'reviewed_at' => '2026-10-10T00:00:00Z']);
        $this->plans()->update($plan, $this->planManager, $this->command($plan, ['notes' => 'New revision']));
        $this->validation(fn () => $this->plans()->review($plan, $this->planReviewer, $stale), 'expected_version');
        $this->assertSame(0, BehaviourSupportPlanReview::count());
        $this->assertDatabaseMissing('restraint_command_receipts', ['idempotency_key' => $stale['idempotency_key']]);
    }
}
