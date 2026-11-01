<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Services\HealthSafety\HsCorrectiveActionWorkspace;
use InvalidArgumentException;

/**
 * K1a (H&S UI/UX plan B1.5/B1.6): while an action's owner needs replacing, Start is off with the reason "Choose a new
 * owner first" for people who could otherwise start it, and the next step is "choose a new owner".
 * Read-only viewers get no blocked clutter (IR3b). D1 (Stephan, 31 Oct): the server refuses a new start too, with the
 * same words; an exact retry of a start it already accepted still returns the first result.
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

    public function test_the_server_refuses_a_new_start_while_the_owner_needs_replacing(): void
    {
        $manager = $this->staff(['hazards.view', 'hazards.manage']);
        $owner = $this->staff(['hazards.view', 'hazards.manage']);
        $action = $this->action($owner);
        $this->revoke($owner, 'hazards.manage');

        $this->actingAs($manager);
        try {
            app(HsCorrectiveActionService::class)->start($action->fresh());
            $this->fail('A new start must wait for a new owner.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(HsCorrectiveActionService::START_NEEDS_NEW_OWNER, $e->getMessage());
        }
        $this->assertSame(HsCorrectiveAction::STATUS_OPEN, $action->fresh()->status);
    }

    public function test_an_exact_retry_of_an_accepted_start_returns_the_first_result_after_the_owner_drifts(): void
    {
        $manager = $this->staff(['hazards.view', 'hazards.manage']);
        $owner = $this->staff(['hazards.view', 'hazards.manage']);
        $action = $this->action($owner);
        $this->actingAs($manager);
        $first = app(HsCorrectiveActionService::class)->start($action->fresh(), $owner->id);
        $startedAt = $action->fresh()->updated_at;

        $this->revoke($owner, 'hazards.manage');
        $this->travel(1)->minutes();
        $retry = app(HsCorrectiveActionService::class)->start($action->fresh(), $owner->id);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(HsCorrectiveAction::STATUS_IN_PROGRESS, $action->fresh()->status);
        $this->assertEquals($startedAt, $action->fresh()->updated_at);
    }
}
