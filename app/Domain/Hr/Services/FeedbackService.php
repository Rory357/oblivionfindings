<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrFeedbackRequest;
use App\Domain\Hr\Models\HrFeedbackResponse;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FeedbackService
{
    /**
     * Standard 360-feedback questions.
     */
    public const FEEDBACK_QUESTIONS = [
        'communication' => 'How effectively does this person communicate?',
        'teamwork' => 'How well does this person collaborate with others?',
        'leadership' => 'How would you rate their leadership qualities?',
        'technical' => 'How strong are their technical/role-specific skills?',
        'initiative' => 'How well do they take initiative and drive results?',
        'overall' => 'Overall, how would you rate their performance?',
    ];

    /**
     * Review types supported.
     */
    public const REVIEW_TYPES = ['peer', 'manager', 'direct_report', 'self'];

    /**
     * Create 360-degree feedback requests for multiple reviewers.
     *
     * @return array<HrFeedbackRequest>
     */
    public function request360Feedback(
        int $subjectUserId,
        array $reviewerUserIds,
        string $reviewType,
        ?int $performanceReviewId,
        User $requester,
    ): array {
        return DB::transaction(function () use ($subjectUserId, $reviewerUserIds, $reviewType, $performanceReviewId, $requester) {
            $requests = [];

            foreach ($reviewerUserIds as $reviewerUserId) {
                $requests[] = HrFeedbackRequest::create([
                    'tenant_id' => $requester->tenant_id,
                    'subject_user_id' => $subjectUserId,
                    'requester_user_id' => $requester->id,
                    'reviewer_user_id' => $reviewerUserId,
                    'review_type' => $reviewType,
                    'performance_review_id' => $performanceReviewId,
                    'status' => 'pending',
                    'due_date' => now()->addDays(14),
                ]);
            }

            return $requests;
        });
    }

    /**
     * Submit feedback responses and mark the request as completed.
     */
    public function submitFeedback(HrFeedbackRequest $request, array $responses): HrFeedbackRequest
    {
        return DB::transaction(function () use ($request, $responses) {
            foreach ($responses as $questionKey => $response) {
                HrFeedbackResponse::create([
                    'feedback_request_id' => $request->id,
                    'question_key' => $questionKey,
                    'rating' => $response['rating'] ?? null,
                    'comment' => $response['comment'] ?? null,
                    'created_at' => now(),
                ]);
            }

            $request->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return $request->load('responses');
        });
    }

    /**
     * Get aggregated feedback summary for a subject user across all completed requests.
     */
    public function getFeedbackSummary(int $subjectUserId): array
    {
        $completedRequests = HrFeedbackRequest::where('subject_user_id', $subjectUserId)
            ->completed()
            ->with('responses')
            ->get();

        if ($completedRequests->isEmpty()) {
            return [
                'total_reviews' => 0,
                'questions' => [],
                'comments' => [],
            ];
        }

        $allResponses = $completedRequests->flatMap->responses;

        $questionSummaries = [];
        foreach (self::FEEDBACK_QUESTIONS as $key => $questionText) {
            $questionResponses = $allResponses->where('question_key', $key);
            $ratings = $questionResponses->pluck('rating')->filter()->values();
            $comments = $questionResponses->pluck('comment')->filter()->values();

            $questionSummaries[$key] = [
                'question' => $questionText,
                'average_rating' => $ratings->count() > 0 ? round($ratings->avg(), 2) : null,
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

    /**
     * Get pending feedback requests for a given reviewer user.
     */
    public function getPendingForUser(int $userId): Collection
    {
        return HrFeedbackRequest::where('reviewer_user_id', $userId)
            ->pending()
            ->with(['subject:id,name', 'requester:id,name'])
            ->orderBy('due_date')
            ->get();
    }
}
