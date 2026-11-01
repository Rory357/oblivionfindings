<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\SecurityDevices\Cctv\Models\CameraPrivacyHours;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A camera's privacy hours (S&D rebuild Phase E, drawing pg-sdr-4c and pop-up 64). Weekly time
 * ranges in NZ wall-clock time. While a camera is inside them Oblivion serves no picture from it:
 * the latest picture, the hub picture (camera.snapshot.capture) and live viewing all refuse, and
 * every screen says "Privacy hours until …" instead. The recorder keeps its own schedule.
 *
 * A range covers its start day: {days: [5], from: "22:00", to: "06:00"} is Friday 10 pm to Saturday
 * 6 am. A range whose "to" equals its "from" covers 24 hours.
 */
final class CameraPrivacy
{
    public const string ZONE = 'Pacific/Auckland';

    public const string REFUSAL = 'privacy_hours';

    public const int MAX_RANGES = 7;

    public const string AUDIT_ACTION = 'security_devices.camera.privacy_hours.changed';

    private const string TIME = '/\A([01]\d|2[0-3]):[0-5]\d\z/';

    /**
     * The camera's saved settings, or the default (off, no ranges).
     *
     * @return array{enabled: bool, ranges: list<array{days: list<int>, from: string, to: string}>, updated_at: ?string}
     */
    public function settings(int $deviceId): array
    {
        $row = CameraPrivacyHours::query()->where('device_id', $deviceId)->first();

        return [
            'enabled' => (bool) $row?->enabled,
            'ranges' => $row ? self::normalise((array) $row->ranges) : [],
            'updated_at' => $row?->updated_at?->toIso8601String(),
        ];
    }

    /** When the camera's privacy hours end, if it is inside them now; null when it isn't. */
    public function until(int $deviceId, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        return $this->untilMany([$deviceId], $now)[$deviceId] ?? null;
    }

    public function active(int $deviceId, ?CarbonImmutable $now = null): bool
    {
        return $this->until($deviceId, $now) !== null;
    }

    /**
     * The cameras among these that are inside their privacy hours now, with when each ends.
     *
     * @param  list<int>  $deviceIds
     * @return array<int, CarbonImmutable>
     */
    public function untilMany(array $deviceIds, ?CarbonImmutable $now = null): array
    {
        if ($deviceIds === []) {
            return [];
        }
        $now ??= CarbonImmutable::now();
        $out = [];
        CameraPrivacyHours::query()
            ->whereIn('device_id', array_values(array_unique(array_map('intval', $deviceIds))))
            ->where('enabled', true)
            ->get(['device_id', 'ranges'])
            ->each(function (CameraPrivacyHours $row) use ($now, &$out): void {
                try {
                    $until = self::activeUntil(self::normalise((array) $row->ranges), $now);
                } catch (InvalidArgumentException) {
                    // A row that can't be read keeps the camera private rather than open.
                    $until = $now->addMinutes(15);
                }
                if ($until !== null) {
                    $out[(int) $row->device_id] = $until;
                }
            });

        return $out;
    }

    /**
     * Refuse a picture: 409 "privacy_hours" while the camera is inside its privacy hours.
     * Call it only after the viewer is known to be allowed to see the camera, so it never says
     * anything about a camera they can't see.
     */
    public function refuse(int $deviceId): void
    {
        abort_if($this->active($deviceId), 409, self::REFUSAL);
    }

    /**
     * Save a camera's privacy hours and audit the change.
     *
     * @param  list<array{days: list<int>, from: string, to: string}>  $ranges
     */
    public function save(User $actor, Device $device, bool $enabled, array $ranges): CameraPrivacyHours
    {
        $ranges = self::normalise($ranges);
        if ($enabled && $ranges === []) {
            throw new InvalidArgumentException('Privacy hours need at least one time.');
        }

        return DB::transaction(function () use ($actor, $device, $enabled, $ranges): CameraPrivacyHours {
            $row = CameraPrivacyHours::query()->where('device_id', $device->id)->lockForUpdate()->first();
            $before = [
                'enabled' => (bool) $row?->enabled,
                'ranges' => $row ? self::normalise((array) $row->ranges) : [],
            ];
            $row ??= new CameraPrivacyHours(['device_id' => (int) $device->id]);
            $row->fill(['enabled' => $enabled, 'ranges' => $ranges, 'updated_by_user_id' => (int) $actor->id]);
            $row->save();

            AuditLogger::logOrFail(self::AUDIT_ACTION, $row, [
                'actor_id' => (int) $actor->id,
                'device_id' => (int) $device->id,
                'before' => $before,
                'after' => ['enabled' => $enabled, 'ranges' => $ranges],
            ]);

            return $row;
        });
    }

    /**
     * Clean, checked ranges: days 1–7 (Monday first) sorted and unique, times "HH:MM".
     *
     * @param  array<int|string, mixed>  $ranges
     * @return list<array{days: list<int>, from: string, to: string}>
     */
    public static function normalise(array $ranges): array
    {
        if (count($ranges) > self::MAX_RANGES) {
            throw new InvalidArgumentException('Too many privacy hour ranges.');
        }
        $out = [];
        foreach (array_values($ranges) as $range) {
            if (! is_array($range)) {
                throw new InvalidArgumentException('A privacy hour range is not readable.');
            }
            $days = array_values(array_unique(array_map(
                fn (mixed $day): int => is_numeric($day) ? (int) $day : 0,
                (array) ($range['days'] ?? []),
            )));
            sort($days);
            $from = (string) ($range['from'] ?? '');
            $to = (string) ($range['to'] ?? '');
            if ($days === [] || min($days) < 1 || max($days) > 7 || preg_match(self::TIME, $from) !== 1 || preg_match(self::TIME, $to) !== 1) {
                throw new InvalidArgumentException('A privacy hour range is not readable.');
            }
            $out[] = ['days' => $days, 'from' => $from, 'to' => $to];
        }

        return $out;
    }

    /**
     * When privacy ends, if `$now` is inside any range; ranges that run on from one another are
     * followed to their real end. Null when `$now` is outside them all.
     *
     * @param  list<array{days: list<int>, from: string, to: string}>  $ranges
     */
    public static function activeUntil(array $ranges, CarbonImmutable $now): ?CarbonImmutable
    {
        $until = self::coveringEnd($ranges, $now);
        // Follow back-to-back ranges (6 pm – 10 pm, then 10 pm – 6 am). A week covers every case.
        for ($step = 0; $until !== null && $step < 16; $step++) {
            $next = self::coveringEnd($ranges, $until);
            if ($next === null || $next->lte($until)) {
                break;
            }
            $until = $next;
        }

        return $until?->utc();
    }

    /**
     * The next time privacy hours start after `$now` (within the coming week), or null.
     *
     * @param  list<array{days: list<int>, from: string, to: string}>  $ranges
     */
    public static function nextStart(array $ranges, CarbonImmutable $now): ?CarbonImmutable
    {
        $next = null;
        foreach (self::windows($ranges, $now, 0, 8) as [$start]) {
            if ($start->gt($now) && ($next === null || $start->lt($next))) {
                $next = $start;
            }
        }

        return $next?->utc();
    }

    /** @param list<array{days: list<int>, from: string, to: string}> $ranges */
    private static function coveringEnd(array $ranges, CarbonImmutable $at): ?CarbonImmutable
    {
        $end = null;
        foreach (self::windows($ranges, $at, -1, 1) as [$start, $finish]) {
            if ($start->lte($at) && $finish->gt($at) && ($end === null || $finish->gt($end))) {
                $end = $finish;
            }
        }

        return $end;
    }

    /**
     * Every window the ranges make, for the NZ days from `$fromDay` to `$toDay` (exclusive) around `$at`.
     *
     * @param  list<array{days: list<int>, from: string, to: string}>  $ranges
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private static function windows(array $ranges, CarbonImmutable $at, int $fromDay, int $toDay): array
    {
        $today = $at->setTimezone(self::ZONE)->startOfDay();
        $windows = [];
        for ($offset = $fromDay; $offset < $toDay; $offset++) {
            $day = $today->addDays($offset);
            $date = $day->format('Y-m-d');
            foreach ($ranges as $range) {
                if (! in_array($day->dayOfWeekIso, $range['days'], true)) {
                    continue;
                }
                $start = CarbonImmutable::parse($date.' '.$range['from'], self::ZONE);
                $finish = $range['to'] > $range['from']
                    ? CarbonImmutable::parse($date.' '.$range['to'], self::ZONE)
                    : CarbonImmutable::parse($day->addDay()->format('Y-m-d').' '.$range['to'], self::ZONE);
                $windows[] = [$start, $finish];
            }
        }

        return $windows;
    }
}
