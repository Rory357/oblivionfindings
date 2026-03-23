<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetServiceSchedule extends Model
{
    protected $fillable = [
        'tenant_id',
        'asset_id',
        'name',
        'interval_km',
        'interval_days',
        'last_completed_at',
        'last_completed_km',
        'next_due_at',
        'next_due_km',
        'is_active',
    ];

    protected $casts = [
        'last_completed_at' => 'datetime',
        'last_completed_km' => 'decimal:1',
        'next_due_at' => 'datetime',
        'next_due_km' => 'decimal:1',
        'is_active' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
