<?php

namespace Tests\Feature\HealthSafety;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * G3 board M6 (approved by Stephan 1 Nov 2026): each safety review row says where it's up to and offers its one next
 * step — the same order as the review page — or says who does it; "Blocked" (decision 17) lists reviews that can't move
 * until someone chooses an owner, with the reason worked out for the viewer. Live .com grants.
 */
class HsReviewListProgressTest extends RestraintEventCommandTestCase
{
    private const MANAGER = ['hazards.view', 'hazards.manage', 'healthSafety.events.close', 'healthSafety.events.closeAny'];

    private const READER = ['hazards.view', 'healthSafety.closureExceptions.approve'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(HandleInertiaRequests::class);
    }

    private function review(?User $owner, array $attributes = []): HsEvent
    {
        return HsEvent::withoutEvents(fn () => HsEvent::factory()->worksafeNotNotifiable($owner ?? $this->staff(self::MANAGER))->create([
            'event_category' => HsEvent::CATEGORY_HAZARD, 'site_id' => $this->site->id, 'client_id' => null, 'severity' => 'medium',
            'status' => HsEvent::STATUS_CORRECTIVE_ACTION, 'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'owner_user_id' => $owner?->id, 'investigation_required' => false, ...$attributes,
        ]));
    }

    /** @return array{rows: array<int, array>, counts: array<string, int>} */
    private function list(User $viewer, string $tab = 'all'): array
    {
        $out = null;
        $this->actingAs($viewer)->get('/health-safety/events?tab='.$tab)->assertOk()
            ->assertInertia(function (Assert $page) use (&$out): void {
                $props = $page->toArray()['props'];
                $out = ['rows' => collect($props['events']['data'])->keyBy('id')->all(), 'counts' => $props['tabCounts']];
            });

        return $out;
    }

    public function test_each_row_says_where_it_is_and_offers_its_next_step(): void
    {
        $manager = $this->staff(self::MANAGER);
        $owner = $this->staff(self::MANAGER);
        $noOwner = $this->review(null);
        $failed = $this->review($owner);
        HsCorrectiveAction::factory()->create([
            'hs_event_id' => $failed->id, 'status' => HsCorrectiveAction::STATUS_VERIFIED, 'effectiveness_confirmed' => false,
            'verified_at' => now(), 'assigned_to_user_id' => $owner->id, 'created_by' => $owner->id,
        ]);

        $rows = $this->list($manager)['rows'];
        $this->assertSame(['choose_owner', true, "/health-safety/events/{$noOwner->id}?action=choose-owner"],
            [$rows[$noOwner->id]['next']['key'], $rows[$noOwner->id]['next']['can'], $rows[$noOwner->id]['next']['href']]);
        $this->assertSame('Owner still needed', $rows[$noOwner->id]['summary']);
        $this->assertSame('revised_action', $rows[$failed->id]['next']['key']);
        $this->assertStringContainsString('revised action needed', $rows[$failed->id]['summary']);

        // A reader who can't act is told who does — no button.
        $reader = $this->list($this->staff(self::READER))['rows'];
        $this->assertFalse($reader[$noOwner->id]['next']['can']);
        $this->assertStringContainsString('chooses the owner', $reader[$noOwner->id]['next']['who']);
    }

    public function test_blocked_lists_reviews_waiting_for_an_owner_with_the_reason_for_the_viewer(): void
    {
        $owner = $this->staff(self::MANAGER);
        $stuck = $this->review(null);
        $this->review($owner);
        $this->review(null, ['handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE]);

        $forManager = $this->list($this->staff(self::MANAGER), 'blocked');
        $this->assertSame(1, $forManager['counts']['blocked']);
        $this->assertSame([$stuck->id], array_keys($forManager['rows']));
        $this->assertSame('Owner still needed — choose one', $forManager['rows'][$stuck->id]['blocked']);

        $forReader = $this->list($this->staff(self::READER), 'blocked');
        $this->assertStringContainsString('a health and safety manager at', $forReader['rows'][$stuck->id]['blocked']);
    }
}
