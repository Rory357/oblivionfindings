<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\BoardEvaluation;
use App\Domain\Governance\Models\BoardEvaluationResponse;
use App\Domain\Governance\Models\BoardMember;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BoardEvaluationController extends Controller
{
    public function index()
    {
        abort_unless(request()->user()?->canDo('governance.evaluations.view'), 403);

        $evaluations = BoardEvaluation::withCount('responses')
            ->orderByDesc('created_at')
            ->paginate(15);

        return Inertia::render('Governance/Evaluations/Index', [
            'evaluations' => $evaluations,
        ]);
    }

    public function create()
    {
        abort_unless(request()->user()?->canDo('governance.evaluations.manage'), 403);

        return Inertia::render('Governance/Evaluations/Create');
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->canDo('governance.evaluations.manage'), 403);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'evaluation_type' => 'required|in:board,committee,chair,individual',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
            'questions' => 'required|array|min:1',
            'questions.*.text' => 'required|string',
            'questions.*.type' => 'required|in:rating,text,yes_no',
            'due_date' => 'required|date|after:today',
        ]);

        $evaluation = BoardEvaluation::create([
            'title' => $validated['title'],
            'evaluation_type' => $validated['evaluation_type'],
            'year' => (int) date('Y', strtotime($validated['period_end'])),
            'status' => 'draft',
            'questions' => collect($validated['questions'])->values()->map(fn (array $question, int $index) => [
                'id' => $index + 1,
                'question' => $question['text'],
                'type' => $question['type'] === 'yes_no' ? 'yes_no' : $question['type'],
            ])->all(),
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('governance.evaluations.show', $evaluation)
            ->with('success', 'Board evaluation created.');
    }

    public function show(BoardEvaluation $evaluation)
    {
        abort_unless(request()->user()?->canDo('governance.evaluations.view'), 403);

        $evaluation->load('responses.boardMember.user');

        $boardMembers = BoardMember::with('user')->active()->get();
        $myResponse = null;

        if (auth()->user()->boardMember) {
            $myResponse = $evaluation->responses()
                ->where('board_member_id', auth()->user()->boardMember->id)
                ->first();
        }

        return Inertia::render('Governance/Evaluations/Show', [
            'evaluation' => [
                'id' => $evaluation->id,
                'title' => $evaluation->title,
                'evaluation_type' => $evaluation->evaluation_type,
                'status' => $this->presentEvaluationStatus($evaluation->status),
                'period_start' => now()->setYear($evaluation->year)->startOfYear()->toDateString(),
                'period_end' => now()->setYear($evaluation->year)->endOfYear()->toDateString(),
                'due_date' => ($evaluation->opened_at?->copy()->addWeeks(2) ?? now()->addWeeks(2))->toDateString(),
                'questions' => collect($evaluation->questions ?? [])->values()->map(fn (array $question) => [
                    'id' => $question['id'] ?? null,
                    'text' => $question['question'] ?? $question['text'] ?? '',
                    'type' => $question['type'] ?? 'text',
                ])->all(),
                'responses' => $evaluation->responses->map(fn (BoardEvaluationResponse $response) => [
                    'id' => $response->id,
                    'board_member' => $response->boardMember?->relationLoaded('user') ? ['user' => ['name' => $response->boardMember?->user?->name]] : null,
                    'is_complete' => $response->submitted_at !== null,
                    'submitted_at' => $response->submitted_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'boardMembers' => $boardMembers,
            'myResponse' => $myResponse ? $this->presentMyResponse($myResponse) : null,
            'responseRate' => [
                'total' => $boardMembers->count(),
                'completed' => $evaluation->responses()->whereNotNull('submitted_at')->count(),
            ],
        ]);
    }

    public function launch(BoardEvaluation $evaluation)
    {
        abort_unless(request()->user()?->canDo('governance.evaluations.manage'), 403);

        $evaluation->update([
            'status' => 'open',
            'opened_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Evaluation launched. Board members can now respond.');
    }

    public function respond(Request $request, BoardEvaluation $evaluation)
    {
        $boardMember = auth()->user()->boardMember;
        if (!$boardMember) {
            return redirect()->back()->with('error', 'You are not a board member.');
        }

        $validated = $request->validate([
            'answers' => 'required|array',
            'overall_comments' => 'nullable|string',
        ]);

        BoardEvaluationResponse::updateOrCreate(
            [
                'board_evaluation_id' => $evaluation->id,
                'board_member_id' => $boardMember->id,
            ],
            [
                'answers' => $this->normalizeAnswers($evaluation, $validated['answers'], $validated['overall_comments'] ?? null),
                'submitted_at' => now(),
            ]
        );

        return redirect()->back()->with('success', 'Response submitted.');
    }

    public function close(BoardEvaluation $evaluation)
    {
        abort_unless(request()->user()?->canDo('governance.evaluations.manage'), 403);

        $evaluation->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Evaluation closed.');
    }

    public function results(BoardEvaluation $evaluation)
    {
        abort_unless(request()->user()?->canDo('governance.evaluations.view'), 403);

        $evaluation->load('responses.boardMember.user');

        return Inertia::render('Governance/Evaluations/Results', [
            'evaluation' => $evaluation,
        ]);
    }

    protected function normalizeAnswers(BoardEvaluation $evaluation, array $answers, ?string $overallComments): array
    {
        $normalized = collect($evaluation->questions ?? [])->values()->map(function (array $question, int $index) use ($answers) {
            $value = $answers[(string) $index] ?? $answers[$index] ?? null;
            $answer = [
                'question_id' => $question['id'] ?? ($index + 1),
                'question' => $question['question'] ?? $question['text'] ?? '',
                'type' => $question['type'] ?? 'text',
                'answer' => $value,
            ];

            if (($question['type'] ?? null) === 'rating' && $value !== null) {
                $answer['rating'] = (int) $value;
            }

            return $answer;
        })->all();

        if ($overallComments) {
            $normalized[] = [
                'question_id' => 'overall_comments',
                'question' => 'Overall Comments',
                'type' => 'text',
                'answer' => $overallComments,
            ];
        }

        return $normalized;
    }

    protected function presentMyResponse(BoardEvaluationResponse $response): array
    {
        $answers = [];
        $overallComments = '';

        foreach ($response->answers ?? [] as $index => $answer) {
            if (($answer['question_id'] ?? null) === 'overall_comments') {
                $overallComments = (string) ($answer['answer'] ?? '');
                continue;
            }

            $answers[(string) $index] = (string) ($answer['answer'] ?? $answer['rating'] ?? '');
        }

        return [
            'answers' => $answers,
            'overall_comments' => $overallComments,
        ];
    }

    protected function presentEvaluationStatus(string $status): string
    {
        return match ($status) {
            'open' => 'active',
            default => $status,
        };
    }
}
