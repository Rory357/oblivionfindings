<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StrategicInitiative extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'strategic_goal_id',
        'title',
        'description',
        'budget_allocated',
        'budget_spent',
        'start_date',
        'target_completion',
        'actual_completion',
        'status',
        'dependencies',
        'risks',
        'owner_id',
    ];

    protected $casts = [
        'budget_allocated' => 'decimal:2',
        'budget_spent' => 'decimal:2',
        'start_date' => 'date',
        'target_completion' => 'date',
        'actual_completion' => 'date',
        'dependencies' => 'array',
        'risks' => 'array',
    ];

    public function strategicGoal(): BelongsTo
    {
        return $this->belongsTo(StrategicGoal::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOverdue($query)
    {
        return $query->where('target_completion', '<', now())
            ->whereNotIn('status', ['complete', 'cancelled']);
    }

    public function isPlanning(): bool
    {
        return $this->status === 'planning';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }

    public function isOnHold(): bool
    {
        return $this->status === 'on_hold';
    }

    public function isOverdue(): bool
    {
        return $this->target_completion && 
               $this->target_completion->isPast() && 
               !$this->isComplete();
    }

    public function getBudgetVariance(): float
    {
        return $this->budget_spent - $this->budget_allocated;
    }

    public function getBudgetUtilization(): float
    {
        if ($this->budget_allocated == 0) {
            return 0;
        }
        return ($this->budget_spent / $this->budget_allocated) * 100;
    }

    public function complete(): void
    {
        $this->update([
            'status' => 'complete',
            'actual_completion' => now(),
        ]);
        
        // Update parent goal progress
        $this->strategicGoal->updateProgress();
    }
}
