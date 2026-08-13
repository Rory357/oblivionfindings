<?php

namespace App\Domain\SecurityDevices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceEventSignalOutbox extends Model
{
    protected $table = 'device_event_signal_outbox';

    protected $fillable = [
        'device_event_id',
        'status',
        'attempts',
        'last_attempt_at',
        'last_error',
    ];

    protected $casts = [
        'last_attempt_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(DeviceEvent::class, 'device_event_id');
    }
}
