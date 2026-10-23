<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class RestraintFollowup extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $casts = ['assignment_revision' => 'integer', 'due_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'completion_outcome' => 'array', 'completion_evidence_ids' => 'array', 'creator_snapshot' => 'array'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Restraint follow-up history must be retained.'));
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(RestraintEvent::class, 'restraint_event_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(RestraintFollowupHistory::class, 'followup_id');
    }
}
