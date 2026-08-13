<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DataRetentionPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'model_type',
        'policy_name',
        'description',
        'retention_period_years',
        'archive_after_years',
        'hard_delete_after_years',
        'retention_conditions',
        'applies_to_soft_deleted',
        'legal_hold_exemption',
        'active_case_exemption',
        'legal_basis',
        'business_justification',
        'active',
        'execution_state',
        'preview_fingerprint',
        'preview_snapshot',
        'previewed_at',
        'previewed_by_user_id',
        'approved_fingerprint',
        'approved_at',
        'approved_by_user_id',
        'last_applied_at',
        'next_review_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'retention_period_years' => 'integer',
        'archive_after_years' => 'integer',
        'hard_delete_after_years' => 'integer',
        'retention_conditions' => 'array',
        'applies_to_soft_deleted' => 'boolean',
        'legal_hold_exemption' => 'boolean',
        'active_case_exemption' => 'boolean',
        'active' => 'boolean',
        'preview_snapshot' => 'array',
        'previewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'last_applied_at' => 'datetime',
        'next_review_at' => 'date',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function previewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previewed_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(DataRetentionExecution::class);
    }

    /**
     * Supporting documents (the written policy, sign-off, legal basis memo).
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(PrivacyAttachment::class, 'attachable')->latest();
    }

    /**
     * Scope: active policies.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
