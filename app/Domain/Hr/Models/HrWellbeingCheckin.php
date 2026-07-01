<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrWellbeingCheckin extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'staff_user_id',
        'manager_user_id',
        'type',
        'notes',
        'mood',
        'follow_up_date',
        'is_private',
        'acknowledged_at',
    ];

    protected $casts = [
        'follow_up_date' => 'date',
        'is_private' => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
