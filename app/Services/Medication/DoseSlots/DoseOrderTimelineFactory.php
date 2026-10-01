<?php

namespace App\Services\Medication\DoseSlots;

use App\Models\ClientMedication;
use App\Models\MedicationDoseOrderPause;
use App\Models\MedicationDoseScheduleVersion;
use Carbon\CarbonImmutable;

/**
 * Builds an order's DoseOrderTimeline from its recorded schedule versions and
 * pauses (DoseScheduleHistory). An order with no recorded history yet — one
 * created before C3 — is read from its current row, as today's screens read
 * it: in effect from verification, with its current pause and stop.
 */
final class DoseOrderTimelineFactory
{
    public const CURRENT_ROW_VERSION = 'current';

    public function forOrder(ClientMedication $order): DoseOrderTimeline
    {
        $versions = MedicationDoseScheduleVersion::query()
            ->where('client_medication_id', $order->id)
            ->orderBy('changed_at')
            ->orderBy('id')
            // A locking read: the latest committed history, not an older snapshot.
            ->sharedLock()
            ->get()
            ->map(fn (MedicationDoseScheduleVersion $row): DoseOrderVersion => new DoseOrderVersion(
                key: (string) $row->id,
                doseTimes: DoseTimeParser::fromColumn($row->dose_times),
                startDate: self::rawDate($row->getRawOriginal('start_date')),
                endDate: self::rawDate($row->getRawOriginal('end_date')),
                changedAt: self::rawInstant($row->getRawOriginal('changed_at')) ?? CarbonImmutable::createFromTimestampUTC(0),
                verifiedAt: self::rawInstant($row->getRawOriginal('verified_at')),
                isPrn: (bool) $row->is_prn,
                selfManaged: (bool) $row->self_managed,
                rejectedAt: self::rawInstant($row->getRawOriginal('rejected_at')),
            ))
            ->all();

        if ($versions === []) {
            $versions = [self::currentRowVersion($order)];
        }

        $pauses = MedicationDoseOrderPause::query()
            ->where('client_medication_id', $order->id)
            ->orderBy('paused_at')
            ->orderBy('id')
            ->sharedLock()
            ->get()
            ->map(fn (MedicationDoseOrderPause $pause): array => [
                self::rawInstant($pause->getRawOriginal('paused_at')) ?? CarbonImmutable::createFromTimestampUTC(0),
                self::rawInstant($pause->getRawOriginal('resumed_at')),
            ])
            ->all();

        if ($pauses === [] && self::isPausedNow($order)) {
            $pauses = [[
                self::rawInstant(self::attribute($order, 'paused_at'))
                    ?? self::rawInstant(self::attribute($order, 'updated_at'))
                    ?? CarbonImmutable::now('UTC'),
                null,
            ]];
        }

        return new DoseOrderTimeline((int) $order->id, $versions, $pauses, self::stoppedAt($order));
    }

    /** The order's current row as a single version (no history recorded yet). */
    public static function currentRowVersion(ClientMedication $order, bool $original = false): DoseOrderVersion
    {
        $value = fn (string $key) => $original ? $order->getOriginal($key) : $order->getAttribute($key);
        $raw = fn (string $key) => $original ? $order->getRawOriginal($key) : ($order->getAttributes()[$key] ?? null);
        $status = $value('approval_status');
        $createdAt = self::rawInstant($raw('created_at')) ?? CarbonImmutable::createFromTimestampUTC(0);
        $verified = $status === null || $status === 'verified';

        return new DoseOrderVersion(
            key: self::CURRENT_ROW_VERSION,
            doseTimes: DoseTimeParser::parse($value('dose_times'), $value('frequency')),
            startDate: self::rawDate($raw('start_date')),
            endDate: self::rawDate($raw('end_date')),
            changedAt: $createdAt,
            // A verified order with no verification time (created verified,
            // or from before verification existed) is read as today's
            // screens read it: in effect for every day from its start date.
            verifiedAt: $verified
                ? (self::rawInstant($raw('verified_at')) ?? CarbonImmutable::createFromTimestampUTC(0))
                : null,
            isPrn: (bool) $value('is_prn'),
            selfManaged: false,
            rejectedAt: $status === 'rejected'
                ? (self::rawInstant($raw('updated_at')) ?? $createdAt)
                : null,
        );
    }

    /** Paused, or inactive without being stopped (legacy rows). */
    public static function isPausedNow(ClientMedication $order): bool
    {
        // An attribute not set on create takes the column default (active).
        $inactive = $order->active !== null && ! (bool) $order->active;
        $ceasedAt = $order->getAttributes()['ceased_at'] ?? null;

        return $order->state === 'paused'
            || ($inactive && $order->state !== 'ceased' && $ceasedAt === null);
    }

    private static function stoppedAt(ClientMedication $order): ?CarbonImmutable
    {
        $stops = array_filter([
            self::rawInstant(self::attribute($order, 'ceased_at')),
            $order->state === 'ceased' && self::attribute($order, 'ceased_at') === null
                ? self::rawInstant(self::attribute($order, 'updated_at'))
                : null,
            $order->superseded_by !== null
                ? (self::rawInstant(self::attribute($order, 'superseded_at')) ?? self::rawInstant(self::attribute($order, 'updated_at')))
                : null,
            self::rawInstant(self::attribute($order, 'deleted_at')),
        ]);

        if ($stops === []) {
            return null;
        }

        usort($stops, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return $stops[0];
    }

    /**
     * The order's current stored value. Model events run before Eloquent
     * syncs the originals, so getRawOriginal() would still hold the old one.
     */
    private static function attribute(ClientMedication $order, string $key): mixed
    {
        return $order->getAttributes()[$key] ?? null;
    }

    public static function rawInstant(mixed $raw): ?CarbonImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if ($raw instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($raw)->utc();
        }

        return CarbonImmutable::parse((string) $raw, 'UTC');
    }

    public static function rawDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        return substr((string) $raw, 0, 10);
    }
}
