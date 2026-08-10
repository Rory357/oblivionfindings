<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

final class MonitoringRuntimeHeartbeat extends Model
{
    protected $fillable = [
        'component',
        'queue',
        'last_dispatched_token',
        'last_dispatched_at',
        'last_consumed_token',
        'last_consumed_dispatch_at',
        'last_consumed_at',
    ];

    protected $casts = [
        'last_dispatched_at' => 'immutable_datetime',
        'last_consumed_dispatch_at' => 'immutable_datetime',
        'last_consumed_at' => 'immutable_datetime',
    ];
}
