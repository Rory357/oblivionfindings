<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientGeofenceMonitor extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime', 'ended_at' => 'datetime', 'last_observed_at' => 'datetime',
        'breach_count' => 'integer',
    ];
}
