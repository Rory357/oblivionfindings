<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One vehicle's assignment to a shared geofence boundary (AssetGeofence).
 * The boundary stays canonical; this keeps the vehicle's purpose, schedule,
 * response proposal and the boundary version it was reviewed against.
 * Monitoring is always inactive here: the fleet evaluator never reads it.
 */
class FleetVehicleGeofenceAssignment extends Model
{
    public const STATE_ACTIVE = 'active';

    public const STATE_REMOVED = 'removed';

    public const ORIGIN_LINKED = 'linked';

    public const ORIGIN_CREATED = 'created';

    public const ORIGIN_COPIED = 'copied';

    protected $fillable = [
        'asset_id',
        'geofence_id',
        'label',
        'origin',
        'purpose',
        'response_proposal',
        'schedule',
        'geometry_hash',
        'geometry_snapshot',
        'monitoring',
        'state',
        'lock_version',
        'payload_hash',
        'request_key',
        'request_fingerprint',
        'created_by_user_id',
        'updated_by_user_id',
        'removed_by_user_id',
        'removed_at',
        'removal_reason',
    ];

    protected $casts = [
        'schedule' => 'array',
        'geometry_snapshot' => 'array',
        'lock_version' => 'integer',
        'removed_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(AssetGeofence::class, 'geofence_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', self::STATE_ACTIVE);
    }
}
