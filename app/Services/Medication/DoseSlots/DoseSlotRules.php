<?php

namespace App\Services\Medication\DoseSlots;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

/**
 * The dose-slot rule (P01 foundation, C1): which scheduled doses an order
 * owes on a New Zealand calendar day. Pure — no database and no clock.
 *
 * A slot exists for each dose time of the version in effect at the dose's
 * due instant, on every NZ day from the start date to the end date
 * inclusive (the end date is the order's last day), unless the order is
 * PRN, paused at that instant, ceased by then, or not yet entered (a dose
 * due before the order was entered is not owed).
 *
 * - Wall-clock times are New Zealand times. On the day the clocks go
 *   forward, a time in the skipped hour is due at the first minute that
 *   exists (03:00) and flagged; on the day they go back, a repeated time is
 *   due at its first occurrence and flagged. No dose is ever dropped.
 * - An order changed but not yet verified keeps its previous version; those
 *   doses are flagged "Order changed — check the new instructions".
 *   Once the change is verified, doses due from then on follow it.
 * - A self-managed order's slots are marked so they are not chased as
 *   staff doses.
 */
final class DoseSlotRules
{
    private readonly DateTimeZone $zone;

    public function __construct(string $timezone = 'Pacific/Auckland')
    {
        $this->zone = new DateTimeZone($timezone);
    }

    public static function forWorkerTimezone(): self
    {
        return new self((string) config('app.worker_timezone', 'Pacific/Auckland'));
    }

    public static function isCalendarDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * @return list<DoseSlot> ordered by due instant, then dose time
     */
    public function slotsOn(DoseOrderTimeline $timeline, string $nzDate): array
    {
        if (! self::isCalendarDate($nzDate)) {
            throw new InvalidArgumentException('An NZ calendar day is Y-m-d.');
        }

        $slots = [];
        foreach ($timeline->versions as $version) {
            if ($version->isPrn || $version->verifiedAt === null || ! $version->coversDate($nzDate)) {
                continue;
            }

            foreach ($version->doseTimes as $doseTime) {
                [$dueAt, $dstAdjustment] = $this->dueAt($nzDate, $doseTime);

                // Only the version in effect when the dose is due owes it.
                if ($timeline->versionInEffectAt($dueAt) !== $version
                    || $timeline->isBeforeEntry($dueAt)
                    || $timeline->isPausedAt($dueAt)
                    || $timeline->isCeasedBy($dueAt)) {
                    continue;
                }

                $slots[] = new DoseSlot(
                    orderId: $timeline->orderId,
                    versionKey: $version->key,
                    nzDate: $nzDate,
                    doseTime: $doseTime,
                    dueAt: $dueAt,
                    dstAdjustment: $dstAdjustment,
                    orderChangePending: $timeline->hasPendingChangeAt($version, $dueAt),
                    selfManaged: $version->selfManaged,
                    lastDay: $version->endDate === $nzDate,
                );
            }
        }

        usort($slots, fn (DoseSlot $a, DoseSlot $b): int => [$a->dueAt->getTimestamp(), $a->doseTime]
            <=> [$b->dueAt->getTimestamp(), $b->doseTime]);

        return $slots;
    }

    /**
     * @return list<DoseSlot> every slot on the NZ days from $fromDate to $toDate inclusive
     */
    public function slotsBetween(DoseOrderTimeline $timeline, string $fromDate, string $toDate): array
    {
        if (! self::isCalendarDate($fromDate) || ! self::isCalendarDate($toDate)) {
            throw new InvalidArgumentException('NZ calendar days are Y-m-d.');
        }

        $slots = [];
        for ($day = CarbonImmutable::parse($fromDate, 'UTC'); $day->toDateString() <= $toDate; $day = $day->addDay()) {
            array_push($slots, ...$this->slotsOn($timeline, $day->toDateString()));
        }

        return $slots;
    }

    /**
     * The UTC instant an NZ wall-clock time on an NZ day is due, and the
     * daylight-saving adjustment made, if any.
     *
     * @return array{0: CarbonImmutable, 1: string|null}
     */
    public function dueAt(string $nzDate, string $doseTime): array
    {
        // The wall-clock reading as if it were UTC; subtracting a real offset
        // gives a candidate instant, kept only if it reads back the same.
        $wall = CarbonImmutable::parse("{$nzDate} {$doseTime}:00", 'UTC');
        $wanted = "{$nzDate} {$doseTime}";
        $transitions = $this->zone->getTransitions(
            $wall->subDays(2)->getTimestamp(),
            $wall->addDays(2)->getTimestamp(),
        ) ?: [];

        $candidates = [];
        foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
            $instant = $wall->subSeconds((int) $offset);
            if ($instant->setTimezone($this->zone)->format('Y-m-d H:i') === $wanted) {
                $candidates[$instant->getTimestamp()] = $instant;
            }
        }

        if ($candidates !== []) {
            ksort($candidates);

            return [
                reset($candidates)->utc(),
                count($candidates) > 1 ? DoseSlot::DST_REPEAT : null,
            ];
        }

        // Not a real time: the clocks went forward over it. Due at the
        // transition — the first minute that exists after the gap.
        for ($i = 1, $n = count($transitions); $i < $n; $i++) {
            $before = (int) $transitions[$i - 1]['offset'];
            $after = (int) $transitions[$i]['offset'];
            $at = (int) $transitions[$i]['ts'];
            if ($after > $before
                && $wall->getTimestamp() >= $at + $before
                && $wall->getTimestamp() < $at + $after) {
                return [CarbonImmutable::createFromTimestampUTC($at), DoseSlot::DST_GAP];
            }
        }

        throw new LogicException("Could not place {$wanted} in {$this->zone->getName()}.");
    }
}
