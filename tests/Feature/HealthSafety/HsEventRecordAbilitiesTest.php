<?php

namespace Tests\Feature\HealthSafety;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\HsClosureException;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\HealthSafety\HsEventClosureService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * K1a (H&S UI/UX plan B1.5): the safety review page offers Close, "Close anyway" and the request decisions only where
 * the server would accept them for this viewer, and says who can close otherwise. Role permission sets are the live
 * .com grants (31 Oct 2026), not the seeder (PLAN: per-role tests run on the live grants; Q12).
 */
class HsEventRecordAbilitiesTest extends RestraintEventCommandTestCase
{
    /** Live .com role grants for the closure keys (read 31 Oct 2026). */
    private const LIVE = [
        'team_lead' => ['hazards.view', 'hazards.manage', 'healthSafety.events.close', 'healthSafety.closureExceptions.request'],
        'health_safety_officer' => ['hazards.view', 'hazards.manage', 'healthSafety.events.close', 'healthSafety.events.closeAny', 'healthSafety.closureExceptions.request'],
        'admin' => ['hazards.view', 'hazards.manage', 'healthSafety.events.close', 'healthSafety.events.closeAny', 'healthSafety.closureExceptions.request', 'healthSafety.closureExceptions.approve'],
        'provider_manager' => ['hazards.view'],
        'compliance_lead' => ['hazards.view', 'healthSafety.closureExceptions.approve'],
    ];

    private const AUTHORITY_REFUSAL = 'Only the recorded H&S owner or an explicitly authorised H&S lead can close this event.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(HandleInertiaRequests::class);
    }

    private function as(string $role): User
    {
        return $this->staff(self::LIVE[$role]);
    }

    private function review(?User $owner): HsEvent
    {
        return HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
            'event_category' => HsEvent::CATEGORY_HAZARD,
            'site_id' => $this->site->id, 'client_id' => null, 'severity' => 'low', 'status' => HsEvent::STATUS_OPEN,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED, 'owner_user_id' => $owner?->id,
        ]));
    }

    /** A review with an open investigation (the exceptional blocker) and a real close-anyway request through the service. */
    private function askedToCloseAnyway(User $asker): array
    {
        $event = HsEvent::factory()->high()->worksafeNotNotifiable($asker)->create([
            'site_id' => $this->site->id, 'owner_user_id' => $asker->id, 'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $request = app(HsEventClosureService::class)->requestException($event, $asker, [
            'category' => HsClosureException::CATEGORY_INVESTIGATION_RECORD,
            'reason' => 'External evidence is pending but the record needs a time-limited decision.',
            'evidence_reference' => 'HS-BOARD-2026-0041', 'scope' => ['hs_investigation'],
            'review_at' => now()->addDays(5)->toIso8601String(), 'expires_at' => now()->addDays(10)->toIso8601String(),
        ]);

        return [$event, $request];
    }

    private function detail(User $viewer, HsEvent $event): array
    {
        $detail = null;
        $this->actingAs($viewer)->get('/health-safety/events/'.$event->id)->assertOk()
            ->assertInertia(function (Assert $page) use (&$detail): void { $detail = $page->toArray()['props']['detail']; });

        return $detail;
    }

    /** "No POST from a disabled control": the server refuses exactly where the page left Close out. */
    private function assertCloseParity(User $viewer, HsEvent $event, bool $shown): void
    {
        $response = $this->actingAs($viewer)->post('/health-safety/events/'.$event->id.'/close', ['closure_summary' => 'Checked on site; nothing left to do.']);
        $error = session('error');
        if (! $shown) {
            $this->assertTrue($response->isForbidden() || $error === self::AUTHORITY_REFUSAL, 'A hidden Close must be refused by the server.');
            $this->assertSame(HsEvent::STATUS_OPEN, $event->fresh()->status);
        } else {
            $this->assertNotSame(self::AUTHORITY_REFUSAL, $error, 'A shown Close must not be refused for authority.');
        }
    }

    public function test_a_team_lead_who_does_not_own_the_review_gets_no_close_and_is_told_who_closes(): void
    {
        $owner = $this->as('health_safety_officer');
        $owner->forceFill(['name' => 'Jordan Peri'])->save();
        $lead = $this->as('team_lead');
        $event = $this->review($owner);

        $detail = $this->detail($lead, $event);
        $this->assertFalse($detail['can']['close']);
        $this->assertFalse($detail['can']['request_closure_exception']);
        $this->assertSame('owner_or_close_any', $detail['close_authority']['who']);
        $this->assertStringContainsString('Jordan Peri, the owner', $detail['close_authority']['text']);
        $this->assertCloseParity($lead, $event, false);
    }

    public function test_the_owner_team_lead_and_closers_of_any_review_can_close(): void
    {
        $lead = $this->as('team_lead');
        $event = $this->review($lead);
        $this->assertTrue($this->detail($lead, $event)['can']['close']);
        $this->assertCloseParity($lead, $this->review($lead), true);

        foreach (['health_safety_officer', 'admin'] as $role) {
            $viewer = $this->as($role);
            $review = $this->review($lead);
            $why = app(HsEventClosureService::class)->closeAuthority($viewer->fresh(), $review)['reason'];
            $this->assertTrue($this->detail($viewer, $review)['can']['close'], $role.': '.($why ?? 'service allows'));
        }
    }

    public function test_a_review_with_no_owner_can_be_closed_by_anyone_who_closes_at_that_site_and_says_so(): void
    {
        $lead = $this->as('team_lead');
        $event = $this->review(null);

        $detail = $this->detail($lead, $event);
        $this->assertTrue($detail['can']['close']);
        $this->assertSame('anyone_at_site', $detail['close_authority']['who']);
        $this->assertStringContainsString('No owner yet', $detail['close_authority']['text']);
        $this->assertStringContainsString($this->site->name, $detail['close_authority']['text']);
    }

    public function test_a_provider_manager_cannot_close_or_ask_and_the_server_agrees(): void
    {
        $manager = $this->as('provider_manager');
        $event = $this->review(null);

        $detail = $this->detail($manager, $event);
        $this->assertFalse($detail['can']['close']);
        $this->assertFalse($detail['can']['request_closure_exception']);
        $this->assertCloseParity($manager, $event, false);
    }

    public function test_the_asker_cannot_decide_their_own_close_anyway_request_but_another_approver_can(): void
    {
        $approver = $this->as('compliance_lead');
        $admin = $this->as('admin');
        [$event, $own] = $this->askedToCloseAnyway($admin);

        $forAsker = collect($this->detail($admin, $event)['closure_exceptions'])->firstWhere('id', $own->id);
        $this->assertFalse($forAsker['can_decide']);
        $this->assertSame('You asked for this, so someone else decides it.', $forAsker['decide_blocked_reason']);

        $forApprover = collect($this->detail($approver, $event)['closure_exceptions'])->firstWhere('id', $own->id);
        $this->assertTrue($forApprover['can_decide']);
        $this->assertNull($forApprover['decide_blocked_reason']);
        $this->assertFalse($forApprover['can_revoke']);
    }

    public function test_an_expired_request_cannot_be_decided(): void
    {
        $approver = $this->as('compliance_lead');
        $admin = $this->as('admin');
        [$event, $expired] = $this->askedToCloseAnyway($admin);
        $this->travel(11)->days();

        $entry = collect($this->detail($approver, $event)['closure_exceptions'])->firstWhere('id', $expired->id);
        $this->assertFalse($entry['can_decide']);
        $this->assertSame('This request is no longer current.', $entry['decide_blocked_reason']);
    }
}
