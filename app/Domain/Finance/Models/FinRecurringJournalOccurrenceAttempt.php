<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FinRecurringJournalOccurrenceAttempt extends Model
{
    protected $fillable = [
        'occurrence_id',
        'attempt_key',
        'outcome',
        'journal_id',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException(
            'Recurring journal occurrence attempts are append-only.',
        ));
        static::deleting(fn (): never => throw new LogicException(
            'Recurring journal occurrence attempts are append-only.',
        ));
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(FinRecurringJournalOccurrence::class, 'occurrence_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }
}
