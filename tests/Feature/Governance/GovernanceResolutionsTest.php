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

    public function test_conflict_declaration_does_not_create_abstain_vote(): void
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
            'withdrew_from_voting' => 1,
        ]);

        // Recusal must NOT inject an abstention vote
        $this->assertDatabaseMissing('votes', [
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
        ]);

        $this->assertNotNull(ConflictDeclaration::first());
        $this->assertNull(Vote::first());
    }

    public function test_vote_replay_via_http_is_idempotent(): void
    {
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $meeting = $this->createMeeting($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(2),
            'governance_meeting_id' => $meeting->id,
        ]);

        // First vote
        $r1 = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/vote", [
            'vote' => 'for',
        ]);
        $r1->assertRedirect();
        $r1->assertSessionHas('success', 'Vote recorded.');
        $this->assertSame(1, Vote::where('resolution_id', $resolution->id)->count());

        // Replaying same vote succeeds idempotently
        $r2 = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/vote", [
            'vote' => 'for',
        ]);
        $r2->assertRedirect();
        $r2->assertSessionHas('success', 'Vote recorded.');
        $this->assertSame(1, Vote::where('resolution_id', $resolution->id)->count());

        // Attempting a conflicting vote returns error
        $r3 = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/vote", [
            'vote' => 'against',
        ]);
        $r3->assertRedirect();
        $r3->assertSessionHas('error', 'Board member has already cast a vote on this resolution');
    }

    public function test_conflict_declaration_on_out_of_session_resolution(): void
    {
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(5),
            'governance_meeting_id' => null,
        ]);

        $response = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/conflict", [
            'type' => 'related',
            'description' => 'Direct interest in contract vendor tender evaluation',
            'withdraw_from_voting' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('conflict_declarations', [
            'resolution_id' => $resolution->id,
            'governance_meeting_id' => null,
            'board_member_id' => $boardMember->id,
            'declaration_type' => 'related',
        ]);
    }

    public function test_finalize_blocks_implementing_unmet_quorum_resolution(): void
    {
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'closed',
            'outcome' => 'no_quorum',
            'voting_threshold' => 'simple_majority',
            'quorum_required' => true,
        ]);

        $response = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/finalize", [
            'status' => 'implemented',
            'notes' => 'Attempting implementation',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', "Cannot mark resolution as implemented unless outcome is carried (outcome is 'no_quorum').");
        $resolution->refresh();
        $this->assertSame('closed', $resolution->status);
    }

    public function test_incomplete_draft_can_be_saved_with_options(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/governance/resolutions', [
            'title' => 'Expansion Draft',
            'purpose' => 'decision',
            'exact_motion' => '', // Incomplete!
            'context' => 'Initial preliminary thoughts',
            'options' => [
                ['label' => 'Option A', 'description' => 'First route', 'benefits' => 'Fast', 'drawbacks' => 'Costly'],
                ['label' => 'Option B', 'description' => 'Second route', 'benefits' => 'Cheap', 'drawbacks' => 'Slow'],
            ],
            'type' => 'ordinary',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('resolutions', [
            'title' => 'Expansion Draft',
            'status' => 'draft',
            'version_number' => 1,
        ]);

        $resolution = \App\Domain\Governance\Models\Resolution::where('title', 'Expansion Draft')->first();
        $this->assertCount(2, $resolution->options);
        $this->assertFalse(empty($resolution->validateForPublication())); // Incomplete draft fails publication criteria
    }

    public function test_opening_resolution_requires_publication_criteria(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);

        // Create an incomplete draft paper (missing exact_motion and recommendation)
        $resolution = \App\Domain\Governance\Models\Resolution::create([
            'title' => 'Half-baked Paper',
            'purpose' => 'decision',
            'context' => 'Some context',
            'exact_motion' => null,
            'options' => [],
            'recommendation' => null,
            'status' => 'draft',
            'voting_threshold' => 'simple_majority',
            'governance_meeting_id' => $meeting->id,
            'proposed_by' => $admin->id,
            'proposed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/open", []);
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $resolution->refresh();
        $this->assertSame('draft', $resolution->status);
    }

    public function test_opening_resolution_freezes_paper_snapshot(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);

        $resolution = $this->createResolution($admin, [
            'title' => 'Capital Investment Paper',
            'purpose' => 'decision',
            'exact_motion' => 'That the Board approves $120,000 capital allocation for regional upgrade.',
            'context' => 'Extensive strategic and financial rationale for regional facilities.',
            'options' => [
                ['label' => 'Option 1: Preferred Upgrade', 'description' => 'Full refurbishment', 'benefits' => '20yr lifespan', 'drawbacks' => 'Initial capital'],
                ['label' => 'Option 2: Deferment', 'description' => 'Delay until next year', 'benefits' => 'Immediate cash preservation', 'drawbacks' => 'Escalating maintenance'],
            ],
            'recommendation' => 'Option 1 is strongly recommended to preserve asset capability.',
            'cost_impact' => ['has_cost' => true, 'amount' => '120,000', 'currency' => 'NZD'],
            'service_user_implications' => 'Provides uninterrupted service delivery for 500+ clients.',
            'risk_equity_implications' => 'Mitigates health and safety facility non-compliance risks.',
            'status' => 'draft',
            'governance_meeting_id' => $meeting->id,
            'version_number' => 1,
        ]);

        $this->assertNull($resolution->paper_snapshot);

        $response = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/open", []);
        $response->assertRedirect();
        $response->assertSessionHas('success', 'Voting opened.');

        $resolution->refresh();
        $this->assertSame('open', $resolution->status);
        $this->assertNotNull($resolution->paper_snapshot);
        $this->assertSame('That the Board approves $120,000 capital allocation for regional upgrade.', $resolution->paper_snapshot['exact_motion']);
        $this->assertCount(2, $resolution->paper_snapshot['options']);
        $this->assertSame(1, $resolution->paper_snapshot['version_number']);
    }

    public function test_updating_open_or_closed_paper_is_rejected_immutability(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);

        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'governance_meeting_id' => $meeting->id,
        ]);

        // Attempting to update an open paper must be rejected with 403 or 422
        $response = $this->actingAs($admin)->put("/governance/resolutions/{$resolution->id}", [
            'title' => 'Altered Title During Active Voting',
        ]);
        $this->assertTrue(in_array($response->status(), [403, 422]));

        // Attempting to attach files to an open paper must be rejected with 403 or 422
        $fileResponse = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/attachments", [
            'files' => [\Illuminate\Http\UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf')],
        ]);
        $this->assertTrue(in_array($fileResponse->status(), [403, 422]));
    }

    public function test_paper_update_rejects_version_mismatch_concurrency(): void
    {
        $admin = $this->createAdminUser();

        $resolution = $this->createResolution($admin, [
            'status' => 'draft',
            'version_number' => 2,
        ]);

        // Submitting with mismatched expected_version (e.g. stale client v1) returns 409 Conflict
        $response = $this->actingAs($admin)->put("/governance/resolutions/{$resolution->id}", [
            'title' => 'Concurrent Update Conflict',
            'expected_version' => 1,
        ]);
        $response->assertStatus(409);

        // Submitting with correct version 2 succeeds and increments version to 3
        $validResponse = $this->actingAs($admin)->put("/governance/resolutions/{$resolution->id}", [
            'title' => 'Valid Updated Title',
            'expected_version' => 2,
        ]);
        $validResponse->assertRedirect();

        $resolution->refresh();
        $this->assertSame('Valid Updated Title', $resolution->title);
        $this->assertSame(3, $resolution->version_number);
    }

    public function test_decision_resolution_with_single_option_requires_reason(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);

        $resolution = $this->createResolution($admin, [
            'purpose' => 'decision',
            'options' => [
                ['label' => 'Sole Vendor Selection', 'description' => 'Only 1 qualified supplier in jurisdiction', 'benefits' => 'Available immediately', 'drawbacks' => 'No market pricing comparison'],
            ],
            'single_option_reason' => null, // Missing!
            'status' => 'draft',
            'governance_meeting_id' => $meeting->id,
        ]);

        // Fails publication validation
        $errors = $resolution->validateForPublication();
        $this->assertNotEmpty($errors);

        // Adding single_option_reason makes it valid
        $resolution->update(['single_option_reason' => 'Sole authorized provider designated under statutory tender exemptions.']);
        $this->assertEmpty($resolution->validateForPublication());

        $response = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/open", []);
        $response->assertRedirect();
        $response->assertSessionHas('success', 'Voting opened.');
    }
}

