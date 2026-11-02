<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decision 2 (Stephan, 1 Nov 2026): a safety review moves to "Checking it worked" (monitoring) as soon as every action
 * is done, and back to "Actions under way" (corrective_action) when work is added or sent back — including a failed
 * check that still needs its revised action (Q11).
 */
class HsReviewStageFollowsActionsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->officer = $this->roleUser('health_safety_officer');
    }

    public function test_the_stage_follows_the_actions_through_a_failed_check_and_its_revised_action(): void
    {
        $service = app(HsCorrectiveActionService::class);
        $event = $this->event();
        $a = $this->action($event, 'CA-A', HsCorrectiveAction::STATUS_IN_PROGRESS);
        $b = $this->action($event, 'CA-B', HsCorrectiveAction::STATUS_IN_PROGRESS);

        $this->actingAs($this->officer);
        $service->complete($a, ['completion_notes' => 'Grips fitted under the rugs.']);
        $this->assertSame(HsEvent::STATUS_CORRECTIVE_ACTION, $event->fresh()->status, 'One action still in progress.');

        $service->complete($b, ['completion_notes' => 'Walker moved next to the couch.']);
        $this->assertSame(HsEvent::STATUS_MONITORING, $event->fresh()->status, 'Every action done: checking it worked.');

        // A check says CA-A didn't work: there is work to add again.
        $checker = $this->roleUser('health_safety_officer');
        $this->actingAs($checker);
        $service->verify($a->fresh(), ['evidence_reviewed' => true, 'effectiveness_confirmed' => false, 'verification_notes' => 'The rug still slides.']);
        $this->assertSame(HsEvent::STATUS_CORRECTIVE_ACTION, $event->fresh()->status, 'Failed check without a revised action.');

        // The revised action is added (not done yet), then done: waiting for its own check.
        $revised = $service->createStandalone($event->fresh(), [
            'title' => 'Replace the rug', 'assigned_to_user_id' => $this->officer->id,
            'due_date' => now()->addWeek()->toDateString(), 'priority' => HsCorrectiveAction::PRIORITY_MEDIUM,
            'replaces_action_id' => $a->id,
        ], $checker);
        $this->assertSame(HsEvent::STATUS_CORRECTIVE_ACTION, $event->fresh()->status);
        $revised->forceFill(['status' => HsCorrectiveAction::STATUS_IN_PROGRESS])->save();
        $this->actingAs($this->officer);
        $service->complete($revised->fresh(), ['completion_notes' => 'New non-slip rug laid.']);
        $this->assertSame(HsEvent::STATUS_MONITORING, $event->fresh()->status, 'Revised action done: checking it worked.');

        // Sent back for rework: actions under way again.
        $this->actingAs($checker);
        $service->returnForRework($b->fresh(), 'The walker is back by the window.');
        $this->assertSame(HsEvent::STATUS_CORRECTIVE_ACTION, $event->fresh()->status);
    }

    public function test_reviews_that_are_not_yet_at_the_action_stage_are_left_alone(): void
    {
        $event = $this->event(['status' => HsEvent::STATUS_INVESTIGATING]);
        $done = $this->action($event, 'CA-DONE', HsCorrectiveAction::STATUS_COMPLETED);

        app(HsCorrectiveActionService::class)->checkEventActionCompletion((int) $event->id);

        $this->assertSame(HsEvent::STATUS_INVESTIGATING, $event->fresh()->status);
        $this->assertSame(HsCorrectiveAction::STATUS_COMPLETED, $done->fresh()->status);
    }

    private function event(array $attributes = []): HsEvent
    {
        return HsEvent::factory()->worksafeNotNotifiable($this->officer)->create([
            'site_id' => $this->site->id,
            'owner_user_id' => $this->officer->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'investigation_required' => false,
            'status' => HsEvent::STATUS_CORRECTIVE_ACTION,
            ...$attributes,
        ]);
    }

    private function action(HsEvent $event, string $reference, string $status): HsCorrectiveAction
    {
        $creator = User::factory()->create();

        return HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id,
            'reference_number' => $reference,
            'status' => $status,
            'priority' => HsCorrectiveAction::PRIORITY_MEDIUM,
            'due_date' => now()->addWeek()->toDateString(),
            'assigned_to_user_id' => $creator->id,
            'created_by' => $creator->id,
            'completed_at' => $status === HsCorrectiveAction::STATUS_COMPLETED ? now() : null,
            'completion_notes' => $status === HsCorrectiveAction::STATUS_COMPLETED ? 'Done.' : null,
            'verified_at' => null,
            'effectiveness_confirmed' => null,
        ]);
    }

    private function roleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'position_role' => $roleName,
        ]);

        return $user->fresh();
    }
}
