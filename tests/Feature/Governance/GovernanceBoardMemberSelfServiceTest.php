<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardEvaluation;
use App\Domain\Governance\Models\BoardEvaluationResponse;
use App\Domain\Governance\Models\BoardMemberInterest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceBoardMemberSelfServiceTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_board_member_can_declare_own_interest_with_normalized_fields(): void
    {
        $user = $this->createUserWithRole('board_member');
        $boardMember = $this->createBoardMember($user);

        $response = $this->actingAs($user)->post('/governance/interests', [
            'board_member_id' => $boardMember->id,
            'interest_type' => 'professional',
            'description' => 'External advisory role',
            'organization_name' => 'Acme Advisory Limited',
            'nature_of_interest' => 'Paid director role',
            'date_from' => '2026-01-15',
            'date_to' => null,
            'is_active' => true,
        ]);

        $response->assertRedirect();
        // "From" is when the interest started; the declaration date is today (NZ).
        $this->assertDatabaseHas('board_member_interests', [
            'board_member_id' => $boardMember->id,
            'interest_type' => 'professional',
            'entity_name' => 'Acme Advisory Limited',
            'nature' => 'Paid director role',
            'started_on' => '2026-01-15',
            'declared_at' => now('Pacific/Auckland')->toDateString(),
            'ceased_at' => null,
            'is_current' => true,
            'recorded_by' => $user->id,
        ]);
    }

    public function test_a_member_can_update_and_end_their_own_declaration_but_not_someone_elses(): void
    {
        $user = $this->createUserWithRole('board_member');
        $boardMember = $this->createBoardMember($user);
        $otherUser = $this->createUserWithRole('board_member', ['email' => 'second-member@example.test']);
        $otherMember = $this->createBoardMember($otherUser);

        $mine = BoardMemberInterest::create([
            'board_member_id' => $boardMember->id,
            'interest_type' => 'professional',
            'entity_name' => 'Acme Advisory Limited',
            'description' => 'Advises on procurement',
            'nature' => 'Director',
            'started_on' => '2024-03-01',
            'declared_at' => '2026-01-10',
            'is_current' => true,
            'recorded_by' => $user->id,
        ]);
        $theirs = BoardMemberInterest::create([
            'board_member_id' => $otherMember->id,
            'interest_type' => 'family',
            'entity_name' => 'Bright Homes',
            'description' => 'Sister works there',
            'nature' => 'Family connection',
            'started_on' => '2023-01-01',
            'declared_at' => '2026-01-10',
            'is_current' => true,
            'recorded_by' => $otherUser->id,
        ]);

        $this->actingAs($user)
            ->from('/governance/interests/mine')
            ->put("/governance/interests/{$mine->id}", ['nature_of_interest' => 'Chair of the advisory board'])
            ->assertRedirect('/governance/interests/mine')
            ->assertSessionHas('success', 'Declaration updated.');
        $this->assertSame('Chair of the advisory board', $mine->fresh()->nature);

        // Declaring your own interests doesn't let you edit someone else's.
        $this->actingAs($user)->put("/governance/interests/{$theirs->id}", ['nature_of_interest' => 'Changed'])->assertForbidden();
        $this->actingAs($user)->post("/governance/interests/{$theirs->id}/end", ['ended_on' => now('Pacific/Auckland')->toDateString()])->assertForbidden();
        $this->assertSame('Family connection', $theirs->fresh()->nature);
        $this->assertTrue($theirs->fresh()->is_current);

        // An end date in the future, or before the interest started, is refused.
        $this->actingAs($user)
            ->from('/governance/interests/mine')
            ->post("/governance/interests/{$mine->id}/end", ['ended_on' => now('Pacific/Auckland')->addWeek()->toDateString()])
            ->assertSessionHasErrors('ended_on');
        $this->actingAs($user)
            ->from('/governance/interests/mine')
            ->post("/governance/interests/{$mine->id}/end", ['ended_on' => '2024-01-01'])
            ->assertSessionHasErrors(['ended_on' => 'The end date must be on or after the date the interest started (1 March 2024).']);

        $this->actingAs($user)
            ->from('/governance/interests/mine')
            ->post("/governance/interests/{$mine->id}/end", ['ended_on' => '2026-08-31'])
            ->assertRedirect('/governance/interests/mine')
            ->assertSessionHasNoErrors();

        $ended = $mine->fresh();
        $this->assertFalse($ended->is_current);
        $this->assertSame('2026-08-31', $ended->ceased_at->toDateString());

        // The register offers the row menu only on the viewer's own declaration.
        $this->actingAs($user)
            ->get('/governance/interests/mine')
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Interests/MyInterests')
                ->where('interests.0.can_update', true)
                ->where('interests.0.date_from', '2024-03-01')
                ->where('interests.0.declared_at', '2026-01-10'));
        $this->actingAs($user)
            ->get('/governance/interests')
            ->assertInertia(fn ($page) => $page
                ->where("interestsByMember.{$otherMember->id}.0.can_update", false));
    }

    public function test_a_board_manager_can_correct_any_declaration(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->createUserWithRole('board_member');
        $boardMember = $this->createBoardMember($member);
        $interest = BoardMemberInterest::create([
            'board_member_id' => $boardMember->id,
            'interest_type' => 'financial',
            'entity_name' => 'Acme Ltd',
            'description' => 'Shares',
            'nature' => 'Shareholder',
            'started_on' => '2022-05-01',
            'declared_at' => '2026-01-10',
            'is_current' => true,
            'recorded_by' => $member->id,
        ]);

        $this->actingAs($admin)
            ->put("/governance/interests/{$interest->id}", ['organization_name' => 'Acme Holdings Ltd'])
            ->assertRedirect();

        $this->assertSame('Acme Holdings Ltd', $interest->fresh()->entity_name);
    }

    public function test_board_member_cannot_declare_interest_for_another_member(): void
    {
        $user = $this->createUserWithRole('board_member');
        $myBoardMember = $this->createBoardMember($user);
        $otherUser = $this->createUserWithRole('board_member', ['email' => 'other-board-member@example.test']);
        $otherBoardMember = $this->createBoardMember($otherUser);

        $response = $this->actingAs($user)->post('/governance/interests', [
            'board_member_id' => $otherBoardMember->id,
            'interest_type' => 'professional',
            'description' => 'Should be rejected',
            'organization_name' => 'Another Organisation',
            'nature_of_interest' => 'Consultancy',
            'date_from' => '2026-01-15',
            'is_active' => true,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('board_member_interests', [
            'board_member_id' => $otherBoardMember->id,
            'description' => 'Should be rejected',
        ]);
        $this->assertDatabaseMissing('board_member_interests', [
            'board_member_id' => $myBoardMember->id,
            'description' => 'Should be rejected',
        ]);
    }

    public function test_board_member_can_respond_to_evaluation_with_view_permission_only(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createUserWithRole('board_member');
        $boardMember = $this->createBoardMember($user);

        $evaluation = BoardEvaluation::create([
            'title' => 'Board Effectiveness Review',
            'evaluation_type' => 'board',
            'year' => 2026,
            'status' => 'open',
            'questions' => [
                ['id' => 1, 'question' => 'Board papers arrive with enough lead time', 'type' => 'rating'],
                ['id' => 2, 'question' => 'What should improve next quarter?', 'type' => 'text'],
            ],
            'created_by' => $admin->id,
            'opened_at' => now(),
        ]);

        $this->assertFalse($user->canDo('governance.evaluations.manage'));
        $this->assertTrue($user->canDo('governance.evaluations.view'));

        $response = $this->actingAs($user)->post("/governance/evaluations/{$evaluation->id}/respond", [
            'answers' => [
                '0' => '4',
                '1' => 'Tighter alignment between packs and decision papers.',
            ],
            'overall_comments' => 'The new cockpit is much clearer for board members.',
        ]);

        $response->assertRedirect();

        $stored = BoardEvaluationResponse::query()
            ->where('board_evaluation_id', $evaluation->id)
            ->where('board_member_id', $boardMember->id)
            ->first();

        $this->assertNotNull($stored);
        $this->assertNotNull($stored->submitted_at);
        $this->assertSame(1, $stored->answers[0]['question_id']);
        $this->assertSame('4', (string) $stored->answers[0]['answer']);
        $this->assertSame(4, $stored->answers[0]['rating']);
        $this->assertSame('Tighter alignment between packs and decision papers.', $stored->answers[1]['answer']);
        $this->assertSame(
            'The new cockpit is much clearer for board members.',
            collect($stored->answers)->firstWhere('question_id', 'overall_comments')['answer']
        );
    }
}
