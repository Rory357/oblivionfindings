<?php

namespace App\Domain\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardEvaluationResponse extends Model
{
    protected $fillable = [
        'board_evaluation_id', 'board_member_id', 'answers',
        'is_anonymous', 'submitted_at',
    ];

    protected $casts = [
        'answers' => 'array',
        'is_anonymous' => 'boolean',
        'submitted_at' => 'datetime',
    ];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(BoardEvaluation::class, 'board_evaluation_id');
    }

    public function boardMember(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class);
    }

    public function submit(): void
    {
        $this->update(['submitted_at' => now()]);
    }
}
