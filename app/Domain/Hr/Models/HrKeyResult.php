<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrKeyResult extends Model
{
    use WritesLegacyStorageContext;

    protected $table = 'hr_key_results';

    protected $fillable = [
        'tenant_id',
        'goal_id',
        'title',
        'start_value',
        'kr_type',
        'target_value',
        'current_value',
        'unit',
        'progress_percentage',
        'weight',
        'status',
        'confidence',
        'due_date',
        'owner_id',
    ];

    protected $casts = [
        'start_value' => 'decimal:2',
        'target_value' => 'decimal:2',
        'current_value' => 'decimal:2',
        'progress_percentage' => 'integer',
        'weight' => 'integer',
        'due_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function goal(): BelongsTo
    {
        return $this->belongsTo(HrGoal::class, 'goal_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(HrKeyResultUpdate::class, 'key_result_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Baseline-aware progress: clamp((current − start) / (target − start), 0, 1).
     * Handles "reduce 50 → 10" KRs the naive current/target ratio cannot.
     */
    public function recalculateProgress(): void
    {
        $start = (float) ($this->start_value ?? 0);
        $target = (float) $this->target_value;
        $current = (float) $this->current_value;

        $denominator = $target - $start;

        if ($denominator == 0.0) {
            $progress = $current >= $target ? 100 : 0;
        } else {
            $ratio = ($current - $start) / $denominator;
            $progress = (int) round(max(0.0, min(1.0, $ratio)) * 100);
        }

        $this->progress_percentage = $progress;

        if ($this->progress_percentage >= 100 && $this->status !== 'completed') {
            $this->status = 'completed';
        } elseif ($this->progress_percentage > 0 && $this->status === 'not_started') {
            $this->status = 'in_progress';
        }
    }
}
