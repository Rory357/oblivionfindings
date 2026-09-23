<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A published version of the trip scoring policy. Version 1 is the fleet
 * configuration and has no row; each row supersedes the one before it and
 * earlier rows stay as history.
 */
class FleetDrivingScorePolicy extends Model
{
    protected $fillable = [
        'version',
        'braking_weight',
        'acceleration_weight',
        'overspeed_weight',
        'idle_weight',
        'min_coverage_pct',
        'min_trips',
        'min_distance_km',
        'reason',
        'asset_id',
        'published_by_user_id',
        'request_key',
        'request_fingerprint',
    ];

    protected $casts = [
        'version' => 'integer',
        'braking_weight' => 'float',
        'acceleration_weight' => 'float',
        'overspeed_weight' => 'float',
        'idle_weight' => 'float',
        'min_coverage_pct' => 'integer',
        'min_trips' => 'integer',
        'min_distance_km' => 'float',
    ];

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
