<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrSurvey;
use App\Domain\Hr\Models\HrSurveyAnswer;
use App\Domain\Hr\Models\HrSurveyResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SurveyService
{
    /**
     * Survey types supported by the system.
     */
    public const SURVEY_TYPES = ['pulse', 'enps', 'engagement', 'custom'];

    /**
     * Question types supported.
     */
    public const QUESTION_TYPES = ['rating', 'text', 'multiple_choice', 'enps_score'];

    /**
     * Create a new survey with questions.
     *
     * @param  array  $data  Survey attributes including nested 'questions' array
     * @return HrSurvey
     */
    public function createSurvey(array $data): HrSurvey
    {
        return DB::transaction(function () use ($data) {
            $survey = HrSurvey::create([
                'tenant_id' => $data['tenant_id'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'survey_type' => $data['survey_type'],
                'status' => $data['status'] ?? 'draft',
                'is_anonymous' => $data['is_anonymous'] ?? true,
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            if (! empty($data['questions'])) {
                foreach ($data['questions'] as $index => $question) {
                    $survey->questions()->create([
                        'question_text' => $question['question_text'],
                        'question_type' => $question['question_type'],
                        'options' => $question['options'] ?? null,
                        'sort_order' => $question['sort_order'] ?? $index,
                        'is_required' => $question['is_required'] ?? true,
                    ]);
                }
            }

            return $survey->load('questions');
        });
    }

    /**
     * Submit a response to a survey.
     *
     * @param  HrSurvey  $survey
     * @param  User|null $user     Null if anonymous
     * @param  array     $answers  Array of {question_id, answer_text?, answer_rating?, answer_choice?}
     * @return HrSurveyResponse
     *
     * @throws \LogicException If survey is not active
     */
    public function submitResponse(HrSurvey $survey, ?User $user, array $answers): HrSurveyResponse
    {
        if ($survey->status !== 'active') {
            throw new \LogicException("Cannot submit a response to a '{$survey->status}' survey.");
        }

        if ($survey->ends_at && $survey->ends_at->isPast()) {
            throw new \LogicException('This survey has ended.');
        }

        return DB::transaction(function () use ($survey, $user, $answers) {
            $response = HrSurveyResponse::create([
                'survey_id' => $survey->id,
                'user_id' => $survey->is_anonymous ? null : $user?->id,
                'submitted_at' => now(),
            ]);

            foreach ($answers as $answer) {
                HrSurveyAnswer::create([
                    'response_id' => $response->id,
                    'question_id' => $answer['question_id'],
                    'answer_text' => $answer['answer_text'] ?? null,
                    'answer_rating' => $answer['answer_rating'] ?? null,
                    'answer_choice' => $answer['answer_choice'] ?? null,
                ]);
            }

            return $response->load('answers');
        });
    }

    /**
     * Calculate aggregated results for a survey.
     *
     * @param  HrSurvey  $survey
     * @return array
     */
    public function calculateResults(HrSurvey $survey): array
    {
        $survey->load(['questions.answers', 'responses']);

        $totalResponses = $survey->responses->count();
        $results = [];

        foreach ($survey->questions as $question) {
            $questionResult = [
                'id' => $question->id,
                'question_text' => $question->question_text,
                'question_type' => $question->question_type,
                'total_answers' => $question->answers->count(),
            ];

            if ($question->question_type === 'rating' || $question->question_type === 'enps_score') {
                $ratings = $question->answers->pluck('answer_rating')->filter()->values();
                $questionResult['average'] = $ratings->count() > 0 ? round($ratings->avg(), 2) : null;
                $questionResult['min'] = $ratings->min();
                $questionResult['max'] = $ratings->max();
                $questionResult['distribution'] = $ratings->countBy()->sortKeys()->all();
            } elseif ($question->question_type === 'multiple_choice') {
                $questionResult['distribution'] = $question->answers
                    ->pluck('answer_choice')
                    ->filter()
                    ->countBy()
                    ->sortDesc()
                    ->all();
            } elseif ($question->question_type === 'text') {
                $questionResult['responses'] = $question->answers
                    ->pluck('answer_text')
                    ->filter()
                    ->values()
                    ->all();
            }

            $results[] = $questionResult;
        }

        return [
            'total_responses' => $totalResponses,
            'questions' => $results,
        ];
    }

    /**
     * Calculate the eNPS (Employee Net Promoter Score).
     *
     * eNPS = % Promoters (9-10) - % Detractors (0-6)
     * Passives are 7-8.
     *
     * @param  HrSurvey  $survey
     * @return array{score: float, promoters: int, passives: int, detractors: int, total: int}
     */
    public function getENPSScore(HrSurvey $survey): array
    {
        $enpsQuestions = $survey->questions()
            ->where('question_type', 'enps_score')
            ->pluck('id');

        $ratings = HrSurveyAnswer::whereIn('question_id', $enpsQuestions)
            ->whereNotNull('answer_rating')
            ->pluck('answer_rating');

        $total = $ratings->count();

        if ($total === 0) {
            return ['score' => 0, 'promoters' => 0, 'passives' => 0, 'detractors' => 0, 'total' => 0];
        }

        $promoters = $ratings->filter(fn ($r) => $r >= 9)->count();
        $detractors = $ratings->filter(fn ($r) => $r <= 6)->count();
        $passives = $total - $promoters - $detractors;

        $score = round((($promoters - $detractors) / $total) * 100, 1);

        return [
            'score' => $score,
            'promoters' => $promoters,
            'passives' => $passives,
            'detractors' => $detractors,
            'total' => $total,
        ];
    }
}
