<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'current_version',
        'approved_by',
        'approved_at',
        'review_date',
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
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SafeWorkProcedureVersion::class)->orderByDesc('version_number');
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
