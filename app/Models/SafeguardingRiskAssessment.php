<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SafeguardingRiskAssessment extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'safeguarding_concern_id',
        'assessor_id',
        'assessed_at',
        'risk_factors',
        'protective_factors',
        'risk_to_self',
        'risk_to_others',
        'risk_from_others',
        'overall_risk_level',
        'capacity_assessed',
        'mental_capacity',
        'capacity_notes',
        'immediate_actions_required',
        'protective_measures',
        'multi_agency_required',
        'agencies_involved',
        'next_review_date',
        'assessment_notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'risk_factors' => 'array',
        'protective_factors' => 'array',
        'protective_measures' => 'array',
        'agencies_involved' => 'array',
        'assessed_at' => 'datetime',
        'next_review_date' => 'datetime',
        'capacity_assessed' => 'boolean',
        'multi_agency_required' => 'boolean',
    ];

    /**
     * Safeguarding concern.
     */
    public function concern(): BelongsTo
    {
        return $this->belongsTo(SafeguardingConcern::class, 'safeguarding_concern_id');
    }

    /**
     * Assessor.
     */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    /**
     * User who created the record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who last updated the record.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope: Due for review.
     */
    public function scopeDueForReview($query)
    {
        return $query->where('next_review_date', '<=', now());
    }

    /**
     * Scope: High risk assessments.
     */
    public function scopeHighRisk($query)
    {
        return $query->whereIn('overall_risk_level', ['high', 'critical']);
    }

    /**
     * Check if assessment is due for review.
     */
    public function isDueForReview(): bool
    {
        return $this->next_review_date && $this->next_review_date->isPast();
    }

    /**
     * Check if risk is high or critical.
     */
    public function isHighRisk(): bool
    {
        return in_array($this->overall_risk_level, ['high', 'critical']);
    }
}
