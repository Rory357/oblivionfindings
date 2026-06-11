<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientTransportBooking extends Model
{
    use HasFactory;

    public const STATUSES = ['requested', 'confirmed', 'completed', 'cancelled'];

    protected $fillable = [
        'organization_id',
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
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'escort_required' => 'boolean',
        'return_trip' => 'boolean',
    ];

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
