<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A vehicle's tracker distance feed: its reconciliation baseline and planning setting. */
class FleetVehicleMileageFeed extends Model
{
    protected $fillable = [
        'asset_id', 'automatic', 'tolerance_km', 'baseline_observation_id', 'baseline_tracker_km', 'baseline_event_id',
        'reconciled_at', 'reconciled_by_user_id', 'paused_at', 'lock_version',
    ];

    protected $casts = [
        'automatic' => 'boolean',
        'tolerance_km' => 'integer',
        'baseline_tracker_km' => 'float',
        'reconciled_at' => 'datetime',
        'paused_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(FleetVehicleOdometerObservation::class, 'baseline_observation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FleetVehicleMileageFeedEvent::class, 'feed_id');
    }
}
