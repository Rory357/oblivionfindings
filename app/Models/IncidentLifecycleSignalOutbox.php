<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentLifecycleSignalOutbox extends Model
{
    protected $table = 'incident_lifecycle_signal_outbox';

    protected $fillable = [
        'incident_lifecycle_signal_id',
        'resulting_alert_id',
        'status',
        'attempts',
        'last_attempt_at',
        'delivered_at',
        'last_error',
    ];

    protected $casts = [
        'last_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(IncidentLifecycleSignal::class, 'incident_lifecycle_signal_id');
    }

    public function resultingAlert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'resulting_alert_id');
    }
}
