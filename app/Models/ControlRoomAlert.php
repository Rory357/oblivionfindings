<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ControlRoomAlert extends Model
{
    protected $fillable = [
        'source',
        'alert_type',
        'severity',
        'status',
        'asset_id',
        'fleet_signal_id',
        'triggered_at',
        'acknowledged_at',
        'resolved_at',
        'context',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'context' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function fleetSignal(): BelongsTo
    {
        return $this->belongsTo(FleetSignal::class, 'fleet_signal_id');
    }
}
