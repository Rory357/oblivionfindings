<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringConsumerCheckpoint extends Model
{
    protected $fillable = [
        'consumer',
        'source',
        'last_sequence',
        'gap_from',
        'gap_to',
    ];

    protected $casts = [
        'last_sequence' => 'integer',
        'gap_from' => 'integer',
        'gap_to' => 'integer',
    ];
}
