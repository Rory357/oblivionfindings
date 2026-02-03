<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetSignalOutbox extends Model
{
    protected $table = 'fleet_signal_outbox';

    protected $fillable = [
        'fleet_signal_id',
        'status',
        'attempts',
        'last_attempt_at',
        'last_error',
    ];

    protected $casts = [
        'last_attempt_at' => 'datetime',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(FleetSignal::class, 'fleet_signal_id');
    }
}
