<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\Vote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * The meeting workspace ballot reads the same voting state as the resolution
 * page (conflict availability, why a member can't vote, the rule applied,
 * whether voting is switched on) and never receives storage paths or internal
 * vote integrity data.
 */
class GovernanceMeetingWorkspaceVotingPayloadTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
        Cache::flush();
    }

    public function test_meeting_workspace_resolution_payload_is_safe_and_carries_voting_state(): void
    {
        $chair = $this->createAdminUser();
        $member = $this->createUserWithRole('board_member');
        $boardMember = $this->createBoardMember($member);
        $meeting = $this->createMeeting($chair, ['title' => 'Ordinary Board Meeting']);

        $storagePath = 'governance/resolutions/private-briefing-7f3a.pdf';
        $resolution = $this->createResolution($chair, [
            'governance_meeting_id' => $meeting->id,
            'title' => 'Approve the new van lease',
            'status' => 'open',
            'opened_at' => now()->subHour(),
            'deadline' => now()->addDays(3),
            'paper_snapshot' => [
                'title' => 'Approve the new van lease',
                'version_number' => 2,
                'attachments' => [[
                    'id' => 'att-1',
                    'path' => $storagePath,
                    'original_name' => 'Lease briefing.pdf',
                ]],
            ],
        ]);

        Vote::create([
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
            'vote' => 'for',
            'voted_at' => now(),
            'voting_method' => 'electronic',
            'vote_hash' => 'internal-integrity-hash-value',
            'conflict_note' => 'legacy note',
        ]);

        $response = $this->actingAs($member)
            ->get("/governance/meetings/{$meeting->id}?tab=resolutions&paper={$resolution->id}");

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Governance/Meetings/Show')
            ->where('resolutions.0.id', $resolution->id)
            ->where('resolutions.0.paper_snapshot.attachments.0.original_name', 'Lease briefing.pdf')
            ->missing('resolutions.0.paper_snapshot.attachments.0.path')
            ->missing('resolutions.0.my_vote.vote_hash')
            ->missing('resolutions.0.my_vote.conflict_note')
            ->where('resolutions.0.can_declare_conflict', true)
            ->where('resolutions.0.applied_threshold', $resolution->appliedThreshold())
            ->has('resolutions.0.voting_rules_switched_on')
            ->has('resolutions.0.ineligible_reason')
            ->missing('meeting.resolutions.0.paper_snapshot')
            ->missing('meeting.resolutions.0.attachments'));

        $props = json_encode($response->viewData('page')['props']);
        $this->assertStringNotContainsString($storagePath, $props);
        $this->assertStringNotContainsString('internal-integrity-hash-value', $props);
    }

    public function test_viewers_without_a_board_seat_cannot_declare_a_conflict(): void
    {
        $chair = $this->createAdminUser();
        // Holds the board member role (and its vote permission) but no board seat.
        $staffViewer = $this->createUserWithRole('board_member');
        $meeting = $this->createMeeting($chair, ['title' => 'Ordinary Board Meeting']);
        $this->createResolution($chair, [
            'governance_meeting_id' => $meeting->id,
            'status' => 'draft',
        ]);

        $this->actingAs($staffViewer)
            ->get("/governance/meetings/{$meeting->id}?tab=resolutions")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('resolutions.0.can_declare_conflict', false));
    }
}
