<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\MeetingMinute;
use App\Domain\Governance\Services\MeetingMinuteService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceMinuteIntegrityTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    protected function createChairUser(array $overrides = []): User
    {
        $user = $this->createUserWithRole('board_chair', $overrides);
        $this->createBoardMember($user, ['board_role' => 'chair']);
        return $user;
    }

    protected function createSecretaryUser(array $overrides = []): User
    {
        $user = $this->createUserWithRole('board_secretary', $overrides);
        $this->createBoardMember($user, ['board_role' => 'secretary']);
        return $user;
    }

    public function test_approved_minutes_put_is_denied_and_old_content_retained(): void
    {
        $chair = $this->createChairUser();
        $meeting = $this->createMeeting($chair);

        $minute = MeetingMinute::create([
            'governance_meeting_id' => $meeting->id,
            'content_blocks' => [
                ['heading' => 'Original Approved Heading', 'content' => 'Original approved immutable text'],
            ],
            'status' => 'approved',
            'version_number' => 1,
            'drafted_by' => $chair->id,
            'drafted_at' => now()->subDay(),
            'reviewed_by' => $chair->boardMember?->id,
            'reviewed_at' => now()->subHours(2),
        ]);

        $response = $this->actingAs($chair)->put("/governance/meetings/{$meeting->id}/minutes", [
            'content_blocks' => [
                ['heading' => 'Hacked Heading', 'content' => 'Malicious replacement content'],
            ],
            'expected_version' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $fresh = $minute->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame(1, $fresh->version_number);
        $this->assertSame('Original Approved Heading', $fresh->content_blocks[0]['heading']);
        $this->assertSame('Original approved immutable text', $fresh->content_blocks[0]['content']);
    }

    public function test_signed_minutes_put_is_denied_and_old_content_retained(): void
    {
        $chair = $this->createChairUser();
        $meeting = $this->createMeeting($chair);

        $minute = MeetingMinute::create([
            'governance_meeting_id' => $meeting->id,
            'content_blocks' => [
                ['heading' => 'Signed Heading', 'content' => 'Signed immutable text'],
            ],
            'status' => 'signed',
            'version_number' => 1,
            'drafted_by' => $chair->id,
            'drafted_at' => now()->subDays(2),
            'signed_by' => $chair->boardMember?->id,
            'signed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($chair)->put("/governance/meetings/{$meeting->id}/minutes", [
            'content_blocks' => [
                ['heading' => 'Overwritten Heading', 'content' => 'Overwritten text'],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $fresh = $minute->fresh();
        $this->assertSame('signed', $fresh->status);
        $this->assertSame('Signed Heading', $fresh->content_blocks[0]['heading']);
        $this->assertSame('Signed immutable text', $fresh->content_blocks[0]['content']);
    }

    public function test_stale_two_editor_conflict_detected_and_rejected(): void
    {
        $secretary = $this->createSecretaryUser();
        $meeting = $this->createMeeting($secretary);

        $minute = MeetingMinute::create([
            'governance_meeting_id' => $meeting->id,
            'content_blocks' => [
                ['heading' => 'Version 2 Heading', 'content' => 'Editor 1 already saved version 2'],
            ],
            'status' => 'draft',
            'version_number' => 2,
            'drafted_by' => $secretary->id,
            'drafted_at' => now(),
        ]);

        // Editor 2 had opened the page when it was v1 and attempts to save with expected_version = 1
        $response = $this->actingAs($secretary)->put("/governance/meetings/{$meeting->id}/minutes", [
            'content_blocks' => [
                ['heading' => 'Editor 2 Heading', 'content' => 'Editor 2 conflicting edit'],
            ],
            'expected_version' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $fresh = $minute->fresh();
        $this->assertSame(2, $fresh->version_number);
        $this->assertSame('Version 2 Heading', $fresh->content_blocks[0]['heading']);
    }

    public function test_old_content_retained_in_version_history_on_update(): void
    {
        $secretary = $this->createSecretaryUser();
        $meeting = $this->createMeeting($secretary);

        $service = app(MeetingMinuteService::class);
        $initial = $service->storeMinutes($meeting, [
            ['heading' => 'Initial Draft', 'content' => 'Initial text block'],
        ], $secretary);

        $initialHash = $initial->content_hash;

        $updated = $service->updateMinutes($meeting, [
            ['heading' => 'Updated Draft', 'content' => 'Updated text block'],
        ], $secretary, 1);

        $this->assertSame(2, $updated->version_number);
        $this->assertSame('Updated Draft', $updated->content_blocks[0]['heading']);

        // Check version history has preserved version 1
        $history = $updated->version_history;
        $this->assertNotEmpty($history);
        $v1Entry = collect($history)->firstWhere('version', 1);
        $this->assertNotNull($v1Entry);
        $this->assertSame('Initial Draft', $v1Entry['content_blocks'][0]['heading']);
        $this->assertSame($initialHash, $v1Entry['content_hash']);
    }

    public function test_signer_and_reviewer_correct_when_user_and_board_member_ids_differ(): void
    {
        // Create dummy users and board members so auto-increment IDs naturally diverge
        $dummy1 = $this->createUserWithRole('board_member');
        $dummy2 = $this->createUserWithRole('board_member');
        $this->createBoardMember($dummy1);
        $this->createBoardMember($dummy2);
        $dummy3 = $this->createUserWithRole('board_member');

        $chairUser = $this->createChairUser();
        $boardMember = $chairUser->boardMember;
        $this->assertNotNull($boardMember);
        $this->assertNotEquals($chairUser->id, $boardMember->id, 'User ID and BoardMember ID must differ for this test');

        $meeting = $this->createMeeting($chairUser, [
            'chair_id' => $boardMember->id,
        ]);

        $service = app(MeetingMinuteService::class);
        $minute = $service->storeMinutes($meeting, [
            ['heading' => 'Substantive Business', 'content' => 'Detailed minutes text'],
        ], $chairUser);

        // Approve
        $approved = $service->approveMinutes($meeting, $chairUser);
        $this->assertSame($boardMember->id, $approved->reviewed_by, 'reviewed_by must store BoardMember ID, not User ID');
        $this->assertNotSame($chairUser->id, $approved->reviewed_by);
        $this->assertSame($chairUser->name, $approved->reviewer_name);
        $this->assertSame($boardMember->id, $meeting->fresh()->minutes_approved_by);

        // Sign
        $signed = $service->signMinutes($meeting, $chairUser);
        $this->assertSame($boardMember->id, $signed->signed_by, 'signed_by must store BoardMember ID, not User ID');
        $this->assertNotSame($chairUser->id, $signed->signed_by);
        $this->assertSame($chairUser->name, $signed->signer_name);
        $this->assertSame($boardMember->id, $meeting->fresh()->minutes_signed_by);
        $this->assertSame('minutes_signed', $meeting->fresh()->status);
    }

    public function test_duplicate_sign_replay_is_idempotent(): void
    {
        $chair = $this->createChairUser();
        $meeting = $this->createMeeting($chair);

        $service = app(MeetingMinuteService::class);
        $service->storeMinutes($meeting, [
            ['heading' => 'Formal Business', 'content' => 'Quorum confirmed and business conducted.'],
        ], $chair);

        $service->approveMinutes($meeting, $chair);
        $signedFirst = $service->signMinutes($meeting, $chair);
        $signedAtFirst = $signedFirst->signed_at;

        // Duplicate replay
        $signedSecond = $service->signMinutes($meeting, $chair);

        $this->assertSame($signedAtFirst->toIso8601String(), $signedSecond->signed_at->toIso8601String());
        $this->assertSame('signed', $signedSecond->status);
    }

    public function test_cannot_approve_empty_minutes(): void
    {
        $chair = $this->createChairUser();
        $meeting = $this->createMeeting($chair);

        $service = app(MeetingMinuteService::class);
        $minute = $service->storeMinutes($meeting, [
            ['heading' => 'Empty Section 1', 'content' => '   '],
            ['heading' => 'Empty Section 2', 'content' => ''],
        ], $chair);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot approve empty minutes');

        $service->approveMinutes($meeting, $chair);
    }

    public function test_correction_creates_new_draft_and_preserves_signed_original(): void
    {
        $chair = $this->createChairUser();
        $secretary = $this->createSecretaryUser();
        $meeting = $this->createMeeting($chair);

        $service = app(MeetingMinuteService::class);
        $service->storeMinutes($meeting, [
            ['heading' => 'Resolution 1', 'content' => 'Board resolved to proceed.'],
        ], $secretary);

        $service->approveMinutes($meeting, $chair);
        $signed = $service->signMinutes($meeting, $chair);
        $v1Hash = $signed->content_hash;
        $v1SignedAt = $signed->signed_at;

        // Initiate correction
        $correction = $service->createCorrection(
            $meeting,
            $secretary,
            'Omitted mention of director recusal on agenda item 3'
        );

        $this->assertSame(2, $correction->version_number);
        $this->assertSame('draft', $correction->status);
        $this->assertNull($correction->signed_by);
        $this->assertNull($correction->signed_at);
        $this->assertSame('minutes_draft', $meeting->fresh()->status);

        // Check that Version 1 is preserved in version_history with full details
        $history = $correction->version_history;
        $this->assertNotEmpty($history);
        $v1Snapshot = collect($history)->firstWhere('event', 'superseded_for_correction');
        $this->assertNotNull($v1Snapshot);
        $this->assertSame('signed', $v1Snapshot['status']);
        $this->assertSame($v1Hash, $v1Snapshot['content_hash']);
        $this->assertSame('Omitted mention of director recusal on agenda item 3', $v1Snapshot['reason_for_correction']);
        $this->assertSame('Resolution 1', $v1Snapshot['content_blocks'][0]['heading']);
    }

    public function test_legacy_attribution_explicitly_reports_unavailable_when_signer_is_null(): void
    {
        $chair = $this->createChairUser();
        $meeting = $this->createMeeting($chair);

        $minute = MeetingMinute::create([
            'governance_meeting_id' => $meeting->id,
            'content_blocks' => [
                ['heading' => 'Historical Meeting', 'content' => 'Old record from legacy system'],
            ],
            'status' => 'signed',
            'version_number' => 1,
            'signed_by' => null,
            'signed_at' => now()->subYears(3),
            'reviewed_by' => null,
            'reviewed_at' => now()->subYears(3),
        ]);

        $this->assertSame('Legacy attribution unavailable', $minute->signer_name);
        $this->assertSame('Legacy attribution unavailable', $minute->reviewer_name);
    }
}
