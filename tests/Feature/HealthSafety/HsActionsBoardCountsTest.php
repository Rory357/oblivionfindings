<?php

namespace Tests\Feature\HealthSafety;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * G3 board M7 (approved by Stephan 1 Nov 2026): the Actions board's four boxes count what the columns don't show —
 * checks that didn't work (with no revised action yet), due in the next 7 days, closed this month — and the failed-check
 * panel links each one to "Add a revised action" on its safety review, for people who manage H&S only.
 */
class HsActionsBoardCountsTest extends RestraintEventCommandTestCase
{
    private const MANAGER = ['hazards.view', 'hazards.manage'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(HandleInertiaRequests::class);
    }

    private function action(HsEvent $event, User $owner, array $attributes): HsCorrectiveAction
    {
        return HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id, 'assigned_to_user_id' => $owner->id, 'created_by' => $owner->id,
            'priority' => HsCorrectiveAction::PRIORITY_MEDIUM, ...$attributes,
        ]);
    }

    public function test_the_boxes_count_failed_checks_due_soon_and_closed_this_month(): void
    {
        $manager = $this->staff(self::MANAGER);
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['site_id' => $this->site->id, 'status' => HsEvent::STATUS_CORRECTIVE_ACTION]));
        $failed = $this->action($event, $manager, ['status' => HsCorrectiveAction::STATUS_VERIFIED, 'effectiveness_confirmed' => false, 'verified_at' => now()]);
        $revisedFailed = $this->action($event, $manager, ['status' => HsCorrectiveAction::STATUS_VERIFIED, 'effectiveness_confirmed' => false, 'verified_at' => now()]);
        $this->action($event, $manager, ['status' => HsCorrectiveAction::STATUS_OPEN, 'replaces_action_id' => $revisedFailed->id, 'due_date' => now()->addDays(3)->toDateString()]);
        $this->action($event, $manager, ['status' => HsCorrectiveAction::STATUS_IN_PROGRESS, 'due_date' => now()->addDays(20)->toDateString()]);
        $this->action($event, $manager, ['status' => HsCorrectiveAction::STATUS_CLOSED, 'effectiveness_confirmed' => true, 'closed_at' => now()]);

        $props = null;
        $this->actingAs($manager)->get('/health-safety/corrective-actions')->assertOk()
            ->assertInertia(function (Assert $page) use (&$props): void { $props = $page->toArray()['props']; });

        $this->assertSame(1, $props['hero']['attention']['failed'], 'A failed check that already has a revised action is not counted.');
        $this->assertSame(1, $props['hero']['attention']['due_soon']);
        $this->assertSame(1, $props['hero']['attention']['closed_month']);
        $this->assertSame([$failed->id], array_column($props['failedChecks'], 'id'));
        $this->assertSame("/health-safety/events/{$event->id}?action=ca-add&replaces={$failed->id}", $props['failedChecks'][0]['revise_href']);

        // A reader who doesn't manage H&S sees the panel without the button.
        $this->actingAs($this->staff(['hazards.view']))->get('/health-safety/corrective-actions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('failedChecks.0.revise_href', null));
    }
}
