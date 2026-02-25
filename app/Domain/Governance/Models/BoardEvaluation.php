<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardEvaluation extends Model
{
    protected $fillable = [
        'title', 'evaluation_type', 'year', 'status', 'summary',
        'questions', 'aggregate_results', 'recommendations',
        'action_plan', 'created_by', 'opened_at', 'closed_at',
    ];

    protected $casts = [
        'questions' => 'array',
        'aggregate_results' => 'array',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(BoardEvaluationResponse::class);
    }

    public function open(): void
    {
        $this->update(['status' => 'open', 'opened_at' => now()]);
    }

    public function close(): void
    {
        $this->update(['status' => 'closed', 'closed_at' => now()]);
        $this->aggregateResults();
    }

    public function aggregateResults(): void
    {
        $responses = $this->responses()->whereNotNull('submitted_at')->get();
        if ($responses->isEmpty()) return;

        $aggregate = [];
        foreach ($this->questions as $question) {
            $qId = $question['id'];
            $answers = $responses->pluck('answers')->filter()
                ->map(fn($a) => collect($a)->firstWhere('question_id', $qId))
                ->filter();

            if ($question['type'] === 'rating') {
                $ratings = $answers->pluck('rating')->filter();
                $aggregate[$qId] = [
                    'question' => $question['question'],
                    'category' => $question['category'] ?? null,
                    'avg_rating' => $ratings->isNotEmpty() ? round($ratings->avg(), 1) : null,
                    'response_count' => $ratings->count(),
                ];
            } else {
                $aggregate[$qId] = [
                    'question' => $question['question'],
                    'category' => $question['category'] ?? null,
                    'response_count' => $answers->count(),
                ];
            }
        }

        $this->update(['aggregate_results' => $aggregate]);
    }

    public function getCompletionRate(): float
    {
        $total = BoardMember::active()->count();
        $responded = $this->responses()->whereNotNull('submitted_at')->count();
        return $total > 0 ? round(($responded / $total) * 100, 1) : 0;
    }

    public static function getDefaultQuestions(): array
    {
        return [
            ['id' => 1, 'category' => 'governance', 'question' => 'The Board has a clear understanding of its role and responsibilities', 'type' => 'rating'],
            ['id' => 2, 'category' => 'governance', 'question' => 'Board meetings are well-organised and productive', 'type' => 'rating'],
            ['id' => 3, 'category' => 'governance', 'question' => 'The Board receives timely and relevant information for decision-making', 'type' => 'rating'],
            ['id' => 4, 'category' => 'strategy', 'question' => 'The Board effectively oversees organisational strategy', 'type' => 'rating'],
            ['id' => 5, 'category' => 'risk', 'question' => 'The Board has effective oversight of risk management', 'type' => 'rating'],
            ['id' => 6, 'category' => 'performance', 'question' => 'The Board effectively monitors CEO and organisational performance', 'type' => 'rating'],
            ['id' => 7, 'category' => 'compliance', 'question' => 'The Board ensures compliance with legal and regulatory requirements', 'type' => 'rating'],
            ['id' => 8, 'category' => 'culture', 'question' => 'Board culture supports open discussion and constructive challenge', 'type' => 'rating'],
            ['id' => 9, 'category' => 'te_tiriti', 'question' => 'The Board demonstrates commitment to Te Tiriti o Waitangi obligations', 'type' => 'rating'],
            ['id' => 10, 'category' => 'improvement', 'question' => 'What areas should the Board focus on improving?', 'type' => 'text'],
        ];
    }
}
