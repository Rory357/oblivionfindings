<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One reconciliation or pause of a vehicle's distance feed. Never edited. */
class FleetVehicleMileageFeedEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'feed_id', 'action', 'actor_user_id', 'dashboard_km', 'tracker_km', 'automatic', 'tolerance_km', 'note',
        'request_key', 'request_fingerprint',
    ];

    protected $casts = [
        'dashboard_km' => 'float',
        'tracker_km' => 'float',
        'automatic' => 'boolean',
        'tolerance_km' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Mileage feed events are a permanent record.'));
        static::deleting(fn () => throw new LogicException('Mileage feed events are a permanent record.'));
    }

    public function feed(): BelongsTo
    {
        return $this->belongsTo(FleetVehicleMileageFeed::class, 'feed_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
