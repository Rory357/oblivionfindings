<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\MeetingAgendaItem;
use App\Domain\Governance\Models\MeetingAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceMeetingsTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

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

        $approveResponse = $this->actingAs($admin)->post("/governance/meetings/{$meeting->id}/minutes/approve", []);
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
}
