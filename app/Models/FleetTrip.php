<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetTrip extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'driver_session_id',
        'started_at',
        'ended_at',
        'start_latitude',
        'start_longitude',
        'start_address',
        'end_latitude',
        'end_longitude',
        'end_address',
        'reverse_geocoded_at',
        'distance_km',
        'duration_s',
        'status',
        'consent_blocked',
        'is_personal',
        'marked_personal_by',
        'marked_personal_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'start_latitude' => 'decimal:7',
        'start_longitude' => 'decimal:7',
        'end_latitude' => 'decimal:7',
        'end_longitude' => 'decimal:7',
        'reverse_geocoded_at' => 'datetime',
        'distance_km' => 'decimal:3',
        'consent_blocked' => 'boolean',
        'duration_s' => 'integer',
        'is_personal' => 'boolean',
        'marked_personal_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function driverSession(): BelongsTo
    {
        return $this->belongsTo(FleetDriverSession::class, 'driver_session_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(FleetTripSegment::class, 'fleet_trip_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(FleetSignal::class, 'trip_id');
    }

    public function markedPersonalBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_personal_by');
    }
}
