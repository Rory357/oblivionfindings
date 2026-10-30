<?php

namespace App\Models;

use App\Services\HealthSafety\RestraintCommandValues;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only factual source evidence; corrections refer to their predecessor. */
class RestraintEventReview extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $casts = ['facts' => 'array', 'actor_snapshot' => 'array', 'actual_actor_snapshot' => 'array', 'evidence_ids' => 'array', 'source_revision' => 'integer', 'occurred_at' => 'immutable_datetime'];

    public function persisted(): self
    {
        $row = self::query()->whereKey($this->id)->lockForUpdate()->first();
        if (! $row || RestraintCommandValues::digest($row->facts) !== RestraintCommandValues::digest($this->facts)) {
            throw new LogicException('Factual source evidence was not durably confirmed.');
        }

        return $row;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Recorded restraint facts cannot be overwritten.'));
        static::deleting(fn () => throw new LogicException('Recorded restraint facts must be retained.'));
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(RestraintEvent::class, 'restraint_event_id');
    }
}
