<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItChange extends Model
{
    use HasFactory;

    public const TYPES = ['standard', 'normal', 'emergency'];

    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    public const VALIDATION_RESULTS = ['successful', 'failed', 'inconclusive'];

    protected $fillable = [
        'tenant_id',
        'ticket_id',
        'change_type',
        'risk_level',
        'is_restricted',
        'impact_summary',
        'implementation_plan',
        'validation_plan',
        'backout_plan',
        'maintenance_starts_at',
        'maintenance_ends_at',
        'actual_outcome',
        'validation_result',
        'validation_summary',
        'backout_summary',
        'pir_summary',
        'implemented_at',
        'implemented_by_user_id',
        'validated_at',
        'validated_by_user_id',
        'backed_out_at',
        'reviewed_at',
        'reviewed_by_user_id',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_restricted' => 'boolean',
        'maintenance_starts_at' => 'datetime',
        'maintenance_ends_at' => 'datetime',
        'implemented_at' => 'datetime',
        'validated_at' => 'datetime',
        'backed_out_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'ticket_id');
    }

    public function implementedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'implemented_by_user_id');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function needsApproval(): bool
    {
        return $this->change_type !== 'standard'
            || $this->risk_level !== 'low'
            || $this->is_restricted;
    }

    public function needsIndependentValidation(): bool
    {
        return $this->is_restricted || in_array($this->risk_level, ['high', 'critical'], true);
    }
}
