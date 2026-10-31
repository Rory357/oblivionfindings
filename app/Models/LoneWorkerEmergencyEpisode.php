<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Accepted source intent and actual pipeline receipt; never human response acceptance. */
class LoneWorkerEmergencyEpisode extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['occurred_at' => 'datetime', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime', 'episode_number' => 'integer', 'attempts' => 'integer'];

    protected static function booted(): void
    {
        static::updating(function (self $episode): void {
            if ($episode->isDirty(['lone_worker_session_id', 'site_id', 'episode_number', 'initiated_by_user_id', 'occurred_at', 'signal_key'])) {
                throw new \LogicException('An accepted emergency episode retains immutable source provenance.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Emergency delivery evidence must be retained.'));
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LoneWorkerSession::class, 'lone_worker_session_id');
    }
}
