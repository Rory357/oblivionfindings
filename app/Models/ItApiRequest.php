<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItApiRequest extends Model
{
    protected $fillable = [
        'tenant_id',
        'service_identity_id',
        'ticket_id',
        'method',
        'path',
        'idempotency_key',
        'request_hash',
        'response_status',
        'response_body',
        'completed_at',
    ];

    protected $casts = [
        'response_status' => 'integer',
        'response_body' => 'array',
        'completed_at' => 'datetime',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(ItServiceIdentity::class, 'service_identity_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'ticket_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
