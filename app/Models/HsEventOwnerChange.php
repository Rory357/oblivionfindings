<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One change of a safety review's owner (Q2). Append-only. */
class HsEventOwnerChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'hs_event_owner_changes';

    protected $fillable = [
        'hs_event_id', 'previous_owner_user_id', 'previous_owner_name', 'owner_user_id', 'owner_name',
        'actor_user_id', 'actor_name', 'reason',
    ];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A safety review owner change is kept as it was recorded.'));
        static::deleting(fn () => throw new LogicException('A safety review owner change is kept as it was recorded.'));
    }

    public function hsEvent(): BelongsTo
    {
        return $this->belongsTo(HsEvent::class, 'hs_event_id');
    }
}
