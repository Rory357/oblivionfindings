<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Enums\MonitorState;
use Database\Factories\MonitorObservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorObservation extends Model
{
    use HasFactory;

    protected static function newFactory(): MonitorObservationFactory
    {
        return MonitorObservationFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'monitor_id',
        'source_key',
        'state',
        'value',
        'unit',
        'latency_ms',
        'message',
        'metrics',
        'observed_at',
        'ingested_at',
    ];

    protected $casts = [
        'state' => MonitorState::class,
        'value' => 'decimal:6',
        'latency_ms' => 'integer',
        'metrics' => 'array',
        'observed_at' => 'datetime',
        'ingested_at' => 'datetime',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
