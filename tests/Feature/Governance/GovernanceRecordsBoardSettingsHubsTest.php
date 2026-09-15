<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardEvaluation;
use App\Domain\Governance\Models\BoardMemberInterest;
use App\Domain\Governance\Models\GovernanceDocument;
use App\Domain\Governance\Models\GovernancePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * Policies & records, Board & members and Settings & audit hubs: retired
 * full-page create/edit routes now open the register wizards, and the
 * registers carry the header filters and meter data their pages render.
 */
class GovernanceRecordsBoardSettingsHubsTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    private function createPolicy(array $overrides = []): GovernancePolicy
    {
        static $sequence = 0;
        $sequence++;
        $ownerId = \App\Models\User::query()->value('id') ?? \App\Models\User::factory()->create()->id;

        return GovernancePolicy::create(array_merge([
            'owner_id' => $ownerId,
            'created_by' => $ownerId,
            'policy_code' => 'POL-TEST'.$sequence,
            'title' => 'Policy '.$sequence,
            'category' => 'governance',
            'content' => 'Policy wording',
            'version_number' => 1,
            'status' => 'draft',
            'requires_attestation' => false,
            'effective_from' => now()->subMonth()->toDateString(),
            'next_review_date' => now()->addYear()->toDateString(),
        ], $overrides));
    }

    public function test_policy_edit_route_redirects_to_the_wizard_deep_link(): void
    {
        $admin = $this->createAdminUser();
        $policy = $this->createPolicy();

        $this->actingAs($admin)
            ->get("/governance/policies/{$policy->id}/edit")
            ->assertRedirect("/governance/policies/{$policy->id}?edit=1");
    }

    public function test_policy_edit_redirect_still_requires_manage_permission(): void
    {
        $member = $this->createUserWithRole('board_member');
        $policy = $this->createPolicy();

        $this->actingAs($member)
            ->get("/governance/policies/{$policy->id}/edit")
            ->assertForbidden();
    }

    public function test_policy_register_filters_by_presented_status_review_and_search(): void
    {
        $admin = $this->createAdminUser();
        $approved = $this->createPolicy(['title' => 'Delegations of Authority', 'status' => 'approved']);
        $overdue = $this->createPolicy([
            'title' => 'Conflicts of Interest',
            'status' => 'approved',
            'requires_attestation' => true,
            'next_review_date' => now()->subDay()->toDateString(),
        ]);
        $this->createPolicy(['title' => 'Draft Treasury Policy', 'status' => 'draft']);

        $this->actingAs($admin)
            ->get('/governance/policies?status=active')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Policies/Index')
                ->has('policies.data', 2)
                ->where('filters.status', 'active')
                ->where('summary.total', 3)
                ->where('summary.active', 2)
                ->where('summary.draft', 1)
                ->where('summary.requires_attestation', 1)
                ->where('summary.review_overdue', 1)
                ->has('categories', 8));

        $this->actingAs($admin)
            ->get('/governance/policies?review=overdue')
            ->assertInertia(fn ($page) => $page
                ->has('policies.data', 1)
                ->where('policies.data.0.id', $overdue->id));

        $this->actingAs($admin)
            ->get('/governance/policies?search=Delegations')
            ->assertInertia(fn ($page) => $page
                ->has('policies.data', 1)
                ->where('policies.data.0.id', $approved->id)
                ->where('filters.search', 'Delegations'));
    }

    public function test_policy_show_carries_every_field_the_edit_wizard_prefills(): void
    {
        $admin = $this->createAdminUser();
        $policy = $this->createPolicy([
            'purpose' => 'Why the board holds this policy',
            'requires_attestation' => true,
            'attestation_frequency' => 'annual',
        ]);

        $this->actingAs($admin)
            ->get("/governance/policies/{$policy->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Policies/Show')
                ->where('canEdit', true)
                ->where('policy.description', 'Why the board holds this policy')
                ->where('policy.content', 'Policy wording')
                ->where('policy.requires_attestation', true)
                ->where('policy.attestation_frequency', 'annual')
                ->has('policy.effective_date')
                ->has('policy.review_date')
                ->where('policy.status', 'draft'));
    }

    public function test_evaluation_create_redirects_and_register_filters_presented_status(): void
    {
        $admin = $this->createAdminUser();
        $open = BoardEvaluation::create([
            'title' => 'Open review',
            'evaluation_type' => 'board',
            'year' => 2026,
            'status' => 'open',
            'questions' => [['id' => 1, 'question' => 'Q1', 'type' => 'rating']],
            'created_by' => $admin->id,
        ]);
        BoardEvaluation::create([
            'title' => 'Chair draft',
            'evaluation_type' => 'chair',
            'year' => 2026,
            'status' => 'draft',
            'questions' => [['id' => 1, 'question' => 'Q1', 'type' => 'rating']],
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get('/governance/evaluations/create')
            ->assertRedirect('/governance/evaluations?create=1');

        $this->actingAs($admin)
            ->get('/governance/evaluations?status=active')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Evaluations/Index')
                ->has('evaluations.data', 1)
                ->where('evaluations.data.0.id', $open->id)
                ->where('summary.total', 2)
                ->where('summary.active', 1)
                ->where('summary.draft', 1));

        $this->actingAs($admin)
            ->get('/governance/evaluations?type=chair')
            ->assertInertia(fn ($page) => $page
                ->has('evaluations.data', 1)
                ->where('evaluations.data.0.title', 'Chair draft'));
    }

    public function test_policy_create_link_reaches_the_register_wizard(): void
    {
        $this->actingAs($this->createAdminUser())
            ->get('/governance/policies/create')
            ->assertRedirect('/governance/policies?create=1');
    }

    public function test_evaluation_results_never_tie_answers_to_a_member(): void
    {
        $admin = $this->createAdminUser();
        $evaluation = BoardEvaluation::create([
            'title' => 'Annual board review',
            'evaluation_type' => 'board',
            'year' => 2026,
            'status' => 'closed',
            'questions' => [['id' => 1, 'question' => 'Board effectiveness', 'type' => 'rating']],
            'created_by' => $admin->id,
        ]);

        $named = $this->createBoardMember($this->createUserWithRole('board_member', ['name' => 'Aroha Named']));
        $anonymous = $this->createBoardMember($this->createUserWithRole('board_member', ['name' => 'Hemi Anonymous']));
        $draft = $this->createBoardMember($this->createUserWithRole('board_member', ['name' => 'Mere Draft']));

        \App\Domain\Governance\Models\BoardEvaluationResponse::create([
            'board_evaluation_id' => $evaluation->id,
            'board_member_id' => $named->id,
            'answers' => [['question_id' => 1, 'answer' => '5', 'rating' => 5]],
            'is_anonymous' => false,
            'submitted_at' => now(),
        ]);
        \App\Domain\Governance\Models\BoardEvaluationResponse::create([
            'board_evaluation_id' => $evaluation->id,
            'board_member_id' => $anonymous->id,
            'answers' => [['question_id' => 1, 'answer' => '2', 'rating' => 2]],
            'is_anonymous' => true,
            'submitted_at' => now(),
        ]);
        \App\Domain\Governance\Models\BoardEvaluationResponse::create([
            'board_evaluation_id' => $evaluation->id,
            'board_member_id' => $draft->id,
            'answers' => [['question_id' => 1, 'answer' => '1', 'rating' => 1]],
            'is_anonymous' => false,
            'submitted_at' => null,
        ]);

        $response = $this->actingAs($admin)->get("/governance/evaluations/{$evaluation->id}/results");

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Governance/Evaluations/Results')
            ->has('evaluation.responses', 3)
            ->has('evaluation.respondents', 1)
            ->where('evaluation.respondents.0.name', 'Aroha Named')
            ->where('evaluation.anonymous_respondent_count', 1));

        $props = $response->viewData('page')['props']['evaluation'];
        foreach ($props['responses'] as $row) {
            $this->assertSame(['submitted', 'answers'], array_keys($row));
        }
        $this->assertCount(2, array_filter($props['responses'], fn ($row) => $row['submitted']));
        $this->assertNull(collect($props['responses'])->firstWhere('submitted', false)['answers']);
        foreach ($props['respondents'] as $row) {
            $this->assertArrayNotHasKey('answers', $row);
        }
        $this->assertStringNotContainsString('Hemi Anonymous', json_encode($props));
        $this->assertStringNotContainsString('Mere Draft', json_encode($props));
    }

    public function test_evaluation_create_redirect_requires_manage_permission(): void
    {
        $member = $this->createUserWithRole('board_member');

        $this->actingAs($member)
            ->get('/governance/evaluations/create')
            ->assertForbidden();
    }

    public function test_document_register_summary_and_removal_returns_to_the_register(): void
    {
        $admin = $this->createAdminUser();
        $document = GovernanceDocument::create([
            'title' => 'Trust Deed',
            'document_type' => 'constitution',
            'file_path' => 'governance/documents/constitution/trust-deed.pdf',
            'file_size' => 1024,
            'uploaded_by' => $admin->id,
            'version_number' => 1,
            'is_current' => true,
        ]);
        GovernanceDocument::create([
            'title' => 'Board Policy Pack',
            'document_type' => 'policy',
            'file_path' => 'governance/documents/policy/pack.pdf',
            'file_size' => 2048,
            'uploaded_by' => $admin->id,
            'version_number' => 1,
            'is_current' => true,
        ]);

        $this->actingAs($admin)
            ->get('/governance/documents?document_type=constitution')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Documents/Index')
                ->has('documents.data', 1)
                ->where('filters.document_type', 'constitution')
                ->where('summary.total', 2)
                ->where('summary.by_type.constitution', 1)
                ->where('summary.by_type.policy', 1));

        $this->actingAs($admin)
            ->from("/governance/documents/{$document->id}")
            ->delete("/governance/documents/{$document->id}")
            ->assertRedirect('/governance/documents');
    }

    public function test_records_search_keeps_the_record_type_query_in_section_pagination(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get('/governance/records?tab=documents&category=policy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Records/Index')
                ->where('tab', 'documents')
                ->where('category', 'policy')
                ->where('capabilities.documents', true)
                ->where('documents.first_page_url', fn ($url) => str_contains((string) $url, 'tab=documents')
                    && str_contains((string) $url, 'category=policy')));
    }

    public function test_interests_register_offers_self_declaration_only_for_the_viewers_record(): void
    {
        $user = $this->createUserWithRole('board_member');
        $boardMember = $this->createBoardMember($user);
        BoardMemberInterest::create([
            'board_member_id' => $boardMember->id,
            'interest_type' => 'financial',
            'entity_name' => 'Acme Ltd',
            'description' => 'Shareholding',
            'nature' => 'Shareholder',
            'declared_at' => now()->subMonth()->toDateString(),
            'is_current' => true,
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get('/governance/interests')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Interests/Index')
                ->where('myBoardMemberId', $boardMember->id)
                ->where("interestsByMember.{$boardMember->id}.0.member_name", $user->name)
                ->where("interestsByMember.{$boardMember->id}.0.board_member_id", $boardMember->id));
    }

    private function candidateBoardRulesProfile(): \App\Domain\Governance\Models\GovernanceVotingProfile
    {
        \App\Domain\Governance\Models\GovernanceVotingProfile::query()
            ->update(['is_active' => false, 'approved_at' => null]);

        return app(\App\Domain\Governance\Services\GovernanceVotingProfileService::class)->createProfile([
            'governing_body' => 'board',
            'governing_document_reference' => 'Trust deed 2026',
            'governing_document_version' => 'v3',
        ], $this->createAdminUser(['email' => 'rules-author@example.test']));
    }

    public function test_settings_activation_offers_bound_resolution_and_activates_rules(): void
    {
        $admin = $this->createAdminUser();
        $profile = $this->candidateBoardRulesProfile();
        $resolution = $this->createBoundCarriedResolution(
            $admin,
            \App\Domain\Governance\Models\GovernanceResolutionBinding::SUBJECT_VOTING_PROFILE,
            $profile->id,
        );

        $this->actingAs($admin)
            ->get('/governance/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Settings/Index')
                ->where('rulesProfile.activation.profile_id', $profile->id)
                ->where('rulesProfile.activation.is_active', false)
                ->has('rulesProfile.approvalResolutions', 1)
                ->where('rulesProfile.approvalResolutions.0.id', $resolution->id));

        $this->actingAs($admin)
            ->from('/governance/settings')
            ->post('/governance/settings/rules/activate', [
                'governing_document_reference' => 'Trust deed 2026',
                'governing_document_version' => 'v3',
                'approved_by_resolution_id' => $resolution->id,
            ])
            ->assertRedirect('/governance/settings')
            ->assertSessionHas('success')
            ->assertSessionMissing('error');

        $profile->refresh();
        $this->assertTrue($profile->is_active);
        $this->assertSame($resolution->id, $profile->approved_by_resolution_id);
    }

    public function test_settings_activation_rejects_a_resolution_not_bound_to_the_rules(): void
    {
        $admin = $this->createAdminUser();
        $profile = $this->candidateBoardRulesProfile();
        $unbound = $this->createResolution($admin, [
            'status' => 'closed',
            'outcome' => 'carried',
            'closed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/governance/settings')
            ->assertInertia(fn ($page) => $page
                ->where('rulesProfile.activation.profile_id', $profile->id)
                ->has('rulesProfile.approvalResolutions', 0));

        $this->actingAs($admin)
            ->from('/governance/settings')
            ->post('/governance/settings/rules/activate', [
                'governing_document_reference' => 'Trust deed 2026',
                'governing_document_version' => 'v3',
                'approved_by_resolution_id' => $unbound->id,
            ])
            ->assertRedirect('/governance/settings')
            ->assertSessionHas('error');

        $this->assertFalse($profile->fresh()->is_active);
    }

    public function test_settings_activation_options_are_withheld_from_read_only_viewers(): void
    {
        $viewer = $this->createUserWithRole('board_member');
        $viewer->permissionOverrides()->syncWithoutDetaching([
            (int) \App\Models\Permission::query()->where('key', 'governance.settings.view')->value('id') => ['allowed' => true],
        ]);
        $viewer = $viewer->fresh();
        $this->assertTrue($viewer->canDo('governance.settings.view'));
        $this->assertFalse($viewer->canDo('governance.settings.manage'));

        $this->actingAs($viewer)
            ->get('/governance/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canManage', false)
                ->where('rulesProfile.activation', null)
                ->has('rulesProfile.approvalResolutions', 0)
                // The person picker's user directory is for managers only.
                ->has('people', 0)
                ->has('rulesProfile.approvalMeetings', 0));
    }

    public function test_settings_explains_how_the_board_votes_and_why_members_cannot_vote(): void
    {
        $admin = $this->createAdminUser(['name' => 'Chair Person']);
        $this->createBoardMember($admin, ['board_role' => 'chair']);
        $this->createBoardMember(
            $this->createUserWithRole('board_observer', ['name' => 'Olive Observer']),
            ['board_role' => 'observer', 'has_voting_seat' => false],
        );
        $this->createBoardMember(
            $this->createUserWithRole('board_member', ['name' => 'Eru Ended']),
            ['term_start' => now()->subYears(3)->toDateString(), 'term_end' => now()->subMonth()->toDateString()],
        );

        $this->actingAs($admin)
            ->get('/governance/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rulesProfile.status', 'on')
                ->where('rulesProfile.eligibleVoterCount', 1)
                ->where('rulesProfile.memberCount', 3)
                ->where('rulesProfile.quorumRequired', 1)
                ->where('rulesProfile.members.0.name', 'Chair Person')
                ->where('rulesProfile.members.0.can_vote', true)
                ->where('rulesProfile.members.0.why_not', null)
                ->where('rulesProfile.members.1.name', 'Eru Ended')
                ->where('rulesProfile.members.1.why_not', fn ($reason) => str_starts_with((string) $reason, 'Term ended '))
                ->where('rulesProfile.members.2.why_not', "Observer (observers can't vote)")
                // Only settings the app actually reads are offered.
                ->has('settings', 6)
                ->where('settings.0.default_label', '$5,000'));
    }

    public function test_settings_save_counts_only_the_values_that_changed(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->from('/governance/settings')
            ->put('/governance/settings', [
                'settings' => [
                    'spend_approval.threshold.capex' => '5000',
                    'spend_approval.threshold.opex' => '12000',
                    'compliance.escalation.max_level' => '3',
                    'compliance.escalation.final_notify_user_id' => (string) $admin->id,
                ],
            ])
            ->assertRedirect('/governance/settings')
            ->assertSessionHas('success', 'Saved 2 changes.');

        $this->assertSame('12000', \App\Domain\Governance\Models\GovernanceSetting::query()->where('key', 'spend_approval.threshold.opex')->value('value'));
        $this->assertNull(\App\Domain\Governance\Models\GovernanceSetting::query()->where('key', 'spend_approval.threshold.capex')->value('value'));

        $this->actingAs($admin)
            ->from('/governance/settings')
            ->put('/governance/settings', ['settings' => ['spend_approval.threshold.opex' => '12000']])
            ->assertSessionHas('success', 'No changes to save.');

        $this->actingAs($admin)
            ->from('/governance/settings')
            ->put('/governance/settings', ['settings' => ['compliance.escalation.final_notify_user_id' => '999999']])
            ->assertSessionHasErrors(['settings.compliance.escalation.final_notify_user_id' => 'Choose a person from the list.']);
    }

    public function test_chair_records_the_boards_first_approval_and_switches_voting_on(): void
    {
        $admin = $this->createAdminUser();
        \App\Domain\Governance\Models\GovernanceVotingProfile::query()->delete();
        $meeting = $this->createMeeting($admin, ['scheduled_at' => now()->subMonths(2), 'title' => 'July board meeting']);

        $this->actingAs($admin)
            ->get('/governance/settings')
            ->assertInertia(fn ($page) => $page
                ->where('rulesProfile.status', 'off')
                ->where('rulesProfile.activation.mode', 'record_first_approval')
                ->where('rulesProfile.approvalMeetings.0.id', $meeting->id));

        // Without the governing document saved, the approval can't be recorded yet.
        $this->actingAs($admin)
            ->from('/governance/settings')
            ->post('/governance/settings/rules/record-approval', [
                'approved_on' => now('Pacific/Auckland')->subMonths(2)->toDateString(),
                'approval_minutes_reference' => 'July board meeting minutes, item 6',
            ])
            ->assertSessionHas('error');

        $this->actingAs($admin)
            ->from('/governance/settings')
            ->post('/governance/settings/rules', [
                'legal_form' => 'charitable_trust',
                'governing_document_reference' => 'Trust deed',
                'governing_document_version' => '2019',
                'quorum_mode' => 'majority_floor_plus_one',
                'written_voting_permitted' => true,
                'written_unanimity_required' => true,
            ])
            ->assertSessionHas('success', 'Voting rules saved.');

        $this->actingAs($admin)
            ->from('/governance/settings')
            ->post('/governance/settings/rules/record-approval', [
                'approved_on' => now('Pacific/Auckland')->subMonths(2)->toDateString(),
                'approval_minutes_reference' => 'July board meeting minutes, item 6',
                'approval_meeting_id' => $meeting->id,
            ])
            ->assertRedirect('/governance/settings')
            ->assertSessionHas('success', "Board voting is switched on. The board's approval of these voting rules is recorded.");

        $profile = \App\Domain\Governance\Models\GovernanceVotingProfile::query()->where('is_active', true)->firstOrFail();
        $this->assertSame('Trust deed', $profile->governing_document_reference);
        $this->assertSame('recorded_board_approval', $profile->approval_source);
        $this->assertSame($admin->id, $profile->approved_by_user_id);
        $this->assertTrue($profile->written_voting_permitted);

        $this->actingAs($admin)
            ->get('/governance/settings')
            ->assertInertia(fn ($page) => $page
                ->where('rulesProfile.status', 'on')
                ->where('rulesProfile.inForce.approval_minutes_reference', 'July board meeting minutes, item 6')
                ->where('rulesProfile.inForce.approval_meeting_title', 'July board meeting')
                ->where('rulesProfile.activation.mode', 'none'));

        // After the first switch-on, changes wait for a passed resolution.
        $this->actingAs($admin)
            ->from('/governance/settings')
            ->post('/governance/settings/rules', [
                'legal_form' => 'charitable_trust',
                'governing_document_reference' => 'Trust deed',
                'governing_document_version' => '2019',
                'quorum_mode' => 'fixed_count',
                'quorum_formula' => '3',
                'written_voting_permitted' => true,
                'written_unanimity_required' => true,
            ])
            ->assertSessionHas('success', "Your changes are saved as proposed voting rules. They'll be used once the board passes a resolution that approves them.");

        $this->actingAs($admin)
            ->from('/governance/settings')
            ->post('/governance/settings/rules/record-approval', [
                'approved_on' => now('Pacific/Auckland')->toDateString(),
                'approval_minutes_reference' => 'Minutes, today',
            ])
            ->assertSessionHas('error', 'Voting rules have already been switched on for this board. To change them, the board needs to pass a resolution that approves the new rules.');

        $live = \App\Domain\Governance\Models\GovernanceVotingProfile::query()->where('is_active', true)->get();
        $this->assertCount(1, $live);
        $this->assertSame('majority_floor_plus_one', $live->first()->quorum_mode);

        $this->actingAs($admin)
            ->get('/governance/settings')
            ->assertInertia(fn ($page) => $page
                ->where('rulesProfile.hasPendingChanges', true)
                ->where('rulesProfile.activation.mode', 'resolution')
                ->where('rulesProfile.rules.quorum_mode', 'fixed_count')
                ->where('rulesProfile.rules.quorum_formula', '3'));
    }

    public function test_audit_log_carries_header_meter_totals_and_filter_preserving_links(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get('/governance/audit-log?entity_type=GovernancePolicy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/AuditLog/Index')
                ->has('summary.all_time')
                ->has('summary.last_7_days')
                ->has('summary.last_7_days_from')
                ->where('filters.entity_type', 'GovernancePolicy'));
    }
}
