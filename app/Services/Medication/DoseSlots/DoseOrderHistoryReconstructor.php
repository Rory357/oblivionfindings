<?php

namespace App\Services\Medication\DoseSlots;

use App\Models\AuditLog;
use App\Models\ClientMedication;
use Carbon\CarbonImmutable;

/**
 * Rebuilds an order's schedule history for the backfill (P01 foundation C5).
 *
 * Orders are edited in place, so the history lives in the audit log:
 * AuditableChanges records each changed column's value before and after.
 * Starting from the order as it is now and walking its updates backwards
 * gives the order's state over every period since it was created: its dose
 * times, dates, verification and pauses. That becomes a DoseOrderTimeline
 * with the same rules as live generation (a change takes effect when
 * verified; the old version stays in effect until then; a rejected change
 * never takes effect).
 *
 * With no audit history the order's current row is the whole history, as
 * today's screens read it.
 */
final class DoseOrderHistoryReconstructor
{
    private const FIELDS = [
        'dose_times', 'frequency', 'start_date', 'end_date', 'is_prn',
        'approval_status', 'verified_at', 'state', 'active', 'paused_at', 'ceased_at',
    ];

    public function forOrder(ClientMedication $order): DoseOrderTimeline
    {
        $createdAt = DoseOrderTimelineFactory::rawInstant($order->getAttributes()['created_at'] ?? null)
            ?? CarbonImmutable::createFromTimestampUTC(0);
        $state = [];
        foreach (self::FIELDS as $field) {
            $state[$field] = $order->getAttributes()[$field] ?? null;
        }
        $state['dose_times'] = $order->dose_times;

        // Newest update first: each one's "before" is the state until it.
        $segments = [];
        $updates = AuditLog::query()
            ->where('auditable_type', $order->getMorphClass())
            ->where('auditable_id', $order->id)
            ->where('action', 'clientmedication.update')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'meta', 'created_at']);
        foreach ($updates as $update) {
            $meta = is_array($update->meta) ? $update->meta : [];
            $before = is_array($meta['before'] ?? null) ? $meta['before'] : [];
            $fields = array_values(array_intersect(
                is_array($meta['fields'] ?? null) ? $meta['fields'] : array_keys($before),
                self::FIELDS,
            ));
            if ($fields === []) {
                continue;
            }
            $at = DoseOrderTimelineFactory::rawInstant($update->getRawOriginal('created_at')) ?? $createdAt;
            $segments[] = [$at->greaterThan($createdAt) ? $at : $createdAt, $state];
            foreach ($fields as $field) {
                if (array_key_exists($field, $before)) {
                    $state[$field] = $before[$field];
                }
            }
        }
        $segments[] = [$createdAt, $state];

        return $this->timeline($order, array_reverse($segments));
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: array<string, mixed>}>  $segments  oldest first
     */
    private function timeline(ClientMedication $order, array $segments): DoseOrderTimeline
    {
        $versions = [];
        $pauses = [];
        $lastVerified = null;
        $pending = null;
        $pausedSince = null;

        foreach ($segments as $index => [$start, $state]) {
            $schedule = $this->schedule($state);
            $status = is_string($state['approval_status'] ?? null) ? $state['approval_status'] : null;

            if ($status === null || $status === 'verified') {
                if ($pending !== null || $lastVerified === null || $schedule !== $lastVerified) {
                    // The first state of an order created verified with no
                    // time is in effect from its start date (as live reads).
                    $verifiedAt = $index === 0 && $pending === null
                        ? (DoseOrderTimelineFactory::rawInstant($state['verified_at'] ?? null) ?? CarbonImmutable::createFromTimestampUTC(0))
                        : $start;
                    $versions[] = $this->version("r{$index}", $schedule, $pending['since'] ?? $start, $verifiedAt, null);
                    $lastVerified = $schedule;
                }
                $pending = null;
            } elseif ($status === 'rejected') {
                if ($pending !== null) {
                    $versions[] = $this->version("r{$index}x", $pending['schedule'], $pending['since'], null, $start);
                    $pending = null;
                }
            } else {
                $pending = ['since' => $pending['since'] ?? $start, 'schedule' => $schedule];
            }

            $active = $state['active'] ?? null;
            $inactive = $active === false || $active === 0 || $active === '0';
            $paused = ($state['state'] ?? null) === 'paused'
                || ($inactive && ($state['state'] ?? null) !== 'ceased' && ($state['ceased_at'] ?? null) === null);
            if ($paused && $pausedSince === null) {
                $pausedSince = $start;
            } elseif (! $paused && $pausedSince !== null) {
                $pauses[] = [$pausedSince, $start];
                $pausedSince = null;
            }
        }

        if ($pending !== null) {
            $versions[] = $this->version('rp', $pending['schedule'], $pending['since'], null, null);
        }
        if ($pausedSince !== null) {
            $pauses[] = [$pausedSince, null];
        }

        return new DoseOrderTimeline(
            (int) $order->id,
            $versions,
            $pauses,
            DoseOrderTimelineFactory::stoppedAt($order),
            // Nothing due before the order was entered is owed.
            DoseOrderTimelineFactory::rawInstant($order->getAttributes()['created_at'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{times: list<string>, start: string|null, end: string|null, prn: bool}
     */
    private function schedule(array $state): array
    {
        $doseTimes = $state['dose_times'] ?? null;
        if (is_string($doseTimes)) {
            $decoded = json_decode($doseTimes, true);
            $doseTimes = is_array($decoded) ? $decoded : null;
        }

        return [
            'times' => DoseTimeParser::parse($doseTimes, is_string($state['frequency'] ?? null) ? $state['frequency'] : null),
            'start' => DoseOrderTimelineFactory::rawDate($state['start_date'] ?? null),
            'end' => DoseOrderTimelineFactory::rawDate($state['end_date'] ?? null),
            'prn' => ! $this->isFalse($state['is_prn'] ?? false),
        ];
    }

    /**
     * @param  array{times: list<string>, start: string|null, end: string|null, prn: bool}  $schedule
     */
    private function version(string $key, array $schedule, CarbonImmutable $changedAt, ?CarbonImmutable $verifiedAt, ?CarbonImmutable $rejectedAt): DoseOrderVersion
    {
        return new DoseOrderVersion(
            key: $key,
            doseTimes: $schedule['times'],
            startDate: $schedule['start'],
            endDate: $schedule['end'],
            changedAt: $changedAt,
            verifiedAt: $verifiedAt,
            isPrn: $schedule['prn'],
            rejectedAt: $rejectedAt,
        );
    }

    private function isFalse(mixed $value): bool
    {
        return $value === false || $value === 0 || $value === '0' || $value === null || $value === '';
    }
}
