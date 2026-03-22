<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrGoalUpdate extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'goal_id',
        'user_id',
        'previous_value',
        'new_value',
        'progress_percentage',
        'comment',
    ];

    protected $casts = [
        'previous_value' => 'decimal:2',
        'new_value' => 'decimal:2',
        'progress_percentage' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function goal(): BelongsTo
    {
        return $this->belongsTo(HrGoal::class, 'goal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
