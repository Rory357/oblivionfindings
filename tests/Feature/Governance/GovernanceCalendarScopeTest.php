<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\Resolution;
use App\Models\Permission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceSyntheticFixtures;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceCalendarScopeTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected array $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = GovernanceSyntheticFixtures::seed();
    }

    protected function createExecutiveViewer(): User
    {
        $user = $this->createUserWithRole('board_member');
        $this->createBoardMember($user);

        $perm = Permission::firstOrCreate([
            'key' => 'governance.executive.view',
        ], [
            'description' => 'View Executive Session Meetings',
        ]);

        $user->permissionOverrides()->attach($perm->id, ['allowed' => true]);

        return $user;
    }

    public function test_calendar_requires_authentication(): void
    {
        $response = $this->get('/governance/calendar');
        $response->assertRedirect('/login');

        $feedResponse = $this->get('/governance/calendar/items?start=2026-09-01&end=2026-09-30');
        $feedResponse->assertRedirect('/login');
    }

    public function test_calendar_requires_governance_permission(): void
    {
        $userWithoutPerm = User::factory()->create([
            'approved_at' => now(),
            'role' => 'staff',
        ]);

        $response = $this->actingAs($userWithoutPerm)->get('/governance/calendar');
        $response->assertForbidden();

        $feedResponse = $this->actingAs($userWithoutPerm)->get('/governance/calendar/items?start=2026-09-01&end=2026-09-30');
        $feedResponse->assertForbidden();
    }

    public function test_calendar_renders_inertia_component_for_authorized_member(): void
    {
        $memberUser = User::find($this->fixtures['users']['member']);

        $response = $this->actingAs($memberUser)->get('/governance/calendar');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Calendar/Index')
            ->has('sources', 4)
            ->has('initialSources', 4)
            ->where('canCreate', false)
        );
    }

    public function test_calendar_items_feed_returns_normalized_calendar_items_in_range(): void
    {
        $memberUser = User::find($this->fixtures['users']['member']);
        $chairUser = User::find($this->fixtures['users']['chair']);

        $start = Carbon::parse('2026-10-01 00:00:00');
        $end = Carbon::parse('2026-10-31 23:59:59');

        // Meeting in range
        $inRangeMeeting = GovernanceMeeting::create([
            'title' => 'October Strategy Board Meeting',
            'meeting_type' => 'full_board',
            'scheduled_at' => '2026-10-15 10:00:00',
            'duration_minutes' => 120,
            'location' => 'Board Room & Zoom',
            'status' => 'scheduled',
            'created_by' => $chairUser->id,
        ]);

        // Meeting out of range
        $outOfRangeMeeting = GovernanceMeeting::create([
            'title' => 'November Audit Committee Meeting',
            'meeting_type' => 'audit_risk',
            'scheduled_at' => '2026-11-15 10:00:00',
            'duration_minutes' => 60,
            'location' => 'Meeting Room 2',
            'status' => 'scheduled',
            'created_by' => $chairUser->id,
        ]);

        // Resolution in range
        $resolution = $this->createResolution($chairUser, [
            'title' => 'Approve 2027 Capital Budget',
            'context' => 'Annual capital expenditure allocation',
            'decision_type' => 'major_transaction',
            'deadline' => '2026-10-20 17:00:00',
            'status' => 'voting_open',
        ]);

        // Compliance obligation in range
        $obligation = $this->createComplianceObligation($memberUser, [
            'obligation_title' => 'Annual Charities Services Return',
            'framework' => 'charities_commission',
            'due_date' => '2026-10-25',
            'status' => 'pending',
        ]);

        // Policy review in range
        $policy = GovernancePolicy::create([
            'title' => 'Code of Conduct Review',
            'policy_code' => 'POL-COC-01',
            'category' => 'governance',
            'content' => 'Full text of the Code of Conduct policy.',
            'status' => 'active',
            'next_review_date' => '2026-10-28',
            'owner_id' => $chairUser->id,
            'created_by' => $chairUser->id,
        ]);

        $response = $this->actingAs($memberUser)->getJson(
            '/governance/calendar/items?start=' . urlencode($start->toIso8601String()) . '&end=' . urlencode($end->toIso8601String())
        );

        $response->assertOk();
        $data = $response->json();

        $this->assertArrayHasKey('events', $data);
        $this->assertArrayHasKey('totals', $data);

        $events = collect($data['events']);

        // In-range items present
        $this->assertTrue($events->contains('id', "governance:meeting:{$inRangeMeeting->id}"));
        $this->assertTrue($events->contains('id', "governance:decision:{$resolution->id}"));
        $this->assertTrue($events->contains('id', "governance:obligation:{$obligation->id}"));
        $this->assertTrue($events->contains('id', "governance:policy:{$policy->id}"));

        // Out-of-range item absent
        $this->assertFalse($events->contains('id', "governance:meeting:{$outOfRangeMeeting->id}"));

        // Check normalization properties
        $meetingEvent = $events->firstWhere('id', "governance:meeting:{$inRangeMeeting->id}");
        $this->assertEquals('meetings', $meetingEvent['source']);
        $this->assertFalse($meetingEvent['allDay']);
        $this->assertFalse($meetingEvent['editable']);
        $this->assertNull($meetingEvent['site']);
        $this->assertEquals("/governance/meetings/{$inRangeMeeting->id}", $meetingEvent['link']);

        $resEvent = $events->firstWhere('id', "governance:decision:{$resolution->id}");
        $this->assertEquals('decisions', $resEvent['source']);
        $this->assertFalse($resEvent['allDay']);
        $this->assertStringContainsString('17:00:00', $resEvent['start']);
        $this->assertFalse($resEvent['editable']);
        $this->assertNull($resEvent['site']);

        $oblEvent = $events->firstWhere('id', "governance:obligation:{$obligation->id}");
        $this->assertEquals('obligations', $oblEvent['source']);
        $this->assertTrue($oblEvent['allDay']);
        $this->assertTrue($oblEvent['mine']);

        // Totals checks
        $this->assertEquals(4, $data['totals']['total']);
        $this->assertEquals(1, $data['totals']['meetings']);
        $this->assertEquals(1, $data['totals']['decisions']);
        $this->assertEquals(1, $data['totals']['obligations']);
        $this->assertEquals(1, $data['totals']['policies']);
    }

    public function test_calendar_items_feed_filters_by_sources(): void
    {
        $memberUser = User::find($this->fixtures['users']['member']);
        $chairUser = User::find($this->fixtures['users']['chair']);

        $start = Carbon::parse('2026-10-01 00:00:00');
        $end = Carbon::parse('2026-10-31 23:59:59');

        $meeting = GovernanceMeeting::create([
            'title' => 'October Strategy Board Meeting',
            'meeting_type' => 'full_board',
            'scheduled_at' => '2026-10-15 10:00:00',
            'duration_minutes' => 120,
            'status' => 'scheduled',
            'created_by' => $chairUser->id,
        ]);

        $resolution = $this->createResolution($chairUser, [
            'title' => 'Approve 2027 Capital Budget',
            'context' => 'Annual capital expenditure allocation',
            'decision_type' => 'major_transaction',
            'deadline' => '2026-10-20 17:00:00',
            'status' => 'voting_open',
        ]);

        $response = $this->actingAs($memberUser)->getJson(
            '/governance/calendar/items?start=' . urlencode($start->toIso8601String()) . '&end=' . urlencode($end->toIso8601String()) . '&sources=meetings'
        );

        $response->assertOk();
        $events = collect($response->json('events'));

        $this->assertTrue($events->contains('id', "governance:meeting:{$meeting->id}"));
        $this->assertFalse($events->contains('id', "governance:decision:{$resolution->id}"));
        $this->assertCount(1, $events);
    }

    public function test_calendar_items_feed_enforces_executive_meeting_visibility(): void
    {
        $chairUser = User::find($this->fixtures['users']['chair']);
        $nonExec = User::find($this->fixtures['users']['member']);
        $execViewer = $this->createExecutiveViewer();

        $start = Carbon::parse('2026-10-01 00:00:00');
        $end = Carbon::parse('2026-10-31 23:59:59');

        $execMeeting = GovernanceMeeting::create([
            'title' => 'Confidential CEO Performance Review Session',
            'meeting_type' => 'executive_session',
            'scheduled_at' => '2026-10-18 14:00:00',
            'duration_minutes' => 90,
            'status' => 'scheduled',
            'created_by' => $chairUser->id,
        ]);

        // Non-executive member cannot see executive session in feed
        $nonExecResponse = $this->actingAs($nonExec)->getJson(
            '/governance/calendar/items?start=' . urlencode($start->toIso8601String()) . '&end=' . urlencode($end->toIso8601String())
        );
        $nonExecResponse->assertOk();
        $nonExecEvents = collect($nonExecResponse->json('events'));
        $this->assertFalse($nonExecEvents->contains('id', "governance:meeting:{$execMeeting->id}"));

        // Executive viewer sees executive session in feed
        $execResponse = $this->actingAs($execViewer)->getJson(
            '/governance/calendar/items?start=' . urlencode($start->toIso8601String()) . '&end=' . urlencode($end->toIso8601String())
        );
        $execResponse->assertOk();
        $execEvents = collect($execResponse->json('events'));
        $this->assertTrue($execEvents->contains('id', "governance:meeting:{$execMeeting->id}"));
    }

    public function test_calendar_items_feed_filters_by_committee(): void
    {
        $chairUser = User::find($this->fixtures['users']['chair']);
        $memberUser = User::find($this->fixtures['users']['member']);

        $committeeA = BoardCommittee::where('committee_type', 'audit_risk')->first()
            ?? BoardCommittee::create([
                'committee_type' => 'audit_risk',
                'name' => 'Audit & Risk Committee',
                'description' => 'Audit oversight',
                'is_active' => true,
            ]);

        $committeeB = BoardCommittee::where('committee_type', 'finance')->first()
            ?? BoardCommittee::create([
                'committee_type' => 'finance',
                'name' => 'Finance Committee',
                'description' => 'Financial oversight',
                'is_active' => true,
            ]);

        $start = Carbon::parse('2026-10-01 00:00:00');
        $end = Carbon::parse('2026-10-31 23:59:59');

        $meetingA = GovernanceMeeting::create([
            'title' => 'Audit Q3 Review',
            'meeting_type' => 'audit_risk',
            'board_committee_id' => $committeeA->id,
            'scheduled_at' => '2026-10-10 10:00:00',
            'status' => 'scheduled',
            'created_by' => $chairUser->id,
        ]);

        $meetingB = GovernanceMeeting::create([
            'title' => 'Remuneration Strategy Session',
            'meeting_type' => 'people',
            'board_committee_id' => $committeeB->id,
            'scheduled_at' => '2026-10-12 14:00:00',
            'status' => 'scheduled',
            'created_by' => $chairUser->id,
        ]);

        $response = $this->actingAs($memberUser)->getJson(
            '/governance/calendar/items?start=' . urlencode($start->toIso8601String()) . '&end=' . urlencode($end->toIso8601String()) . '&committee_id=' . $committeeA->id
        );

        $response->assertOk();
        $events = collect($response->json('events'));

        $this->assertTrue($events->contains('id', "governance:meeting:{$meetingA->id}"));
        $this->assertFalse($events->contains('id', "governance:meeting:{$meetingB->id}"));
    }

    /**
     * Requirements and policy reviews are only offered to viewers who can
     * open those registers — no entry links to a page that returns 403.
     */
    public function test_calendar_only_offers_sources_whose_registers_the_viewer_can_open(): void
    {
        $chairUser = User::find($this->fixtures['users']['chair']);
        $member = $this->createUserWithRole('board_member');
        $this->createBoardMember($member);
        foreach (['governance.compliance.view', 'governance.policies.view'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
            $member->permissionOverrides()->attach($permission->id, ['allowed' => false]);
        }

        $this->createComplianceObligation($chairUser, [
            'obligation_code' => 'SECRET-REQ',
            'obligation_title' => 'SECRET requirement',
            'due_date' => now()->addDays(2)->toDateString(),
        ]);
        GovernancePolicy::create([
            'title' => 'SECRET policy review',
            'policy_code' => 'POL-SECRET-CAL',
            'category' => 'privacy',
            'content' => 'Policy text.',
            'status' => 'approved',
            'next_review_date' => now()->addDays(2)->toDateString(),
            'owner_id' => $chairUser->id,
            'created_by' => $chairUser->id,
        ]);

        $this->actingAs($member)
            ->get('/governance/calendar')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Calendar/Index')
                ->has('sources', 2)
                ->where('sources.0.key', 'meetings')
                ->where('sources.1.key', 'decisions')
                ->where('initialSources', ['meetings', 'decisions'])
            );

        $feed = $this->actingAs($member)->getJson(
            '/governance/calendar/items?start='.urlencode(now()->subDays(5)->toIso8601String())
                .'&end='.urlencode(now()->addDays(10)->toIso8601String())
                .'&sources=obligations,policies,meetings'
        );

        $feed->assertOk();
        $sources = collect($feed->json('events'))->pluck('source')->unique()->values()->all();
        $this->assertNotContains('obligations', $sources);
        $this->assertNotContains('policies', $sources);
        $this->assertSame(0, $feed->json('totals.obligations'));
        $this->assertStringNotContainsString('SECRET', $feed->getContent());
    }

    public function test_calendar_entries_use_plain_labels_and_past_meetings_show_minutes_due(): void
    {
        $chairUser = User::find($this->fixtures['users']['chair']);
        $member = $this->createUserWithRole('board_member');
        $this->createBoardMember($member);

        $held = GovernanceMeeting::create([
            'title' => 'August board meeting',
            'meeting_type' => 'full_board',
            'scheduled_at' => now()->subDays(2),
            'duration_minutes' => 90,
            'status' => 'scheduled',
            'created_by' => $chairUser->id,
        ]);
        $resolution = $this->createResolution($chairUser, [
            'title' => 'Approve the new rosters',
            'decision_type' => 'budget_approval',
            'deadline' => now()->addDays(3)->setTime(17, 0),
            'status' => 'open',
        ]);
        $policy = GovernancePolicy::create([
            'title' => 'Privacy policy',
            'policy_code' => 'POL-PRIV-02',
            'category' => 'privacy',
            'content' => 'Policy text.',
            'status' => 'approved',
            'next_review_date' => now()->addDays(4)->toDateString(),
            'owner_id' => $chairUser->id,
            'created_by' => $chairUser->id,
        ]);

        $response = $this->actingAs($member)->getJson(
            '/governance/calendar/items?start='.urlencode(now()->subDays(10)->toIso8601String())
                .'&end='.urlencode(now()->addDays(10)->toIso8601String())
        );

        $response->assertOk();
        $events = collect($response->json('events'))->keyBy('id');

        $meeting = $events->get("governance:meeting:{$held->id}");
        $this->assertSame('pending', $meeting['status'], 'A held meeting is not overdue.');
        $this->assertSame('Minutes due', $meeting['statusLabel']);
        $this->assertSame('auto', $meeting['group']);
        $this->assertSame('Full board meeting', $meeting['typeLabel']);
        $this->assertNull($meeting['eventType']);
        // Invited members count the meeting as theirs.
        $this->assertTrue($meeting['mine']);
        $this->assertSame([$member->id], $meeting['attendeeIds']);

        $decision = $events->get("governance:decision:{$resolution->id}");
        $this->assertSame('Approve the new rosters', $decision['title']);
        $this->assertSame('Voting deadline · Budget approval', $decision['typeLabel']);
        $this->assertSame('Open for voting', $decision['statusLabel']);

        $review = $events->get("governance:policy:{$policy->id}");
        $this->assertSame('Privacy policy', $review['title']);
        $this->assertSame('Policy review · Privacy', $review['typeLabel']);

        $this->assertStringNotContainsString('[Vote deadline]', $response->getContent());
        $this->assertStringNotContainsString('[Policy review]', $response->getContent());
        $this->assertStringNotContainsString('full_board', $response->getContent());
    }

    public function test_calendar_items_feed_maps_terminal_and_cancelled_statuses_correctly(): void
    {
        $chairUser = User::find($this->fixtures['users']['chair']);
        $memberUser = User::find($this->fixtures['users']['member']);

        $start = Carbon::parse('2026-10-01 00:00:00');
        $end = Carbon::parse('2026-10-31 23:59:59');

        $implementedRes = $this->createResolution($chairUser, [
            'title' => 'Implemented Decision',
            'status' => 'implemented',
            'voting_threshold' => 'simple_majority',
            'decision_type' => 'resolution',
            'deadline' => '2026-10-10 14:00:00',
        ]);

        $cancelledRes = $this->createResolution($chairUser, [
            'title' => 'Cancelled Decision Past Deadline',
            'status' => 'cancelled',
            'voting_threshold' => 'simple_majority',
            'decision_type' => 'resolution',
            'deadline' => '2026-10-05 14:00:00',
        ]);

        $cancelledObl = $this->createComplianceObligation($chairUser, [
            'obligation_title' => 'Cancelled Health Audit',
            'framework' => 'health_safety',
            'status' => 'cancelled',
            'due_date' => '2026-10-04',
        ]);

        $response = $this->actingAs($memberUser)->getJson(
            '/governance/calendar/items?start=' . urlencode($start->toIso8601String()) . '&end=' . urlencode($end->toIso8601String())
        );

        $response->assertOk();
        $events = collect($response->json('events'));

        $impEvent = $events->firstWhere('id', "governance:decision:{$implementedRes->id}");
        $this->assertNotNull($impEvent);
        $this->assertEquals('completed', $impEvent['status']);

        $cancResEvent = $events->firstWhere('id', "governance:decision:{$cancelledRes->id}");
        $this->assertNotNull($cancResEvent);
        $this->assertEquals('cancelled', $cancResEvent['status']);

        $cancOblEvent = $events->firstWhere('id', "governance:obligation:{$cancelledObl->id}");
        $this->assertNotNull($cancOblEvent);
        $this->assertEquals('cancelled', $cancOblEvent['status']);
    }
}
