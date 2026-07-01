<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single KR-level check-in — value + confidence + comment + actor — so a key
 * result's history is first-class, not only the goal-level HrGoalUpdate log.
 */
class HrKeyResultUpdate extends Model
{
    protected $table = 'hr_key_result_updates';

    protected $fillable = [
        'key_result_id',
        'user_id',
        'previous_value',
        'new_value',
        'progress_percentage',
        'confidence',
        'comment',
    ];

    protected $casts = [
        'previous_value' => 'decimal:2',
        'new_value' => 'decimal:2',
        'progress_percentage' => 'integer',
    ];

    public function keyResult(): BelongsTo
    {
        return $this->belongsTo(HrKeyResult::class, 'key_result_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
