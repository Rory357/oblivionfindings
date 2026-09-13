<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\DashboardSnapshot;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\MeetingRsvp;
use App\Domain\Governance\Models\PerformanceReview;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\BoardPackAccessService;
use App\Domain\Governance\Services\GovernanceRecordAccessService;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceDerivedAudienceTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
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

    protected function createNonExecutiveViewer(): User
    {
        $user = $this->createUserWithRole('board_member');
        $this->createBoardMember($user);

        return $user;
    }

    public function test_ordinary_member_cannot_view_or_list_resolutions_from_executive_session(): void
    {
        $admin = $this->createAdminUser();
        $execMeeting = $this->createMeeting($admin, [
            'meeting_type' => 'executive_session',
            'title' => 'Secret Acquisition Session',
            'scheduled_at' => now()->addDays(2),
        ]);

        $publicMeeting = $this->createMeeting($admin, [
            'meeting_type' => 'full_board',
            'title' => 'Quarterly Public Board Meeting',
            'scheduled_at' => now()->addDays(5),
        ]);

        $execResolution = Resolution::create([
            'title' => 'Confidential Acquisition Approval',
            'context' => 'High sensitivity M&A decision',
            'options' => [],
            'status' => 'draft',
            'voting_threshold' => 'simple_majority',
            'governance_meeting_id' => $execMeeting->id,
            'proposed_by' => $admin->id,
            'proposed_at' => now(),
            'attachments' => [
                [
                    'id' => 'att-123',
                    'original_name' => 'confidential_terms.pdf',
                    'path' => 'governance/resolutions/confidential_terms.pdf',
                    'mime_type' => 'application/pdf',
                ],
            ],
        ]);

        $publicResolution = Resolution::create([
            'title' => 'Approve Annual Budget Plan',
            'context' => 'Public budget approval for the upcoming year',
            'options' => [],
            'status' => 'draft',
            'voting_threshold' => 'simple_majority',
            'governance_meeting_id' => $publicMeeting->id,
            'proposed_by' => $admin->id,
            'proposed_at' => now(),
        ]);

        $nonExec = $this->createNonExecutiveViewer();

        // 1. Index should NOT contain the executive resolution
        $response = $this->actingAs($nonExec)->get('/governance/resolutions');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Resolutions/Index')
            ->has('resolutions.data', 1)
            ->where('resolutions.data.0.id', $publicResolution->id)
            ->has('meetings', 1)
            ->where('meetings.0.id', $publicMeeting->id)
        );

        $response->assertDontSee('Confidential Acquisition Approval');
        $response->assertDontSee('Secret Acquisition Session');

        // 2. Direct show URL on executive resolution must be forbidden (403)
        $this->actingAs($nonExec)->get("/governance/resolutions/{$execResolution->id}")
            ->assertForbidden();

        // 3. Direct attachment download on executive resolution must be forbidden (403)
        $this->actingAs($nonExec)->get("/governance/resolutions/{$execResolution->id}/attachments/att-123/download")
            ->assertForbidden();

        // 4. Executive viewer can see the resolution
        $execViewer = $this->createExecutiveViewer();
        $this->actingAs($execViewer)->get("/governance/resolutions/{$execResolution->id}")
            ->assertOk();
    }

    public function test_dashboard_cockpit_and_workflow_conceal_executive_session_from_ordinary_member(): void
    {
        $admin = $this->createAdminUser();
        $execMeeting = $this->createMeeting($admin, [
            'meeting_type' => 'executive_session',
            'title' => 'Secret In-Camera Strategy Meeting',
            'scheduled_at' => now()->addDays(1),
        ]);

        $publicMeeting = $this->createMeeting($admin, [
            'meeting_type' => 'full_board',
            'title' => 'Open Board Meeting',
            'scheduled_at' => now()->addDays(4),
        ]);

        $nonExec = $this->createNonExecutiveViewer();

        $response = $this->actingAs($nonExec)->getJson('/governance/dashboard/data?period=month');
        $response->assertOk();

        $data = $response->json();

        // Next meeting should be the public meeting, NOT the executive session
        $this->assertEquals($publicMeeting->id, $data['cockpit']['next_meeting']['meeting']['id']);
        $this->assertEquals('Open Board Meeting', $data['cockpit']['next_meeting']['meeting']['title']);

        // KPI band should only count 1 upcoming meeting for this member
        $upcomingCard = collect($data['cockpit']['kpi_band'])->firstWhere('key', 'upcoming_meetings');
        $this->assertEquals('1', $upcomingCard['value']);

        // Calendar events must NOT leak executive session title
        $events = $data['cockpit']['calendar_events'];
        $this->assertFalse(collect($events)->contains(fn ($e) => ($e['title'] ?? '') === 'Secret In-Camera Strategy Meeting'));

        // Response string as a whole must NOT contain the secret title
        $response->assertDontSee('Secret In-Camera Strategy Meeting');

        // Executive viewer sees the executive meeting as next meeting
        $execViewer = $this->createExecutiveViewer();
        $execResponse = $this->actingAs($execViewer)->getJson('/governance/dashboard/data?period=month');
        $execResponse->assertOk();
        $this->assertEquals($execMeeting->id, $execResponse->json('cockpit.next_meeting.meeting.id'));
    }

    public function test_ordinary_member_cannot_view_raw_ceo_performance_review(): void
    {
        $admin = $this->createAdminUser();
        $ceoUser = $this->createUserWithRole('ceo');

        $review = PerformanceReview::create([
            'reviewee_id' => $ceoUser->id,
            'review_cycle' => now()->year . '-Annual',
            'review_type' => 'annual',
            'period_start' => now()->subYear(),
            'period_end' => now(),
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $nonExec = $this->createNonExecutiveViewer();

        // Ordinary member denied on index, show, edit
        $this->actingAs($nonExec)->get('/governance/performance')->assertForbidden();
        $this->actingAs($nonExec)->get("/governance/performance/{$review->id}")->assertForbidden();
        $this->actingAs($nonExec)->get("/governance/performance/{$review->id}/edit")->assertForbidden();

        // CEO can access own review
        $ceoIndex = $this->actingAs($ceoUser)->get('/governance/performance');
        $ceoIndex->assertOk();
        $ceoIndex->assertInertia(fn ($page) => $page
            ->component('Governance/Performance/Index')
            ->has('reviews.data', 1)
            ->where('reviews.data.0.id', $review->id)
        );

        $this->actingAs($ceoUser)->get("/governance/performance/{$review->id}")->assertOk();

        // CEO can submit own self-assessment
        $this->actingAs($ceoUser)->post("/governance/performance/{$review->id}/self-assessment", [
            'self_assessment' => 'Key achievements delivered this year across clinical and strategic goals.',
        ])->assertRedirect();

        $review->refresh();
        $this->assertEquals('Key achievements delivered this year across clinical and strategic goals.', $review->self_assessment);
        $this->assertEquals('board_review', $review->status);

        // Chair can access
        $chairUser = $this->createUserWithRole('board_chair');
        $this->createBoardMember($chairUser, ['board_role' => 'chair']);
        $this->actingAs($chairUser)->get("/governance/performance/{$review->id}")->assertOk();
    }

    public function test_action_items_from_executive_session_hidden_from_ordinary_member(): void
    {
        $admin = $this->createAdminUser();
        $execMeeting = $this->createMeeting($admin, [
            'meeting_type' => 'executive_session',
            'title' => 'Secret Session',
            'scheduled_at' => now()->addDays(2),
        ]);

        $publicMeeting = $this->createMeeting($admin, [
            'meeting_type' => 'full_board',
            'title' => 'Public Session',
            'scheduled_at' => now()->addDays(3),
        ]);

        $execAction = ActionItem::create([
            'source_type' => GovernanceMeeting::class,
            'source_id' => $execMeeting->id,
            'description' => 'Confidential personnel disciplinary action item',
            'assigned_to' => $admin->id,
            'due_date' => now()->addDays(5),
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $publicAction = ActionItem::create([
            'source_type' => GovernanceMeeting::class,
            'source_id' => $publicMeeting->id,
            'description' => 'Review public financial audit report',
            'assigned_to' => $admin->id,
            'due_date' => now()->addDays(5),
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $nonExec = $this->createNonExecutiveViewer();
        $accessService = app(GovernanceRecordAccessService::class);

        $this->assertFalse($accessService->canViewActionItem($nonExec, $execAction));
        $this->assertTrue($accessService->canViewActionItem($nonExec, $publicAction));

        // Even if assigned to nonExec, nonExec cannot view task from inaccessible executive meeting (GOV-R02)
        $execAction->update(['assigned_to' => $nonExec->id]);
        $this->assertFalse($accessService->canViewActionItem($nonExec, $execAction));
    }

    public function test_pack_manager_without_executive_authority_cannot_view_executive_session_pack(): void
    {
        Storage::fake('local');

        $admin = $this->createAdminUser();
        $execMeeting = $this->createMeeting($admin, [
            'meeting_type' => 'executive_session',
            'title' => 'Classified Board Meeting',
            'scheduled_at' => now()->addDays(2),
        ]);

        $snapshotData = ['period' => ['type' => 'month'], 'widgets' => []];
        $snapshot = DashboardSnapshot::create([
            'snapshot_data' => $snapshotData,
            'period_type' => 'month',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'checksum' => DashboardSnapshot::generateChecksum($snapshotData),
            'captured_at' => now(),
            'captured_by' => $admin->id,
        ]);

        $content = 'executive-pack-content';
        $path = 'board-packs/exec-pack.pdf';
        Storage::put($path, $content);

        $pack = BoardPack::create([
            'governance_meeting_id' => $execMeeting->id,
            'dashboard_snapshot_id' => $snapshot->id,
            'document_manifest' => [],
            'generated_at' => now(),
            'generated_by' => $admin->id,
            'file_path' => $path,
            'file_size' => strlen($content),
            'checksum' => hash('sha256', $content),
            'watermark_text' => 'CONFIDENTIAL',
            'distributed_at' => now(),
            'distributed_to' => [],
        ]);

        // Create a user with pack manage permission but NO executive session access
        $packManager = $this->createUserWithRole('board_secretary');
        $this->createBoardMember($packManager, ['board_role' => 'secretary']);

        $packAccess = app(BoardPackAccessService::class);

        // canManage is true
        $this->assertTrue($packAccess->canManage($packManager));

        // But canView on this pack is FALSE because meeting is executive session
        $this->assertFalse($packAccess->canView($packManager, $pack));

        // And visibleQuery does not include this pack
        $visiblePacks = $packAccess->visibleQuery($packManager)->pluck('id');
        $this->assertFalse($visiblePacks->contains($pack->id));

        // Direct show returns 404
        $this->actingAs($packManager)->get("/governance/packs/{$pack->id}")->assertNotFound();
    }

    public function test_revoked_grant_denies_access_immediately(): void
    {
        $admin = $this->createAdminUser();
        $committee = BoardCommittee::firstOrCreate([
            'committee_type' => 'executive',
        ], [
            'name' => 'Executive Committee',
            'meeting_frequency' => 'monthly',
            'is_active' => true,
        ]);

        $execMeeting = $this->createMeeting($admin, [
            'meeting_type' => 'executive_session',
            'board_committee_id' => $committee->id,
            'title' => 'Executive Committee Strategy',
            'scheduled_at' => now()->addDays(2),
        ]);

        $memberUser = $this->createNonExecutiveViewer();
        $member = $memberUser->boardMember;

        $committee->members()->attach($member->id, [
            'role' => 'member',
            'appointed_at' => now(),
            'is_active' => true,
        ]);

        // Active member can view
        $this->actingAs($memberUser)->get("/governance/meetings/{$execMeeting->id}")->assertOk();

        // Deactivate appointment
        $committee->members()->updateExistingPivot($member->id, ['is_active' => false]);

        // Immediately denied
        $this->actingAs($memberUser)->get("/governance/meetings/{$execMeeting->id}")->assertForbidden();
    }

    public function test_rsvp_existence_does_not_confer_executive_session_access(): void
    {
        $admin = $this->createAdminUser();
        $execMeeting = $this->createMeeting($admin, [
            'meeting_type' => 'executive_session',
            'title' => 'Restricted Executive Session',
            'scheduled_at' => now()->addDays(2),
        ]);

        $nonExec = $this->createNonExecutiveViewer();
        $member = $nonExec->boardMember;

        // RSVP record exists in database
        MeetingRsvp::create([
            'governance_meeting_id' => $execMeeting->id,
            'board_member_id' => $member->id,
            'response' => 'accepted',
            'responded_at' => now(),
        ]);

        // RSVP does NOT confer access; user must still be forbidden
        $this->actingAs($nonExec)->get("/governance/meetings/{$execMeeting->id}")
            ->assertForbidden();
    }
}
