<?php

namespace App\Services\Medication\DoseSlots;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One version of a medication order's schedule, as the dose-slot rules see it.
 *
 * A version is written at $changedAt and takes effect when it is verified.
 * Until then the previous verified version stays in effect (Main, 1 Oct
 * 2026: unverified edits). A rejected change never takes effect.
 */
final class DoseOrderVersion
{
    /** @var list<string> */
    public readonly array $doseTimes;

    /**
     * @param  list<string>  $doseTimes  New Zealand wall-clock "HH:MM" times (see DoseTimeParser)
     * @param  string|null  $startDate  first NZ calendar day (Y-m-d), inclusive
     * @param  string|null  $endDate  last NZ calendar day (Y-m-d), inclusive: the order's last day
     */
    public function __construct(
        public readonly string $key,
        array $doseTimes,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly CarbonImmutable $changedAt,
        public readonly ?CarbonImmutable $verifiedAt,
        public readonly bool $isPrn = false,
        public readonly bool $selfManaged = false,
        public readonly ?CarbonImmutable $rejectedAt = null,
    ) {
        foreach ($doseTimes as $time) {
            if (! is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                throw new InvalidArgumentException('Dose times must be HH:MM.');
            }
        }
        foreach ([$startDate, $endDate] as $date) {
            if ($date !== null && ! DoseSlotRules::isCalendarDate($date)) {
                throw new InvalidArgumentException('Order dates must be Y-m-d calendar days.');
            }
        }
        if ($verifiedAt !== null && $rejectedAt !== null) {
            throw new InvalidArgumentException('A version is either verified or rejected, not both.');
        }

        $times = array_values(array_unique($doseTimes));
        sort($times);
        $this->doseTimes = $times;
    }

    /** The NZ calendar day falls inside the order's start and last day (both inclusive). */
    public function coversDate(string $nzDate): bool
    {
        return ($this->startDate === null || $nzDate >= $this->startDate)
            && ($this->endDate === null || $nzDate <= $this->endDate);
    }

    /** Verified at or before this instant. */
    public function isVerifiedBy(CarbonImmutable $at): bool
    {
        return $this->verifiedAt !== null && $this->verifiedAt->lessThanOrEqualTo($at);
    }

    /** Written at or before this instant and, at that instant, neither verified nor rejected. */
    public function isPendingAt(CarbonImmutable $at): bool
    {
        return $this->changedAt->lessThanOrEqualTo($at)
            && ! $this->isVerifiedBy($at)
            && ($this->rejectedAt === null || $this->rejectedAt->greaterThan($at));
    }
}
