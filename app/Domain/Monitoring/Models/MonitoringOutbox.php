<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringOutbox extends Model
{
    protected $table = 'monitoring_outbox';

    protected $fillable = [
        'message_id',
        'stream',
        'source',
        'sequence',
        'idempotency_key',
        'envelope',
        'available_at',
        'published_at',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'envelope' => 'array',
        'available_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'attempts' => 'integer',
    ];
}
