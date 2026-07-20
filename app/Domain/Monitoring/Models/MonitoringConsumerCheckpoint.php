<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MonitoringConsumerCheckpoint extends Model
{
    protected $fillable = [
        'tenant_id',
        'consumer',
        'source',
        'last_sequence',
        'gap_from',
        'gap_to',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'last_sequence' => 'integer',
        'gap_from' => 'integer',
        'gap_to' => 'integer',
    ];

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
