<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\BoardEvaluation;
use App\Domain\Governance\Models\BoardEvaluationResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * Evaluations: drafts can be edited, every question is checked in place (no
 * raw error pages), the form explains when it can't be used, and nobody's
 * answers or anonymity are tied to a name.
 */
class GovernanceBoardEvaluationFlowTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    private function evaluation(User $creator, array $overrides = []): BoardEvaluation
    {
        return BoardEvaluation::create(array_merge([
            'title' => 'Board effectiveness 2026',
            'evaluation_type' => 'board',
            'year' => 2026,
            'status' => 'open',
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'due_date' => now('Pacific/Auckland')->addWeek()->toDateString(),
            'questions' => [
                ['id' => 1, 'question' => 'Board papers arrive in good time', 'type' => 'rating'],
                ['id' => 2, 'question' => 'What should improve?', 'type' => 'text'],
            ],
            'created_by' => $creator->id,
            'opened_at' => now(),
        ], $overrides));
    }

    public function test_a_draft_can_be_edited_with_the_wizard_but_not_once_it_is_open(): void
    {
        $admin = $this->createAdminUser();
        $draft = $this->evaluation($admin, ['status' => 'draft', 'opened_at' => null]);

        $payload = [
            'title' => 'Board effectiveness review 2026',
            'evaluation_type' => 'board',
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'due_date' => now('Pacific/Auckland')->addMonth()->toDateString(),
            'questions' => [
                ['text' => 'Board papers arrive with enough time to read them', 'type' => 'rating'],
                ['text' => 'Meetings finish on time', 'type' => 'yes_no'],
            ],
        ];

        $this->actingAs($admin)
            ->from("/governance/evaluations/{$draft->id}")
            ->put("/governance/evaluations/{$draft->id}", $payload)
            ->assertRedirect("/governance/evaluations/{$draft->id}")
            ->assertSessionHas('success', 'Evaluation saved.');

        $fresh = $draft->fresh();
        $this->assertSame('Board effectiveness review 2026', $fresh->title);
        $this->assertSame('Meetings finish on time', $fresh->questions[1]['question']);
        $this->assertSame('yes_no', $fresh->questions[1]['type']);

        $open = $this->evaluation($admin);
        $this->actingAs($admin)
            ->from("/governance/evaluations/{$open->id}")
            ->put("/governance/evaluations/{$open->id}", $payload)
            ->assertSessionHas('error');
        $this->assertSame('Board effectiveness 2026', $open->fresh()->title);

        $member = $this->createUserWithRole('board_member');
        $this->actingAs($member)->put("/governance/evaluations/{$draft->id}", $payload)->assertForbidden();

        // Plain validation, keyed by question.
        $this->actingAs($admin)
            ->from("/governance/evaluations/{$draft->id}")
            ->put("/governance/evaluations/{$draft->id}", [...$payload, 'questions' => [['text' => '', 'type' => 'rating']]])
            ->assertSessionHasErrors(['questions.0.text' => 'Write the question.']);
    }

    public function test_unanswered_questions_come_back_as_messages_beside_each_question(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createUserWithRole('board_member');
        $this->createBoardMember($user);
        $evaluation = $this->evaluation($admin);

        $this->actingAs($user)
            ->from("/governance/evaluations/{$evaluation->id}")
            ->post("/governance/evaluations/{$evaluation->id}/respond", [
                'answers' => ['0' => '7', '1' => ''],
            ])
            ->assertRedirect("/governance/evaluations/{$evaluation->id}")
            ->assertSessionHasErrors([
                'answers.0' => 'Choose a rating from 1 to 5.',
                'answers.1' => 'Answer this question.',
            ]);

        $this->assertSame(0, BoardEvaluationResponse::query()->count());
    }

    public function test_the_form_explains_why_it_cannot_be_used(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->createUserWithRole('board_member');
        $this->createBoardMember($member);
        $pastDue = $this->evaluation($admin, ['due_date' => now('Pacific/Auckland')->subDays(2)->toDateString()]);

        $this->actingAs($member)
            ->get("/governance/evaluations/{$pastDue->id}")
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Evaluations/Show')
                ->where('respondBlockedReason', fn ($reason) => str_starts_with((string) $reason, 'Responses were due by ')));

        $this->actingAs($member)
            ->from("/governance/evaluations/{$pastDue->id}")
            ->post("/governance/evaluations/{$pastDue->id}/respond", ['answers' => ['0' => '4', '1' => 'Fine']])
            ->assertSessionHas('error');

        // Someone who isn't on the board sees why, and can't post a response.
        $admin2 = $this->createAdminUser();
        $open = $this->evaluation($admin);
        $this->actingAs($admin2)
            ->get("/governance/evaluations/{$open->id}")
            ->assertInertia(fn ($page) => $page->where('respondBlockedReason', 'Only board members answer this evaluation.'));
        $this->actingAs($admin2)
            ->post("/governance/evaluations/{$open->id}/respond", ['answers' => ['0' => '4', '1' => 'Fine']])
            ->assertForbidden();
    }

    public function test_who_responded_is_shown_without_anonymous_names_and_completion_uses_active_members(): void
    {
        $admin = $this->createAdminUser();
        $evaluation = $this->evaluation($admin);
        $named = $this->createBoardMember($this->createUserWithRole('board_member', ['name' => 'Aroha Named']));
        $anonymous = $this->createBoardMember($this->createUserWithRole('board_member', ['name' => 'Hemi Anonymous']));
        $this->createBoardMember($this->createUserWithRole('board_member', ['name' => 'Mere Not Yet']));

        BoardEvaluationResponse::create([
            'board_evaluation_id' => $evaluation->id,
            'board_member_id' => $named->id,
            'answers' => [['question_id' => 1, 'answer' => '5', 'rating' => 5]],
            'is_anonymous' => false,
            'submitted_at' => now(),
        ]);
        BoardEvaluationResponse::create([
            'board_evaluation_id' => $evaluation->id,
            'board_member_id' => $anonymous->id,
            'answers' => [['question_id' => 1, 'answer' => '2', 'rating' => 2]],
            'is_anonymous' => true,
            'submitted_at' => now(),
        ]);

        $show = $this->actingAs($admin)->get("/governance/evaluations/{$evaluation->id}");
        $show->assertInertia(fn ($page) => $page
            ->where('responseRate.total', 3)
            ->where('responseRate.completed', 2)
            ->has('evaluation.respondents', 1)
            ->where('evaluation.respondents.0.name', 'Aroha Named')
            ->where('evaluation.anonymous_respondent_count', 1)
            ->missing('evaluation.responses'));
        $this->assertStringNotContainsString('Hemi Anonymous', json_encode($show->viewData('page')['props']));

        // Results stay shut while answers are still coming in — even for the
        // people running the evaluation — and open once it closes.
        $this->actingAs($admin)
            ->get("/governance/evaluations/{$evaluation->id}/results")
            ->assertForbidden();
        $this->actingAs($named->user)
            ->get("/governance/evaluations/{$evaluation->id}/results")
            ->assertForbidden();

        $evaluation->update(['status' => 'closed', 'closed_at' => now()]);

        $this->actingAs($admin)
            ->get("/governance/evaluations/{$evaluation->id}/results")
            ->assertInertia(fn ($page) => $page->where('evaluation.active_member_count', 3));
        $this->actingAs($named->user)
            ->get("/governance/evaluations/{$evaluation->id}/results")
            ->assertOk();

        // The register tells a member whether they've responded.
        $this->actingAs($named->user)
            ->get('/governance/evaluations')
            ->assertInertia(fn ($page) => $page
                ->where('is_board_member', true)
                ->where('evaluations.data.0.my_response', 'responded'));
    }

    public function test_a_committee_evaluation_names_the_committee(): void
    {
        $admin = $this->createAdminUser();
        $committee = BoardCommittee::create([
            'committee_type' => 'finance',
            'name' => 'Finance and audit committee',
            'is_active' => true,
        ]);

        $payload = [
            'title' => 'Finance committee review 2026',
            'evaluation_type' => 'committee',
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'due_date' => now('Pacific/Auckland')->addMonth()->toDateString(),
            'questions' => [['text' => 'The committee reports clearly to the board', 'type' => 'rating']],
        ];

        $this->actingAs($admin)
            ->from('/governance/evaluations')
            ->post('/governance/evaluations', $payload)
            ->assertSessionHasErrors(['board_committee_id' => 'Choose which committee is being evaluated.']);

        $this->actingAs($admin)
            ->post('/governance/evaluations', [...$payload, 'board_committee_id' => $committee->id])
            ->assertSessionHasNoErrors();

        $evaluation = BoardEvaluation::query()->where('title', 'Finance committee review 2026')->firstOrFail();
        $this->assertSame($committee->id, (int) $evaluation->board_committee_id);

        $this->actingAs($admin)
            ->get("/governance/evaluations/{$evaluation->id}")
            ->assertInertia(fn ($page) => $page
                ->where('evaluation.committee_name', 'Finance and audit committee')
                ->where('committees', fn ($committees) => collect($committees)->contains(fn ($c) => $c['id'] === $committee->id)));

        // Changing the draft to a whole-board evaluation drops the committee.
        $this->actingAs($admin)
            ->put("/governance/evaluations/{$evaluation->id}", [...$payload, 'evaluation_type' => 'board', 'board_committee_id' => $committee->id])
            ->assertSessionHasNoErrors();
        $this->assertNull($evaluation->fresh()->board_committee_id);
    }

    public function test_opening_and_closing_only_happen_from_the_right_state(): void
    {
        $admin = $this->createAdminUser();
        $open = $this->evaluation($admin);

        $this->actingAs($admin)
            ->from("/governance/evaluations/{$open->id}")
            ->post("/governance/evaluations/{$open->id}/launch")
            ->assertSessionHas('error', 'This evaluation is already open or closed.');

        $this->actingAs($admin)
            ->from("/governance/evaluations/{$open->id}")
            ->post("/governance/evaluations/{$open->id}/close")
            ->assertSessionHas('success', 'Evaluation closed. No more responses can be added.');

        $this->assertSame('closed', $open->fresh()->status);
    }
}
