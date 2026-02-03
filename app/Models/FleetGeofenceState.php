<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetGeofenceState extends Model
{
    protected $fillable = [
        'asset_id',
        'geofence_id',
        'status',
        'last_changed_at',
        'last_inside_at',
        'last_outside_at',
        'dwell_started_at',
    ];

    protected $casts = [
        'last_changed_at' => 'datetime',
        'last_inside_at' => 'datetime',
        'last_outside_at' => 'datetime',
        'dwell_started_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(AssetGeofence::class, 'geofence_id');
    }
}
