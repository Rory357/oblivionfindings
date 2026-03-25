<?php

namespace App\Domain\Hr\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrWebhookDelivery extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RETRYING = 'retrying';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'endpoint_id',
        'tenant_id',
        'event_type',
        'event_uuid',
        'payload',
        'status',
        'attempts',
        'max_attempts',
        'queued_at',
        'delivered_at',
        'failed_at',
        'response_code',
        'response_body',
        'error_message',
        'idempotency_key',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'response_code' => 'integer',
        'queued_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(HrWebhookEndpoint::class, 'endpoint_id');
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
