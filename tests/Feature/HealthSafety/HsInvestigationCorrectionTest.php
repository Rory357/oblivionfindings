<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsInvestigationCorrection;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Answer 20 (Stephan, 1 Nov 2026): a correction to agreed findings needs a reason and goes through independent review
 * and approval like the original. The original stands until then and is never overwritten.
 */
class HsInvestigationCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private HsEvent $event;

    private HsInvestigation $investigation;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->lead = $this->officer();
        $this->event = HsEvent::factory()->create(['site_id' => $this->site->id, 'owner_user_id' => $this->lead->id, 'status' => HsEvent::STATUS_CORRECTIVE_ACTION]);
        $this->investigation = HsInvestigation::factory()->create([
            'hs_event_id' => $this->event->id,
            'status' => HsInvestigation::STATUS_COMPLETED,
            'lead_investigator_id' => $this->lead->id,
            'submitted_by_id' => $this->lead->id,
            'team_member_ids' => [],
            'findings_summary' => 'The rug edge lifts at the lounge doorway.',
        ]);
    }

    public function test_a_correction_is_reviewed_and_approved_independently_before_it_counts(): void
    {
        [$proposer, $reviewer, $approver] = [$this->officer(), $this->officer(), $this->officer()];
        $base = "/health-safety/events/{$this->event->id}";

        $this->actingAs($proposer)->post("{$base}/investigations/{$this->investigation->id}/corrections", [
            'after_value' => 'The rug edge lifts at the doorway and the rug has no grip underneath.',
            'reason' => 'The check found the rug has no grip underneath.',
        ])->assertSessionHas('success');
        $correction = HsInvestigationCorrection::query()->sole();
        $this->assertSame(HsInvestigationCorrection::STATUS_PROPOSED, $correction->status);
        $this->assertSame('The rug edge lifts at the lounge doorway.', $this->investigation->fresh()->effectiveFindingsSummary(), 'The original stands until approval.');

        // Neither the proposer nor an investigator may review it.
        foreach ([$proposer, $this->lead] as $notIndependent) {
            $this->actingAs($notIndependent)->post("{$base}/corrections/{$correction->id}/review")->assertSessionHasErrors('correction');
        }
        $this->actingAs($reviewer)->post("{$base}/corrections/{$correction->id}/review")->assertSessionHas('success');

        // The reviewer may not also approve it.
        $this->actingAs($reviewer)->post("{$base}/corrections/{$correction->id}/approve")->assertSessionHasErrors('correction');
        $this->actingAs($approver)->post("{$base}/corrections/{$correction->id}/approve")->assertSessionHas('success');

        $investigation = $this->investigation->fresh();
        $this->assertSame('The rug edge lifts at the doorway and the rug has no grip underneath.', $investigation->effectiveFindingsSummary());
        $this->assertSame('The rug edge lifts at the lounge doorway.', $investigation->findings_summary, 'Never overwritten.');
        foreach (['Proposed', 'Reviewed', 'Approved'] as $step) {
            $this->assertDatabaseHas('audit_logs', ['action' => "healthSafety.investigation.correction{$step}", 'auditable_id' => $investigation->id]);
        }
    }

    public function test_a_rejected_correction_is_kept_with_its_reason_and_changes_nothing(): void
    {
        $correction = $this->propose($this->officer());
        $reviewer = $this->officer();

        $this->actingAs($reviewer)->post("/health-safety/events/{$this->event->id}/corrections/{$correction->id}/reject", ['reason' => 'The check photo shows grips fitted.'])
            ->assertSessionHas('success');

        $this->assertSame(HsInvestigationCorrection::STATUS_REJECTED, $correction->fresh()->status);
        $this->assertSame('The check photo shows grips fitted.', $correction->fresh()->rejection_reason);
        $this->assertSame('The rug edge lifts at the lounge doorway.', $this->investigation->fresh()->effectiveFindingsSummary());
    }

    public function test_only_agreed_findings_on_open_reviews_and_one_correction_at_a_time(): void
    {
        $this->propose($this->officer());
        $this->actingAs($this->officer())->post("/health-safety/events/{$this->event->id}/investigations/{$this->investigation->id}/corrections", [
            'after_value' => 'Another version of the findings.', 'reason' => 'A second correction at the same time.',
        ])->assertSessionHasErrors('after_value');

        $open = HsInvestigation::factory()->create(['hs_event_id' => $this->event->id, 'status' => HsInvestigation::STATUS_IN_PROGRESS, 'findings_summary' => 'Draft.']);
        $this->actingAs($this->officer())->post("/health-safety/events/{$this->event->id}/investigations/{$open->id}/corrections", [
            'after_value' => 'Corrected draft.', 'reason' => 'Trying to correct an open investigation.',
        ])->assertSessionHasErrors('after_value');

        $this->assertSame(1, HsInvestigationCorrection::query()->count());
    }

    public function test_a_proposed_correction_keeps_what_it_proposed_and_is_never_deleted(): void
    {
        $correction = $this->propose($this->officer());

        try {
            $correction->update(['after_value' => 'Rewritten afterwards.']);
            $this->fail('A proposed correction changed.');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $correction->delete();
    }

    private function propose(User $proposer): HsInvestigationCorrection
    {
        $this->actingAs($proposer)->post("/health-safety/events/{$this->event->id}/investigations/{$this->investigation->id}/corrections", [
            'after_value' => 'The rug has no grip underneath.', 'reason' => 'The check found the rug has no grip underneath.',
        ])->assertSessionHas('success');

        return HsInvestigationCorrection::query()->latest('id')->firstOrFail();
    }

    private function officer(): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', 'health_safety_officer')->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'position_role' => 'health_safety_officer', 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
        ]);

        return $user->fresh();
    }
}
