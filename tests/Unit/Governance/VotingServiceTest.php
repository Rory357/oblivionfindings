<?php

namespace Tests\Unit\Governance;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Services\VotingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class VotingServiceTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    public function test_open_and_cast_vote(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'draft',
        ]);

        $service = new VotingService();
        $service->openVoting($resolution, now()->addDays(2));

        $resolution->refresh();
        $this->assertEquals('open', $resolution->status);

        $vote = $service->castVote($resolution, $boardMember, 'for');
        $this->assertEquals('for', $vote->vote);
        $this->assertDatabaseHas('votes', [
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
        ]);
    }

    public function test_cast_vote_prevents_duplicates(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(2),
        ]);

        $service = new VotingService();
        $service->castVote($resolution, $boardMember, 'for');

        $this->expectException(\InvalidArgumentException::class);
        $service->castVote($resolution, $boardMember, 'against');
    }

    public function test_declare_conflict_creates_abstain_vote(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(5),
        ]);

        $service = new VotingService();
        $service->declareConflict(
            $resolution,
            $boardMember,
            'material',
            'Conflict note',
            true,
            false,
            $admin
        );

        $this->assertDatabaseHas('conflict_declarations', [
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
            'withdrew_from_voting' => 1,
        ]);

        $this->assertDatabaseHas('votes', [
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
            'vote' => 'abstain',
        ]);
    }

    public function test_calculate_quorum_with_null_meeting(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $this->createBoardMember($admin);

        $service = new VotingService();
        $quorum = $service->calculateQuorum(null);

        $this->assertEquals(1, $quorum['total_eligible']);
        $this->assertTrue($quorum['met']);
    }
}
