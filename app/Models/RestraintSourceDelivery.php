<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A durable intent and actual delivery state, distinct from human acceptance. */
class RestraintSourceDelivery extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['membership' => 'array', 'assignment_revision' => 'integer', 'attempts' => 'integer', 'reminder_day' => 'date', 'due_cutoff_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if ($row->isDirty(['id', 'dedupe_key', 'purpose', 'restraint_event_id', 'followup_id', 'attachment_id', 'recipient_user_id', 'initiated_by_user_id', 'assignment_revision', 'reminder_day', 'due_cutoff_at', 'membership'])) {
                throw new LogicException('An accepted restraint delivery cannot be transferred.');
            }
        });
        static::deleting(fn () => throw new LogicException('Restraint delivery provenance must be retained.'));
    }
}
