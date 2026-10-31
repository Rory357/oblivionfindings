<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Accepted source intent, distinct from review receipt, notification and human response. */
class FirstAidSourceDelivery extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'membership' => 'array', 'assignment_revision' => 'integer', 'attempts' => 'integer',
        'reminder_day' => 'date', 'due_cutoff_at' => 'datetime', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if ($row->isDirty(['id', 'dedupe_key', 'purpose', 'first_aid_record_id', 'followup_id', 'recipient_user_id', 'initiated_by_user_id', 'assignment_revision', 'reminder_day', 'due_cutoff_at', 'membership'])) {
                throw new LogicException('Accepted First Aid delivery intent must not be transferred or rewritten.');
            }
        });
        static::deleting(fn () => throw new LogicException('First Aid delivery evidence must be retained.'));
    }
}
