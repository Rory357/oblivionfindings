<?php

namespace App\Domain\Finance\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class FinRecurringJournalOccurrence extends Model
{
    protected $fillable = [
        'recurring_journal_id',
        'scheduled_for',
        'occurrence_key',
        'status',
        'journal_id',
        'attempt_count',
        'last_attempted_at',
        'posted_at',
        'failed_at',
        'recovered_at',
        'last_error_code',
        'last_error',
    ];

    protected $casts = [
        'scheduled_for' => 'date',
        'attempt_count' => 'integer',
        'last_attempted_at' => 'datetime',
        'posted_at' => 'datetime',
        'failed_at' => 'datetime',
        'recovered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (FinRecurringJournalOccurrence $occurrence): void {
            if ($occurrence->isDirty([
                'recurring_journal_id',
                'scheduled_for',
                'occurrence_key',
            ])) {
                throw new LogicException('Recurring journal occurrence identity is immutable.');
            }
            if ($occurrence->getOriginal('journal_id') !== null
                && $occurrence->isDirty('journal_id')) {
                throw new LogicException('Recurring journal occurrence lineage is immutable once linked.');
            }
            if ($occurrence->getOriginal('status') === 'posted'
                && $occurrence->isDirty(['status', 'posted_at'])) {
                throw new LogicException('A posted recurring journal occurrence is terminal.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Recurring journal occurrences are durable and cannot be deleted.');
        });
    }

    public static function buildOccurrenceKey(
        int $recurringJournalId,
        CarbonImmutable $scheduledFor,
    ): string {
        return hash('sha256', implode('|', [
            'fin-recurring-journal-occurrence-v1',
            (string) $recurringJournalId,
            $scheduledFor->toDateString(),
        ]));
    }

    public function recurringJournal(): BelongsTo
    {
        return $this->belongsTo(FinRecurringJournal::class, 'recurring_journal_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(FinRecurringJournalOccurrenceAttempt::class, 'occurrence_id');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }
}
