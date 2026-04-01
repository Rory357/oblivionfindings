<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrKeyResult extends Model
{
    protected $table = 'hr_key_results';

    protected $fillable = [
        'tenant_id',
        'goal_id',
        'title',
        'target_value',
        'current_value',
        'unit',
        'progress_percentage',
        'status',
        'due_date',
        'owner_id',
    ];

    protected $casts = [
        'target_value' => 'decimal:2',
        'current_value' => 'decimal:2',
        'progress_percentage' => 'integer',
        'due_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function goal(): BelongsTo
    {
        return $this->belongsTo(HrGoal::class, 'goal_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    public function recalculateProgress(): void
    {
        if ($this->target_value > 0) {
            $this->progress_percentage = min(100, (int) round(($this->current_value / $this->target_value) * 100));
        }

        if ($this->progress_percentage >= 100 && $this->status !== 'completed') {
            $this->status = 'completed';
        } elseif ($this->progress_percentage > 0 && $this->status === 'not_started') {
            $this->status = 'in_progress';
        }
    }
}
