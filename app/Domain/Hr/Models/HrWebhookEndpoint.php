<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrWebhookEndpoint extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'name',
        'target_url',
        'signing_secret',
        'event_types',
        'headers',
        'timeout_seconds',
        'retry_limit',
        'is_active',
        'last_delivery_at',
        'last_status',
        'last_error',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'event_types' => 'array',
        'headers' => 'array',
        'is_active' => 'boolean',
        'timeout_seconds' => 'integer',
        'retry_limit' => 'integer',
        'last_delivery_at' => 'datetime',
        'signing_secret' => 'encrypted',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(HrWebhookDelivery::class, 'endpoint_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
