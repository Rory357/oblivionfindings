<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FleetReport extends Model
{
    protected $fillable = [
        'report_type',
        'period_start',
        'period_end',
        'status',
        'storage_path',
        'meta',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'meta' => 'array',
    ];
}
