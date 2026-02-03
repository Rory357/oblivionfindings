<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FleetIntegrationCursor extends Model
{
    protected $fillable = [
        'vendor',
        'last_message_id',
        'last_received_at',
        'status',
        'meta',
    ];

    protected $casts = [
        'last_received_at' => 'datetime',
        'meta' => 'array',
    ];
}
