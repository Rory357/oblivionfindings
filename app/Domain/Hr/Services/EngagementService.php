<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrEngagementSurveyQuestion;
use App\Domain\Hr\Models\HrEngagementSurveyResponse;
use App\Domain\Hr\Notifications\EngagementSurveyInvitationNotification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EngagementService
{
    public function __construct(
        private readonly HrAudienceAccessService $audiences,
    ) {}

    /**
     * Seeded survey templates that prefill the builder's Questions step.
     */
    public const TEMPLATES = [
        [
            'key' => 'enps',
            'name' => 'eNPS',
            'survey_type' => 'enps',
            'description' => 'A two-question employee Net Promoter check.',
            'questions' => [
                ['question_type' => 'enps', 'question_text' => 'How likely are you to recommend us as a place to work?', 'is_required' => true],
                ['question_type' => 'text', 'question_text' => 'What is the main reason for your score?', 'is_required' => false],
            ],
        ],
        [
            'key' => 'monthly_pulse',
            'name' => 'Monthly pulse',
            'survey_type' => 'pulse',
            'description' => 'A quick monthly mood and workload check.',
            'questions' => [
                ['question_type' => 'scale', 'question_text' => 'How are you feeling about work this month?', 'is_required' => true],
                ['question_type' => 'scale', 'question_text' => 'Do you feel you have enough rest between shifts?', 'is_required' => true],
                ['question_type' => 'text', 'question_text' => 'What is one thing that would make next month better?', 'is_required' => false],
            ],
        ],
        [
            'key' => 'wellbeing_pulse',
            'name' => 'Wellbeing pulse',
            'survey_type' => 'engagement',
            'description' => 'A deeper wellbeing and support check.',
            'questions' => [
                ['question_type' => 'scale', 'question_text' => 'How manageable has your workload felt lately?', 'is_required' => true],
                ['question_type' => 'scale', 'question_text' => 'How supported do you feel by your team and manager?', 'is_required' => true],
                ['question_type' => 'boolean', 'question_text' => 'Do you know where to go if you need wellbeing support?', 'is_required' => true],
                ['question_type' => 'text', 'question_text' => 'Is there anything you would like us to know?', 'is_required' => false],
            ],
        ],
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function templates(): array
    {
        return collect(self::TEMPLATES)->map(fn (array $template, int $index) => [
            ...$template,
            'questions' => collect($template['questions'])
                ->map(fn (array $question, int $i) => [
                    ...$question,
                    'options' => $question['options'] ?? [],
                    'sort_order' => $i + 1,
                ])
                ->all(),
        ])->all();
    }

    public function createSurvey(User $actor, array $data): HrEngagementSurvey
    {
        return DB::transaction(function () use ($actor, $data) {
            $survey = HrEngagementSurvey::create([
                'title' => trim((string) $data['title']),
                'description' => $data['description'] ?? null,
                'survey_type' => $data['survey_type'] ?? 'pulse',
                'status' => 'draft',
                'is_anonymous' => (bool) ($data['is_anonymous'] ?? true),
                'audience_type' => $data['audience_type'] ?? 'all',
                'audience_site_ids' => ($data['audience_type'] ?? 'all') === 'site' ? array_values($data['audience_site_ids'] ?? []) : null,
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $questions = collect($data['questions'] ?? [])
                ->filter(fn ($question) => is_array($question) && trim((string) ($question['question_text'] ?? '')) !== '')
                ->values();

            if ($questions->isEmpty()) {
                throw ValidationException::withMessages(['questions' => 'At least one question is required.']);
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
        }, attempts: 1);
    }

    public function updateSurvey(HrEngagementSurvey $survey, User $actor, array $data): HrEngagementSurvey
    {
        return DB::transaction(function () use ($survey, $actor, $data) {
            $survey = HrEngagementSurvey::query()->lockForUpdate()->findOrFail($survey->getKey());
            if ($survey->status !== 'draft') {
                throw ValidationException::withMessages(['survey' => 'Only draft surveys can be edited.']);
            }

            $survey->update([
                'title' => trim((string) ($data['title'] ?? $survey->title)),
                'description' => array_key_exists('description', $data) ? $data['description'] : $survey->description,
                'survey_type' => $data['survey_type'] ?? $survey->survey_type,
                'is_anonymous' => array_key_exists('is_anonymous', $data)
                    ? (bool) $data['is_anonymous']
                    : $survey->is_anonymous,
                'audience_type' => $data['audience_type'] ?? $survey->audience_type ?? 'all',
                'audience_site_ids' => array_key_exists('audience_type', $data)
                    ? (($data['audience_type'] === 'site') ? array_values($data['audience_site_ids'] ?? []) : null)
                    : $survey->audience_site_ids,
                'starts_at' => array_key_exists('starts_at', $data) ? $data['starts_at'] : $survey->starts_at,
                'ends_at' => array_key_exists('ends_at', $data) ? $data['ends_at'] : $survey->ends_at,
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
        }, attempts: 1);
    }

    public function publishSurvey(HrEngagementSurvey $survey, User $actor): HrEngagementSurvey
    {
        $survey = DB::transaction(function () use ($survey, $actor): HrEngagementSurvey {
            $locked = HrEngagementSurvey::query()->lockForUpdate()->findOrFail($survey->getKey());
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['survey' => 'Only draft surveys can be published.']);
            }
            if ($locked->questions()->count() === 0) {
                throw ValidationException::withMessages(['questions' => 'Survey must include at least one question before publishing.']);
            }
            $locked->update([
                'status' => 'published',
                'published_by' => $actor->id,
                'published_at' => now(),
                'updated_by' => $actor->id,
            ]);

            return $locked->fresh();
        }, attempts: 1);

        $recipients = $this->recipientsFor($survey);

        if ($recipients->isNotEmpty()) {
            $notification = new EngagementSurveyInvitationNotification($survey->fresh());
            $recipients->each(fn (User $recipient) => $recipient->notify($notification));
        }

        return $survey->fresh();
    }

    /**
     * Resolve current staff recipients for a survey based on its audience scope.
     * Null / 'all' audience means every current staff member in the application.
     *
     * @return Collection<int, User>
     */
    public function recipientsFor(HrEngagementSurvey $survey): Collection
    {
        $audienceType = $survey->audience_type ?: 'all';
        if (! in_array($audienceType, ['all', 'site'], true)) {
            return collect();
        }

        $siteIds = ($audienceType === 'site')
            ? collect($survey->audience_site_ids ?? [])->map(fn ($id) => (int) $id)->filter()->values()
            : collect();

        if ($audienceType === 'site' && $siteIds->isEmpty()) {
            return collect();
        }

        $targets = $audienceType === 'all'
            ? [['type' => 'all', 'value' => null]]
            : $siteIds->map(fn (int $siteId) => ['type' => 'site', 'value' => (string) $siteId])->all();

        return $this->audiences->resolveUsers($targets);
    }

    public function isCurrentRecipient(HrEngagementSurvey $survey, User $user): bool
    {
        return $this->recipientsFor($survey)
            ->contains(fn (User $recipient) => (int) $recipient->id === (int) $user->id);
    }

    public function recipientCount(HrEngagementSurvey $survey): int
    {
        return $this->recipientsFor($survey)->count();
    }

    /**
     * Duplicate a survey (and its questions) as a fresh draft.
     */
    public function duplicateSurvey(HrEngagementSurvey $survey, User $actor): HrEngagementSurvey
    {
        return DB::transaction(function () use ($survey, $actor) {
            $survey = HrEngagementSurvey::query()
                ->with('questions')
                ->lockForUpdate()
                ->findOrFail($survey->getKey());
            $survey->loadMissing('questions');

            $copy = HrEngagementSurvey::create([
                'title' => $this->copyTitle($survey->title),
                'description' => $survey->description,
                'survey_type' => $survey->survey_type,
                'status' => 'draft',
                'is_anonymous' => $survey->is_anonymous,
                'audience_type' => $survey->audience_type ?? 'all',
                'audience_site_ids' => $survey->audience_site_ids,
                'starts_at' => null,
                'ends_at' => null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $survey->questions->each(fn ($question) => $copy->questions()->create([
                'question_type' => $question->question_type,
                'question_text' => $question->question_text,
                'options' => $question->options,
                'is_required' => $question->is_required,
                'sort_order' => $question->sort_order,
            ]));

            return $copy->load('questions');
        }, attempts: 1);
    }

    protected function copyTitle(string $title): string
    {
        return mb_substr(trim($title).' (copy)', 0, 255);
    }

    /**
     * Re-dispatch the survey invitation to recipients who have not yet responded.
     * Anonymity-safe: we target by recipient list and check each recipient's
     * deterministic respondent hash, never "who answered".
     */
    public function nudgeNonResponders(HrEngagementSurvey $survey, User $actor): int
    {
        if ($survey->status !== 'published') {
            throw ValidationException::withMessages(['survey' => 'Only published surveys can be nudged.']);
        }

        $recipients = $this->recipientsFor($survey);
        if ($recipients->isEmpty()) {
            return 0;
        }

        $key = (string) config('app.key');
        $respondedHashes = $survey->responses()->pluck('respondent_hash')->filter()->all();
        $respondedUserIds = $survey->is_anonymous
            ? collect()
            : $survey->responses()->pluck('user_id')->filter()->values();

        $pending = $recipients->filter(function (User $recipient) use ($survey, $key, $respondedHashes, $respondedUserIds) {
            if ($survey->is_anonymous) {
                $hash = hash_hmac('sha256', $survey->id.':'.$recipient->id, $key);

                return ! in_array($hash, $respondedHashes, true);
            }

            return ! $respondedUserIds->contains($recipient->id);
        })->values();

        if ($pending->isEmpty()) {
            return 0;
        }

        $notification = new EngagementSurveyInvitationNotification($survey->fresh());
        $pending->each(fn (User $recipient) => $recipient->notify($notification));

        return $pending->count();
    }

    /**
     * Archive a closed survey for list hygiene.
     */
    public function archiveSurvey(HrEngagementSurvey $survey, User $actor): HrEngagementSurvey
    {
        return DB::transaction(function () use ($survey, $actor): HrEngagementSurvey {
            $locked = HrEngagementSurvey::query()->lockForUpdate()->findOrFail($survey->getKey());
            if (! in_array($locked->status, ['closed', 'archived'], true)) {
                throw ValidationException::withMessages(['survey' => 'Only closed surveys can be archived.']);
            }
            $locked->update(['status' => 'archived', 'updated_by' => $actor->id]);

            return $locked->fresh();
        }, attempts: 1);
    }

    /**
     * Archive a draft survey without discarding its questions or history.
     */
    public function archiveDraftSurvey(HrEngagementSurvey $survey, User $actor): HrEngagementSurvey
    {
        return DB::transaction(function () use ($survey, $actor): HrEngagementSurvey {
            $locked = HrEngagementSurvey::query()->lockForUpdate()->findOrFail($survey->getKey());
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['survey' => 'Only draft surveys can be archived through this action.']);
            }
            $locked->update([
                'status' => 'archived',
                'updated_by' => $actor->id,
            ]);

            return $locked->fresh();
        }, attempts: 1);
    }

    /**
     * Close a published survey. $actor is null when the system auto-closes a
     * survey whose ends_at has passed (SendWellbeingRemindersJob) — the same
     * transition, just not attributed to a user.
     */
    public function closeSurvey(HrEngagementSurvey $survey, ?User $actor = null): HrEngagementSurvey
    {
        return DB::transaction(function () use ($survey, $actor): HrEngagementSurvey {
            $locked = HrEngagementSurvey::query()->lockForUpdate()->findOrFail($survey->getKey());
            if ($locked->status !== 'published') {
                throw ValidationException::withMessages(['survey' => 'Only published surveys can be closed.']);
            }
            $locked->update([
                'status' => 'closed',
                'closed_at' => now(),
                'updated_by' => $actor?->id,
            ]);

            return $locked->fresh();
        }, attempts: 1);
    }

    public function submitResponse(HrEngagementSurvey $survey, User $user, array $answers): HrEngagementSurveyResponse
    {
        return DB::transaction(function () use ($survey, $user, $answers): HrEngagementSurveyResponse {
            $survey = HrEngagementSurvey::query()
                ->with('questions')
                ->lockForUpdate()
                ->findOrFail($survey->getKey());
            if ($survey->status !== 'published') {
                throw ValidationException::withMessages(['survey' => 'This survey is not accepting responses.']);
            }

            if ($survey->starts_at && $survey->starts_at->isFuture()) {
                throw ValidationException::withMessages(['survey' => 'This survey has not started yet.']);
            }

            if ($survey->ends_at && $survey->ends_at->isPast()) {
                throw ValidationException::withMessages(['survey' => 'This survey has closed.']);
            }

            $respondentHash = hash_hmac('sha256', $survey->id.':'.$user->id, (string) config('app.key'));

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
                throw ValidationException::withMessages(['survey' => 'You have already submitted this survey.']);
            }

            $survey->loadMissing('questions');
            $normalizedAnswers = [];
            foreach ($survey->questions as $question) {
                $key = (string) $question->id;
                $answer = $answers[$key] ?? null;

                if ($question->is_required && ($answer === null || $answer === '')) {
                    throw ValidationException::withMessages([
                        'answers.'.(string) $question->id => 'Please complete all required survey questions.',
                    ]);
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
        }, attempts: 1);
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
    public function actionPlanSlaSummary(Collection $plans): array
    {
        $openStatuses = ['open', 'in_progress'];
        $openPlans = $plans->whereIn('status', $openStatuses)->values();

        $overdue = $openPlans->filter(fn (HrEngagementActionPlan $plan) => $plan->due_date && $plan->due_date->isBefore(now()->startOfDay()));
        $dueToday = $openPlans->filter(fn (HrEngagementActionPlan $plan) => $plan->due_date && $plan->due_date->isToday());
        $dueNext7 = $openPlans->filter(fn (HrEngagementActionPlan $plan) => $plan->due_date
            && $plan->due_date->isBetween(now()->startOfDay(), now()->addDays(7)->endOfDay()));

        $completedLast30 = $plans->filter(fn (HrEngagementActionPlan $plan) => $plan->status === 'completed'
            && $plan->completed_at
            && $plan->completed_at->betweenIncluded(now()->subDays(30)->startOfDay(), now()->endOfDay()))
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
    public function actionPlanOwnerWorkload(Collection $plans): array
    {
        $plans = $plans->whereIn('status', ['open', 'in_progress']);

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
     * @param  Collection<int, HrEngagementSurveyQuestion>  $questions
     * @param  array<string, mixed>  $answers
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
