<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\BoardEvaluation;
use App\Domain\Governance\Models\BoardEvaluationResponse;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

        $myBoardMemberId = $this->activeBoardMemberId($request->user());

        $evaluations = BoardEvaluation::withCount(['responses' => fn ($q) => $q->whereNotNull('submitted_at')])
            ->with('committee:id,name')
            ->when($myBoardMemberId !== null, fn ($q) => $q->withExists([
                'responses as my_response_submitted' => fn ($r) => $r
                    ->where('board_member_id', $myBoardMemberId)
                    ->whereNotNull('submitted_at'),
            ]))
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($type, fn ($q, $t) => $q->where('evaluation_type', $t))
            ->when($search !== '', fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(function (BoardEvaluation $evaluation) use ($myBoardMemberId) {
                return [
                    'id' => $evaluation->id,
                    'title' => $evaluation->title,
                    'evaluation_type' => $evaluation->evaluation_type,
                    'committee_name' => $evaluation->committee?->name,
                    'status' => $this->presentEvaluationStatus($evaluation->status),
                    'period_start' => $evaluation->period_start?->toDateString() ?? now()->setYear($evaluation->year)->startOfYear()->toDateString(),
                    'period_end' => $evaluation->period_end?->toDateString() ?? now()->setYear($evaluation->year)->endOfYear()->toDateString(),
                    'due_date' => $evaluation->due_date?->toDateString() ?? ($evaluation->opened_at?->copy()->addWeeks(2) ?? now()->setYear($evaluation->year)->endOfYear())->toDateString(),
                    'responses_count' => $evaluation->responses_count,
                    // "You: Responded / Not yet" — only for people who answer evaluations.
                    'my_response' => $myBoardMemberId === null || $evaluation->status === 'draft'
                        ? null
                        : ((bool) $evaluation->my_response_submitted ? 'responded' : 'not_yet'),
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
            'is_board_member' => $myBoardMemberId !== null,
            'today' => $this->today()->toDateString(),
            'committees' => $request->user()->can('create', BoardEvaluation::class) ? $this->committeeOptions() : [],
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

        $validated = $this->validateEvaluation($request);

        $evaluation = BoardEvaluation::create([
            ...$this->evaluationAttributes($validated),
            'version_number' => 1,
            'audience' => $request->input('audience', 'all_members'),
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('governance.evaluations.show', $evaluation)
            ->with('success', 'Evaluation saved as a draft. Open it for responses when the questions are ready.');
    }

    /** Edit a draft with the same wizard used to create it. */
    public function update(Request $request, BoardEvaluation $evaluation)
    {
        $this->authorize('update', $evaluation);

        if ($evaluation->status !== 'draft') {
            return redirect()->back()->with('error', "This evaluation is already open or closed, so its questions can't be changed.");
        }

        $validated = $this->validateEvaluation($request);

        $evaluation->update([
            ...$this->evaluationAttributes($validated),
            'version_number' => (int) ($evaluation->version_number ?? 1) + 1,
        ]);

        return redirect()->back()->with('success', 'Evaluation saved.');
    }

    public function show(Request $request, BoardEvaluation $evaluation)
    {
        $this->authorize('view', $evaluation);

        $viewer = $request->user();
        $evaluation->load(['responses.boardMember.user', 'committee:id,name']);

        $activeMemberCount = BoardMember::active()->count();
        $boardMember = $viewer->boardMember;
        $myResponse = null;

        if ($boardMember) {
            $myResponse = $evaluation->responses
                ->firstWhere('board_member_id', $boardMember->id);
        }

        $submitted = $evaluation->responses->filter(fn (BoardEvaluationResponse $response) => $response->submitted_at !== null);

        return Inertia::render('Governance/Evaluations/Show', [
            'evaluation' => [
                'id' => $evaluation->id,
                'title' => $evaluation->title,
                'evaluation_type' => $evaluation->evaluation_type,
                'board_committee_id' => $evaluation->board_committee_id,
                'committee_name' => $evaluation->committee?->name,
                'status' => $this->presentEvaluationStatus($evaluation->status),
                'period_start' => $evaluation->period_start?->toDateString() ?? now()->setYear($evaluation->year)->startOfYear()->toDateString(),
                'period_end' => $evaluation->period_end?->toDateString() ?? now()->setYear($evaluation->year)->endOfYear()->toDateString(),
                'due_date' => $evaluation->due_date?->toDateString() ?? ($evaluation->opened_at?->copy()->addWeeks(2) ?? now()->addWeeks(2))->toDateString(),
                'questions' => collect($evaluation->questions ?? [])->values()->map(fn (array $question) => [
                    'id' => $question['id'] ?? null,
                    'text' => $question['question'] ?? $question['text'] ?? '',
                    'type' => $question['type'] ?? 'text',
                    'required' => true,
                ])->all(),
                // Who has responded — by name only when they didn't choose to
                // stay anonymous. Answers are never on this page.
                'respondents' => $submitted
                    ->reject(fn (BoardEvaluationResponse $response) => (bool) $response->is_anonymous)
                    ->map(fn (BoardEvaluationResponse $response) => [
                        'name' => $response->boardMember?->user?->name ?? 'Board member',
                        'submitted_at' => $response->submitted_at?->toIso8601String(),
                    ])
                    ->sortBy('name')
                    ->values()
                    ->all(),
                'anonymous_respondent_count' => $submitted->filter(fn (BoardEvaluationResponse $response) => (bool) $response->is_anonymous)->count(),
            ],
            'myResponse' => $myResponse ? $this->presentMyResponse($myResponse) : null,
            'responseRate' => [
                'total' => $activeMemberCount,
                'completed' => $submitted->count(),
            ],
            'respondBlockedReason' => $this->respondBlockedReason($evaluation, $viewer),
            'committees' => $evaluation->status === 'draft' && $viewer->can('update', $evaluation) ? $this->committeeOptions() : [],
        ]);
    }

    public function launch(BoardEvaluation $evaluation)
    {
        $this->authorize('launch', $evaluation);

        if ($evaluation->status !== 'draft') {
            return redirect()->back()->with('error', 'This evaluation is already open or closed.');
        }

        $evaluation->update([
            'status' => 'open',
            'opened_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Evaluation open. Board members can now respond.');
    }

    public function respond(Request $request, BoardEvaluation $evaluation)
    {
        $user = auth()->user();
        $boardMember = $user?->boardMember;

        $blocked = $this->respondBlockedReason($evaluation, $user);
        if ($blocked !== null) {
            if (! $boardMember || ! $boardMember->is_active || $this->isChairOnlyFor($evaluation, $user)) {
                abort(403, $blocked);
            }

            return redirect()->back()->with('error', $blocked);
        }

        $validated = $request->validate([
            'answers' => 'nullable|array',
            'overall_comments' => 'nullable|string|max:5000',
        ], [
            'overall_comments.max' => 'Keep your overall comments under 5,000 characters.',
        ]);
        $answers = $validated['answers'] ?? [];

        // Every question needs an answer. Errors are keyed by question so the
        // form can show each message beside the question it belongs to.
        $errors = [];
        foreach (collect($evaluation->questions ?? [])->values() as $index => $q) {
            $val = $answers[(string) $index] ?? $answers[$index] ?? null;
            $qType = $q['type'] ?? 'text';
            $key = "answers.{$index}";

            if ($val === null || (is_string($val) && trim($val) === '')) {
                $errors[$key] = match ($qType) {
                    'rating' => 'Choose a rating from 1 to 5.',
                    'yes_no' => 'Choose Yes or No.',
                    default => 'Answer this question.',
                };

                continue;
            }

            if ($qType === 'rating') {
                if (! is_numeric($val) || (string) (int) $val !== (string) $val || (int) $val < 1 || (int) $val > 5) {
                    $errors[$key] = 'Choose a rating from 1 to 5.';
                }
            } elseif ($qType === 'yes_no') {
                $validYesNo = [0, 1, '0', '1', 'true', 'false', true, false, 'yes', 'no', 'Yes', 'No'];
                if (! in_array($val, $validYesNo, true)) {
                    $errors[$key] = 'Choose Yes or No.';
                }
            } elseif (is_array($val)) {
                $errors[$key] = 'Answer this question in words.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        BoardEvaluationResponse::updateOrCreate(
            [
                'board_evaluation_id' => $evaluation->id,
                'board_member_id' => $boardMember->id,
            ],
            [
                'answers' => $this->normalizeAnswers($evaluation, $answers, $validated['overall_comments'] ?? null),
                'submitted_at' => now(),
            ]
        );

        return redirect()->back()->with('success', 'Thanks — your response is saved. You can change it until the evaluation closes.');
    }

    public function close(BoardEvaluation $evaluation)
    {
        $this->authorize('close', $evaluation);

        if ($evaluation->status !== 'open') {
            return redirect()->back()->with('error', $evaluation->status === 'closed'
                ? 'This evaluation is already closed.'
                : "This evaluation hasn't been opened yet.");
        }

        $evaluation->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Evaluation closed. No more responses can be added.');
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
        // Completion is measured against everyone who could answer.
        $payload['active_member_count'] = BoardMember::active()->count();
        $payload['committee_name'] = $evaluation->committee()->value('name');

        return Inertia::render('Governance/Evaluations/Results', [
            'evaluation' => $payload,
        ]);
    }

    /** @return array<string, mixed> */
    private function validateEvaluation(Request $request): array
    {
        $today = $this->today()->toDateString();

        return $request->validate([
            'title' => 'required|string|max:255',
            'evaluation_type' => 'required|in:board,committee,chair,individual',
            'board_committee_id' => 'nullable|required_if:evaluation_type,committee|integer|exists:board_committees,id',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
            'questions' => 'required|array|min:1',
            'questions.*.text' => 'required|string|max:1000',
            'questions.*.type' => 'required|in:rating,text,yes_no',
            'due_date' => 'required|date|after:'.$today,
        ], [
            'title.required' => 'Give the evaluation a title.',
            'title.max' => 'Keep the title under 255 characters.',
            'evaluation_type.required' => 'Choose who is being evaluated.',
            'evaluation_type.in' => 'Choose who is being evaluated.',
            'board_committee_id.required_if' => 'Choose which committee is being evaluated.',
            'board_committee_id.integer' => 'Choose which committee is being evaluated.',
            'board_committee_id.exists' => "That committee doesn't exist any more. Choose another one.",
            'period_start.required' => 'Set the period start.',
            'period_start.date' => 'Enter the period start as a date.',
            'period_end.required' => 'Set the period end.',
            'period_end.date' => 'Enter the period end as a date.',
            'period_end.after' => 'The period must end after it starts.',
            'questions.required' => 'Add at least one question.',
            'questions.min' => 'Add at least one question.',
            'questions.*.text.required' => 'Write the question.',
            'questions.*.text.max' => 'Keep each question under 1,000 characters.',
            'questions.*.type.required' => 'Choose how members answer this question.',
            'questions.*.type.in' => 'Choose how members answer this question.',
            'due_date.required' => 'Set when responses are due.',
            'due_date.date' => 'Enter the due date as a date.',
            'due_date.after' => 'The due date must be after today.',
        ]);
    }

    /** @return array<string, mixed> */
    private function evaluationAttributes(array $validated): array
    {
        return [
            'title' => $validated['title'],
            'evaluation_type' => $validated['evaluation_type'],
            'board_committee_id' => $validated['evaluation_type'] === 'committee'
                ? (int) $validated['board_committee_id']
                : null,
            'year' => (int) date('Y', strtotime($validated['period_end'])),
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'due_date' => $validated['due_date'],
            'questions' => collect($validated['questions'])->values()->map(fn (array $question, int $index) => [
                'id' => $index + 1,
                'question' => $question['text'],
                'type' => $question['type'],
            ])->all(),
        ];
    }

    /**
     * Why this viewer can't answer right now — shown instead of the form — or
     * null when they can.
     */
    private function respondBlockedReason(BoardEvaluation $evaluation, ?User $viewer): ?string
    {
        $boardMember = $viewer?->boardMember;

        if (! $boardMember || ! $boardMember->is_active) {
            return 'Only board members answer this evaluation.';
        }

        if ($this->isChairOnlyFor($evaluation, $viewer)) {
            return 'Only the board chair answers this evaluation.';
        }

        if ($evaluation->status === 'draft') {
            return "This evaluation isn't open yet. You can answer once the board secretary opens it.";
        }

        if ($evaluation->status !== 'open') {
            return 'This evaluation is closed, so responses can no longer be added or changed.';
        }

        if ($evaluation->due_date && $this->today()->toDateString() > $evaluation->due_date->toDateString()) {
            return 'Responses were due by '.GovernanceLabels::date($evaluation->due_date->toDateString()).', so the form is closed.';
        }

        return null;
    }

    private function isChairOnlyFor(BoardEvaluation $evaluation, ?User $viewer): bool
    {
        if ($evaluation->audience !== 'chair_only' || $viewer === null) {
            return false;
        }

        $boardMember = $viewer->boardMember;

        return ! (($boardMember && $boardMember->board_role === 'chair') || $viewer->hasRole('board_chair'));
    }

    /** @return array<int, array{id: int, name: string}> */
    private function committeeOptions(): array
    {
        return BoardCommittee::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (BoardCommittee $committee) => ['id' => (int) $committee->id, 'name' => (string) $committee->name])
            ->all();
    }

    private function activeBoardMemberId(?User $viewer): ?int
    {
        if ($viewer === null) {
            return null;
        }

        $id = BoardMember::query()->active()->where('user_id', $viewer->id)->value('id');

        return $id === null ? null : (int) $id;
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
                'question' => 'Overall comments',
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
            'submitted_at' => $response->submitted_at?->toIso8601String(),
        ];
    }

    protected function presentEvaluationStatus(string $status): string
    {
        return match ($status) {
            'open' => 'active',
            default => $status,
        };
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) (config('app.worker_timezone') ?: GovernanceLabels::TIMEZONE))->startOfDay();
    }
}
