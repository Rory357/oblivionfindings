<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrEapReferral extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'staff_user_id',
        'referred_by',
        'reason_category',
        'provider',
        'status',
        'consent_given',
        'is_self_referral',
        'notes',
    ];

    protected $casts = [
        'consent_given' => 'boolean',
        'is_self_referral' => 'boolean',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
