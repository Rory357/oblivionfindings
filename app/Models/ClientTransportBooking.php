<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClientTransportBooking extends Model
{
    use HasFactory, WritesLegacyOrganizationStorageContext;

    public const STATUSES = ['requested', 'confirmed', 'completed', 'cancelled'];

    protected $fillable = [
        'client_id',
        'purpose',
        'destination',
        'scheduled_at',
        'vehicle',
        'driver_id',
        'escort_required',
        'return_trip',
        'status',
        'notes',
        'created_by',
        'site_id', 'fleet_booking_id', 'expected_return_at', 'pickup_location',
        'required_seats', 'wheelchair_required', 'workflow_state', 'lock_version',
        'assessed_by', 'assessed_at', 'escort_user_id', 'equipment_required',
        'operational_notes', 'information_required', 'key_pickup_room_id',
        'key_return_room_id', 'key_delivery_arrangement', 'creation_key', 'creation_fingerprint',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'escort_required' => 'boolean',
        'return_trip' => 'boolean',
        'expected_return_at' => 'datetime', 'assessed_at' => 'datetime',
        'wheelchair_required' => 'boolean', 'equipment_required' => 'array',
        'lock_version' => 'integer', 'required_seats' => 'integer',
    ];

    public function fleetBooking(): BelongsTo
    {
        return $this->belongsTo(FleetVehicleBooking::class, 'fleet_booking_id');
    }

    public function journey(): HasOne
    {
        return $this->hasOne(FleetResidentTransport::class, 'transport_request_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransportRequestEvent::class, 'transport_request_id');
    }

    public function escort(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escort_user_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
