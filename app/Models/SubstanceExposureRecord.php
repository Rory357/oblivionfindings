<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubstanceExposureRecord extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'hazardous_substance_id',
        'user_id',
        'site_id',
        'exposed_at',
        'exposure_type',
        'exposure_duration',
        'circumstances',
        'symptoms',
        'first_aid_given',
        'medical_attention_sought',
        'medical_treatment',
        'medical_outcome',
        'incident_reported',
        'related_incident_id',
        'created_by',
        'updated_by',
    ];

    // Generic activity cannot copy identifiable exposure/medical source contents into a broad audit channel.
    protected $auditExcludedAttributes = [
        'hazardous_substance_id', 'user_id', 'site_id', 'exposed_at', 'exposure_type', 'exposure_duration',
        'circumstances', 'symptoms', 'first_aid_given', 'medical_attention_sought', 'medical_treatment',
        'medical_outcome', 'incident_reported', 'related_incident_id', 'created_by', 'updated_by',
        'original_context',
    ];

    protected $casts = [
        'source_schema_version' => 'integer',
        'source_revision' => 'integer',
        'hazardous_substance_id' => 'integer',
        'user_id' => 'integer',
        'site_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'related_incident_id' => 'integer',
        'original_context' => 'array',
        'exposed_at' => 'datetime',
        'medical_attention_sought' => 'boolean',
        'incident_reported' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function hazardousSubstance(): BelongsTo
    {
        return $this->belongsTo(HazardousSubstance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function relatedIncident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'related_incident_id');
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

    public function scopeForWorker($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeRequiringMedicalAttention($query)
    {
        return $query->where('medical_attention_sought', true);
    }
    public function sourceVersions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChemicalExposureSourceVersion::class, 'exposure_id');
    }

    public function commandReceipts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChemicalExposureCommandReceipt::class, 'exposure_id');
    }

    public function reviews(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChemicalExposureReview::class, 'exposure_id');
    }

    public function followups(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChemicalExposureFollowup::class, 'exposure_id');
    }

    public function evidence(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChemicalExposureEvidence::class, 'exposure_id');
    }

}
