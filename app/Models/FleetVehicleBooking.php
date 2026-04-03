<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetVehicleBooking extends Model
{
    use AuditableChanges, HasFactory;

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'user_id',
        'approved_by_user_id',
        'purpose',
        'passengers',
        'pickup_site_id',
        'return_site_id',
        'notes',
        'pre_trip_inspection_id',
        'post_trip_inspection_id',
        'destination',
        'starts_at',
        'ends_at',
        'checked_out_at',
        'returned_at',
        'checked_out_by',
        'returned_by',
        'odometer_out',
        'odometer_in',
        'status',
        'rejection_reason',
        'return_notes',
        'condition_on_return',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'returned_at' => 'datetime',
        'odometer_out' => 'decimal:1',
        'odometer_in' => 'decimal:1',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
