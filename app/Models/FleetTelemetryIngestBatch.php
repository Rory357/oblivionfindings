<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FleetTelemetryIngestBatch extends Model
{
    protected $fillable = [
        'vendor',
        'received_at',
        'record_count',
        'checksum',
        'status',
        'errors_json',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'errors_json' => 'array',
    ];
}
