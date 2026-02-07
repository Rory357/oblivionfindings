<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RiskRegisterEntry extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'risk_register_entries';

    protected $fillable = [
        'risk_reference',
        'category',
        'title',
        'description',
        'likelihood_score',
        'impact_score',
        'inherent_score',
        'control_effectiveness',
        'residual_score',
        'appetite_threshold',
        'within_appetite',
        'risk_owner_id',
        'risk_committee',
        'review_frequency',
        'next_review_date',
        'status',
        'mitigation_strategy',
        'closure_rationale',
        'closed_at',
        'closed_by',
        'identified_at',
        'identified_by',
    ];

    protected $casts = [
        'likelihood_score' => 'integer',
        'impact_score' => 'integer',
        'inherent_score' => 'integer',
        'residual_score' => 'integer',
        'appetite_threshold' => 'integer',
        'within_appetite' => 'boolean',
        'next_review_date' => 'date',
        'identified_at' => 'date',
        'closed_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->risk_reference)) {
                $model->risk_reference = static::generateReference();
            }
            $model->calculateScores();
        });

        static::updating(function ($model) {
            if ($model->isDirty(['likelihood_score', 'impact_score', 'control_effectiveness'])) {
                $model->calculateScores();
            }
        });
    }

    public static function generateReference(): string
    {
        $year = now()->year;
        $prefix = "R-{$year}-";
        $last = static::whereYear('created_at', $year)->count() + 1;
        return $prefix . str_pad($last, 3, '0', STR_PAD_LEFT);
    }

    public function calculateScores(): void
    {
        $this->inherent_score = $this->likelihood_score * $this->impact_score;
        
        $multiplier = match($this->control_effectiveness) {
            'strong' => 0.2,
            'moderate' => 0.5,
            'weak' => 0.8,
            default => 1.0,
        };
        
        $this->residual_score = (int) round($this->inherent_score * $multiplier);
        $this->within_appetite = $this->residual_score <= $this->appetite_threshold;
    }

    public function riskOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'risk_owner_id');
    }

    public function identifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'identified_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RiskEventLink::class, 'risk_register_entry_id');
    }

    public function treatments(): HasMany
    {
        return $this->hasMany(RiskTreatment::class, 'risk_register_entry_id');
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(RiskAcceptance::class, 'risk_register_entry_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeCritical($query)
    {
        return $query->where('residual_score', '>=', 20);
    }

    public function scopeHigh($query)
    {
        return $query->whereBetween('residual_score', [15, 19]);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeAboveAppetite($query)
    {
        return $query->where('within_appetite', false);
    }

    public function scopeReviewDue($query)
    {
        return $query->where('next_review_date', '<=', now()->addWeek());
    }

    public function isCritical(): bool
    {
        return $this->residual_score >= 20;
    }

    public function isHigh(): bool
    {
        return $this->residual_score >= 15 && $this->residual_score < 20;
    }

    public function isMedium(): bool
    {
        return $this->residual_score >= 10 && $this->residual_score < 15;
    }

    public function isLow(): bool
    {
        return $this->residual_score < 10;
    }

    public function requiresBoardAcceptance(): bool
    {
        return !$this->within_appetite && $this->status === 'active';
    }

    public function close(string $reason, int $userId): void
    {
        $this->update([
            'status' => 'voided',
            'closure_rationale' => $reason,
            'closed_at' => now(),
            'closed_by' => $userId,
        ]);
    }

    public function getSeverityColor(): string
    {
        return match(true) {
            $this->isCritical() => 'red',
            $this->isHigh() => 'orange',
            $this->isMedium() => 'yellow',
            default => 'green',
        };
    }
}
