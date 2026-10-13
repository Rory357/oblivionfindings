<?php

namespace App\Domain\Monitoring\Services;

use App\Support\ZonedLocalTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/** Bounded maintenance occurrences. This does not authorise or execute work. */
final class MaintenanceSchedule
{
    public function __construct(private readonly ZonedLocalTime $clock = new ZonedLocalTime) {}

    /** @param array<string, mixed> $schedule
     * @return list<array<string, mixed>>
     */
    public function occurrences(array $schedule): array
    {
        $start = CarbonImmutable::instance($schedule['starts_at'])->utc();
        $until = isset($schedule['recurrence_until']) ? CarbonImmutable::instance($schedule['recurrence_until'])->utc() : null;
        $recurrence = $schedule['recurrence'] ?? null;
        if (! in_array($recurrence, [null, 'daily', 'weekly'], true)
            || ($recurrence !== null && ($until === null || $until <= $start || $until > $start->addYear()))) {
            throw new InvalidArgumentException('invalid_recurrence_limit');
        }
        $this->assertPeriod($schedule);
        $localStart = $start->setTimezone($schedule['timezone']);
        $cursor = CarbonImmutable::parse($localStart->format('Y-m-d'), 'UTC');
        $lastDate = $until?->setTimezone($schedule['timezone'])->format('Y-m-d') ?? $cursor->format('Y-m-d');
        $rows = [];
        $previousEnd = null;
        for ($count = 0; $cursor->format('Y-m-d') <= $lastDate; $count++) {
            if ($count >= 400) {
                throw new InvalidArgumentException('invalid_recurrence_limit');
            }
            if ($until !== null && $cursor->format('Y-m-d') === $lastDate) {
                $wall = $cursor->format('Y-m-d').'T'.$localStart->format('H:i:s.u');
                $choices = $this->clock->candidates($wall, $schedule['timezone']);
                if (($choices !== [] && $choices[0] >= $until)
                    || ($choices === [] && $wall >= $until->setTimezone($schedule['timezone'])->format('Y-m-d\TH:i:s.u'))) {
                    break;
                }
            }
            $row = $this->occurrence($schedule, $cursor->format('Y-m-d'));
            if ($row['state'] === 'ready') {
                if ($previousEnd !== null && $row['starts_at'] < $previousEnd) {
                    throw new InvalidArgumentException('overlapping_occurrences');
                }
                if ($until === null || $row['starts_at'] < $until) {
                    $rows[] = $row;
                    $previousEnd = $row['ends_at'];
                }
            } else {
                $rows[] = $row;
            }
            if ($recurrence === null) {
                break;
            }
            $cursor = $cursor->addDays($recurrence === 'weekly' ? 7 : 1);
        }

        return $rows;
    }

    /** @param array<string, mixed> $schedule
     * @return array<string, mixed>
     */
    public function occurrence(array $schedule, string $date): array
    {
        $zone = $schedule['timezone'];
        $start = CarbonImmutable::instance($schedule['starts_at'])->utc();
        $end = CarbonImmutable::instance($schedule['ends_at'])->utc();
        if ($end <= $start) {
            throw new InvalidArgumentException('empty_period');
        }
        $first = $start->setTimezone($zone);
        $last = $end->setTimezone($zone);
        $overlap = $schedule['dst_overlap'] ?? 'reject';
        $gap = $schedule['dst_gap'] ?? 'reject';
        if (! in_array($overlap, ['reject', 'earlier', 'later'], true) || ! in_array($gap, ['reject', 'skip'], true)) {
            throw new InvalidArgumentException('invalid_clock_policy');
        }
        $nominalFirst = CarbonImmutable::parse($first->format('Y-m-d'), 'UTC');
        $nominalLast = CarbonImmutable::parse($last->format('Y-m-d'), 'UTC');
        $dayOffset = (int) $nominalFirst->diffInDays($nominalLast, false);
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('invalid_local_time');
        }
        $localStart = $date.'T'.$first->format('H:i:s.u');
        $localEnd = $day->addDays($dayOffset)->format('Y-m-d').'T'.$last->format('H:i:s.u');
        $base = ['local_start' => $localStart, 'local_end' => $localEnd, 'timezone' => $zone];
        if ($date !== $first->format('Y-m-d')) {
            try {
                $start = $this->clock->parse($localStart, $zone, $overlap);
                $end = $this->clock->parse($localEnd, $zone, $overlap);
            } catch (InvalidArgumentException $exception) {
                if ($gap === 'skip' && $exception->getMessage() === 'nonexistent_local_time') {
                    return [...$base, 'state' => 'skipped', 'reason_code' => 'nonexistent_local_time', 'starts_at' => null, 'ends_at' => null];
                }
                throw $exception;
            }
        }
        if ($end <= $start) {
            throw new InvalidArgumentException('empty_period');
        }
        if (isset($schedule['recurrence_until'])) {
            $until = CarbonImmutable::instance($schedule['recurrence_until'])->utc();
            $end = $end->min($until);
        }

        return [...$base, 'state' => 'ready', 'reason_code' => null, 'starts_at' => $start, 'ends_at' => $end];
    }

    /** @param array<string, mixed> $schedule */
    private function assertPeriod(array $schedule): void
    {
        $recurrence = $schedule['recurrence'] ?? null;
        if ($recurrence === null) {
            return;
        }
        $zone = $schedule['timezone'];
        $a = CarbonImmutable::instance($schedule['starts_at'])->setTimezone($zone);
        $b = CarbonImmutable::instance($schedule['ends_at'])->setTimezone($zone);
        $wallA = CarbonImmutable::parse($a->format('Y-m-d H:i:s.u'), 'UTC');
        $wallB = CarbonImmutable::parse($b->format('Y-m-d H:i:s.u'), 'UTC');
        $seconds = $wallA->diffInSeconds($wallB, false);
        if ($seconds <= 0 || $seconds >= ($recurrence === 'weekly' ? 604800 : 86400)) {
            throw new InvalidArgumentException('invalid_recurring_duration');
        }
    }

    /** @param array<string, mixed> $schedule */
    public function contains(array $schedule, DateTimeInterface $instant): bool
    {
        try {
            $at = CarbonImmutable::instance($instant)->utc();
            $start = CarbonImmutable::instance($schedule['starts_at'])->utc();
            $end = CarbonImmutable::instance($schedule['ends_at'])->utc();
            if ($at < $start || $end <= $start) {
                return false;
            }
            if (($schedule['recurrence'] ?? null) === null) {
                return $at < $end;
            }
            $this->assertPeriod($schedule);
            if (! in_array($schedule['recurrence'], ['daily', 'weekly'], true) || ! isset($schedule['recurrence_until'])) {
                return false;
            }
            $until = CarbonImmutable::instance($schedule['recurrence_until'])->utc();
            if ($at >= $until || $until <= $start || $until > $start->addYear()) {
                return false;
            }
            $first = $start->setTimezone($schedule['timezone']);
            $local = $at->setTimezone($schedule['timezone']);
            $firstDay = CarbonImmutable::parse($first->format('Y-m-d'), 'UTC');
            $atDay = CarbonImmutable::parse($local->format('Y-m-d'), 'UTC');
            $elapsed = (int) $firstDay->diffInDays($atDay, false);
            $period = $schedule['recurrence'] === 'weekly' ? 7 : 1;
            $candidate = $firstDay->addDays(intdiv($elapsed, $period) * $period);
            foreach ([$candidate, $candidate->subDays($period)] as $day) {
                if ($day < $firstDay) {
                    continue;
                }
                $row = $this->occurrence($schedule, $day->format('Y-m-d'));
                if ($row['state'] === 'ready' && $at >= $row['starts_at'] && $at < $row['ends_at']) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // Invalid or unresolved clock inputs must not suppress a failure.
        }

        return false;
    }
}
