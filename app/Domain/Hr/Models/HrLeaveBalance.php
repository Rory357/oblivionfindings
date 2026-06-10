<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Database\Factories\Hr\HrLeaveBalanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrLeaveBalance extends Model
{
    use AuditableChanges, HasFactory;

    protected static function newFactory()
    {
        return HrLeaveBalanceFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'user_id',
        'leave_type',
        'balance_hours',
        'accrued_hours',
        'used_hours',
        'pending_hours',
        'year',
        'source',
        'last_synced_at',
        'notes',
        'updated_by',
    ];

    protected $casts = [
        'balance_hours' => 'decimal:2',
        'accrued_hours' => 'decimal:2',
        'used_hours' => 'decimal:2',
        'pending_hours' => 'decimal:2',
        'year' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
