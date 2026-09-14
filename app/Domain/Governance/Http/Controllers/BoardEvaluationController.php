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
    public function index(Request $request)
    {
        $this->authorize('viewAny', BoardEvaluation::class);

        $search = trim((string) $request->query('search', ''));
        // Filters use the presented statuses ("active" is stored as open).
        $status = match ($request->query('status')) {
            'active' => 'open',
            'draft', 'closed' => $request->query('status'),
            default => null,
        };
        $type = in_array($request->query('type'), ['board', 'committee', 'chair', 'individual'], true)
            ? $request->query('type')
            : null;

        $evaluations = BoardEvaluation::withCount('responses')
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($type, fn ($q, $t) => $q->where('evaluation_type', $t))
            ->when($search !== '', fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(function (BoardEvaluation $evaluation) {
                return [
                    'id' => $evaluation->id,
                    'title' => $evaluation->title,
                    'evaluation_type' => $evaluation->evaluation_type,
                    'status' => $this->presentEvaluationStatus($evaluation->status),
                    'period_start' => $evaluation->period_start?->toDateString() ?? now()->setYear($evaluation->year)->startOfYear()->toDateString(),
                    'period_end' => $evaluation->period_end?->toDateString() ?? now()->setYear($evaluation->year)->endOfYear()->toDateString(),
                    'due_date' => $evaluation->due_date?->toDateString() ?? ($evaluation->opened_at?->copy()->addWeeks(2) ?? now()->setYear($evaluation->year)->endOfYear())->toDateString(),
                    'responses_count' => $evaluation->responses_count,
                ];
            });

        $statusCounts = BoardEvaluation::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('Governance/Evaluations/Index', [
            'evaluations' => $evaluations,
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'status' => $request->query('status'),
                'type' => $type,
            ],
            'summary' => [
                'total' => (int) $statusCounts->sum(),
                'active' => (int) ($statusCounts['open'] ?? 0),
                'draft' => (int) ($statusCounts['draft'] ?? 0),
                'closed' => (int) ($statusCounts['closed'] ?? 0),
                'active_board_members' => BoardMember::active()->count(),
            ],
        ]);
    }

    public function create()
    {
        $this->authorize('create', BoardEvaluation::class);

        // The full-page form was retired: the register opens the evaluation wizard.
        return redirect()->route('governance.evaluations.index', ['create' => 1]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', BoardEvaluation::class);

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
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'due_date' => $validated['due_date'],
            'version_number' => 1,
            'audience' => $request->input('audience', 'all_members'),
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
        $this->authorize('view', $evaluation);

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
                'period_start' => $evaluation->period_start?->toDateString() ?? now()->setYear($evaluation->year)->startOfYear()->toDateString(),
                'period_end' => $evaluation->period_end?->toDateString() ?? now()->setYear($evaluation->year)->endOfYear()->toDateString(),
                'due_date' => $evaluation->due_date?->toDateString() ?? ($evaluation->opened_at?->copy()->addWeeks(2) ?? now()->addWeeks(2))->toDateString(),
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
        $this->authorize('launch', $evaluation);

        $evaluation->update([
            'status' => 'open',
            'opened_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Evaluation launched. Board members can now respond.');
    }

    public function respond(Request $request, BoardEvaluation $evaluation)
    {
        $user = auth()->user();
        $boardMember = $user?->boardMember;
        if (! $boardMember || ! $boardMember->is_active) {
            abort(403, 'Only active board members may respond to evaluations.');
        }

        if ($evaluation->audience === 'chair_only' && $boardMember->role !== 'chair' && ! $user->hasRole('board_chair')) {
            abort(403, 'This evaluation is restricted to the Board Chair.');
        }

        if ($evaluation->status !== 'open') {
            abort(422, 'This evaluation is not currently open for responses.');
        }

        if ($evaluation->due_date && now()->isAfter($evaluation->due_date->copy()->endOfDay())) {
            abort(422, 'The submission deadline for this evaluation has passed.');
        }

        $validated = $request->validate([
            'answers' => 'required|array',
            'overall_comments' => 'nullable|string',
        ]);

        $questions = collect($evaluation->questions ?? []);
        foreach ($questions as $index => $q) {
            $val = $validated['answers'][(string) $index] ?? $validated['answers'][$index] ?? null;
            $qType = $q['type'] ?? 'text';
            $qId = $q['id'] ?? ($index + 1);

            if ($val === null) {
                abort(422, "Answer for question {$qId} is required.");
            }

            if ($qType === 'rating') {
                if (! is_numeric($val) || (string) (int) $val !== (string) $val || (int) $val < 1 || (int) $val > 5) {
                    abort(422, "Rating answer for question {$qId} must be an integer between 1 and 5.");
                }
            } elseif ($qType === 'yes_no') {
                $validYesNo = [0, 1, '0', '1', 'true', 'false', true, false, 'yes', 'no', 'Yes', 'No'];
                if (! in_array($val, $validYesNo, true)) {
                    abort(422, "Answer for question {$qId} must be a boolean yes/no value.");
                }
            } else {
                if (is_array($val)) {
                    abort(422, "Answer for question {$qId} must not be an array.");
                }
            }
        }

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
        $this->authorize('close', $evaluation);

        $evaluation->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Evaluation closed.');
    }

    public function results(BoardEvaluation $evaluation)
    {
        $this->authorize('results', $evaluation);

        $responses = $evaluation->responses()->with('boardMember.user')->get();
        $submitted = $responses->whereNotNull('submitted_at');

        // Answers are released detached from identity (no member, id or
        // timestamp, shuffled) so a response can't be traced to a member.
        // Participation is listed separately, without answers; members who
        // chose anonymity are counted but not named.
        $payload = $evaluation->withoutRelations()->toArray();
        $payload['responses'] = $responses
            ->map(fn ($response) => [
                'submitted' => $response->submitted_at !== null,
                'answers' => $response->submitted_at !== null ? ($response->answers ?? []) : null,
            ])
            ->shuffle()
            ->values()
            ->all();
        $payload['respondents'] = $submitted
            ->reject(fn ($response) => (bool) $response->is_anonymous)
            ->map(fn ($response) => [
                'name' => $response->boardMember?->user?->name ?? $response->boardMember?->name ?? 'Board member',
                'submitted_at' => $response->submitted_at?->toIso8601String(),
            ])
            ->sortBy('name')
            ->values()
            ->all();
        $payload['anonymous_respondent_count'] = $submitted->filter(fn ($response) => (bool) $response->is_anonymous)->count();

        return Inertia::render('Governance/Evaluations/Results', [
            'evaluation' => $payload,
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
