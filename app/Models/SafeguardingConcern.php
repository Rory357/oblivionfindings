<?php

namespace App\Models;

use App\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SafeguardingConcern extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'reference_number',
        'subject_type',
        'subject_id',
        'subject_name',
        'concern_type',
        'abuse_category',
        'severity',
        'description',
        'occurred_at',
        'location',
        'alleged_perpetrator_type',
        'alleged_perpetrator_id',
        'alleged_perpetrator_name',
        'alleged_perpetrator_details',
        'reported_by_user_id',
        'reported_by_name',
        'reported_by_role',
        'reported_at',
        'reporter_notes',
        'witnesses',
        'status',
        'immediate_actions',
        'subject_informed',
        'subject_informed_at',
        'requires_external_referral',
        'current_risk_level',
        'protective_measures',
        'assigned_to_user_id',
        'assigned_at',
        'closed_by_user_id',
        'closed_at',
        'closure_summary',
        'lessons_learned',
        'related_incident_id',
        'site_id',
        'organization_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'witnesses' => 'array',
        'reported_at' => 'datetime',
        'occurred_at' => 'datetime',
        'subject_informed_at' => 'datetime',
        'assigned_at' => 'datetime',
        'closed_at' => 'datetime',
        'subject_informed' => 'boolean',
        'requires_external_referral' => 'boolean',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($concern) {
            if (empty($concern->reference_number)) {
                $concern->reference_number = static::generateReferenceNumber();
            }
            if (empty($concern->reported_at)) {
                $concern->reported_at = now();
            }
        });
    }

    /**
     * Generate a unique reference number.
     */
    public static function generateReferenceNumber(): string
    {
        $year = now()->year;
        $prefix = 'SG-' . $year . '-';

        $lastConcern = static::where('reference_number', 'like', $prefix . '%')
            ->orderByDesc('reference_number')
            ->first();

        if ($lastConcern) {
            $lastNumber = (int) str_replace($prefix, '', $lastConcern->reference_number);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Subject of the concern (polymorphic).
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Alleged perpetrator (polymorphic).
     */
    public function allegedPerpetrator(): MorphTo
    {
        return $this->morphTo('alleged_perpetrator');
    }

    /**
     * User who reported the concern.
     */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    /**
     * User assigned to manage the concern.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * User who closed the concern.
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * Related incident.
     */
    public function relatedIncident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'related_incident_id');
    }

    /**
     * Site where concern occurred.
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Organization.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
     * Investigations.
     */
    public function investigations(): HasMany
    {
        return $this->hasMany(SafeguardingInvestigation::class);
    }

    /**
     * External reports.
     */
    public function externalReports(): HasMany
    {
        return $this->hasMany(SafeguardingExternalReport::class);
    }

    /**
     * Risk assessments.
     */
    public function riskAssessments(): HasMany
    {
        return $this->hasMany(SafeguardingRiskAssessment::class);
    }

    /**
     * Action plans.
     */
    public function actionPlans(): HasMany
    {
        return $this->hasMany(SafeguardingActionPlan::class);
    }

    /**
     * Safeguarding alerts.
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(SafeguardingAlert::class);
    }

    /**
     * Get the latest investigation.
     */
    public function latestInvestigation()
    {
        return $this->hasOne(SafeguardingInvestigation::class)->latestOfMany();
    }

    /**
     * Get the latest risk assessment.
     */
    public function latestRiskAssessment()
    {
        return $this->hasOne(SafeguardingRiskAssessment::class)->latestOfMany();
    }

    /**
     * Scope: Open concerns.
     */
    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', ['closed']);
    }

    /**
     * Scope: Closed concerns.
     */
    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    /**
     * Scope: High priority concerns.
     */
    public function scopeHighPriority($query)
    {
        return $query->whereIn('severity', ['high', 'critical']);
    }

    /**
     * Scope: Requiring external referral.
     */
    public function scopeRequiringExternalReferral($query)
    {
        return $query->where('requires_external_referral', true)
            ->whereDoesntHave('externalReports');
    }

    /**
     * Check if concern is open.
     */
    public function isOpen(): bool
    {
        return $this->status !== 'closed';
    }

    /**
     * Check if concern is critical.
     */
    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }

    /**
     * Check if external referral is required but not made.
     */
    public function needsExternalReferral(): bool
    {
        return $this->requires_external_referral && $this->externalReports()->count() === 0;
    }
}
