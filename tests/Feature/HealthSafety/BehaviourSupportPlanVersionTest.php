<?php

namespace Tests\Feature\HealthSafety;

use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanApproval;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\RestraintCommandReceipt;
use Illuminate\Support\Carbon;
use LogicException;

final class BehaviourSupportPlanVersionTest extends BehaviourSupportPlanTestCase
{
    public function test_legacy_active_content_remains_unverified_without_manufactured_versions(): void
    {
        $legacy = BehaviourSupportPlan::factory()->for($this->client)->create();
        $this->assertNull($legacy->source_schema_version);
        $this->assertSame(0, BehaviourSupportPlanVersion::count());
        $resolved = $this->resolve($legacy);
        $this->assertSame('approval_unknown', $resolved['authorization_state']);
        $this->assertNull($resolved['approval_id']);
        $this->assertSame(0, BehaviourSupportPlanApproval::count());
    }

    public function test_creation_materializes_complete_typed_candidate_before_any_decision(): void
    {
        $plan = $this->makePlan();
        $candidate = $plan->candidateVersion;
        $this->assertSame('candidate', $candidate->kind);
        $this->assertSame('2026-10-01', $candidate->content['developed_at']);
        $this->assertSame('2026-11-30', $candidate->content['review_date']);
        $this->assertSame('Private plan narrative', $candidate->content['notes']);
        $this->assertSame($this->site->id, $candidate->site_id);
        $this->assertNull($candidate->approval_id);
        $this->assertNull($candidate->published_at);
        $this->assertNull($plan->current_published_version_id);
        $this->assertSame(1, $plan->lock_version);
    }

    public function test_published_snapshot_and_candidate_are_distinct_immutable_facts(): void
    {
        $plan = $this->makePlan();
        $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan));
        $receipt = $this->plans()->activate($plan, $this->planManager, $this->command($plan, $this->targetPayload($plan)));
        $candidate = $plan->fresh()->candidateVersion;
        $published = $plan->fresh()->publishedVersion;
        $this->assertNotSame($candidate->id, $published->id);
        $this->assertSame($candidate->id, $published->candidate_version_id);
        $this->assertSame($candidate->content_digest, $published->content_digest);
        $this->assertNull($candidate->approval_id);
        $this->assertSame($receipt['approval_id'], $published->approval_id);
        // Keep the stored digest: the refused update below still dirties $candidate in memory on the first pass.
        $digest = $candidate->content_digest;
        foreach ([$candidate, $published] as $version) {
            try {
                $version->update(['content_digest' => str_repeat('0', 64)]);
                $this->fail('Immutable content must not change.');
            } catch (LogicException) {
                $this->assertSame($digest, $version->fresh()->content_digest);
            }
        }
    }

    public function test_edit_published_content_creates_replacement_draft_without_moving_old_links(): void
    {
        $plan = $this->makePlan();
        $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan));
        $published = $this->plans()->activate($plan, $this->planManager, $this->command($plan, $this->targetPayload($plan)));
        $old = BehaviourSupportPlanVersion::findOrFail($published['published_version_id']);
        $updated = $this->plans()->update($plan, $this->planManager, $this->command($plan, ['notes' => 'Replacement content']));
        $this->assertSame('under_review', $plan->fresh()->status);
        $this->assertSame($old->id, $plan->fresh()->current_published_version_id);
        $this->assertSame('Private plan narrative', $old->fresh()->content['notes']);
        $this->assertNotSame($old->candidate_version_id, $updated['candidate_version_id']);
        $this->assertSame('verified', $this->resolve($plan)['authorization_state']);
    }

    public function test_review_date_change_is_a_new_candidate_and_keeps_old_publication_date(): void
    {
        $plan = $this->makePlan();
        $original = $plan->candidateVersion;
        $receipt = $this->plans()->review($plan, $this->planReviewer, $this->command($plan, ['outcome' => 'modified', 'reviewed_at' => '2026-10-10T10:00:00+13:00', 'next_review_date' => '2026-12-31']));
        $this->assertNotSame($original->id, $receipt['candidate_version_id']);
        $this->assertSame('2026-11-30', $original->fresh()->content['review_date']);
        $this->assertSame('2026-12-31', $plan->fresh()->review_date->format('Y-m-d'));
    }

    public function test_effective_resolution_uses_actual_start_and_explicit_interval_not_today(): void
    {
        $plan = $this->makePlan();
        $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['effective_to' => '2026-10-10T12:00:00Z']));
        $this->plans()->activate($plan, $this->planManager, $this->command($plan, $this->targetPayload($plan)));
        $this->assertSame('future', $this->resolve($plan, '2026-10-09T23:00:00Z')['authorization_state']);
        $this->assertSame('verified', $this->resolve($plan, '2026-10-10T02:00:00Z')['authorization_state']);
        $this->assertSame('expired', $this->resolve($plan, '2026-10-10T12:00:00Z')['authorization_state']);
        $this->assertSame('expired', $this->resolve($plan, '2026-10-11T00:00:00Z')['authorization_state']);
    }

    public function test_explicit_foreign_or_candidate_version_denies_without_guessing_identity(): void
    {
        $plan = $this->makePlan();
        $other = $this->makePlan();
        $this->denied(fn () => $this->resolve($plan, version: (int) $other->current_candidate_version_id));
        $this->denied(fn () => $this->resolve($plan, version: (int) $plan->current_candidate_version_id));
    }

    public function test_rollback_refuses_new_plan_metadata_and_preserves_legacy_records(): void
    {
        $legacy = BehaviourSupportPlan::factory()->for($this->client)->create();
        $new = $this->makePlan();
        $migration = require database_path('migrations/2026_10_08_180600_version_behaviour_support_plans.php');
        try {
            $migration->down();
            $this->fail('New immutable version evidence must retain schema.');
        } catch (\RuntimeException) {
            $this->assertDatabaseHas('behaviour_support_plans', ['id' => $legacy->id, 'status' => 'active']);
            $this->assertDatabaseHas('behaviour_support_plans', ['id' => $new->id, 'lock_version' => 1]);
            $this->assertSame(1, BehaviourSupportPlanVersion::count());
            $this->assertSame(1, RestraintCommandReceipt::where('source_type', 'plan')->count());
        }
    }

    public function test_multiple_applicable_plans_remain_ambiguous_until_an_exact_version_is_selected(): void
    {
        $first = $this->makePlan();
        $second = $this->makePlan();
        $published = $this->approveAndPublish($first);
        $this->approveAndPublish($second);
        $automatic = $this->access()->withCurrentViewer($this->planCapturer, fn ($current, $reads, $sites) => $this->plans()->resolveForEvent($this->client->fresh(), (int) $this->site->id, null, Carbon::parse('2026-10-10T02:00:00Z'), true, null, null, $current, $reads, $sites));
        $this->assertSame('ambiguous', $automatic['authorization_state']);
        $this->assertNull($automatic['plan_version_id']);
        $selected = $this->resolve($first, version: $published['published_version_id']);
        $this->assertSame('verified', $selected['authorization_state']);
        $this->assertSame($first->id, $selected['plan_id']);
    }

    public function test_overdue_review_day_is_not_an_invented_approval_expiry(): void
    {
        $plan = $this->makePlan(['review_date' => '2026-10-01']);
        $published = $this->approveAndPublish($plan);
        $this->assertSame('verified', $this->resolve($plan, version: $published['published_version_id'])['authorization_state']);
        $version = BehaviourSupportPlanVersion::findOrFail($published['published_version_id']);
        $this->assertSame('2026-10-01', $version->review_date->format('Y-m-d'));
        $this->assertNull($version->effective_to);
    }
}
