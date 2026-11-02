<?php

namespace App\Domain\SecurityDevices\Modes;

use App\Domain\SecurityDevices\Modes\Models\HouseMode;
use Carbon\CarbonImmutable;

/**
 * What a house mode does, in plain words (S&D Phase F): its card on the Modes tab (pg-sdr-12c) and
 * the summary in "Switch … to Night?" (pop-up 42). One place, so the card and the confirm always say
 * the same thing. Modes check and remind; the words never say a mode locks, arms, disarms or unlocks.
 * Times are NZ wall-clock ("9:15 pm"); "**…**" marks a device name for the page to make bold.
 */
final class HouseModeWords
{
    private const array DAY = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /**
     * The mode's card lines: one per action, read by its ring.
     *
     * @param  array<int, string>  $names  device id → name, for devices the viewer may see at the Site
     * @return list<array{tone: string, text: string}>
     */
    public static function facts(HouseMode $mode, array $names): array
    {
        $facts = [];
        $starts = $mode->schedule['starts'] ?? null;
        foreach ($mode->actionList() as $action) {
            $text = match ($action['action'] ?? null) {
                'alarm_armed_check' => self::alarmWhen($action, $starts, 'Check the alarm is armed').' · remind staff on shift if not',
                'door_locked_check' => self::doorsLine($action, $names).' · tell staff on shift if not',
                'camera_privacy_on' => 'Camera privacy on until '.self::clock((string) ($action['until'] ?? '')).': '.self::list(self::named($action['cameras'] ?? [], $names, 'camera')),
                'notify' => ($action['message'] ?? null) !== null
                    ? 'Tell staff on shift it’s '.$mode->name.': “'.$action['message'].'”'
                    : 'Tell staff on shift it’s '.$mode->name,
                'open_alert' => 'Open an alert for anything a check finds',
                default => null,
            };
            if ($text !== null) {
                $facts[] = ['tone' => ($action['action'] ?? null) === 'camera_privacy_on' ? 'none' : 'info', 'text' => $text];
            }
        }

        return $facts !== [] ? $facts : [['tone' => 'none', 'text' => 'Doesn’t do anything yet: only the house mode changes']];
    }

    /**
     * Everything a switch by hand will do, and what it won't, for pop-up 42.
     *
     * @param  array<int, string>  $names
     * @return list<string>
     */
    public static function summary(HouseMode $mode, array $names): array
    {
        $out = [];
        $privacy = $mode->action('camera_privacy_on');
        if ($privacy !== null) {
            $out[] = 'It turns on camera privacy for '.self::list(self::bold(self::named($privacy['cameras'] ?? [], $names, 'camera'))).' until '.self::clock((string) ($privacy['until'] ?? '')).'.';
        }
        $doors = $mode->action('door_locked_check');
        if ($doors !== null) {
            $named = self::named($doors['doors'] ?? [], $names, 'door');
            $out[] = count($named) === 1
                ? 'It checks '.self::list(self::bold($named)).' is locked and tells staff on shift if it isn’t. It doesn’t lock or unlock it.'
                : 'It checks '.self::list(self::bold($named)).' are locked and tells staff on shift about any that aren’t. It doesn’t lock or unlock them.';
        }
        $alarm = $mode->action('alarm_armed_check');
        if ($alarm !== null) {
            $minutes = (int) ($alarm['after_minutes'] ?? 0);
            $out[] = 'It doesn’t arm the alarm: it checks the alarm is armed '.($minutes > 0 ? self::minutes($minutes).' after the switch' : 'straight away').' and reminds staff on shift if not.';
        }
        if ($mode->action('open_alert') !== null && ($doors !== null || $alarm !== null)) {
            $out[] = 'Anything a check finds opens an alert in Alerts.';
        }
        if ($mode->action('notify') !== null) {
            $out[] = 'Staff on shift are told.';
        }
        if ($out === []) {
            $out[] = $mode->name.' doesn’t do anything yet: only the house mode changes.';
        }

        return $out;
    }

    /**
     * The card's line under the name: "9:00 pm – 7:00 am", "9:00 pm – 7:00 am · Mon – Fri" or "By hand".
     */
    public static function when(HouseMode $mode, ?string $endsAt): string
    {
        $schedule = $mode->schedule;
        if ($schedule === null) {
            return 'By hand';
        }
        $range = self::clock($schedule['starts']).($endsAt !== null ? ' – '.self::clock($endsAt) : ' on');
        $days = self::days($schedule['days']);

        return $days === null ? $range : $range.' · '.$days;
    }

    /** "21:00" → "9:00 pm". */
    public static function clock(string $hhmm): string
    {
        if (preg_match('/\A(\d{2}):(\d{2})\z/', $hhmm, $match) !== 1) {
            return '—';
        }
        $hour = (int) $match[1];

        return (($hour % 12) ?: 12).':'.$match[2].' '.($hour >= 12 ? 'pm' : 'am');
    }

    /** An instant as NZ wall-clock time: "9:06 pm". */
    public static function at(CarbonImmutable $at): string
    {
        return $at->setTimezone(HouseModeSchedule::ZONE)->format('g:i a');
    }

    /** Every day → null (said by the times alone); Mon–Fri → "Mon – Fri"; [1, 3, 5] → "Mon, Wed and Fri". */
    public static function days(array $days): ?string
    {
        $days = array_values(array_unique(array_filter(array_map('intval', $days), fn (int $day): bool => $day >= 1 && $day <= 7)));
        sort($days);
        if (count($days) === 7) {
            return null;
        }
        $runs = [];
        foreach ($days as $day) {
            $last = count($runs) - 1;
            if ($last >= 0 && $day === end($runs[$last]) + 1) {
                $runs[$last][] = $day;
            } else {
                $runs[] = [$day];
            }
        }
        $parts = [];
        foreach ($runs as $run) {
            if (count($run) >= 3) {
                $parts[] = self::DAY[$run[0]].' – '.self::DAY[end($run)];
            } else {
                foreach ($run as $day) {
                    $parts[] = self::DAY[$day];
                }
            }
        }

        return self::list($parts);
    }

    /** 15 → "15 min", 60 → "1 hour". */
    public static function minutes(int $minutes): string
    {
        return $minutes === 60 ? '1 hour' : $minutes.' min';
    }

    /** @param list<string> $parts "a", "a and b", "a, b and c". */
    public static function list(array $parts): string
    {
        $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== ''));
        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }
        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private static function alarmWhen(array $action, ?string $starts, string $lead): string
    {
        $minutes = (int) ($action['after_minutes'] ?? 0);
        if ($minutes === 0) {
            return $lead.' when it starts';
        }
        if ($starts === null) {
            return $lead.' '.self::minutes($minutes).' after the switch';
        }
        [$hour, $minute] = array_map('intval', explode(':', $starts));
        $total = ($hour * 60 + $minute + $minutes) % (24 * 60);

        return $lead.' by '.self::clock(sprintf('%02d:%02d', intdiv($total, 60), $total % 60));
    }

    /** @param array<string, mixed> $action */
    private static function doorsLine(array $action, array $names): string
    {
        $named = self::named($action['doors'] ?? [], $names, 'door');

        return (count($named) === 1 ? 'Check the door is locked: ' : 'Check doors are locked: ').self::list($named);
    }

    /**
     * The names of these devices the viewer may see; the rest as "1 door no longer at this Site".
     *
     * @param  array<int, string>  $names
     * @return list<string>
     */
    private static function named(mixed $ids, array $names, string $noun): array
    {
        $out = [];
        $missing = 0;
        foreach ((array) $ids as $id) {
            if (isset($names[(int) $id])) {
                $out[] = $names[(int) $id];
            } else {
                $missing++;
            }
        }
        if ($missing > 0) {
            $out[] = $missing.' '.$noun.($missing === 1 ? '' : 's').' no longer at this Site';
        }

        return $out;
    }

    /**
     * @param  list<string>  $parts
     * @return list<string>
     */
    private static function bold(array $parts): array
    {
        return array_map(fn (string $part): string => str_contains($part, 'no longer at this Site') ? $part : '**'.str_replace('*', '', $part).'**', $parts);
    }
}
