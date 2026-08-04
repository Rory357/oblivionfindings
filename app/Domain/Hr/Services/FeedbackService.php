<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrFeedbackRequest;
use App\Domain\Hr\Models\HrFeedbackResponse;
use App\Domain\Hr\Models\HrFeedbackTemplate;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Notifications\FeedbackReminderNotification;
use App\Domain\Hr\Notifications\FeedbackRequestedNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FeedbackService
{
    /** Standard application questions used when no template is selected. */
    public const FEEDBACK_QUESTIONS = [
        'communication' => 'How effectively does this person communicate?',
        'teamwork' => 'How well does this person collaborate with others?',
        'leadership' => 'How would you rate their leadership qualities?',
        'technical' => 'How strong are their technical/role-specific skills?',
        'initiative' => 'How well do they take initiative and drive results?',
        'overall' => 'Overall, how would you rate their performance?',
    ];

    public const REVIEW_TYPES = ['peer', 'manager', 'direct_report', 'self'];

    public function __construct(
        private readonly HrPerformanceAccessService $access,
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    /**
     * Create one pending request per exact current reviewer. Locking the
     * canonical subject profile serializes concurrent fan-out and makes a
     * repeated submission idempotent for the same open review cycle.
     *
     * @param  Collection<int, User>  $reviewers
     * @return array<int, HrFeedbackRequest>
     */
    public function request360Feedback(
        User $subject,
        Collection $reviewers,
        string $reviewType,
        ?HrPerformanceReview $performanceReview,
        User $requester,
        ?HrFeedbackTemplate $template = null,
        ?string $dueDate = null,
    ): array {
        [$requests, $created] = DB::transaction(function () use (
            $subject,
            $reviewers,
            $reviewType,
            $performanceReview,
            $requester,
            $template,
            $dueDate,
        ): array {
            $this->access->currentStaff($requester, $requester);
            $subjectProfile = $this->access
                ->applyCurrentProfileScope(HrEmployeeProfile::query(), $requester)
                ->where('user_id', $subject->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $reviewerIds = $reviewers
                ->map(fn (User $reviewer): int => (int) $reviewer->getKey())
                ->unique()
                ->sort()
                ->values();
            $lockedReviewers = $this->access
                ->currentUserIds($requester)
                ->whereIn('users.id', $reviewerIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($lockedReviewers->count() !== $reviewerIds->count()) {
                throw (new ModelNotFoundException)->setModel(User::class);
            }

            $this->assertReviewerShape($subjectProfile->user_id, $reviewerIds, $reviewType);

            $lockedReview = null;
            if ($performanceReview) {
                $lockedReview = $this->access
                    ->applyHistoricalSubjectScope(HrPerformanceReview::query(), $requester)
                    ->lockForUpdate()
                    ->findOrFail($performanceReview->getKey());
                if ((int) $lockedReview->employee_user_id !== (int) $subjectProfile->user_id) {
                    throw ValidationException::withMessages([
                        'performance_review_id' => 'The performance review must belong to the feedback subject.',
                    ]);
                }
            }

            $lockedTemplate = null;
            if ($template) {
                $lockedTemplate = HrFeedbackTemplate::query()
                    ->active()
                    ->lockForUpdate()
                    ->findOrFail($template->getKey());
            }
            $questionsSnapshot = $this->questionsSnapshot($lockedTemplate);
            $resolvedDueDate = $dueDate
                ? Carbon::parse($dueDate)->toDateString()
                : now()->addDays(14)->toDateString();

            $requests = [];
            $created = [];
            foreach ($reviewerIds as $reviewerId) {
                $existing = HrFeedbackRequest::query()
                    ->where('subject_user_id', $subjectProfile->user_id)
                    ->where('reviewer_user_id', $reviewerId)
                    ->where('review_type', $reviewType)
                    ->when(
                        $lockedReview,
                        fn ($query) => $query->where('performance_review_id', $lockedReview->id),
                        fn ($query) => $query->whereNull('performance_review_id'),
                    )
                    ->pending()
                    ->first();
                if ($existing) {
                    $requests[] = $existing;

                    continue;
                }

                $feedbackRequest = HrFeedbackRequest::query()->create([
                    'subject_user_id' => $subjectProfile->user_id,
                    'requester_user_id' => $requester->id,
                    'reviewer_user_id' => $reviewerId,
                    'review_type' => $reviewType,
                    'performance_review_id' => $lockedReview?->id,
                    'template_id' => $lockedTemplate?->id,
                    'questions_snapshot' => $questionsSnapshot,
                    'status' => 'pending',
                    'due_date' => $resolvedDueDate,
                ]);
                $requests[] = $feedbackRequest;
                $created[] = $feedbackRequest;
            }

            return [$requests, $created];
        }, attempts: 1);

        foreach ($created as $feedbackRequest) {
            DB::afterCommit(fn () => $this->notifyRequested($feedbackRequest, $subject->name));
        }

        return $requests;
    }

    public function transition(
        HrFeedbackRequest $feedbackRequest,
        User $manager,
        string $status,
    ): HrFeedbackRequest {
        if (! in_array($status, ['declined', 'expired'], true)) {
            throw ValidationException::withMessages(['status' => 'Unsupported feedback transition.']);
        }

        return DB::transaction(function () use ($feedbackRequest, $manager, $status): HrFeedbackRequest {
            $locked = $this->access
                ->applyFeedbackSubjectScope(HrFeedbackRequest::query(), $manager)
                ->lockForUpdate()
                ->findOrFail($feedbackRequest->getKey());
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending feedback requests can be changed.',
                ]);
            }

            $locked->update(['status' => $status]);

            return $locked->fresh();
        }, attempts: 1);
    }

    public function remind(HrFeedbackRequest $feedbackRequest, User $manager): void
    {
        $notification = DB::transaction(function () use ($feedbackRequest, $manager): ?array {
            $locked = $this->access
                ->applyFeedbackSubjectScope(HrFeedbackRequest::query(), $manager)
                ->with(['reviewer:id,name', 'subject:id,name'])
                ->lockForUpdate()
                ->findOrFail($feedbackRequest->getKey());
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending feedback requests can be reminded.',
                ]);
            }
            if (! $locked->reviewer || ! $this->currentStaff->isCurrent($locked->reviewer)) {
                throw ValidationException::withMessages([
                    'reviewer_user_id' => 'The assigned reviewer is no longer current staff.',
                ]);
            }

            return [$locked, $locked->reviewer, $locked->subject?->name ?? 'a colleague'];
        }, attempts: 1);

        if ($notification) {
            [$locked, $reviewer, $subjectName] = $notification;
            DB::afterCommit(function () use ($locked, $reviewer, $subjectName): void {
                try {
                    $reviewer->notify(new FeedbackReminderNotification($locked, $subjectName));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to send feedback reminder notification', [
                        'request_id' => $locked->id,
                        'reviewer_id' => $locked->reviewer_user_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
        }
    }

    public function submitFeedback(
        HrFeedbackRequest $feedbackRequest,
        array $responses,
        User $reviewer,
    ): HrFeedbackRequest {
        return DB::transaction(function () use ($feedbackRequest, $responses, $reviewer): HrFeedbackRequest {
            $this->access->currentStaff($reviewer, $reviewer);
            $locked = HrFeedbackRequest::query()
                ->where('reviewer_user_id', $reviewer->id)
                ->pending()
                ->lockForUpdate()
                ->findOrFail($feedbackRequest->getKey());

            $expectedKeys = array_keys($locked->getQuestionsMap());
            $submittedKeys = array_keys($responses);
            sort($expectedKeys);
            sort($submittedKeys);
            if ($submittedKeys !== $expectedKeys) {
                throw ValidationException::withMessages([
                    'responses' => 'Answer every question in this feedback request exactly once.',
                ]);
            }

            foreach ($responses as $questionKey => $response) {
                HrFeedbackResponse::query()->create([
                    'feedback_request_id' => $locked->id,
                    'question_key' => $questionKey,
                    'rating' => $response['rating'],
                    'comment' => filled($response['comment'] ?? null)
                        ? trim((string) $response['comment'])
                        : null,
                    'created_at' => now(),
                ]);
            }

            $locked->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return $locked->fresh()->load('responses');
        }, attempts: 1);
    }

    public function getFeedbackSummary(User $subject): array
    {
        $completedRequests = HrFeedbackRequest::query()
            ->where('subject_user_id', $subject->id)
            ->completed()
            ->with('responses')
            ->get();

        if ($completedRequests->isEmpty()) {
            return ['total_reviews' => 0, 'questions' => []];
        }

        $allResponses = $completedRequests->flatMap->responses;
        $questionsMap = $completedRequests->first()->getQuestionsMap();
        $allKeys = $allResponses->pluck('question_key')->unique()->values();
        $questionSummaries = [];
        foreach ($allKeys as $key) {
            $questionResponses = $allResponses->where('question_key', $key);
            $ratings = $questionResponses->pluck('rating')->filter()->values();
            $comments = $questionResponses->pluck('comment')->filter()->values();
            $questionSummaries[$key] = [
                'question' => $questionsMap[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                'average_rating' => $ratings->isNotEmpty() ? round($ratings->avg(), 2) : null,
                'rating_count' => $ratings->count(),
                'min_rating' => $ratings->min(),
                'max_rating' => $ratings->max(),
                'comments' => $comments->all(),
            ];
        }

        return [
            'total_reviews' => $completedRequests->count(),
            'questions' => $questionSummaries,
        ];
    }

    /** @return Collection<int, HrFeedbackRequest> */
    public function getPendingForUser(User $reviewer): Collection
    {
        if (! $this->currentStaff->isCurrent($reviewer)) {
            return collect();
        }

        return HrFeedbackRequest::query()
            ->where('reviewer_user_id', $reviewer->id)
            ->pending()
            ->with(['subject:id,name', 'requester:id,name'])
            ->orderBy('due_date')
            ->get();
    }

    private function assertReviewerShape(int $subjectUserId, Collection $reviewerIds, string $reviewType): void
    {
        if ($reviewType === 'self' && $reviewerIds->all() !== [$subjectUserId]) {
            throw ValidationException::withMessages([
                'reviewer_user_ids' => 'A self assessment must be assigned only to its subject.',
            ]);
        }
        if ($reviewType !== 'self' && $reviewerIds->contains($subjectUserId)) {
            throw ValidationException::withMessages([
                'reviewer_user_ids' => 'Choose the self review type when the subject is the reviewer.',
            ]);
        }
    }

    private function questionsSnapshot(?HrFeedbackTemplate $template): array
    {
        if (! $template) {
            return collect(self::FEEDBACK_QUESTIONS)
                ->map(fn (string $question, string $key): array => compact('key', 'question'))
                ->values()
                ->all();
        }

        $questions = collect($template->questions)
            ->map(fn (mixed $question): array => [
                'key' => trim((string) ($question['key'] ?? '')),
                'question' => trim((string) ($question['question'] ?? '')),
            ]);
        if ($questions->isEmpty()
            || $questions->contains(fn (array $question): bool => $question['key'] === '' || $question['question'] === '')
            || $questions->pluck('key')->unique()->count() !== $questions->count()
        ) {
            throw ValidationException::withMessages([
                'template_id' => 'The selected feedback template has invalid question definitions.',
            ]);
        }

        return $questions->values()->all();
    }

    private function notifyRequested(HrFeedbackRequest $feedbackRequest, string $subjectName): void
    {
        $reviewer = $feedbackRequest->reviewer;
        if (! $reviewer || ! $this->currentStaff->isCurrent($reviewer)) {
            return;
        }

        try {
            $reviewer->notify(new FeedbackRequestedNotification($feedbackRequest, $subjectName));
        } catch (\Throwable $exception) {
            Log::warning('Failed to send feedback-requested notification', [
                'request_id' => $feedbackRequest->id,
                'reviewer_id' => $feedbackRequest->reviewer_user_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
