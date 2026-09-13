<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\CommitteeMembership;
use App\Domain\Governance\Models\MeetingAgendaItem;
use App\Domain\Governance\Models\MeetingAttendance;
use App\Domain\Governance\Models\MeetingRsvp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceMeetingsTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_admin_can_create_meeting(): void
    {
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin, ['board_role' => 'chair']);

        $payload = [
            'meeting_type' => 'full_board',
            'title' => 'Board Meeting',
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            'duration_minutes' => 60,
            'chair_id' => $boardMember->id,
            'quorum_required' => 50,
        ];

        $response = $this->actingAs($admin)->post('/governance/meetings', $payload);

        $response->assertRedirect();
        $this->assertDatabaseHas('governance_meetings', [
            'title' => 'Board Meeting',
            'chair_id' => $boardMember->id,
        ]);
    }

    public function test_admin_can_view_edit_page(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);

        $response = $this->actingAs($admin)->get("/governance/meetings/{$meeting->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Meetings/Edit')
        );
    }

    public function test_admin_can_update_meeting(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);

        $response = $this->actingAs($admin)->put("/governance/meetings/{$meeting->id}", [
            'title' => 'Updated Meeting',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('governance_meetings', [
            'id' => $meeting->id,
            'title' => 'Updated Meeting',
        ]);
    }

    public function test_can_manage_agenda_items(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);

        $addResponse = $this->actingAs($admin)->post("/governance/meetings/{$meeting->id}/agenda", [
            'title' => 'Approve Budget',
            'description' => 'Discuss budget',
            'duration_minutes' => 30,
            'item_type' => 'decision',
            'is_confidential' => false,
        ]);

        $addResponse->assertRedirect();
        $this->assertDatabaseHas('meeting_agenda_items', [
            'governance_meeting_id' => $meeting->id,
            'title' => 'Approve Budget',
        ]);

        $item = MeetingAgendaItem::first();

        $updateResponse = $this->actingAs($admin)->put("/governance/meetings/{$meeting->id}/agenda/{$item->id}", [
            'title' => 'Approve Budget v2',
        ]);

        $updateResponse->assertRedirect();
        $this->assertDatabaseHas('meeting_agenda_items', [
            'id' => $item->id,
            'title' => 'Approve Budget v2',
        ]);

        $deleteResponse = $this->actingAs($admin)->delete("/governance/meetings/{$meeting->id}/agenda/{$item->id}");
        $deleteResponse->assertRedirect();
        $this->assertDatabaseMissing('meeting_agenda_items', [
            'id' => $item->id,
        ]);
    }

    public function test_can_manage_minutes(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);

        $storeResponse = $this->actingAs($admin)->post("/governance/meetings/{$meeting->id}/minutes", [
            'content_blocks' => [
                ['type' => 'text', 'content' => 'Minutes content'],
            ],
        ]);

        $storeResponse->assertRedirect();
        $this->assertDatabaseHas('meeting_minutes', [
            'governance_meeting_id' => $meeting->id,
            'status' => 'draft',
        ]);

        $updateResponse = $this->actingAs($admin)->put("/governance/meetings/{$meeting->id}/minutes", [
            'content_blocks' => [
                ['type' => 'text', 'content' => 'Updated minutes'],
            ],
        ]);

        $updateResponse->assertRedirect();

        $approveResponse = $this->actingAs($admin)->post("/governance/meetings/{$meeting->id}/minutes/approve", [
            'expected_version' => 2,
        ]);
        $approveResponse->assertRedirect();

        $this->assertDatabaseHas('meeting_minutes', [
            'governance_meeting_id' => $meeting->id,
            'status' => 'approved',
        ]);
    }

    public function test_can_record_attendance(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);
        $member = $this->createBoardMember($admin);

        $response = $this->actingAs($admin)->post("/governance/meetings/{$meeting->id}/attendance", [
            'attendance' => [
                [
                    'board_member_id' => $member->id,
                    'status' => 'present',
                    'apology_reason' => null,
                ],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('meeting_attendances', [
            'governance_meeting_id' => $meeting->id,
            'board_member_id' => $member->id,
            'status' => 'present',
        ]);

        $attendance = MeetingAttendance::first();
        $this->assertNotNull($attendance);
    }

    public function test_meeting_show_includes_workflow_checklist(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);

        $response = $this->actingAs($admin)->get("/governance/meetings/{$meeting->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Meetings/Show')
            ->has('workflowChecklist')
            ->has('workflowChecklist.items', 10)
            ->where('workflowChecklist.items.0.key', 'agenda')
        );
    }

    public function test_admin_can_view_meetings_calendar(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin, [
            'title' => 'Calendar Meeting',
            'meeting_type' => 'full_board',
            'scheduled_at' => now()->addDays(2),
        ]);

        $response = $this->actingAs($admin)->get('/governance/meetings/calendar');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Meetings/Calendar')
            ->where('month', now()->format('Y-m'))
            ->where('selectedMeetingType', 'all')
            ->where('meetings.0.id', $meeting->id)
            ->where('meetings.0.title', 'Calendar Meeting')
        );
    }

    public function test_calendar_filters_by_meeting_type(): void
    {
        $admin = $this->createAdminUser();
        $month = now()->format('Y-m');

        $this->createMeeting($admin, [
            'title' => 'Full Board Meeting',
            'meeting_type' => 'full_board',
            'scheduled_at' => now()->addDays(3),
        ]);

        $financeMeeting = $this->createMeeting($admin, [
            'title' => 'Finance Committee',
            'meeting_type' => 'finance',
            'scheduled_at' => now()->addDays(4),
        ]);

        $response = $this->actingAs($admin)->get("/governance/meetings/calendar?month={$month}&meeting_type=finance");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Meetings/Calendar')
            ->where('selectedMeetingType', 'finance')
            ->has('meetings', 1)
            ->where('meetings.0.id', $financeMeeting->id)
            ->where('meetings.0.meeting_type', 'finance')
        );
    }

    public function test_invited_member_can_submit_rsvp_with_receipt(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);
        $memberUser = $this->createUserWithRole('board_member');
        $member = $this->createBoardMember($memberUser);

        $response = $this->actingAs($memberUser)->post("/governance/meetings/{$meeting->id}/rsvp", [
            'status' => 'attending',
            'dietary_requirements' => true,
            'dietary_notes' => 'Gluten-free vegetarian',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('receipt_id');

        $this->assertDatabaseHas('meeting_rsvps', [
            'governance_meeting_id' => $meeting->id,
            'board_member_id' => $member->id,
            'response' => 'accepted',
            'dietary_requirements' => 1,
            'dietary_notes' => 'Gluten-free vegetarian',
        ]);
    }

    public function test_member_can_submit_apology_rsvp_with_reason(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);
        $memberUser = $this->createUserWithRole('board_member');
        $member = $this->createBoardMember($memberUser);

        $response = $this->actingAs($memberUser)->post("/governance/meetings/{$meeting->id}/rsvp", [
            'status' => 'apology',
            'notes' => 'Traveling interstate on university business',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('meeting_rsvps', [
            'governance_meeting_id' => $meeting->id,
            'board_member_id' => $member->id,
            'response' => 'declined',
            'decline_reason' => 'Traveling interstate on university business',
        ]);
    }

    public function test_uninvited_member_cannot_rsvp_to_committee_meeting(): void
    {
        $admin = $this->createAdminUser();
        $committee = BoardCommittee::create([
            'name' => 'Audit & Risk Committee',
            'committee_type' => 'audit_risk',
            'is_active' => true,
        ]);

        $committeeMeeting = $this->createMeeting($admin, [
            'title' => 'Audit Committee Q1',
            'meeting_type' => 'committee',
            'board_committee_id' => $committee->id,
        ]);

        // Member who is NOT in this committee
        $nonMemberUser = $this->createUserWithRole('board_member');
        $this->createBoardMember($nonMemberUser);

        $response = $this->actingAs($nonMemberUser)->post("/governance/meetings/{$committeeMeeting->id}/rsvp", [
            'status' => 'attending',
        ]);

        $response->assertForbidden();
    }

    public function test_attendance_status_unrecorded_removes_existing_attendance_record(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);
        $member = $this->createBoardMember($admin);

        // First record as present
        $this->actingAs($admin)->post("/governance/meetings/{$meeting->id}/attendance", [
            'attendance' => [
                [
                    'board_member_id' => $member->id,
                    'status' => 'present',
                ],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('meeting_attendances', [
            'governance_meeting_id' => $meeting->id,
            'board_member_id' => $member->id,
            'status' => 'present',
        ]);

        // Now record as unrecorded
        $this->actingAs($admin)->post("/governance/meetings/{$meeting->id}/attendance", [
            'attendance' => [
                [
                    'board_member_id' => $member->id,
                    'status' => 'unrecorded',
                ],
            ],
        ])->assertRedirect();

        $this->assertDatabaseMissing('meeting_attendances', [
            'governance_meeting_id' => $meeting->id,
            'board_member_id' => $member->id,
        ]);
    }

    public function test_late_arrival_counts_towards_quorum_and_committee_quorum_is_scoped(): void
    {
        $admin = $this->createAdminUser();
        $committee = BoardCommittee::create([
            'name' => 'People & Culture Committee',
            'committee_type' => 'people',
            'is_active' => true,
        ]);

        $memberUser1 = $this->createUserWithRole('board_member');
        $member1 = $this->createBoardMember($memberUser1);

        $memberUser2 = $this->createUserWithRole('board_member');
        $member2 = $this->createBoardMember($memberUser2);

        CommitteeMembership::create([
            'board_committee_id' => $committee->id,
            'board_member_id' => $member1->id,
            'role' => 'member',
            'appointed_at' => now()->subMonth(),
            'is_active' => true,
        ]);
        CommitteeMembership::create([
            'board_committee_id' => $committee->id,
            'board_member_id' => $member2->id,
            'role' => 'member',
            'appointed_at' => now()->subMonth(),
            'is_active' => true,
        ]);

        $meeting = $this->createMeeting($admin, [
            'title' => 'People Committee Meeting',
            'meeting_type' => 'committee',
            'board_committee_id' => $committee->id,
            'quorum_required' => 50,
        ]);

        // Record member1 as 'late' and member2 as 'apology'
        $this->actingAs($admin)->post("/governance/meetings/{$meeting->id}/attendance", [
            'attendance' => [
                [
                    'board_member_id' => $member1->id,
                    'status' => 'late',
                ],
                [
                    'board_member_id' => $member2->id,
                    'status' => 'apology',
                    'apology_reason' => 'Family event',
                ],
            ],
        ])->assertRedirect();

        $meeting->refresh();
        $quorum = $meeting->calculateQuorum();

        // 1 late out of 2 total committee members = 50%, meeting quorum requirement of 50%
        $this->assertEquals(2, $quorum['total_members']);
        $this->assertEquals(1, $quorum['present']);
        $this->assertEquals(50.0, $quorum['percentage']);
        $this->assertTrue($quorum['is_met']);
    }

    public function test_meeting_show_checklist_marks_ceo_report_not_applicable_for_committee(): void
    {
        $admin = $this->createAdminUser();
        $committee = BoardCommittee::create([
            'name' => 'Finance Committee',
            'committee_type' => 'finance',
            'is_active' => true,
        ]);

        $meeting = $this->createMeeting($admin, [
            'title' => 'Finance Committee Q1',
            'meeting_type' => 'committee',
            'board_committee_id' => $committee->id,
        ]);

        $response = $this->actingAs($admin)->get("/governance/meetings/{$meeting->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $page->component('Governance/Meetings/Show')
                ->where('workflowChecklist.items.2.key', 'ceo_report')
                ->where('workflowChecklist.items.2.status', 'not_applicable');
        });
    }

    public function test_previous_meeting_follow_through_scoped_to_same_committee(): void
    {
        $admin = $this->createAdminUser();
        $committee1 = BoardCommittee::create([
            'name' => 'Audit & Risk Committee',
            'committee_type' => 'audit_risk',
            'is_active' => true,
        ]);
        $committee2 = BoardCommittee::create([
            'name' => 'Finance Committee',
            'committee_type' => 'finance',
            'is_active' => true,
        ]);

        $meeting1 = $this->createMeeting($admin, [
            'title' => 'Audit Meeting 1',
            'meeting_type' => 'committee',
            'board_committee_id' => $committee1->id,
            'scheduled_at' => now()->subDays(20),
        ]);

        // Intervening meeting for committee 2
        $this->createMeeting($admin, [
            'title' => 'Finance Meeting 1',
            'meeting_type' => 'committee',
            'board_committee_id' => $committee2->id,
            'scheduled_at' => now()->subDays(10),
        ]);

        $currentMeeting = $this->createMeeting($admin, [
            'title' => 'Audit Meeting 2',
            'meeting_type' => 'committee',
            'board_committee_id' => $committee1->id,
            'scheduled_at' => now()->addDays(2),
        ]);

        $response = $this->actingAs($admin)->get("/governance/meetings/{$currentMeeting->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) use ($meeting1) {
            $page->component('Governance/Meetings/Show')
                ->where('workflowChecklist.items.9.key', 'follow_through')
                ->where('workflowChecklist.items.9.detail', "No open action items remain from {$meeting1->title}.");
        });
    }
}
