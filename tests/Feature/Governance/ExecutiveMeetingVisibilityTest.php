<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\MeetingAgendaItem;
use App\Domain\Governance\Models\MeetingAttendance;
use App\Domain\Governance\Models\MeetingMinute;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class ExecutiveMeetingVisibilityTest extends TestCase
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

    protected function createExecutiveMeeting(User $creator, array $overrides = []): GovernanceMeeting
    {
        return $this->createMeeting($creator, array_merge([
            'meeting_type' => 'executive_session',
            'title' => 'Confidential Executive Session',
            'scheduled_at' => now()->addDays(2),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'quorum_required' => 50,
        ], $overrides));
    }

    public function test_non_executive_viewer_cannot_view_executive_session_meeting(): void
    {
        $admin = $this->createAdminUser();
        $execMeeting = $this->createExecutiveMeeting($admin);

        $nonExec = $this->createNonExecutiveViewer();
        $response = $this->actingAs($nonExec)->get("/governance/meetings/{$execMeeting->id}");
        $response->assertForbidden();

        $execViewer = $this->createExecutiveViewer();
        $allowedResponse = $this->actingAs($execViewer)->get("/governance/meetings/{$execMeeting->id}");
        $allowedResponse->assertOk();
    }

    public function test_non_executive_viewer_does_not_see_executive_session_in_index_or_calendar(): void
    {
        $admin = $this->createAdminUser();
        $execMeeting = $this->createExecutiveMeeting($admin, ['title' => 'Secret Exec Meeting']);
        $publicMeeting = $this->createMeeting($admin, [
            'meeting_type' => 'full_board',
            'title' => 'Public Board Meeting',
            'scheduled_at' => now()->addDays(3),
        ]);

        $nonExec = $this->createNonExecutiveViewer();

        // Index check
        $indexResponse = $this->actingAs($nonExec)->get('/governance/meetings');
        $indexResponse->assertOk();
        $indexResponse->assertInertia(fn ($page) => $page
            ->component('Governance/Meetings/Index')
            ->has('meetings.data', 1)
            ->where('meetings.data.0.id', $publicMeeting->id)
        );

        // Calendar check
        $calendarResponse = $this->actingAs($nonExec)->get('/governance/meetings/calendar');
        $calendarResponse->assertOk();
        $calendarResponse->assertInertia(fn ($page) => $page
            ->component('Governance/Meetings/Calendar')
            ->has('meetings', 1)
            ->where('meetings.0.id', $publicMeeting->id)
        );

        // Calendar check when filtering explicitly by executive_session
        $calendarExecFilter = $this->actingAs($nonExec)->get('/governance/meetings/calendar?meeting_type=executive_session');
        $calendarExecFilter->assertOk();
        $calendarExecFilter->assertInertia(fn ($page) => $page
            ->component('Governance/Meetings/Calendar')
            ->has('meetings', 0)
        );

        // Executive viewer sees both in index and calendar
        $execViewer = $this->createExecutiveViewer();
        $execIndex = $this->actingAs($execViewer)->get('/governance/meetings');
        $execIndex->assertOk();
        $execIndex->assertInertia(fn ($page) => $page
            ->has('meetings.data', 2)
        );

        $execCalendar = $this->actingAs($execViewer)->get('/governance/meetings/calendar');
        $execCalendar->assertOk();
        $execCalendar->assertInertia(fn ($page) => $page
            ->has('meetings', 2)
        );
    }

    public function test_non_executive_viewer_cannot_edit_update_or_delete_executive_session(): void
    {
        $admin = $this->createAdminUser();
        $execMeeting = $this->createExecutiveMeeting($admin);

        $nonExec = $this->createNonExecutiveViewer();

        $this->actingAs($nonExec)->get("/governance/meetings/{$execMeeting->id}/edit")
            ->assertForbidden();

        $this->actingAs($nonExec)->put("/governance/meetings/{$execMeeting->id}", [
            'title' => 'Tampered Title',
        ])->assertForbidden();

        $this->actingAs($nonExec)->delete("/governance/meetings/{$execMeeting->id}")
            ->assertForbidden();
    }

    public function test_non_executive_viewer_cannot_manage_minutes_of_executive_session(): void
    {
        $admin = $this->createAdminUser();
        $execMeeting = $this->createExecutiveMeeting($admin);
        $minute = MeetingMinute::create([
            'governance_meeting_id' => $execMeeting->id,
            'content_blocks' => [['type' => 'heading', 'content' => 'Confidential Minutes']],
            'status' => 'draft',
            'drafted_by' => $admin->id,
            'drafted_at' => now(),
        ]);

        $nonExec = $this->createNonExecutiveViewer();

        $this->actingAs($nonExec)->post("/governance/meetings/{$execMeeting->id}/minutes", [
            'content_blocks' => [['type' => 'text', 'content' => 'Tampered']],
        ])->assertForbidden();

        $this->actingAs($nonExec)->put("/governance/meetings/{$execMeeting->id}/minutes", [
            'content_blocks' => [['type' => 'text', 'content' => 'Updated Tampered']],
        ])->assertForbidden();

        $this->actingAs($nonExec)->post("/governance/meetings/{$execMeeting->id}/minutes/approve")
            ->assertForbidden();

        $this->actingAs($nonExec)->post("/governance/meetings/{$execMeeting->id}/sign-minutes")
            ->assertForbidden();
    }

    public function test_non_executive_viewer_cannot_manage_agendas_of_executive_session(): void
    {
        $admin = $this->createAdminUser();
        $execMeeting = $this->createExecutiveMeeting($admin);
        $item = MeetingAgendaItem::create([
            'governance_meeting_id' => $execMeeting->id,
            'order' => 1,
            'title' => 'Sensitive Item',
            'duration_minutes' => 15,
            'item_type' => 'standard',
            'is_confidential' => true,
        ]);

        $nonExec = $this->createNonExecutiveViewer();

        $this->actingAs($nonExec)->post("/governance/meetings/{$execMeeting->id}/agenda", [
            'title' => 'New Item',
            'duration_minutes' => 10,
            'item_type' => 'standard',
        ])->assertForbidden();

        $this->actingAs($nonExec)->put("/governance/meetings/{$execMeeting->id}/agenda/{$item->id}", [
            'title' => 'Updated Item',
        ])->assertForbidden();

        $this->actingAs($nonExec)->delete("/governance/meetings/{$execMeeting->id}/agenda/{$item->id}")
            ->assertForbidden();
    }

    public function test_non_executive_viewer_cannot_record_attendance_lock_or_rsvp_to_executive_session(): void
    {
        $admin = $this->createAdminUser();
        $execMeeting = $this->createExecutiveMeeting($admin);

        $nonExec = $this->createNonExecutiveViewer();

        $this->actingAs($nonExec)->post("/governance/meetings/{$execMeeting->id}/attendance", [
            'attendance' => [
                [
                    'board_member_id' => $nonExec->boardMember->id,
                    'status' => 'present',
                ],
            ],
        ])->assertForbidden();

        $this->actingAs($nonExec)->post("/governance/meetings/{$execMeeting->id}/lock")
            ->assertForbidden();

        $this->actingAs($nonExec)->post("/governance/meetings/{$execMeeting->id}/rsvp", [
            'status' => 'attending',
        ])->assertForbidden();

        $this->actingAs($nonExec)->post("/governance/meetings/{$execMeeting->id}/advance-status")
            ->assertForbidden();
    }

    public function test_designated_attendee_can_view_executive_session_meeting(): void
    {
        $admin = $this->createAdminUser();
        $execMeeting = $this->createExecutiveMeeting($admin);

        $attendeeUser = $this->createNonExecutiveViewer();
        $attendeeMember = $attendeeUser->boardMember;

        MeetingAttendance::create([
            'governance_meeting_id' => $execMeeting->id,
            'board_member_id' => $attendeeMember->id,
            'status' => 'present',
            'marked_at' => now(),
            'marked_by' => $admin->id,
        ]);

        $response = $this->actingAs($attendeeUser)->get("/governance/meetings/{$execMeeting->id}");
        $response->assertOk();

        $indexResponse = $this->actingAs($attendeeUser)->get('/governance/meetings');
        $indexResponse->assertOk();
        $indexResponse->assertInertia(fn ($page) => $page
            ->has('meetings.data', 1)
            ->where('meetings.data.0.id', $execMeeting->id)
        );
    }

    public function test_executive_committee_member_can_view_executive_session_meeting(): void
    {
        $admin = $this->createAdminUser();
        $execMeeting = $this->createExecutiveMeeting($admin);

        $memberUser = $this->createNonExecutiveViewer();
        $member = $memberUser->boardMember;

        $committee = BoardCommittee::firstOrCreate([
            'committee_type' => 'executive',
        ], [
            'name' => 'Executive Committee',
            'meeting_frequency' => 'monthly',
            'is_active' => true,
        ]);

        $committee->members()->attach($member->id, [
            'role' => 'member',
            'appointed_at' => now(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($memberUser)->get("/governance/meetings/{$execMeeting->id}");
        $response->assertOk();
    }

    public function test_sensitive_agenda_items_are_hidden_from_non_executive_viewers_on_regular_meetings(): void
    {
        $admin = $this->createAdminUser();
        $regularMeeting = $this->createMeeting($admin, [
            'meeting_type' => 'full_board',
            'title' => 'Quarterly General Meeting',
        ]);

        $publicItem = MeetingAgendaItem::create([
            'governance_meeting_id' => $regularMeeting->id,
            'order' => 1,
            'title' => 'Public Review',
            'duration_minutes' => 30,
            'item_type' => 'standard',
            'is_confidential' => false,
        ]);

        $confidentialItem = MeetingAgendaItem::create([
            'governance_meeting_id' => $regularMeeting->id,
            'order' => 2,
            'title' => 'Secret Acquisition',
            'duration_minutes' => 30,
            'item_type' => 'decision',
            'is_confidential' => true,
        ]);

        $nonExec = $this->createNonExecutiveViewer();
        $response = $this->actingAs($nonExec)->get("/governance/meetings/{$regularMeeting->id}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Meetings/Show')
            ->has('meeting.agenda_items', 1)
            ->where('meeting.agenda_items.0.id', $publicItem->id)
            ->where('meeting.agenda_items.0.title', 'Public Review')
        );

        $execViewer = $this->createExecutiveViewer();
        $execResponse = $this->actingAs($execViewer)->get("/governance/meetings/{$regularMeeting->id}");
        $execResponse->assertOk();
        $execResponse->assertInertia(fn ($page) => $page
            ->component('Governance/Meetings/Show')
            ->has('meeting.agenda_items', 2)
            ->where('meeting.agenda_items.0.id', $publicItem->id)
            ->where('meeting.agenda_items.1.id', $confidentialItem->id)
        );
    }

    public function test_non_executive_cannot_manage_confidential_agenda_item_on_regular_meeting(): void
    {
        $admin = $this->createAdminUser();
        $regularMeeting = $this->createMeeting($admin, [
            'meeting_type' => 'full_board',
            'title' => 'Quarterly General Meeting',
        ]);

        $confidentialItem = MeetingAgendaItem::create([
            'governance_meeting_id' => $regularMeeting->id,
            'order' => 1,
            'title' => 'Sensitive Item',
            'duration_minutes' => 20,
            'item_type' => 'decision',
            'is_confidential' => true,
        ]);

        $nonExec = $this->createNonExecutiveViewer();

        // Adding confidential item denied
        $this->actingAs($nonExec)->post("/governance/meetings/{$regularMeeting->id}/agenda", [
            'title' => 'New Confidential Item',
            'duration_minutes' => 15,
            'item_type' => 'standard',
            'is_confidential' => true,
        ])->assertForbidden();

        // Updating confidential item denied
        $this->actingAs($nonExec)->put("/governance/meetings/{$regularMeeting->id}/agenda/{$confidentialItem->id}", [
            'title' => 'Tampered Title',
        ])->assertForbidden();

        // Deleting confidential item denied
        $this->actingAs($nonExec)->delete("/governance/meetings/{$regularMeeting->id}/agenda/{$confidentialItem->id}")
            ->assertForbidden();
    }
}
