<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetPersonalTrip extends Model
{
    use AuditableChanges;
    use WritesLegacyStorageContext;

    protected $fillable = [
        'user_id',
        'date',
        'start_location',
        'end_location',
        'distance_km',
        'purpose',
        'client_id',
        'shift_id',
        'rate_per_km',
        'total_amount',
        'status',
        'approved_by_user_id',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'distance_km' => 'decimal:1',
        'rate_per_km' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
