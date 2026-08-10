<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetResidentTransport extends Model
{
    use AuditableChanges;
    use WritesLegacyStorageContext;

    protected $fillable = [
        'asset_id',
        'booking_id',
        'shift_id',
        'site_id',
        'service_context_id',
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
        'review_required',
        'review_reason',
        'review_flagged_at',
        'review_flagged_by',
        'site_name_snapshot',
        'shift_location_snapshot',
        'service_context_name_snapshot',
        'driver_name_snapshot',
    ];

    protected $casts = [
        'departed_at' => 'datetime',
        'arrived_at' => 'datetime',
        'passengers_count' => 'integer',
        'review_required' => 'boolean',
        'review_flagged_at' => 'datetime',
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

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function serviceContext(): BelongsTo
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function reviewFlaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_flagged_by');
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'resident_id');
    }

    public function getDurationMinutesAttribute(): ?float
    {
        if (!$this->departed_at || !$this->arrived_at) {
            return null;
        }

        return round($this->departed_at->diffInMinutes($this->arrived_at), 1);
    }
}
