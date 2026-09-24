<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One confirmation of who actually drove a trip, with the evidence given.
 * Rows are only ever added: a later confirmation supersedes the trip's
 * current driver but keeps the earlier attribution here.
 */
class FleetTripDriverConfirmation extends Model
{
    protected $fillable = [
        'fleet_trip_id',
        'asset_id',
        'driver_user_id',
        'previous_driver_user_id',
        'previous_source',
        'source',
        'booking_id',
        'reason',
        'confirmed_by_user_id',
        'confirmed_at',
        'request_key',
        'request_fingerprint',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(FleetTrip::class, 'fleet_trip_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FleetVehicleBooking::class, 'booking_id');
    }
}
