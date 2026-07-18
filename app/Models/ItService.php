<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItService extends Model
{
    use HasFactory;

    public const STATUSES = ['operational', 'degraded', 'outage', 'maintenance', 'retired'];

    public const CRITICALITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'tenant_id',
        'owner_user_id',
        'key',
        'name',
        'description',
        'status',
        'criticality',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(ItTicket::class, 'it_service_id');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
