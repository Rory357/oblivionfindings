<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrEngagementSurveyResponse;
use App\Domain\Hr\Notifications\EngagementSurveyInvitationNotification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EngagementService
{
    /**
     * @throws \InvalidArgumentException
     */
    public function createSurvey(User $actor, array $data): HrEngagementSurvey
    {
        return DB::transaction(function () use ($actor, $data) {
            $survey = HrEngagementSurvey::create([
                'tenant_id' => $actor->tenant_id ?? null,
                'title' => trim((string) $data['title']),
                'description' => $data['description'] ?? null,
                'survey_type' => $data['survey_type'] ?? 'pulse',
                'status' => 'draft',
                'is_anonymous' => (bool) ($data['is_anonymous'] ?? true),
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $questions = collect($data['questions'] ?? [])
                ->filter(fn ($question) => is_array($question) && trim((string) ($question['question_text'] ?? '')) !== '')
                ->values();

            if ($questions->isEmpty()) {
                throw new \InvalidArgumentException('At least one question is required.');
            }

            $questions->each(function (array $question, int $index) use ($survey) {
                $survey->questions()->create([
                    'question_type' => $question['question_type'] ?? 'scale',
                    'question_text' => trim((string) $question['question_text']),
                    'options' => $question['options'] ?? null,
                    'is_required' => (bool) ($question['is_required'] ?? true),
                    'sort_order' => (int) ($question['sort_order'] ?? ($index + 1)),
                ]);
            });

            return $survey->load('questions');
        });
    }

    public function updateSurvey(HrEngagementSurvey $survey, User $actor, array $data): HrEngagementSurvey
    {
        return DB::transaction(function () use ($survey, $actor, $data) {
            if ($survey->status !== 'draft') {
                throw new \InvalidArgumentException('Only draft surveys can be edited.');
            }

            $survey->update([
                'title' => trim((string) ($data['title'] ?? $survey->title)),
                'description' => $data['description'] ?? $survey->description,
                'survey_type' => $data['survey_type'] ?? $survey->survey_type,
                'is_anonymous' => array_key_exists('is_anonymous', $data)
                    ? (bool) $data['is_anonymous']
                    : $survey->is_anonymous,
                'starts_at' => $data['starts_at'] ?? $survey->starts_at,
                'ends_at' => $data['ends_at'] ?? $survey->ends_at,
                'updated_by' => $actor->id,
            ]);

            if (array_key_exists('questions', $data) && is_array($data['questions'])) {
                $survey->questions()->delete();
                collect($data['questions'])
                    ->filter(fn ($question) => is_array($question) && trim((string) ($question['question_text'] ?? '')) !== '')
                    ->values()
                    ->each(function (array $question, int $index) use ($survey) {
                        $survey->questions()->create([
                            'question_type' => $question['question_type'] ?? 'scale',
                            'question_text' => trim((string) $question['question_text']),
                            'options' => $question['options'] ?? null,
                            'is_required' => (bool) ($question['is_required'] ?? true),
                            'sort_order' => (int) ($question['sort_order'] ?? ($index + 1)),
                        ]);
                    });
            }

            return $survey->fresh('questions');
        });
    }

    public function publishSurvey(HrEngagementSurvey $survey, User $actor): HrEngagementSurvey
    {
        if ($survey->status !== 'draft') {
            throw new \InvalidArgumentException('Only draft surveys can be published.');
        }

        if ($survey->questions()->count() === 0) {
            throw new \InvalidArgumentException('Survey must include at least one question before publishing.');
        }

        $survey->update([
            'status' => 'published',
            'published_by' => $actor->id,
            'published_at' => now(),
            'updated_by' => $actor->id,
        ]);

        $recipients = HrEmployeeProfile::query()
            ->where('is_active', true)
            ->when($survey->tenant_id !== null, fn ($query) => $query->where('tenant_id', $survey->tenant_id))
            ->with('user:id,email,name')
            ->get()
            ->pluck('user')
            ->filter()
            ->values();

        if ($recipients->isNotEmpty()) {
            $notification = new EngagementSurveyInvitationNotification($survey->fresh());
            $recipients->each(fn (User $recipient) => $recipient->notify($notification));
        }

        return $survey->fresh();
    }

    public function closeSurvey(HrEngagementSurvey $survey, User $actor): HrEngagementSurvey
    {
        if ($survey->status !== 'published') {
            throw new \InvalidArgumentException('Only published surveys can be closed.');
        }

        $survey->update([
            'status' => 'closed',
            'closed_at' => now(),
            'updated_by' => $actor->id,
        ]);

        return $survey->fresh();
    }

    public function submitResponse(HrEngagementSurvey $survey, User $user, array $answers): HrEngagementSurveyResponse
    {
        if ($survey->status !== 'published') {
            throw new \InvalidArgumentException('This survey is not accepting responses.');
        }

        if ($survey->starts_at && $survey->starts_at->isFuture()) {
            throw new \InvalidArgumentException('This survey has not started yet.');
        }

        if ($survey->ends_at && $survey->ends_at->isPast()) {
            throw new \InvalidArgumentException('This survey has closed.');
        }

        $respondentHash = hash_hmac('sha256', $survey->id . ':' . $user->id, (string) config('app.key'));

        $existing = HrEngagementSurveyResponse::query()
            ->where('survey_id', $survey->id)
            ->where(function ($query) use ($survey, $user, $respondentHash) {
                if ($survey->is_anonymous) {
                    $query->where('respondent_hash', $respondentHash);
                } else {
                    $query->where('user_id', $user->id);
                }
            })
            ->exists();

        if ($existing) {
            throw new \InvalidArgumentException('You have already submitted this survey.');
        }

        $survey->loadMissing('questions');
        $normalizedAnswers = [];
        foreach ($survey->questions as $question) {
            $key = (string) $question->id;
            $answer = $answers[$key] ?? null;

            if ($question->is_required && ($answer === null || $answer === '')) {
                throw new \InvalidArgumentException('Please complete all required survey questions.');
            }

            if ($answer !== null && $answer !== '') {
                $normalizedAnswers[$key] = $answer;
            }
        }

        $overallScore = $this->computeOverallScore($survey->questions, $normalizedAnswers);

        return HrEngagementSurveyResponse::create([
            'survey_id' => $survey->id,
            'user_id' => $survey->is_anonymous ? null : $user->id,
            'respondent_hash' => $respondentHash,
            'answers' => $normalizedAnswers,
            'overall_score' => $overallScore,
            'submitted_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(HrEngagementSurvey $survey): array
    {
        $survey->loadMissing(['questions', 'responses']);
        $responses = $survey->responses;
        $responseCount = $responses->count();

        $scores = $responses->pluck('overall_score')
            ->filter(fn ($score) => $score !== null)
            ->map(fn ($score) => (float) $score);

        $questionStats = $survey->questions->map(function ($question) use ($responses) {
            $values = $responses
                ->map(fn (HrEngagementSurveyResponse $response) => $response->answers[(string) $question->id] ?? null)
                ->filter(fn ($value) => $value !== null && $value !== '');

            $numericValues = $values
                ->filter(fn ($value) => is_numeric($value))
                ->map(fn ($value) => (float) $value);

            return [
                'id' => $question->id,
                'question_text' => $question->question_text,
                'question_type' => $question->question_type,
                'responses' => $values->count(),
                'average' => $numericValues->isEmpty() ? null : round($numericValues->avg(), 2),
            ];
        })->values();

        $enpsScores = $this->extractEnpsValues($survey, $responses);
        $promoters = $enpsScores->filter(fn (float $value) => $value >= 9)->count();
        $detractors = $enpsScores->filter(fn (float $value) => $value <= 6)->count();
        $enps = $enpsScores->isEmpty()
            ? null
            : round((($promoters / $enpsScores->count()) - ($detractors / $enpsScores->count())) * 100, 1);

        return [
            'response_count' => $responseCount,
            'average_overall_score' => $scores->isEmpty() ? null : round($scores->avg(), 2),
            'enps' => $enps,
            'question_stats' => $questionStats,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    public function actionPlanSlaSummary(?int $tenantId, ?int $viewerUserId, bool $canManage): array
    {
        $openStatuses = ['open', 'in_progress'];
        $baseQuery = HrEngagementActionPlan::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when(! $canManage && $viewerUserId !== null, fn ($query) => $query->where('owner_user_id', $viewerUserId));

        $openPlans = (clone $baseQuery)
            ->whereIn('status', $openStatuses)
            ->get();

        $overdue = $openPlans->filter(fn (HrEngagementActionPlan $plan) => $plan->due_date && $plan->due_date->isBefore(now()->startOfDay()));
        $dueToday = $openPlans->filter(fn (HrEngagementActionPlan $plan) => $plan->due_date && $plan->due_date->isToday());
        $dueNext7 = $openPlans->filter(fn (HrEngagementActionPlan $plan) => $plan->due_date
            && $plan->due_date->isBetween(now()->startOfDay(), now()->addDays(7)->endOfDay()));

        $completedLast30 = (clone $baseQuery)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [now()->subDays(30)->toDateString(), now()->toDateString()])
            ->count();

        return [
            'open_total' => $openPlans->count(),
            'overdue' => $overdue->count(),
            'due_today' => $dueToday->count(),
            'due_next_7_days' => $dueNext7->count(),
            'high_priority_overdue' => $overdue->where('priority', 'high')->count(),
            'avg_progress_open' => round((float) ($openPlans->avg('progress_percent') ?? 0), 1),
            'completed_last_30_days' => (int) $completedLast30,
        ];
    }

    /**
     * @return array<int, array<string, int|string|null>>
     */
    public function actionPlanOwnerWorkload(?int $tenantId): array
    {
        $plans = HrEngagementActionPlan::query()
            ->with('owner:id,name')
            ->whereIn('status', ['open', 'in_progress'])
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->get();

        return $plans
            ->groupBy('owner_user_id')
            ->map(function (Collection $group) {
                /** @var HrEngagementActionPlan $first */
                $first = $group->first();
                $overdue = $group->filter(fn (HrEngagementActionPlan $plan) => $plan->due_date && $plan->due_date->isBefore(now()->startOfDay()));
                $dueSoon = $group->filter(fn (HrEngagementActionPlan $plan) => $plan->due_date
                    && $plan->due_date->isBetween(now()->startOfDay(), now()->addDays(7)->endOfDay()));

                return [
                    'owner_user_id' => (int) $first->owner_user_id,
                    'owner_name' => $first->owner?->name,
                    'open_count' => $group->count(),
                    'overdue_count' => $overdue->count(),
                    'due_next_7_days' => $dueSoon->count(),
                    'avg_progress_percent' => (int) round((float) ($group->avg('progress_percent') ?? 0)),
                ];
            })
            ->sortByDesc('open_count')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, \App\Domain\Hr\Models\HrEngagementSurveyQuestion>  $questions
     * @param  array<string, mixed>                                                $answers
     */
    protected function computeOverallScore(Collection $questions, array $answers): ?float
    {
        $numeric = collect($answers)
            ->filter(fn ($value, $key) => is_numeric($value) && $questions->firstWhere('id', (int) $key)?->question_type !== 'text')
            ->map(fn ($value) => (float) $value);

        return $numeric->isEmpty() ? null : round($numeric->avg(), 2);
    }

    /**
     * @param  Collection<int, HrEngagementSurveyResponse>  $responses
     * @return Collection<int, float>
     */
    protected function extractEnpsValues(HrEngagementSurvey $survey, Collection $responses): Collection
    {
        $enpsQuestionIds = $survey->questions
            ->where('question_type', 'enps')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values();

        if ($enpsQuestionIds->isEmpty()) {
            return collect();
        }

        return $responses
            ->flatMap(function (HrEngagementSurveyResponse $response) use ($enpsQuestionIds) {
                return $enpsQuestionIds
                    ->map(fn (string $id) => $response->answers[$id] ?? null)
                    ->filter(fn ($value) => is_numeric($value))
                    ->map(fn ($value) => (float) $value);
            })
            ->values();
    }
}
