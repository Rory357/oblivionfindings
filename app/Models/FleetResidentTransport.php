<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetResidentTransport extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'booking_id',
        'driver_user_id',
        'resident_id',
        'resident_name',
        'transport_type',
        'pickup_location',
        'dropoff_location',
        'departed_at',
        'arrived_at',
        'passengers_count',
        'supervisor_name',
        'notes',
        'status',
    ];

    protected $casts = [
        'departed_at' => 'datetime',
        'arrived_at' => 'datetime',
        'passengers_count' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FleetVehicleBooking::class, 'booking_id');
    }

    public function getDurationMinutesAttribute(): ?float
    {
        if (!$this->departed_at || !$this->arrived_at) {
            return null;
        }

        return round($this->departed_at->diffInMinutes($this->arrived_at), 1);
    }
}
