<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A period when a vehicle can't be booked, recorded on its calendar. It is a
 * scheduling block only: it never creates or clears a safety restriction.
 */
class FleetVehicleUnavailablePeriod extends Model
{
    public const STATE_ACTIVE = 'active';

    public const STATE_CANCELLED = 'cancelled';

    protected $fillable = [
        'asset_id',
        'starts_at',
        'ends_at',
        'reason',
        'work_order_id',
        'state',
        'lock_version',
        'created_by_user_id',
        'cancelled_by_user_id',
        'cancelled_at',
        'cancellation_reason',
        'request_key',
        'request_fingerprint',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(FleetWorkOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /** Active periods overlapping [start, end). */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $start, \DateTimeInterface $end): Builder
    {
        return $query->where('state', self::STATE_ACTIVE)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start);
    }
}
