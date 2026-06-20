<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SafeWorkProcedure extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'title',
        'reference_number',
        'category',
        'purpose',
        'scope',
        'hazards_addressed',
        'ppe_required',
        'steps',
        'emergency_procedures',
        'status',
        'previous_status',
        'current_version',
        'approved_by',
        'approved_at',
        'owner_id',
        'review_date',
        'review_frequency_months',
        'applicable_roles',
        'applicable_sites',
        'related_training',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'hazards_addressed' => 'array',
        'ppe_required' => 'array',
        'steps' => 'array',
        'emergency_procedures' => 'array',
        'applicable_roles' => 'array',
        'applicable_sites' => 'array',
        'related_training' => 'array',
        'approved_at' => 'datetime',
        'review_date' => 'date',
        'current_version' => 'integer',
        'review_frequency_months' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SafeWorkProcedureVersion::class)->orderByDesc('version_number');
    }

    /**
     * Controlled-document library — reuses the shared polymorphic HsAttachment store
     * (private disk) rather than a bespoke table. Each file is version-stamped via
     * version_at_upload so the detail modal can flag superseded master documents.
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(HsAttachment::class, 'attachable')->latest();
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
