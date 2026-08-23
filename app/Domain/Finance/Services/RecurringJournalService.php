<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinRecurringJournal;
use App\Domain\Finance\Models\FinRecurringJournalOccurrence;
use App\Domain\Finance\Models\FinRecurringJournalOccurrenceAttempt;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class RecurringJournalService
{
    public function __construct(
        protected JournalPostingService $postingService,
    ) {}

    /**
     * Find all active recurring journals due for an organisation, create and post them,
     * then advance each recurring journal's next_run_date.
     *
     * @return array<\App\Domain\Finance\Models\FinJournal> Created journals.
     */
    public function processDueRecurringJournals(int $orgId): array
    {
        $this->assertOrganizationContext($orgId);

        $dueRecurrings = FinRecurringJournal::query()
            ->where('organization_id', $orgId)
            ->due()
            ->orderBy('id')
            ->get(['id', 'organization_id', 'next_run_date']);

        $createdJournals = [];
        $failures = [];

        foreach ($dueRecurrings as $recurring) {
            $scheduledFor = $recurring->next_run_date->toDateString();
            try {
                $createdJournals[] = $this->processOccurrence(
                    (int) $recurring->id,
                    $scheduledFor,
                    (int) $recurring->organization_id,
                );
            } catch (Throwable $exception) {
                $failures[] = [
                    'recurring_journal_id' => (int) $recurring->id,
                    'scheduled_for' => $scheduledFor,
                    'exception' => $exception,
                ];
                Log::error('Recurring journal occurrence failed.', [
                    'recurring_journal_id' => (int) $recurring->id,
                    'scheduled_for' => $scheduledFor,
                    'error_code' => $this->failureCode($exception),
                ]);
            }
        }

        if ($failures !== []) {
            $failedOccurrences = array_map(
                fn (array $failure): string => sprintf(
                    '#%d@%s',
                    $failure['recurring_journal_id'],
                    $failure['scheduled_for'],
                ),
                $failures,
            );

            throw new RuntimeException(
                'Recurring journal occurrences failed and remain available for retry: '
                .implode(', ', $failedOccurrences).'.',
                0,
                $failures[0]['exception'],
            );
        }

        return $createdJournals;
    }

    /**
     * Post or replay one immutable schedule/date occurrence. The shared journal
     * sequence mutex is always acquired before the schedule and occurrence.
     */
    public function processOccurrence(
        int $recurringJournalId,
        string $scheduledFor,
        int $organizationId,
    ): FinJournal {
        $this->assertOrganizationContext($organizationId);
        $scheduledDate = $this->canonicalDate($scheduledFor);
        $scopedRecurring = FinRecurringJournal::query()
            ->whereKey($recurringJournalId)
            ->where('organization_id', $organizationId)
            ->firstOrFail(['organization_id']);
        $organizationId = (int) $scopedRecurring->organization_id;
        $attemptKey = (string) Str::uuid();
        $attemptStartedAt = CarbonImmutable::now('UTC')->startOfSecond();
        $postingAttempted = false;

        try {
            return DB::transaction(function () use (
                $recurringJournalId,
                $scheduledDate,
                $organizationId,
                $attemptKey,
                $attemptStartedAt,
                &$postingAttempted,
            ): FinJournal {
                // Introduced by the preceding 000080 depreciation migration:
                // all GL producers lock this durable per-organisation sequence
                // before locking their own aggregate.
                $this->postingService->lockJournalSequence($organizationId);

                $recurring = FinRecurringJournal::query()
                    ->whereKey($recurringJournalId)
                    ->where('organization_id', $organizationId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $occurrenceKey = FinRecurringJournalOccurrence::buildOccurrenceKey(
                    (int) $recurring->id,
                    $scheduledDate,
                );
                $occurrence = FinRecurringJournalOccurrence::query()
                    ->where('occurrence_key', $occurrenceKey)
                    ->lockForUpdate()
                    ->first();

                if ($occurrence) {
                    $this->assertOccurrenceIdentity($recurring, $occurrence, $scheduledDate);
                }
                if ($occurrence?->status === 'posted') {
                    $journal = $this->assertPostedOccurrence($recurring, $occurrence);
                    $this->advanceSchedule($recurring, $scheduledDate);

                    return $journal;
                }

                if (! $recurring->is_active) {
                    throw new InvalidArgumentException('The recurring journal schedule is inactive.');
                }
                if ($recurring->next_run_date->toDateString() !== $scheduledDate->toDateString()) {
                    throw new InvalidArgumentException(
                        'The requested recurring journal occurrence is not the schedule current due date.',
                    );
                }
                if ($scheduledDate->gt(CarbonImmutable::now('UTC')->startOfDay())) {
                    throw new InvalidArgumentException('A future recurring journal occurrence cannot be posted early.');
                }

                $occurrence ??= FinRecurringJournalOccurrence::query()->create([
                    'recurring_journal_id' => $recurring->id,
                    'scheduled_for' => $scheduledDate->toDateString(),
                    'occurrence_key' => $occurrenceKey,
                    'status' => 'processing',
                    'attempt_count' => 0,
                ]);
                $this->assertOccurrenceIdentity($recurring, $occurrence, $scheduledDate);

                $postingAttempted = true;
                $occurrence->forceFill([
                    'status' => 'processing',
                    'attempt_count' => ((int) $occurrence->attempt_count) + 1,
                    'last_attempted_at' => $attemptStartedAt,
                    'failed_at' => null,
                    'last_error_code' => null,
                    'last_error' => null,
                ])->save();

                if ($legacy = $this->recoverLegacyOccurrence(
                    $recurring,
                    $occurrence,
                    $scheduledDate,
                    $attemptKey,
                    $attemptStartedAt,
                )) {
                    return $legacy;
                }

                $journal = $this->postingService->createAndPost($organizationId, [
                    'journal_date' => $scheduledDate->toDateString(),
                    'type' => 'recurring',
                    'reference' => sprintf(
                        'REC-%d-%s',
                        $recurring->id,
                        $scheduledDate->format('Ymd'),
                    ),
                    'description' => $recurring->description ?? $recurring->name,
                    'source_type' => FinRecurringJournalOccurrence::class,
                    'source_id' => $occurrence->id,
                    'lines' => $recurring->template_lines,
                ]);

                $this->completeOccurrence(
                    $recurring,
                    $occurrence,
                    $journal,
                    $scheduledDate,
                    $attemptKey,
                    $attemptStartedAt,
                    'posted',
                );

                return $journal;
            }, attempts: 3);
        } catch (Throwable $exception) {
            if ($postingAttempted) {
                try {
                    $this->recordFailure(
                        $recurringJournalId,
                        $organizationId,
                        $scheduledDate,
                        $attemptKey,
                        $attemptStartedAt,
                        $exception,
                    );
                } catch (Throwable $historyException) {
                    Log::critical('Recurring journal failure history could not be persisted.', [
                        'recurring_journal_id' => $recurringJournalId,
                        'scheduled_for' => $scheduledDate->toDateString(),
                        'error_code' => $this->failureCode($historyException),
                    ]);
                }
            }

            throw $exception;
        }
    }

    /**
     * Calculate the next run date based on frequency.
     */
    public function calculateNextRunDate(string $frequency, string $currentDate): string
    {
        $date = Carbon::parse($currentDate);

        return match ($frequency) {
            'daily' => $date->addDay()->toDateString(),
            'weekly' => $date->addWeek()->toDateString(),
            'monthly' => $date->addMonth()->toDateString(),
            'quarterly' => $date->addMonths(3)->toDateString(),
            'annually' => $date->addYear()->toDateString(),
            default => throw new \InvalidArgumentException("Unknown recurring journal frequency: {$frequency}"),
        };
    }

    private function recoverLegacyOccurrence(
        FinRecurringJournal $recurring,
        FinRecurringJournalOccurrence $occurrence,
        CarbonImmutable $scheduledDate,
        string $attemptKey,
        CarbonImmutable $attemptStartedAt,
    ): ?FinJournal {
        $legacyJournals = FinJournal::query()
            ->where('organization_id', $recurring->organization_id)
            ->where('reference', "REC-{$recurring->id}")
            ->whereDate('journal_date', $scheduledDate->toDateString())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($legacyJournals->isEmpty()) {
            return null;
        }
        if ($legacyJournals->count() !== 1) {
            throw new RuntimeException(
                'Multiple legacy journals claim this recurring schedule occurrence; finance review is required.',
            );
        }

        $journal = $legacyJournals->first();
        if ($journal->status !== 'posted'
            || $journal->type !== 'standard'
            || $journal->source_type !== null
            || $journal->source_id !== null
            || $journal->reversal_of_journal_id !== null
            || $journal->reversed_by_journal_id !== null
            || ! $this->legacyTemplateMatches($recurring, $journal)) {
            throw new RuntimeException(
                'The legacy recurring journal occurrence is ambiguous; finance review is required.',
            );
        }

        $journal->forceFill([
            'source_type' => FinRecurringJournalOccurrence::class,
            'source_id' => $occurrence->id,
        ])->save();
        $this->completeOccurrence(
            $recurring,
            $occurrence,
            $journal,
            $scheduledDate,
            $attemptKey,
            $attemptStartedAt,
            'recovered',
        );
        $occurrence->forceFill(['recovered_at' => CarbonImmutable::now('UTC')->startOfSecond()])->save();

        return $journal->refresh();
    }

    private function legacyTemplateMatches(
        FinRecurringJournal $recurring,
        FinJournal $journal,
    ): bool {
        $journal->setRelation('lines', $journal->lines()->lockForUpdate()->get());
        $expected = collect($recurring->template_lines)
            ->map(fn (array $line): string => $this->lineSignature($line))
            ->sort()
            ->values()
            ->all();
        $actual = $journal->lines
            ->map(fn (object $line): string => $this->lineSignature((array) $line->getAttributes()))
            ->sort()
            ->values()
            ->all();

        return $expected !== [] && $expected === $actual;
    }

    /** @param array<string, mixed> $line */
    private function lineSignature(array $line): string
    {
        return json_encode([
            'account_id' => (int) $line['account_id'],
            'description' => $line['description'] ?? null,
            'debit' => bcadd((string) ($line['debit'] ?? 0), '0', 2),
            'credit' => bcadd((string) ($line['credit'] ?? 0), '0', 2),
            'cost_centre_id' => isset($line['cost_centre_id']) ? (int) $line['cost_centre_id'] : null,
            'funding_stream_id' => isset($line['funding_stream_id']) ? (int) $line['funding_stream_id'] : null,
            'client_id' => isset($line['client_id']) ? (int) $line['client_id'] : null,
            'client_fund_id' => isset($line['client_fund_id']) ? (int) $line['client_fund_id'] : null,
            'site_id' => isset($line['site_id']) ? (int) $line['site_id'] : null,
            'tax_rate_id' => isset($line['tax_rate_id']) ? (int) $line['tax_rate_id'] : null,
            'tax_amount' => bcadd((string) ($line['tax_amount'] ?? 0), '0', 2),
        ], JSON_THROW_ON_ERROR);
    }

    private function completeOccurrence(
        FinRecurringJournal $recurring,
        FinRecurringJournalOccurrence $occurrence,
        FinJournal $journal,
        CarbonImmutable $scheduledDate,
        string $attemptKey,
        CarbonImmutable $attemptStartedAt,
        string $outcome,
    ): void {
        if ($journal->status !== 'posted'
            || (int) $journal->organization_id !== (int) $recurring->organization_id
            || $journal->journal_date->toDateString() !== $scheduledDate->toDateString()
            || $journal->source_type !== FinRecurringJournalOccurrence::class
            || (int) $journal->source_id !== (int) $occurrence->id) {
            throw new RuntimeException(
                'The posted journal has conflicting recurring occurrence provenance.',
            );
        }

        $finishedAt = CarbonImmutable::now('UTC')->startOfSecond();
        $occurrence->forceFill([
            'status' => 'posted',
            'journal_id' => $journal->id,
            'posted_at' => $journal->posted_at ?? $finishedAt,
            'failed_at' => null,
            'last_error_code' => null,
            'last_error' => null,
        ])->save();
        FinRecurringJournalOccurrenceAttempt::query()->create([
            'occurrence_id' => $occurrence->id,
            'attempt_key' => $attemptKey,
            'outcome' => $outcome,
            'journal_id' => $journal->id,
            'started_at' => $attemptStartedAt,
            'finished_at' => $finishedAt,
        ]);
        $this->advanceSchedule($recurring, $scheduledDate);
    }

    private function assertPostedOccurrence(
        FinRecurringJournal $recurring,
        FinRecurringJournalOccurrence $occurrence,
    ): FinJournal {
        if ($occurrence->journal_id === null) {
            throw new RuntimeException(
                'The posted recurring occurrence has no linked journal; finance review is required.',
            );
        }

        $journal = FinJournal::query()
            ->whereKey($occurrence->journal_id)
            ->lockForUpdate()
            ->firstOrFail();
        if ($journal->status !== 'posted'
            || (int) $journal->organization_id !== (int) $recurring->organization_id
            || $journal->journal_date->toDateString() !== $occurrence->scheduled_for->toDateString()
            || $journal->source_type !== FinRecurringJournalOccurrence::class
            || (int) $journal->source_id !== (int) $occurrence->id) {
            throw new RuntimeException(
                'The recurring occurrence journal link is inconsistent; finance review is required.',
            );
        }

        return $journal;
    }

    private function advanceSchedule(
        FinRecurringJournal $recurring,
        CarbonImmutable $scheduledDate,
    ): void {
        $currentDueDate = CarbonImmutable::instance($recurring->next_run_date)->startOfDay();
        if ($currentDueDate->isBefore($scheduledDate)) {
            throw new RuntimeException(
                'The recurring journal schedule is behind its posted occurrence; finance review is required.',
            );
        }
        if ($currentDueDate->equalTo($scheduledDate)) {
            $recurring->forceFill([
                'last_run_date' => $scheduledDate->toDateString(),
                'next_run_date' => $this->calculateNextRunDate(
                    $recurring->frequency,
                    $scheduledDate->toDateString(),
                ),
            ])->save();

            return;
        }

        if (! $recurring->last_run_date || $recurring->last_run_date->isBefore($scheduledDate)) {
            $recurring->forceFill(['last_run_date' => $scheduledDate->toDateString()])->save();
        }
    }

    private function recordFailure(
        int $recurringJournalId,
        int $organizationId,
        CarbonImmutable $scheduledDate,
        string $attemptKey,
        CarbonImmutable $attemptStartedAt,
        Throwable $exception,
    ): void {
        DB::transaction(function () use (
            $recurringJournalId,
            $organizationId,
            $scheduledDate,
            $attemptKey,
            $attemptStartedAt,
            $exception,
        ): void {
            $recurring = FinRecurringJournal::query()
                ->whereKey($recurringJournalId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();
            if (! $recurring) {
                return;
            }

            $occurrenceKey = FinRecurringJournalOccurrence::buildOccurrenceKey(
                $recurringJournalId,
                $scheduledDate,
            );
            $occurrence = FinRecurringJournalOccurrence::query()
                ->where('occurrence_key', $occurrenceKey)
                ->lockForUpdate()
                ->first();
            $occurrence ??= FinRecurringJournalOccurrence::query()->create([
                'recurring_journal_id' => $recurringJournalId,
                'scheduled_for' => $scheduledDate->toDateString(),
                'occurrence_key' => $occurrenceKey,
                'status' => 'failed',
                'attempt_count' => 0,
            ]);
            $this->assertOccurrenceIdentity($recurring, $occurrence, $scheduledDate);
            if ($occurrence->attempts()->where('attempt_key', $attemptKey)->exists()) {
                return;
            }

            $finishedAt = CarbonImmutable::now('UTC')->startOfSecond();
            $errorCode = $this->failureCode($exception);
            $errorMessage = Str::limit(
                trim($exception->getMessage()) ?: $exception::class,
                2000,
                '',
            );
            FinRecurringJournalOccurrenceAttempt::query()->create([
                'occurrence_id' => $occurrence->id,
                'attempt_key' => $attemptKey,
                'outcome' => 'failed',
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'started_at' => $attemptStartedAt,
                'finished_at' => $finishedAt,
            ]);

            $updates = [
                'attempt_count' => ((int) $occurrence->attempt_count) + 1,
                'last_attempted_at' => $finishedAt,
            ];
            if ($occurrence->status !== 'posted') {
                $updates += [
                    'status' => 'failed',
                    'failed_at' => $finishedAt,
                    'last_error_code' => $errorCode,
                    'last_error' => $errorMessage,
                ];
            }
            $occurrence->forceFill($updates)->save();
        }, attempts: 3);
    }

    private function assertOrganizationContext(int $organizationId): void
    {
        if ($organizationId < 1) {
            throw new InvalidArgumentException('A valid recurring journal organization context is required.');
        }
    }

    private function assertOccurrenceIdentity(
        FinRecurringJournal $recurring,
        FinRecurringJournalOccurrence $occurrence,
        CarbonImmutable $scheduledDate,
    ): void {
        $expectedKey = FinRecurringJournalOccurrence::buildOccurrenceKey(
            (int) $recurring->id,
            $scheduledDate,
        );
        if ((int) $occurrence->recurring_journal_id !== (int) $recurring->id
            || $occurrence->scheduled_for->toDateString() !== $scheduledDate->toDateString()
            || ! hash_equals($expectedKey, (string) $occurrence->occurrence_key)) {
            throw new RuntimeException(
                'The recurring journal occurrence key has conflicting schedule provenance.',
            );
        }
    }

    private function canonicalDate(string $date): CarbonImmutable
    {
        $date = trim($date);
        try {
            $canonical = CarbonImmutable::parse($date, 'UTC')->startOfDay();
        } catch (Throwable) {
            throw new InvalidArgumentException('A valid recurring occurrence date is required.');
        }
        if ($canonical->toDateString() !== $date) {
            throw new InvalidArgumentException('A canonical recurring occurrence date is required.');
        }

        return $canonical;
    }

    private function failureCode(Throwable $exception): string
    {
        return Str::limit(Str::snake(class_basename($exception)), 120, '');
    }
}
