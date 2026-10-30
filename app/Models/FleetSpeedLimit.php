<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A manually recorded road speed limit used to evaluate a vehicle's recorded
 * overspeed episodes. It stays pending until someone other than the proposer
 * approves it with evidence, and it applies only between its effective and
 * expiry times.
 */
class FleetSpeedLimit extends Model
{
    public const STATUSES = ['pending', 'approved', 'retired'];

    protected $fillable = [
        'asset_id',
        'road_segment',
        'direction',
        'limit_kph',
        'effective_from',
        'expires_at',
        'reason',
        'status',
        'proposed_by_user_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'retired_by_user_id',
        'retired_at',
        'lock_version',
        'request_key',
        'request_fingerprint',
    ];

    protected $casts = [
        'limit_kph' => 'integer',
        'effective_from' => 'datetime',
        'expires_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'retired_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FleetSpeedLimitEvent::class, 'speed_limit_id');
    }
}
