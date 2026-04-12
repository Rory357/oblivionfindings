<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardEvaluation;
use App\Domain\Governance\Models\BoardEvaluationResponse;
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
        $this->assertDatabaseHas('board_member_interests', [
            'board_member_id' => $boardMember->id,
            'interest_type' => 'professional',
            'entity_name' => 'Acme Advisory Limited',
            'nature' => 'Paid director role',
            'declared_at' => '2026-01-15',
            'ceased_at' => null,
            'is_current' => true,
            'recorded_by' => $user->id,
        ]);
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
