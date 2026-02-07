<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StrategicGoal extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'strategic_plan_id',
        'timeframe',
        'pillar',
        'title',
        'description',
        'key_results',
        'progress_pct',
        'status',
        'lead_executive_id',
        'risks',
        'order',
    ];

    protected $casts = [
        'key_results' => 'array',
        'risks' => 'array',
        'progress_pct' => 'decimal:2',
        'order' => 'integer',
    ];

    public function strategicPlan(): BelongsTo
    {
        return $this->belongsTo(StrategicPlan::class);
    }

    public function leadExecutive(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_executive_id');
    }

    public function initiatives(): HasMany
    {
        return $this->hasMany(StrategicInitiative::class);
    }

    public function scopeByPillar($query, string $pillar)
    {
        return $query->where('pillar', $pillar);
    }

    public function scopeByTimeframe($query, string $timeframe)
    {
        return $query->where('timeframe', $timeframe);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function isNotStarted(): bool
    {
        return $this->status === 'not_started';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function isAchieved(): bool
    {
        return $this->status === 'achieved';
    }

    public function isAtRisk(): bool
    {
        return $this->status === 'at_risk';
    }

    public function updateProgress(): void
    {
        $initiatives = $this->initiatives;
        if ($initiatives->isEmpty()) {
            return;
        }

        // Calculate progress based on initiative completion
        $completed = $initiatives->where('status', 'complete')->count();
        $total = $initiatives->count();
        
        $this->progress_pct = $total > 0 ? ($completed / $total) * 100 : 0;
        
        // Update status based on progress
        if ($this->progress_pct >= 100) {
            $this->status = 'achieved';
        } elseif ($this->progress_pct > 0) {
            $this->status = 'in_progress';
        }
        
        $this->save();
    }
}
