<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class HrApprovalInstance extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'approval_chain_id',
        'approvable_type',
        'approvable_id',
        'current_step',
        'status',
        'initiated_by',
        'initiated_at',
        'completed_at',
    ];

    protected $casts = [
        'current_step' => 'integer',
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function chain(): BelongsTo
    {
        return $this->belongsTo(HrApprovalChain::class, 'approval_chain_id');
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(HrApprovalAction::class, 'approval_instance_id')->orderBy('step_order');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeForStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}
