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
        $r1->assertSessionHas('success', 'Your vote is recorded.');
        $this->assertSame(1, Vote::where('resolution_id', $resolution->id)->count());

        // Replaying same vote succeeds idempotently
        $r2 = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/vote", [
            'vote' => 'for',
        ]);
        $r2->assertRedirect();
        $r2->assertSessionHas('success', 'Your vote is recorded.');
        $this->assertSame(1, Vote::where('resolution_id', $resolution->id)->count());

        // Attempting a conflicting vote returns error
        $r3 = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/vote", [
            'vote' => 'against',
        ]);
        $r3->assertRedirect();
        $r3->assertSessionHas('error', "You've already voted on this resolution, and a vote can't be changed once it's recorded.");
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
        $response->assertSessionHas('error', "Only resolutions that passed can be marked as done. This one's result is: No decision — not enough members took part.");
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
        $response->assertSessionHas('success', 'Voting is now open.');

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
        $response->assertSessionHas('success', 'Voting is now open.');
    }

    // ── Decisions & actions hub: one wizard for authoring and editing ──────

    public function test_create_page_redirects_to_the_register_wizard_with_the_meeting_preselected(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);

        $this->actingAs($admin)
            ->get("/governance/resolutions/create?meeting_id={$meeting->id}")
            ->assertRedirect("/governance/resolutions?create=1&meeting_id={$meeting->id}");

        $this->actingAs($admin)
            ->get('/governance/resolutions/create')
            ->assertRedirect('/governance/resolutions?create=1');

        $member = $this->createUserWithRole('board_member');
        $this->createBoardMember($member);
        $this->actingAs($member)->get('/governance/resolutions/create')->assertForbidden();
    }

    public function test_register_carries_wizard_options_only_for_paper_authors(): void
    {
        $admin = $this->createAdminUser();
        $this->createMeeting($admin);

        $this->actingAs($admin)
            ->get('/governance/resolutions?create=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Resolutions/Index')
                ->where('can_create', true)
                ->where('can_publish', true)
                ->has('meetings', 1)
                ->has('committees')
                ->has('users')
                ->has('authoritySubjects')
                ->where('authoritySubjectGroups', fn ($groups) => collect($groups)
                    ->every(fn ($group) => in_array($group['subject_type'], \App\Domain\Governance\Models\GovernanceResolutionBinding::SUBJECT_TYPES, true))));

        $member = $this->createUserWithRole('board_member');
        $this->createBoardMember($member);

        $this->actingAs($member)
            ->get('/governance/resolutions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_create', false)
                ->where('authoritySubjects', null)
                ->has('users', 0)
                ->has('authoritySubjectGroups', 0));
    }

    public function test_register_filters_and_summary_reflect_the_query(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);
        $this->createResolution($admin, ['title' => 'Draft fleet paper', 'status' => 'draft']);
        $this->createResolution($admin, ['title' => 'Carried housing paper', 'status' => 'closed', 'outcome' => 'carried', 'governance_meeting_id' => $meeting->id]);

        $this->actingAs($admin)
            ->get('/governance/resolutions?status=draft')
            ->assertInertia(fn ($page) => $page
                ->where('filters.status', 'draft')
                ->has('resolutions.data', 1)
                ->where('resolutions.data.0.title', 'Draft fleet paper')
                ->where('summary.total', 2)
                ->where('summary.draft', 1)
                ->where('summary.carried', 1));

        $this->actingAs($admin)
            ->get("/governance/resolutions?outcome=carried&meeting={$meeting->id}&search=housing")
            ->assertInertia(fn ($page) => $page
                ->where('filters.outcome', 'carried')
                ->where('filters.meeting', (string) $meeting->id)
                ->has('resolutions.data', 1)
                ->where('resolutions.data.0.title', 'Carried housing paper'));
    }

    public function test_show_presents_attachments_without_storage_paths_and_edit_options_only_while_editable(): void
    {
        $admin = $this->createAdminUser();
        $draft = $this->createResolution($admin, [
            'attachments' => [[
                'id' => 'att-1',
                'path' => 'governance/resolutions/secret-storage-location/contract.pdf',
                'original_name' => 'contract.pdf',
                'mime_type' => 'application/pdf',
            ]],
        ]);

        $response = $this->actingAs($admin)->get("/governance/resolutions/{$draft->id}?edit=1");
        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Resolutions/Show')
                ->where('can_manage', true)
                ->where('attachments.0.original_name', 'contract.pdf')
                ->missing('attachments.0.path')
                ->missing('resolution.attachments')
                ->has('authoritySubjects')
                ->has('authoritySubjectGroups')
                ->has('committees'));
        $this->assertStringNotContainsString('secret-storage-location', $response->getContent());

        $open = $this->createResolution($admin, [
            'status' => 'open',
            'paper_snapshot' => [
                'version_number' => 1,
                'attachments' => [['id' => 'att-2', 'path' => 'governance/resolutions/frozen-secret-path/brief.pdf', 'original_name' => 'brief.pdf']],
            ],
        ]);

        $openResponse = $this->actingAs($admin)->get("/governance/resolutions/{$open->id}");
        $openResponse->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_manage', false)
                ->where('can_close_voting', true)
                ->where('authoritySubjects', null)
                ->has('meetings', 0)
                ->where('paper_snapshot.attachments.0.original_name', 'brief.pdf')
                ->missing('paper_snapshot.attachments.0.path')
                ->missing('resolution.paper_snapshot'));
        $this->assertStringNotContainsString('frozen-secret-path', $openResponse->getContent());
    }

    public function test_wizard_authoring_stays_in_context_and_reports_an_unpublishable_paper_honestly(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);
        $meetingUrl = "/governance/meetings/{$meeting->id}?tab=resolutions";

        $this->actingAs($admin)
            ->from($meetingUrl)
            ->post('/governance/resolutions', [
                'title' => 'Incomplete paper from the meeting',
                'meeting_id' => $meeting->id,
                'publish_now' => true,
                '_modal' => true,
            ])
            ->assertRedirect($meetingUrl)
            ->assertSessionHas('error');

        $this->assertDatabaseHas('resolutions', [
            'title' => 'Incomplete paper from the meeting',
            'governance_meeting_id' => $meeting->id,
            'status' => 'draft',
        ]);
    }

    public function test_publishing_from_the_wizard_requires_the_open_voting_ability(): void
    {
        // A CEO may author papers (policy) once given the register permissions,
        // but opening voting is reserved to the chair, secretary and admins.
        $ceo = $this->createUserWithRole('ceo');
        foreach (['governance.resolutions.view', 'governance.resolutions.manage'] as $key) {
            $permission = \App\Models\Permission::query()->where('key', $key)->firstOrFail();
            $ceo->permissionOverrides()->sync([$permission->id => ['allowed' => true]], false);
        }

        $payload = [
            'title' => 'CEO-authored decision paper',
            'exact_motion' => 'That the board approves the proposal.',
            'context' => 'Background for the proposal.',
            'purpose' => 'decision',
            'options' => [
                ['label' => 'Approve', 'description' => 'Proceed'],
                ['label' => 'Decline', 'description' => 'Do not proceed'],
            ],
            'recommendation' => 'Approve.',
            'cost_impact' => ['has_cost' => false],
            'service_user_implications' => 'None.',
            'risk_equity_implications' => 'None.',
            'publish_now' => true,
            '_modal' => true,
        ];

        $this->actingAs($ceo)
            ->get('/governance/resolutions')
            ->assertInertia(fn ($page) => $page
                ->where('can_create', true)
                ->where('can_publish', false));

        $this->actingAs($ceo)
            ->from('/governance/resolutions')
            ->post('/governance/resolutions', $payload)
            ->assertRedirect('/governance/resolutions')
            ->assertSessionHas('error');

        $this->assertDatabaseHas('resolutions', ['title' => 'CEO-authored decision paper', 'status' => 'draft']);
    }

    public function test_inertia_edit_with_a_stale_version_reports_the_conflict_in_the_wizard(): void
    {
        $admin = $this->createAdminUser();
        $resolution = $this->createResolution($admin, ['version_number' => 4]);

        $this->actingAs($admin)
            ->from("/governance/resolutions/{$resolution->id}")
            ->withHeaders(['X-Inertia' => 'true'])
            ->put("/governance/resolutions/{$resolution->id}", [
                'title' => 'Edited from a stale tab',
                'expected_version' => 3,
            ])
            ->assertSessionHasErrors('expected_version');

        $this->assertSame(4, $resolution->fresh()->version_number);
        $this->assertNotSame('Edited from a stale tab', $resolution->fresh()->title);
    }

    // ── Plain-language UX audit 2026-09-14: voting, conflicts, resolutions ──

    public function test_a_reason_given_with_a_vote_is_saved_as_a_vote_note_and_never_as_a_conflict(): void
    {
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $meeting = $this->createMeeting($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(2),
            'governance_meeting_id' => $meeting->id,
        ]);

        $this->actingAs($admin)
            ->post("/governance/resolutions/{$resolution->id}/vote", [
                'vote' => 'for',
                'vote_note' => "The costs are covered in this year's budget.",
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Your vote is recorded.');

        $vote = Vote::query()->where('resolution_id', $resolution->id)->where('board_member_id', $boardMember->id)->firstOrFail();
        $this->assertSame("The costs are covered in this year's budget.", $vote->vote_note);
        $this->assertFalse($vote->conflict_declared, 'A reason for a vote is not a conflict of interest.');
        $this->assertNull($vote->conflict_note);
        $this->assertSame(0, ConflictDeclaration::query()->count());

        // Older ballots that still send the reason as `conflict_note` don't declare a conflict either.
        $other = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(2),
            'governance_meeting_id' => $meeting->id,
        ]);
        $this->actingAs($admin)
            ->post("/governance/resolutions/{$other->id}/vote", [
                'vote' => 'against',
                'conflict_note' => 'Too expensive right now.',
            ])
            ->assertSessionHas('success');

        $legacy = Vote::query()->where('resolution_id', $other->id)->firstOrFail();
        $this->assertSame('Too expensive right now.', $legacy->vote_note);
        $this->assertFalse($legacy->conflict_declared);
        $this->assertNull($legacy->conflict_note);
    }

    public function test_the_shared_conflict_dialog_payload_is_recorded_through_the_real_route(): void
    {
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $meeting = $this->createMeeting($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(2),
            'governance_meeting_id' => $meeting->id,
        ]);

        // The keys the old meeting workspace sent were never accepted …
        $this->actingAs($admin)
            ->from("/governance/meetings/{$meeting->id}?tab=resolutions&paper={$resolution->id}")
            ->post("/governance/resolutions/{$resolution->id}/conflict", [
                'declaration_type' => 'material',
                'declaration_text' => 'My sister is a director of the preferred supplier.',
                'withdrew_from_voting' => true,
            ])
            ->assertSessionHasErrors([
                'type' => 'Choose what kind of conflict of interest it is.',
                'description' => 'Describe the conflict of interest.',
            ]);
        $this->assertSame(0, ConflictDeclaration::query()->count());

        // … the shared dialog's payload (buildConflictPayload) is.
        $this->actingAs($admin)
            ->post("/governance/resolutions/{$resolution->id}/conflict", [
                'type' => 'related',
                'description' => 'My sister is a director of the preferred supplier.',
                'withdraw_from_voting' => true,
                'withdraw_from_discussion' => false,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "Your conflict of interest is recorded, and you've stepped aside from the vote.");

        $this->assertDatabaseHas('conflict_declarations', [
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
            'declaration_type' => 'related',
            'withdrew_from_voting' => 1,
            'withdrew_from_discussion' => 0,
        ]);

        $this->actingAs($admin)
            ->post("/governance/resolutions/{$resolution->id}/conflict", [
                'type' => 'related',
                'description' => 'Too short',
            ])
            ->assertSessionHasErrors(['description' => 'Add a little more detail about the conflict (at least 20 characters).']);
    }

    public function test_a_member_can_declare_a_conflict_while_reading_a_draft_and_the_page_shows_it(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->createUserWithRole('board_member');
        $this->createBoardMember($member);
        $draft = $this->createResolution($admin, ['status' => 'draft', 'deadline' => now()->addWeek()]);

        $this->actingAs($member)
            ->get("/governance/resolutions/{$draft->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_declare_conflict', true)
                ->where('can_vote', false)
                ->where('my_conflict', null));

        $this->actingAs($member)
            ->post("/governance/resolutions/{$draft->id}/conflict", [
                'type' => 'material',
                'description' => 'I own shares in the company this resolution proposes to hire.',
                'withdraw_from_voting' => false,
            ])
            ->assertSessionHas('success', 'Your conflict of interest is recorded.');

        $this->actingAs($member)
            ->get("/governance/resolutions/{$draft->id}")
            ->assertInertia(fn ($page) => $page
                ->where('my_conflict.declaration_type', 'material')
                ->where('my_conflict.withdrew_from_voting', false));
    }

    public function test_a_member_who_declares_a_conflict_but_still_votes_has_the_vote_marked(): void
    {
        $admin = $this->createAdminUser();
        $this->createBoardMember($admin);
        $resolution = $this->createResolution($admin, ['status' => 'open', 'deadline' => now()->addDays(2)]);

        $this->actingAs($admin)
            ->post("/governance/resolutions/{$resolution->id}/vote", ['vote' => 'for'])
            ->assertSessionHas('success');
        $this->assertFalse(Vote::query()->firstOrFail()->conflict_declared);

        $this->actingAs($admin)
            ->post("/governance/resolutions/{$resolution->id}/conflict", [
                'type' => 'other',
                'description' => 'I used to work for the organisation involved.',
                'withdraw_from_voting' => false,
            ])
            ->assertSessionHas('success');

        $this->assertTrue(Vote::query()->firstOrFail()->conflict_declared, 'A real declaration marks the vote.');
    }

    public function test_papers_for_information_or_discussion_never_go_to_a_vote(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);
        $paper = $this->createResolution($admin, [
            'purpose' => 'information',
            'status' => 'draft',
            'governance_meeting_id' => $meeting->id,
        ]);

        $this->actingAs($admin)
            ->get("/governance/resolutions/{$paper->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can_open_voting', false)
                ->where('can_publish_to_members', true));

        $this->actingAs($admin)
            ->post("/governance/resolutions/{$paper->id}/open", [])
            ->assertRedirect()
            ->assertSessionHas('error', 'This paper is for information, so the board doesn\'t vote on it. Use "Publish to members" to share it.');
        $this->assertSame('draft', $paper->fresh()->status);
        $this->assertNull($paper->fresh()->opened_at);

        $this->actingAs($admin)
            ->post("/governance/resolutions/{$paper->id}/publish")
            ->assertSessionHas('success', 'The paper is now published to board members.');
        $this->assertSame('proposed', $paper->fresh()->status);
        $this->assertNotNull($paper->fresh()->paper_snapshot);

        // Still no ballot once it's published.
        $this->actingAs($admin)
            ->post("/governance/resolutions/{$paper->id}/open", [])
            ->assertSessionHas('error');
        $this->assertSame('proposed', $paper->fresh()->status);

        // A resolution for decision can't skip its vote by being "published".
        $decision = $this->createResolution($admin, ['status' => 'draft', 'governance_meeting_id' => $meeting->id]);
        $this->actingAs($admin)
            ->post("/governance/resolutions/{$decision->id}/publish")
            ->assertSessionHas('error');
        $this->assertSame('draft', $decision->fresh()->status);
    }

    public function test_follow_up_actions_from_the_wizard_are_created_when_the_resolution_passes(): void
    {
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $meeting = $this->createMeeting($admin);
        $assignee = $this->createUserWithRole('board_member', ['name' => 'Aroha Ngata']);

        $this->actingAs($admin)
            ->post('/governance/resolutions', [
                'title' => 'Replace the house van',
                'exact_motion' => 'That the board approves replacing the house van.',
                'context' => 'The van failed its warrant of fitness.',
                'purpose' => 'decision',
                'options' => [['label' => 'Replace it'], ['label' => 'Repair it']],
                'recommendation' => 'Replace it.',
                'cost_impact' => ['has_cost' => false],
                'service_user_implications' => 'Residents keep safe transport.',
                'risk_equity_implications' => 'Low.',
                'meeting_id' => $meeting->id,
                'type' => 'ordinary',
                'follow_up_actions' => [
                    ['title' => 'Order the new van', 'assigned_to' => $assignee->id, 'due_date' => now()->addWeeks(3)->toDateString()],
                ],
            ])
            ->assertSessionHasNoErrors();

        $resolution = \App\Domain\Governance\Models\Resolution::query()->where('title', 'Replace the house van')->firstOrFail();
        $this->assertTrue($resolution->auto_generate_actions, 'Follow-up actions promised by the wizard must be created when it passes.');

        $meeting->attendances()->create([
            'board_member_id' => $boardMember->id,
            'status' => 'present',
            'marked_at' => now(),
            'marked_by' => $admin->id,
        ]);
        $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/open", [])->assertSessionHas('success');
        $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/vote", ['vote' => 'for'])->assertSessionHas('success');
        $this->actingAs($admin)
            ->post("/governance/resolutions/{$resolution->id}/close", [])
            ->assertSessionHas('success', 'Voting is closed. Result: Passed. 1 follow-up action was created.');

        $this->assertDatabaseHas('action_items', [
            'source_type' => 'resolution',
            'source_id' => $resolution->id,
            'title' => 'Order the new van',
            'assigned_to' => $assignee->id,
        ]);

        $this->actingAs($admin)
            ->get("/governance/resolutions/{$resolution->id}")
            ->assertInertia(fn ($page) => $page
                ->where('results.outcome', 'carried')
                ->has('action_items', 1)
                ->where('action_items.0.title', 'Order the new van'));
    }

    public function test_a_5pm_voting_deadline_entered_in_nz_time_is_stored_as_5pm_nz(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-09-01 00:00:00', 'UTC'));
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post('/governance/resolutions', [
                'title' => 'Written vote on the insurance renewal',
                'type' => 'ordinary',
                'voting_deadline' => '2026-09-18T17:00',
            ])
            ->assertSessionHasNoErrors();

        $resolution = \App\Domain\Governance\Models\Resolution::query()->where('title', 'Written vote on the insurance renewal')->firstOrFail();
        // 5:00 pm NZST (UTC+12) is 5:00 am UTC — not 5:00 pm UTC (5:00 am the next day in NZ).
        $this->assertSame('2026-09-18 05:00:00', $resolution->getRawOriginal('deadline'));
        $this->assertSame('2026-09-18 17:00', $resolution->deadline->copy()->setTimezone('Pacific/Auckland')->format('Y-m-d H:i'));

        $this->actingAs($admin)
            ->put("/governance/resolutions/{$resolution->id}", ['voting_deadline' => '2026-09-19T17:00'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-09-19 05:00:00', $resolution->fresh()->getRawOriginal('deadline'));

        $this->actingAs($admin)
            ->post('/governance/resolutions', ['title' => 'Too late', 'voting_deadline' => '2026-08-01T17:00'])
            ->assertSessionHasErrors(['voting_deadline' => 'Pick a voting deadline in the future.']);
    }

    public function test_vote_now_from_the_register_opens_meeting_resolutions_in_the_meeting_workspace(): void
    {
        $admin = $this->createAdminUser();
        $this->createBoardMember($admin);
        $meeting = $this->createMeeting($admin);
        $meetingPaper = $this->createResolution($admin, [
            'title' => 'Meeting paper',
            'status' => 'open',
            'deadline' => now()->addDays(2),
            'governance_meeting_id' => $meeting->id,
        ]);
        $writtenPaper = $this->createResolution($admin, [
            'title' => 'Written paper',
            'status' => 'open',
            'deadline' => now()->addDays(3),
        ]);

        $this->actingAs($admin)
            ->get('/governance/resolutions')
            ->assertInertia(fn ($page) => $page
                ->has('my_pending_votes', 2)
                ->where('my_pending_votes.0.vote_href', "/governance/meetings/{$meeting->id}?tab=resolutions&paper={$meetingPaper->id}")
                ->where('my_pending_votes.1.vote_href', "/governance/resolutions/{$writtenPaper->id}"));
    }

    public function test_resolution_pages_say_when_board_voting_is_switched_off_and_who_can_switch_it_on(): void
    {
        \App\Domain\Governance\Models\GovernanceVotingProfile::query()->update(['is_active' => false, 'approved_at' => null]);
        $admin = $this->createAdminUser();
        $resolution = $this->createResolution($admin);

        $this->actingAs($admin)
            ->get('/governance/resolutions')
            ->assertInertia(fn ($page) => $page
                ->where('voting_rules.switched_on', false)
                ->where('voting_rules.can_switch_on', true));

        $member = $this->createUserWithRole('board_member');
        $this->createBoardMember($member);
        $this->actingAs($member)
            ->get("/governance/resolutions/{$resolution->id}")
            ->assertInertia(fn ($page) => $page
                ->where('voting_rules.switched_on', false)
                ->where('voting_rules.can_switch_on', false));

        $this->actingAs($admin)
            ->post("/governance/resolutions/{$resolution->id}/open", [])
            ->assertSessionHas('error', 'Board voting is switched off until the voting rules are confirmed. The chair or board secretary can confirm them in Settings, under "How the board votes".');
    }
}

