<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Services\HealthSafety\HsCorrectiveActionWorkspace;

/**
 * K1a (H&S UI/UX plan B1.5/B1.6): while an action's owner needs replacing, Start is off with the reason "Choose a new
 * owner first" for people who could otherwise start it, and the next step is "choose a new owner".
 * Read-only viewers get no blocked clutter (IR3b). (Whether the server also refuses such a start is open: exact retries
 * of a start are kept today, HsCorrectiveActionTest.)
 */
class HsCorrectiveActionRecordAbilitiesTest extends RestraintEventCommandTestCase
{
    private function action(User $owner): HsCorrectiveAction
    {
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
            'site_id' => $this->site->id, 'client_id' => null, 'event_category' => HsEvent::CATEGORY_HAZARD,
            'status' => HsEvent::STATUS_CORRECTIVE_ACTION, 'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]));

        return HsCorrectiveAction::withoutEvents(fn () => HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id, 'assigned_to_user_id' => $owner->id, 'status' => HsCorrectiveAction::STATUS_OPEN,
        ]));
    }

    private function workspace(HsCorrectiveAction $action, User $viewer): array
    {
        return app(HsCorrectiveActionWorkspace::class)->forViewer($action, $viewer->fresh());
    }

    public function test_start_waits_for_a_new_owner_when_the_owner_can_no_longer_own_the_action(): void
    {
        $manager = $this->staff(['hazards.view', 'hazards.manage']);
        $owner = $this->staff(['hazards.view', 'hazards.manage']);
        $action = $this->action($owner);
        $this->assertTrue($this->workspace($action, $manager)['can']['start']);

        $this->revoke($owner, 'hazards.manage');
        $data = $this->workspace($action, $manager);
        $this->assertSame('replacement_needed', $data['ownership']['state']);
        $this->assertFalse($data['can']['start']);
        $this->assertSame('Choose a new owner first', $data['blocked']['start']['reason']);
        $this->assertSame(['key' => 'choose_owner', 'can' => true], $data['next_step']);
    }

    public function test_a_read_only_viewer_gets_no_blocked_start_and_no_next_step_button(): void
    {
        $reader = $this->staff(['hazards.view']);
        $owner = $this->staff(['hazards.view']);
        $data = $this->workspace($this->action($owner), $reader);

        $this->assertFalse($data['can']['start']);
        $this->assertSame([], $data['blocked']);
        $this->assertFalse($data['next_step']['can']);
    }

    public function test_an_eligible_owner_can_be_started_as_before(): void
    {
        $manager = $this->staff(['hazards.view', 'hazards.manage']);
        $owner = $this->staff(['hazards.view', 'hazards.manage']);
        $action = $this->action($owner);

        $this->actingAs($manager);
        app(HsCorrectiveActionService::class)->start($action->fresh());
        $this->assertSame(HsCorrectiveAction::STATUS_IN_PROGRESS, $action->fresh()->status);
    }
}
