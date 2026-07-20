<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MonitoringInbox extends Model
{
    protected $table = 'monitoring_inbox';

    protected $fillable = [
        'message_id',
        'tenant_id',
        'consumer',
        'source',
        'sequence',
        'idempotency_key',
        'payload_hash',
        'envelope',
        'processed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'sequence' => 'integer',
        'envelope' => 'array',
        'processed_at' => 'immutable_datetime',
    ];

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
