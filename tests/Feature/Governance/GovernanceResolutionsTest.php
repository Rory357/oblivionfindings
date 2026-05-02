<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\ConflictDeclaration;
use App\Domain\Governance\Models\Vote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceResolutionsTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_admin_can_create_resolution(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/governance/resolutions', [
            'title' => 'Approve Budget',
            'description' => 'Budget approval',
            'type' => 'ordinary',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('resolutions', [
            'title' => 'Approve Budget',
            'status' => 'draft',
        ]);
    }

    public function test_can_open_vote_cast_vote_and_close(): void
    {
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $meeting = $this->createMeeting($admin);

        $resolution = $this->createResolution($admin, [
            'status' => 'draft',
            'deadline' => now()->addDays(2),
            'governance_meeting_id' => $meeting->id,
        ]);

        $openResponse = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/open", [
            'deadline' => now()->addDays(3)->toDateTimeString(),
        ]);
        $openResponse->assertRedirect();

        $resolution->refresh();
        $this->assertEquals('open', $resolution->status);

        $voteResponse = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/vote", [
            'vote' => 'for',
        ]);
        $voteResponse->assertRedirect();

        $this->assertDatabaseHas('votes', [
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
            'vote' => 'for',
        ]);

        $closeResponse = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/close", [
            'notes' => 'Voting closed',
        ]);
        $closeResponse->assertRedirect();

        $resolution->refresh();
        $this->assertEquals('closed', $resolution->status);
    }

    public function test_conflict_declaration_creates_abstain_vote(): void
    {
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $meeting = $this->createMeeting($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'draft',
            'deadline' => now()->addDays(5),
            'governance_meeting_id' => $meeting->id,
        ]);

        $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/open", []);
        $resolution->refresh();

        $response = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/conflict", [
            'type' => 'material',
            'description' => str_repeat('Conflict reason ', 2),
            'withdraw_from_voting' => true,
            'withdraw_from_discussion' => true,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('conflict_declarations', [
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
            'declaration_type' => 'material',
        ]);

        $this->assertDatabaseHas('votes', [
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
            'vote' => 'abstain',
        ]);

        $this->assertNotNull(ConflictDeclaration::first());
        $this->assertNotNull(Vote::first());
    }
}
