<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringInbox extends Model
{
    protected $table = 'monitoring_inbox';

    protected $fillable = [
        'message_id',
        'consumer',
        'source',
        'sequence',
        'idempotency_key',
        'payload_hash',
        'envelope',
        'processed_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'envelope' => 'array',
        'processed_at' => 'immutable_datetime',
    ];
}
