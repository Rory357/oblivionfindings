<?php

namespace Tests\Feature\HealthSafety;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\HsClosureException;
use App\Models\HsEvent;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\HealthSafety\HsEventClosureService;
use App\Services\Tasks\Providers\HsClosureExceptionProvider;
use Illuminate\Support\Facades\Notification;

/**
 * Board M11 (G3, approved by Stephan 1 Nov 2026; K6): a "close anyway" request no longer waits unseen. Everyone who
 * may decide it is told and finds it in All Tasks; the person who asked never is, and once it's decided it's gone.
 * Role permission sets are the live .com grants (as HsEventRecordAbilitiesTest).
 */
class HsClosureExceptionWorklistTest extends RestraintEventCommandTestCase
{
    private const LIVE = [
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

    /** @return array{0: HsEvent, 1: HsClosureException} */
    private function ask(User $asker): array
    {
        $event = HsEvent::factory()->high()->worksafeNotNotifiable($asker)->create([
            'site_id' => $this->site->id, 'owner_user_id' => $asker->id, 'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $request = app(HsEventClosureService::class)->requestException($event, $asker, [
            'category' => HsClosureException::CATEGORY_INVESTIGATION_RECORD,
            'reason' => 'Found on the monthly hoist check and replaced the same afternoon.',
            'evidence_reference' => 'Hoist check 28 Oct', 'scope' => ['hs_investigation'],
            'review_at' => now()->addDays(5)->toIso8601String(), 'expires_at' => now()->addDays(10)->toIso8601String(),
        ]);

        return [$event, $request];
    }

    public function test_everyone_who_may_decide_is_told_and_the_asker_is_not(): void
    {
        Notification::fake();
        $compliance = $this->as('compliance_lead');
        $otherAdmin = $this->as('admin');
        $officer = $this->as('health_safety_officer');
        $elsewhere = $this->staff(self::LIVE['compliance_lead'], \App\Models\Site::factory()->create(['is_active' => true]));
        $asker = $this->as('admin');

        $this->ask($asker);

        Notification::assertSentTo($compliance, AppEventNotification::class);
        Notification::assertSentTo($otherAdmin, AppEventNotification::class);
        Notification::assertNotSentTo($asker, AppEventNotification::class);
        Notification::assertNotSentTo($officer, AppEventNotification::class);
        Notification::assertNotSentTo($elsewhere, AppEventNotification::class);
    }

    public function test_the_request_is_in_all_tasks_for_deciders_until_it_is_decided(): void
    {
        $compliance = $this->as('compliance_lead');
        $asker = $this->as('admin');
        [$event, $request] = $this->ask($asker);

        $ids = fn (User $user) => array_map(fn ($task) => $task->id, (new HsClosureExceptionProvider)->authorizedTasks($user));
        $this->assertSame(['hs_closure_exception-'.$event->id], $ids($compliance));
        $this->assertSame([], $ids($asker), 'Nobody decides their own request.');
        $this->assertSame([], $ids($this->as('health_safety_officer')), 'Only people who approve see it.');

        app(HsEventClosureService::class)->decideException($event, $request, $compliance, 'approved', 'Found before harm and replaced the same day.');
        $this->assertSame([], $ids($compliance), 'Decided requests leave the list.');
    }

    public function test_the_overview_worklist_lists_requests_this_person_may_decide_and_never_their_own(): void
    {
        $compliance = $this->as('compliance_lead');
        $asker = $this->as('admin');
        [$event, $request] = $this->ask($asker);

        $rows = $this->actingAs($compliance)->getJson('/health-safety/events/close-anyway-requests')->assertOk()->json('requests');
        $this->assertCount(1, $rows);
        $this->assertSame([$event->id, $request->id], [$rows[0]['review']['id'], $rows[0]['exception']['id']]);
        $this->assertTrue($rows[0]['exception']['can_decide']);
        $this->assertNotEmpty($rows[0]['review']['close_readiness']['requirements'], 'The Decide pop-up shows what is covered and what can’t be skipped.');

        $this->actingAs($asker)->getJson('/health-safety/events/close-anyway-requests')->assertOk()->assertJsonPath('requests', []);
        $this->actingAs($this->as('health_safety_officer'))->getJson('/health-safety/events/close-anyway-requests')->assertForbidden();

        app(HsEventClosureService::class)->decideException($event, $request, $compliance, 'approved', 'Found before harm and replaced the same day.');
        $this->actingAs($compliance)->getJson('/health-safety/events/close-anyway-requests')->assertOk()->assertJsonPath('requests', []);
    }
}
