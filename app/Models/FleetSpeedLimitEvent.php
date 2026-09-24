<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The review history of a manual speed limit: proposed, approved, retired. */
class FleetSpeedLimitEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'speed_limit_id',
        'action',
        'actor_user_id',
        'note',
        'request_key',
        'request_fingerprint',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function limit(): BelongsTo
    {
        return $this->belongsTo(FleetSpeedLimit::class, 'speed_limit_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
