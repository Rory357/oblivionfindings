<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrFeedbackRequest;
use App\Domain\Hr\Models\HrFeedbackTemplate;
use App\Domain\Hr\Services\FeedbackService;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FeedbackController extends Controller
{
    public function __construct(
        private readonly FeedbackService $feedbackService,
        private readonly HrPerformanceAccessService $access,
    ) {}

    public function index(Request $request)
    {
        $user = $this->viewer($request);
        $canManage = $user->canDo('hr.performance.manage');
        $scope = $canManage
            ? $this->access->applyFeedbackSubjectScope(HrFeedbackRequest::query(), $user)
            : HrFeedbackRequest::query()->where('reviewer_user_id', $user->id);

        $allRequests = (clone $scope)
            ->with(['subject:id,name', 'requester:id,name', 'reviewer:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
        $allRequests->through(fn (HrFeedbackRequest $feedbackRequest): array => [
            'id' => $feedbackRequest->id,
            'subject' => $feedbackRequest->subject
                ? ['id' => $feedbackRequest->subject->id, 'name' => $feedbackRequest->subject->name]
                : null,
            'requester' => $feedbackRequest->requester
                ? ['id' => $feedbackRequest->requester->id, 'name' => $feedbackRequest->requester->name]
                : null,
            'reviewer' => $feedbackRequest->reviewer
                ? ['id' => $feedbackRequest->reviewer->id, 'name' => $feedbackRequest->reviewer->name]
                : null,
            'review_type' => $feedbackRequest->review_type,
            'status' => $feedbackRequest->status,
            'due_date' => $feedbackRequest->due_date?->toDateString(),
            'completed_at' => $feedbackRequest->completed_at?->toDateString(),
            'created_at' => $feedbackRequest->created_at?->toDateString(),
        ]);

        $pendingCount = HrFeedbackRequest::query()
            ->where('reviewer_user_id', $user->id)
            ->pending()
            ->count();
        $statusCounts = (clone $scope)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');
        $overdueCount = (clone $scope)
            ->pending()
            ->whereDate('due_date', '<', today())
            ->count();

        $wizard = null;
        if ($canManage) {
            $wizard = [
                'employees' => $this->access
                    ->currentUserIds($user)
                    ->orderBy('name')
                    ->get(['users.id', 'users.name']),
                'reviewTypes' => FeedbackService::REVIEW_TYPES,
                'templates' => HrFeedbackTemplate::query()
                    ->active()
                    ->orderByDesc('is_default')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (HrFeedbackTemplate $template): array => $this->serializeTemplate($template))
                    ->values(),
                'defaultQuestions' => FeedbackService::FEEDBACK_QUESTIONS,
            ];
        }

        return Inertia::render('hr/feedback/index', [
            'requests' => $allRequests,
            'pendingCount' => $pendingCount,
            'stats' => [
                'total' => (int) $statusCounts->sum(),
                'pending' => (int) ($statusCounts['pending'] ?? 0),
                'completed' => (int) ($statusCounts['completed'] ?? 0),
                'overdue' => $overdueCount,
            ],
            'can' => ['manage' => $canManage],
            'wizard' => $wizard,
        ]);
    }

    public function request(Request $request)
    {
        $this->manager($request);

        return redirect()->route('hr.feedback.index', array_filter([
            'new' => 1,
            'employee' => $request->query('employee'),
        ]));
    }

    public function storeRequest(Request $request)
    {
        $this->createRequests($request, acceptsDueDate: false);

        return redirect('/hr/feedback')->with('success', '360-degree feedback requests are ready.');
    }

    public function bulkRequest(Request $request)
    {
        $count = $this->createRequests($request, acceptsDueDate: true);

        return redirect()->back()->with('success', "360 feedback requests are ready for {$count} reviewers.");
    }

    public function respond(Request $request, HrFeedbackRequest $feedbackRequest)
    {
        $user = $this->viewer($request);
        $feedbackRequest = $this->reviewerRequest($user, $feedbackRequest, pending: true)
            ->load('subject:id,name');

        return Inertia::render('hr/feedback/respond', [
            'feedbackRequest' => [
                'id' => $feedbackRequest->id,
                'subject' => $feedbackRequest->subject ? [
                    'id' => $feedbackRequest->subject->id,
                    'name' => $feedbackRequest->subject->name,
                ] : null,
                'review_type' => $feedbackRequest->review_type,
                'due_date' => $feedbackRequest->due_date?->toDateString(),
            ],
            'questions' => $feedbackRequest->getQuestionsMap(),
        ]);
    }

    public function submitResponse(Request $request, HrFeedbackRequest $feedbackRequest)
    {
        $user = $this->viewer($request);
        $feedbackRequest = $this->reviewerRequest($user, $feedbackRequest, pending: true);
        $validKeys = array_keys($feedbackRequest->getQuestionsMap());

        $validated = $request->validate([
            'responses' => ['required', 'array', 'size:'.count($validKeys)],
            'responses.*.question_key' => ['required', 'string', 'distinct', Rule::in($validKeys)],
            'responses.*.rating' => ['required', 'integer', 'min:1', 'max:5'],
            'responses.*.comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $responses = collect($validated['responses'])
            ->mapWithKeys(fn (array $response): array => [$response['question_key'] => $response])
            ->all();

        $this->feedbackService->submitFeedback($feedbackRequest, $responses, $user);

        return redirect('/hr/feedback')->with('success', 'Feedback submitted. Thank you!');
    }

    public function summary(Request $request, int $user)
    {
        $viewer = $this->viewer($request);
        $subject = $this->access->historicalUserIds($viewer)->findOrFail($user);
        $summary = $this->feedbackService->getFeedbackSummary($subject);
        $questions = collect($summary['questions'] ?? [])
            ->mapWithKeys(fn (array $question, string $key): array => [$key => $question['question']])
            ->all();
        if ($questions === []) {
            $questions = FeedbackService::FEEDBACK_QUESTIONS;
        }

        return Inertia::render('hr/feedback/summary', [
            'subjectUser' => ['id' => $subject->id, 'name' => $subject->name],
            'summary' => $summary,
            'questions' => $questions,
        ]);
    }

    public function decline(Request $request, HrFeedbackRequest $feedbackRequest)
    {
        $manager = $this->manager($request);
        $feedbackRequest = $this->access->feedbackRequest($manager, $feedbackRequest);
        $this->feedbackService->transition($feedbackRequest, $manager, 'declined');

        return redirect()->back()->with('success', 'Feedback request declined.');
    }

    public function remind(Request $request, HrFeedbackRequest $feedbackRequest)
    {
        $manager = $this->manager($request);
        $feedbackRequest = $this->access->feedbackRequest($manager, $feedbackRequest);
        $this->feedbackService->remind($feedbackRequest, $manager);

        return redirect()->back()->with('success', 'Reminder sent to the reviewer.');
    }

    public function cancel(Request $request, HrFeedbackRequest $feedbackRequest)
    {
        $manager = $this->manager($request);
        $feedbackRequest = $this->access->feedbackRequest($manager, $feedbackRequest);
        $this->feedbackService->transition($feedbackRequest, $manager, 'expired');

        return redirect()->back()->with('success', 'Feedback request cancelled.');
    }

    public function storeTemplate(Request $request)
    {
        $user = $this->manager($request);
        $validated = $this->validateTemplate($request);

        HrFeedbackTemplate::query()->create([
            ...$validated,
            'is_default' => false,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Template created.');
    }

    public function updateTemplate(Request $request, HrFeedbackTemplate $template)
    {
        $this->manager($request);
        $validated = $this->validateTemplate($request, $template);

        DB::transaction(function () use ($template, $validated): void {
            HrFeedbackTemplate::query()
                ->lockForUpdate()
                ->findOrFail($template->getKey())
                ->update($validated);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Template updated.');
    }

    public function deleteTemplate(Request $request, HrFeedbackTemplate $template)
    {
        $this->manager($request);

        DB::transaction(function () use ($template): void {
            $locked = HrFeedbackTemplate::query()
                ->lockForUpdate()
                ->findOrFail($template->getKey());
            abort_if($locked->is_default, 422, 'Cannot delete the default template.');
            $locked->delete();
        }, attempts: 1);

        return redirect()->back()->with('success', 'Template deleted.');
    }

    private function createRequests(Request $request, bool $acceptsDueDate): int
    {
        $manager = $this->manager($request);
        $rules = [
            'subject_user_id' => ['required', 'integer'],
            'reviewer_user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'reviewer_user_ids.*' => ['integer', 'distinct'],
            'review_type' => ['required', 'string', Rule::in(FeedbackService::REVIEW_TYPES)],
            'performance_review_id' => ['nullable', 'integer'],
            'template_id' => ['nullable', 'integer'],
        ];
        if ($acceptsDueDate) {
            $rules['due_date'] = ['nullable', 'date', 'after_or_equal:today'];
        }
        $validated = $request->validate($rules);

        $subject = $this->access->currentStaff($manager, (int) $validated['subject_user_id']);
        $reviewers = $this->reviewers($manager, $validated['reviewer_user_ids']);
        $performanceReview = isset($validated['performance_review_id'])
            ? $this->access->performanceReview($manager, (int) $validated['performance_review_id'])
            : null;
        if ($performanceReview && (int) $performanceReview->employee_user_id !== (int) $subject->id) {
            throw ValidationException::withMessages([
                'performance_review_id' => 'The performance review must belong to the feedback subject.',
            ]);
        }
        $template = isset($validated['template_id'])
            ? HrFeedbackTemplate::query()->active()->findOrFail((int) $validated['template_id'])
            : null;

        $this->feedbackService->request360Feedback(
            $subject,
            $reviewers,
            $validated['review_type'],
            $performanceReview,
            $manager,
            $template,
            $acceptsDueDate ? ($validated['due_date'] ?? null) : null,
        );

        return $reviewers->count();
    }

    /** @return Collection<int, User> */
    private function reviewers(User $manager, array $reviewerIds): Collection
    {
        $ids = collect($reviewerIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $reviewers = $this->access
            ->currentUserIds($manager)
            ->whereIn('users.id', $ids)
            ->get();
        if ($reviewers->count() !== $ids->count()) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }

        return $reviewers;
    }

    private function reviewerRequest(
        User $reviewer,
        HrFeedbackRequest $feedbackRequest,
        bool $pending = false,
    ): HrFeedbackRequest {
        return HrFeedbackRequest::query()
            ->where('reviewer_user_id', $reviewer->id)
            ->when($pending, fn (Builder $query) => $query->pending())
            ->findOrFail($feedbackRequest->getKey());
    }

    private function validateTemplate(
        Request $request,
        ?HrFeedbackTemplate $template = null,
    ): array {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('hr_feedback_templates', 'name')->ignore($template?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'questions' => ['required', 'array', 'min:1', 'max:100'],
            'questions.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'questions.*.question' => ['required', 'string', 'max:500'],
        ]);
        $validated['questions'] = collect($validated['questions'])
            ->map(fn (array $question): array => [
                'key' => trim($question['key']),
                'question' => trim($question['question']),
            ])
            ->values()
            ->all();

        return $validated;
    }

    private function serializeTemplate(HrFeedbackTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'questions' => $template->questions,
            'is_default' => $template->is_default,
        ];
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);

        return $this->access->currentStaff($user, $user);
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        return $this->access->currentStaff($user, $user);
    }
}
