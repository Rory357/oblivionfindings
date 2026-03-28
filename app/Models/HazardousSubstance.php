<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HazardousSubstance extends Model
{
    use HasFactory;
    use AuditableChanges;
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
    /*  Relationships                                                      */
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
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
}
