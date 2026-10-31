<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ClientIncident;
use App\Models\RestraintEventReview;
use App\Models\RestraintFollowup;
use App\Models\RestraintFollowupHistory;
use App\Models\Site;

class RestraintReviewOwnershipTest extends RestraintEventCommandTestCase
{
    public function test_owner_is_distinct_from_first_historical_reviewer_and_corrections_append_actual_recorder(): void
    {
        $row = $this->event();
        $reviewer = $this->staff(['restraints.review', 'clients.viewAny']);
        $this->service()->assignReview($row, $this->actor, $this->command($row, ['review_owner_id' => $reviewer->id, 'review_due_at' => now()->addDay()->toIso8601String(), 'reason' => 'Named review requested']));
        $this->assertNull($row->fresh()->reviewed_at);
        $this->assertSame($reviewer->id, $row->fresh()->review_owner_id);
        $actual = now()->subMinutes(20)->toIso8601String();
        $this->service()->review($row, $this->actor, $this->command($row, ['reviewed_by' => $reviewer->id, 'reviewed_at' => $actual, 'review_notes' => 'Actual review']));
        $first = $row->fresh()->reviewed_at->toIso8601String();
        $this->service()->review($row, $this->actor, $this->command($row, ['reviewed_at' => now()->subMinute()->toIso8601String(), 'severity' => 'critical', 'lessons_learned' => 'Correction', 'reason' => 'Later evidence']));
        $this->assertSame($reviewer->id, $row->fresh()->reviewed_by);
        $this->assertSame($first, $row->fresh()->reviewed_at->toIso8601String());
        $this->assertSame('critical', $row->fresh()->severity);
        $latest = RestraintEventReview::latest('id')->first();
        $this->assertSame($this->actor->id, $latest->recorder_id);
        $this->assertSame($this->actor->id, $latest->actual_actor_id);
        $this->assertFalse($row->fresh()->needsReview());
        $this->service()->correct($row, $this->actor, $this->command($row, ['reason' => 'New fact', 'person_response' => 'Later factual response']));
        $this->assertTrue($row->fresh()->needsReview());
    }

    public function test_unassigned_owner_is_explicit_and_reassignment_requires_current_authorized_recipient(): void
    {
        $row = $this->event();
        $outsider = $this->staff(['restraints.review', 'clients.viewAny'], Site::factory()->create());
        $this->denied(fn () => $this->service()->assignReview($row, $this->actor, $this->command($row, ['review_owner_id' => $outsider->id, 'reason' => 'Forged foreign assignment'])));
        $this->service()->assignReview($row, $this->actor, $this->command($row, ['review_owner_id' => null, 'review_due_at' => null, 'reason' => 'Unassigned pending allocation']));
        $this->assertNull($row->fresh()->review_owner_id);
        $this->assertSame(1, $row->fresh()->review_assignment_revision);
    }

    public function test_named_executor_alone_completes_and_reassignment_does_not_impersonate_execution(): void
    {
        $row = $this->event();
        $executor = $this->staff(['restraints.view', 'clients.viewAny']);
        $other = $this->staff(['restraints.view', 'clients.viewAny']);
        $created = $this->service()->addFollowup($row, $this->actor, $this->command($row, ['notes' => 'Person requested follow-up', 'assigned_to_user_id' => $executor->id, 'due_at' => now()->addDay()->toIso8601String()]));
        $completion = $this->command($row, ['followup_id' => $created['followup_id'], 'completed_at' => now()->subMinute()->toIso8601String(), 'outcome' => 'declined', 'notes' => 'Person declined contact']);
        $this->denied(fn () => $this->service()->completeFollowup($row, $other, $completion));
        $this->denied(fn () => $this->service()->completeFollowup($row, $this->actor, $completion));
        $first = $this->service()->completeFollowup($row, $executor, $completion);
        $this->assertTrue($this->service()->completeFollowup($row, $executor, $completion)['replayed']);
        $child = RestraintFollowup::findOrFail($created['followup_id']);
        $this->assertSame($executor->id, $child->completed_by_user_id);
        $this->assertSame('declined', $child->completion_outcome['outcome']);
        $this->service()->correctFollowup($row, $this->actor, $this->command($row, ['followup_id' => $child->id, 'completed_at' => now()->subMinute()->toIso8601String(), 'outcome' => 'attempted', 'notes' => 'Actual correction', 'reason' => 'Corrected original account']));
        $this->assertSame($executor->id, $child->fresh()->completed_by_user_id);
        $this->assertSame(3, RestraintFollowupHistory::where('followup_id', $child->id)->count());
        $this->invalid(fn () => $this->service()->reassignFollowup($row, $this->actor, $this->command($row, ['followup_id' => $child->id, 'assigned_to_user_id' => $other->id, 'reason' => 'Cannot reopen'])), 'followup_id');
    }

    public function test_current_review_capability_can_link_and_unlink_without_later_incident_read(): void
    {
        $row = $this->event();
        $reviewer = $this->staff(['restraints.review', 'clients.viewAny', 'incidents.viewAny', 'incidents.update']);
        $incident = ClientIncident::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'status' => 'submitted']);
        $this->service()->linkIncident($row, $reviewer, $this->command($row, ['related_incident_id' => $incident->id]));
        $this->revoke($reviewer, 'incidents.viewAny');
        $this->service()->unlinkIncident($row, $reviewer, $this->command($row, ['reason' => 'Correct retained association']));
        $this->assertNull($row->fresh()->related_incident_id);
        $this->assertSame('submitted', $incident->fresh()->status);
    }
}
