<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetIncident extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'asset_id',
        'reported_by_user_id',
        'driver_user_id',
        'booking_id',
        'incident_type',
        'severity',
        'occurred_at',
        'location',
        'latitude',
        'longitude',
        'description',
        'damage_details',
        'police_notified',
        'police_reference',
        'insurance_claimed',
        'insurance_reference',
        'status',
        'resolution_notes',
        'resolved_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
        'damage_details' => 'array',
        'police_notified' => 'boolean',
        'insurance_claimed' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FleetVehicleBooking::class, 'booking_id');
    }
}
