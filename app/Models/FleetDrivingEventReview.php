<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One human review of a recorded driving event. Rows are only added: the
 * latest review of an event is its current outcome and the recorded
 * telemetry is never changed.
 */
class FleetDrivingEventReview extends Model
{
    public const OUTCOMES = ['confirmed', 'dismissed', 'disputed'];

    protected $fillable = [
        'asset_id',
        'fleet_trip_id',
        'event_key',
        'event_type',
        'event_title',
        'event_at',
        'outcome',
        'reason',
        'review_owner_user_id',
        'recorded_by_user_id',
        'policy_version',
        'sequence',
        'request_key',
        'request_fingerprint',
    ];

    protected $casts = [
        'event_at' => 'datetime',
        'policy_version' => 'integer',
        'sequence' => 'integer',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(FleetTrip::class, 'fleet_trip_id');
    }

    public function reviewOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_owner_user_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
