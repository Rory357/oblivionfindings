<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class HazardousSubstance extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'common_name',
        'un_number',
        'hsno_approval',
        'hsno_classification',
        'hazard_classifications',
        'ghs_pictograms',
        'signal_word',
        'hazard_statements',
        'precautionary_statements',
        'physical_form',
        'first_aid_measures',
        'firefighting_measures',
        'spill_procedures',
        'handling_precautions',
        'storage_requirements',
        'ppe_required',
        'exposure_limit_type',
        'exposure_limit_value',
        'requires_tracking',
        'is_controlled_substance',
        'status',
        'status_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'hazard_classifications' => 'array',
        'ghs_pictograms' => 'array',
        'requires_tracking' => 'boolean',
        'is_controlled_substance' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function safetyDataSheets(): HasMany
    {
        return $this->hasMany(SafetyDataSheet::class);
    }

    public function storageLocations(): HasMany
    {
        return $this->hasMany(SubstanceStorageLocation::class);
    }

    public function exposureRecords(): HasMany
    {
        return $this->hasMany(SubstanceExposureRecord::class);
    }

    /**
     * The single current Safety Data Sheet (latest issued, not superseded).
     */
    public function currentSds(): HasOne
    {
        return $this->hasOne(SafetyDataSheet::class)
            ->where('status', 'current')
            ->latest('issue_date');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeControlled($query)
    {
        return $query->where('is_controlled_substance', true);
    }

    public function scopeRequiresTracking($query)
    {
        return $query->where('requires_tracking', true);
    }

    /** Substances with no current SDS on file. */
    public function scopeSdsMissing($query)
    {
        return $query->whereDoesntHave('safetyDataSheets', fn ($q) => $q->where('status', 'current'));
    }

    /** Substances whose current SDS is due for review within `$days` (includes overdue). */
    public function scopeSdsExpiring($query, int $days = SafetyDataSheet::REVIEW_HORIZON_DAYS)
    {
        return $query->whereHas('safetyDataSheets', fn ($q) => $q->where('status', 'current')
            ->whereNotNull('review_date')
            ->whereDate('review_date', '<=', now()->addDays($days)));
    }

    /** Substances with a current SDS that is genuinely up to date (not expiring/overdue). */
    public function scopeSdsCurrent($query, int $days = SafetyDataSheet::REVIEW_HORIZON_DAYS)
    {
        return $query->whereHas('safetyDataSheets', fn ($q) => $q->where('status', 'current'))
            ->whereDoesntHave('safetyDataSheets', fn ($q) => $q->where('status', 'current')
                ->whereNotNull('review_date')
                ->whereDate('review_date', '<=', now()->addDays($days)));
    }

    /* ------------------------------------------------------------------ */
    /*  Computed SDS state */
    /* ------------------------------------------------------------------ */

    /**
     * Org-wide SDS posture for this substance, derived from the current sheet:
     *  missing (no current SDS) · current · expiring (review ≤30d) · expired (review past).
     * Prefers the eager-loaded `currentSds` relation to avoid N+1.
     */
    public function getSdsStateAttribute(): string
    {
        $current = $this->relationLoaded('currentSds')
            ? $this->getRelation('currentSds')
            : $this->currentSds()->first();

        return $current?->state ?? 'missing';
    }
}
