<?php

namespace App\Services\HealthSafety;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Prospective civil review periods; no inference from cumulative training days. */
final class WorkerParticipationTrainingReviewPeriod
{
    /** Initial review records the current position, rather than a guessed historical year. */
    public static function initial(string $today): array
    {
        self::date($today);

        return ['start' => $today, 'end' => $today, 'due' => $today];
    }

    /** Completion time does not move the original annual review anniversary. */
    public static function next(string $previousDue, string $rootAnniversary): array
    {
        $previous = self::date($previousDue);
        $root = self::date($rootAnniversary);
        if ($previous->lt($root)
            || ! $previous->equalTo(self::anniversary($root, $previous->year))
            || $previous->year >= 9999) {
            throw new InvalidArgumentException('Previous due date must belong to the original review series.');
        }
        $due = self::anniversary($root, $previous->year + 1);

        return [
            'start' => $previous->addDay()->toDateString(),
            'end' => $due->toDateString(),
            'due' => $due->toDateString(),
        ];
    }

    private static function anniversary(CarbonImmutable $root, int $year): CarbonImmutable
    {
        $month = CarbonImmutable::create($year, $root->month, 1, 0, 0, 0, 'UTC');

        return $month->day(min($root->day, $month->daysInMonth));
    }

    private static function date(string $value): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || substr($value, 0, 4) === '0000') {
            throw new InvalidArgumentException('Review dates must be civil dates.');
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        if (! $date || $date->toDateString() !== $value) {
            throw new InvalidArgumentException('Review date is invalid.');
        }

        return $date;
    }
}
