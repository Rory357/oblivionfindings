<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\HasReferenceNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BehaviourSupportPlan extends Model
{
    use AuditableChanges, HasFactory, HasReferenceNumber, SoftDeletes;

    public const REFERENCE_PREFIX = 'BSP';

    protected $fillable = [
        'lock_version', 'creation_key', 'creation_fingerprint', 'source_schema_version', 'source_site_id', 'source_stay_id', 'version_sequence', 'current_candidate_version_id', 'current_published_version_id',
        'reference_number',
        'client_id',
        'title',
        'triggers',
        'de_escalation_strategies',
        'approved_interventions',
        'prohibited_interventions',
        'restrictive_practice_type',
        'developed_by',
        'developed_at',
        'review_date',
        'status',
        'status_changed_at',
        'status_changed_by',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'lock_version' => 'integer', 'source_schema_version' => 'integer', 'source_site_id' => 'integer', 'source_stay_id' => 'integer', 'version_sequence' => 'integer', 'current_candidate_version_id' => 'integer', 'current_published_version_id' => 'integer',
        'developed_at' => 'date',
        'review_date' => 'date',
        'status_changed_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function versions(): HasMany
    {
        return $this->hasMany(BehaviourSupportPlanVersion::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(BehaviourSupportPlanApproval::class);
    }

    public function candidateVersion(): BelongsTo
    {
        return $this->belongsTo(BehaviourSupportPlanVersion::class, 'current_candidate_version_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(BehaviourSupportPlanVersion::class, 'current_published_version_id');
    }

    public function sourceSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'source_site_id');
    }

    public function sourceStay(): BelongsTo
    {
        return $this->belongsTo(RespiteStay::class, 'source_stay_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function developedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'developed_by');
    }

    public function restraintEvents(): HasMany
    {
        return $this->hasMany(RestraintEvent::class, 'behaviour_support_plan_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(BehaviourSupportPlanReview::class, 'behaviour_support_plan_id');
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
