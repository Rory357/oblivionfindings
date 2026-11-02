<?php

namespace Tests\Feature\HealthSafety;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\User;
use App\Services\HealthSafety\HsInvestigationCorrectionService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * G3 pages (board M5, approved by Stephan 1 Nov 2026): the two closing rules added in G3a each have their way through on
 * the page. Rule 1 (an owner is needed) → the next step is "Choose the owner" with the button only for people the
 * owner route accepts. Rule 2 (a check that didn't work needs a revised action) → the next step is "Add a revised
 * action for …" naming the failed action. Plus the correction controls show only where the server would accept them.
 * Role permission sets are the live .com grants (as HsEventRecordAbilitiesTest).
 */
class HsReviewPageWaysThroughTest extends RestraintEventCommandTestCase
{
    private const LIVE = [
        'team_lead' => ['hazards.view', 'hazards.manage', 'healthSafety.events.close', 'healthSafety.closureExceptions.request'],
        'health_safety_officer' => ['hazards.view', 'hazards.manage', 'healthSafety.events.close', 'healthSafety.events.closeAny', 'healthSafety.closureExceptions.request'],
        'admin' => ['hazards.view', 'hazards.manage', 'healthSafety.events.close', 'healthSafety.events.closeAny', 'healthSafety.closureExceptions.request', 'healthSafety.closureExceptions.approve'],
        'compliance_lead' => ['hazards.view', 'healthSafety.closureExceptions.approve'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(HandleInertiaRequests::class);
    }

    private function as(string $role): User
    {
        return $this->staff(self::LIVE[$role]);
    }

    private function review(?User $owner, array $attributes = []): HsEvent
    {
        return HsEvent::withoutEvents(fn () => HsEvent::factory()->worksafeNotNotifiable($owner ?? $this->as('health_safety_officer'))->create([
            'event_category' => HsEvent::CATEGORY_HAZARD,
            'site_id' => $this->site->id, 'client_id' => null, 'severity' => 'low', 'status' => HsEvent::STATUS_CORRECTIVE_ACTION,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED, 'owner_user_id' => $owner?->id, 'investigation_required' => false,
            ...$attributes,
        ]));
    }

    private function detail(User $viewer, HsEvent $event): array
    {
        $detail = null;
        $this->actingAs($viewer)->get('/health-safety/events/'.$event->id)->assertOk()
            ->assertInertia(function (Assert $page) use (&$detail): void { $detail = $page->toArray()['props']['detail']; });

        return $detail;
    }

    public function test_rule_one_a_review_with_no_owner_leads_to_choose_the_owner_for_each_role_that_can_close(): void
    {
        $event = $this->review(null);

        foreach (['team_lead', 'health_safety_officer', 'admin'] as $role) {
            $detail = $this->detail($this->as($role), $event);
            $this->assertSame('choose_owner', $detail['next_step']['key'], $role);
            $this->assertTrue($detail['next_step']['can'], $role);
            $this->assertTrue($detail['can']['change_owner'], $role);
            $this->assertNotEmpty($detail['assignable_staff'], $role.' has people to choose from');
        }

        // A reader who can't manage health and safety is told who does it, with no button.
        $reader = $this->detail($this->as('compliance_lead'), $event);
        $this->assertSame('choose_owner', $reader['next_step']['key']);
        $this->assertFalse($reader['next_step']['can']);
        $this->assertFalse($reader['can']['change_owner']);
        $this->assertStringContainsString('chooses the owner', $reader['next_step']['who']);
    }

    public function test_choosing_the_owner_from_the_page_clears_rule_one(): void
    {
        $officer = $this->as('health_safety_officer');
        $owner = $this->as('health_safety_officer');
        $event = $this->review(null);

        $this->actingAs($officer)->post("/health-safety/events/{$event->id}/owner", ['owner_user_id' => $owner->id])
            ->assertSessionHas('success', 'Owner chosen.');

        $detail = $this->detail($officer, $event);
        $this->assertNotSame('choose_owner', $detail['next_step']['key']);
        $this->assertTrue(collect($detail['close_readiness']['requirements'])->firstWhere('key', 'has_owner')['complete']);
    }

    public function test_rule_two_a_failed_check_leads_to_add_a_revised_action_naming_it(): void
    {
        $owner = $this->as('health_safety_officer');
        $event = $this->review($owner);
        $failed = HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id, 'title' => 'Fit grips under every lounge rug', 'status' => HsCorrectiveAction::STATUS_VERIFIED,
            'effectiveness_confirmed' => false, 'verified_at' => now(), 'assigned_to_user_id' => $owner->id, 'created_by' => $owner->id,
        ]);

        foreach (['team_lead', 'health_safety_officer', 'admin'] as $role) {
            $detail = $this->detail($this->as($role), $event);
            $this->assertSame('revised_action', $detail['next_step']['key'], $role);
            $this->assertSame($failed->id, $detail['next_step']['action_id'], $role);
            $this->assertStringContainsString('Fit grips under every lounge rug', $detail['next_step']['label'], $role);
            $this->assertTrue($detail['next_step']['can'], $role);
            $this->assertTrue(collect($detail['corrective_actions'])->firstWhere('id', $failed->id)['needs_revision'], $role);
        }

        // Adding the revised action through the page's form clears the step; the revision says what it replaces.
        $this->actingAs($owner)->post("/health-safety/events/{$event->id}/corrective-actions", [
            'title' => 'Replace the lounge rug with a non-slip rug', 'priority' => 'medium', 'action_type' => 'corrective',
            'assigned_to_user_id' => $owner->id, 'due_date' => now()->addWeek()->toDateString(), 'replaces_action_id' => $failed->id,
        ])->assertSessionHasNoErrors();
        $detail = $this->detail($owner, $event);
        $this->assertSame('actions', $detail['next_step']['key']);
        $actions = collect($detail['corrective_actions']);
        $this->assertFalse($actions->firstWhere('id', $failed->id)['needs_revision']);
        $this->assertSame($failed->id, $actions->firstWhere('title', 'Replace the lounge rug with a non-slip rug')['replaces']['id']);
    }

    public function test_correction_controls_follow_independence_and_findings_show_the_approved_correction(): void
    {
        $owner = $this->as('health_safety_officer');
        $lead = $this->as('health_safety_officer');
        $proposer = $this->as('health_safety_officer');
        $reviewer = $this->as('health_safety_officer');
        $approver = $this->as('admin');
        $event = $this->review($owner);
        $inv = HsInvestigation::factory()->completed()->create([
            'hs_event_id' => $event->id, 'findings_summary' => 'The rug edge lifts at the doorway.',
            'lead_investigator_id' => $lead->id, 'submitted_by_id' => $lead->id,
        ]);
        $this->assertTrue(collect($this->detail($proposer, $event)['investigations'])->firstWhere('id', $inv->id)['can_propose_correction']);

        app(HsInvestigationCorrectionService::class)->propose($event, $inv, $proposer, 'The rug edge lifts and it has no grip underneath.', 'The check found the rug has no grip underneath.');

        $forProposer = collect($this->detail($proposer, $event)['investigations'])->firstWhere('id', $inv->id);
        $this->assertFalse($forProposer['can_propose_correction'], 'One correction at a time.');
        $this->assertSame('proposed', $forProposer['correction']['status']);
        $this->assertFalse($forProposer['correction']['can_review'], 'The proposer never reviews it.');
        $this->assertFalse(collect($this->detail($lead, $event)['investigations'])->firstWhere('id', $inv->id)['correction']['can_review'], 'Nor the investigator.');
        $this->assertTrue(collect($this->detail($reviewer, $event)['investigations'])->firstWhere('id', $inv->id)['correction']['can_review']);
        $this->assertFalse(collect($this->detail($this->as('compliance_lead'), $event)['investigations'])->firstWhere('id', $inv->id)['correction']['can_review'], 'Readers who can\'t manage H&S never decide.');
        $this->assertSame('The rug edge lifts at the doorway.', $forProposer['findings_summary'], 'The original stands until approval.');

        $correction = $inv->corrections()->sole();
        $this->actingAs($reviewer)->post("/health-safety/events/{$event->id}/corrections/{$correction->id}/review")->assertSessionHas('success');
        $this->assertFalse(collect($this->detail($reviewer, $event)['investigations'])->firstWhere('id', $inv->id)['correction']['can_approve'], 'The reviewer never approves.');
        $this->assertTrue(collect($this->detail($approver, $event)['investigations'])->firstWhere('id', $inv->id)['correction']['can_approve']);
        $this->actingAs($approver)->post("/health-safety/events/{$event->id}/corrections/{$correction->id}/approve")->assertSessionHas('success');

        $after = collect($this->detail($owner, $event)['investigations'])->firstWhere('id', $inv->id);
        $this->assertSame('The rug edge lifts and it has no grip underneath.', $after['findings_summary']);
        $this->assertNull($after['correction']);
        $this->assertTrue($after['can_propose_correction']);
    }
}
