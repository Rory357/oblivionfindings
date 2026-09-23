<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FleetVehicleBooking extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;
    use Concerns\HasReferenceNumber;
    use WritesLegacyStorageContext;

    public const REFERENCE_PREFIX = 'BK';

    protected $fillable = [
        'reference_number',
        'asset_id',
        'user_id',
        'driver_user_id',
        'pickup_arrangement',
        'lock_version',
        'checkout_condition',
        'checkout_evidence_reference',
        'checkout_notes',
        'return_evidence_reference',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
        'request_key',
        'request_fingerprint',
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
        'approval_route',
        'approval_not_required_reason',
        'approval_not_required_evidence',
        'approval_authority_recorded_by',
        'approval_authority_recorded_at',
        'approved_at',
        'review_required',
        'review_reason',
        'review_flagged_at',
        'review_flagged_by',
        'rejection_reason',
        'return_notes',
        'condition_on_return',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'returned_at' => 'datetime',
        'approval_authority_recorded_at' => 'datetime',
        'approved_at' => 'datetime',
        'review_required' => 'boolean',
        'review_flagged_at' => 'datetime',
        'odometer_out' => 'decimal:1',
        'odometer_in' => 'decimal:1',
        'passengers' => 'integer',
        'lock_version' => 'integer',
        'cancelled_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The named driver; the requester drives when none is recorded. */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function keyLogs(): HasMany
    {
        return $this->hasMany(FleetKeyLog::class, 'booking_id');
    }

    public function driverUserId(): int
    {
        return (int) ($this->driver_user_id ?: $this->user_id);
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

    public function reviewFlaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_flagged_by');
    }

    public function pickupSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'pickup_site_id');
    }

    public function returnSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'return_site_id');
    }

    public function preTripInspection(): BelongsTo
    {
        return $this->belongsTo(FleetChecklistRun::class, 'pre_trip_inspection_id');
    }

    public function postTripInspection(): BelongsTo
    {
        return $this->belongsTo(FleetChecklistRun::class, 'post_trip_inspection_id');
    }

    public function transports(): HasMany
    {
        return $this->hasMany(FleetResidentTransport::class, 'booking_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(FleetIncident::class, 'booking_id');
    }

    public function outings(): HasMany
    {
        return $this->hasMany(FleetOuting::class, 'booking_id');
    }
}
