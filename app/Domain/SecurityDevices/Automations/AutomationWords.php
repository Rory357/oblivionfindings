<?php

namespace App\Domain\SecurityDevices\Automations;

use App\Domain\SecurityDevices\Automations\Models\Automation;
use Carbon\CarbonImmutable;

/**
 * An automation in plain words (S&D Phase F, pg-sdr-13b): "Front door opens between 10:00 pm and
 * 6:00 am" → "Tell the staff on shift (app) · check the alarm is armed". Times are NZ wall clock.
 */
final class AutomationWords
{
    public const string ZONE = 'Pacific/Auckland';

    private const array DAY_SHORT = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /**
     * @param  array<int, string>  $names  device id => name
     * @param  array<int, string>  $modes  house mode id => name
     */
    public static function when(Automation $automation, array $names, array $modes = []): string
    {
        $trigger = (array) $automation->trigger;
        $device = isset($trigger['device_id']) && is_numeric($trigger['device_id']) ? ($names[(int) $trigger['device_id']] ?? 'A device no longer here') : null;
        $sentence = match ((string) ($trigger['kind'] ?? '')) {
            'door_opened' => $device !== null ? "{$device} opens" : 'A door opens',
            'reading' => self::reading($trigger, $device),
            'device_offline' => $device !== null ? "{$device} goes offline" : 'A device goes offline',
            'device_back' => $device !== null ? "{$device} is back online" : 'A device comes back online',
            'time' => 'At '.self::clock((string) ($trigger['at'] ?? '')).' '.self::days((array) ($trigger['days'] ?? [])),
            default => 'Something happens',
        };
        $between = $automation->conditions['between'] ?? null;
        if (is_array($between)) {
            $sentence .= ' between '.self::clock((string) ($between['from'] ?? '')).' and '.self::clock((string) ($between['to'] ?? ''));
        }
        $inModes = array_values(array_map(fn (mixed $id): string => $modes[(int) $id] ?? 'a mode no longer there', (array) ($automation->conditions['modes'] ?? [])));
        if ($inModes !== []) {
            $sentence .= ' in '.self::list($inModes, 'or').' mode';
        }

        return $sentence;
    }

    /** "Automation · door", for the rules list. */
    public static function sub(Automation $automation): string
    {
        return 'Automation · '.match ((string) $automation->trigger_kind) {
            'door_opened' => 'door',
            'reading' => 'sensor reading',
            'device_offline', 'device_back' => 'device',
            'time' => 'time of day',
            default => 'house',
        };
    }

    public static function icon(Automation $automation): string
    {
        return match ((string) $automation->trigger_kind) {
            'door_opened' => 'door-open',
            'reading' => 'thermometer',
            'device_offline' => 'wifi-off',
            'device_back' => 'wifi',
            'time' => 'clock',
            default => 'workflow',
        };
    }

    /**
     * @param  array<int, string>  $names  device id => name
     * @param  array<string, string>  $roles  role name => label
     */
    public static function then(Automation $automation, array $names, array $roles): string
    {
        $parts = array_map(fn (array $action): string => self::action($action, $names, $roles), $automation->actionList());

        return $parts === [] ? 'Nothing' : ucfirst(implode(' · ', $parts));
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<int, string>  $names
     * @param  array<string, string>  $roles
     */
    public static function action(array $action, array $names, array $roles): string
    {
        $list = fn (string $key): string => self::list(array_map(
            fn (mixed $id): string => $names[(int) $id] ?? 'a device no longer here',
            (array) ($action[$key] ?? []),
        ));

        return match ((string) ($action['action'] ?? '')) {
            'notify' => 'tell '.self::who($action, $roles).' ('.self::channels($action).')',
            'open_alert' => 'open an alert for the staff on shift',
            'camera_privacy_on' => 'turn camera privacy on until '.self::clock((string) ($action['until'] ?? '')).' for '.$list('cameras'),
            'door_locked_check' => 'check '.$list('doors').(count((array) ($action['doors'] ?? [])) === 1 ? ' is' : ' are').' locked',
            'alarm_armed_check' => 'check the alarm is armed',
            default => 'an action automations can’t take (skipped)',
        };
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, string>  $roles
     */
    public static function who(array $action, array $roles): string
    {
        if (($action['who'] ?? 'on_shift') === 'role') {
            $role = (string) ($action['role'] ?? '');

            return 'people at the house with the '.($roles[$role] ?? ucfirst(str_replace('_', ' ', $role))).' role';
        }

        return 'the staff on shift';
    }

    /** @param array<string, mixed> $action */
    public static function channels(array $action): string
    {
        return implode(', ', array_values(array_filter([
            'app',
            ! empty($action['push']) ? 'push' : null,
            ! empty($action['email']) ? 'email' : null,
        ])));
    }

    /** "21:00" → "9:00 pm". */
    public static function clock(string $hhmm): string
    {
        if (preg_match('/\A([01]\d|2[0-3]):([0-5]\d)\z/', $hhmm, $match) !== 1) {
            return '—';
        }
        $hour = (int) $match[1];

        return ($hour % 12 === 0 ? 12 : $hour % 12).':'.$match[2].' '.($hour >= 12 ? 'pm' : 'am');
    }

    /** An instant as NZ wall clock: "9:00 pm". */
    public static function at(CarbonImmutable $at): string
    {
        return $at->setTimezone(self::ZONE)->format('g:i a');
    }

    /** @param list<mixed> $days */
    public static function days(array $days): string
    {
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);

        return match (true) {
            $days === [1, 2, 3, 4, 5, 6, 7] => 'every day',
            $days === [1, 2, 3, 4, 5] => 'on weekdays',
            $days === [6, 7] => 'on weekends',
            default => 'on '.self::list(array_map(fn (int $day): string => self::DAY_SHORT[$day] ?? '?', $days)),
        };
    }

    /** "45 °C", "1000 ppm". */
    public static function value(string $metric, float $value): string
    {
        $unit = AutomationDefinition::METRICS[$metric]['unit'] ?? '';
        $number = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return $unit === '%' ? $number.'%' : trim($number.' '.$unit);
    }

    /** @param list<string> $items */
    public static function list(array $items, string $joiner = 'and'): string
    {
        $items = array_values(array_unique(array_filter($items)));
        if (count($items) <= 1) {
            return $items[0] ?? 'nothing';
        }
        $last = array_pop($items);

        return implode(', ', $items).' '.$joiner.' '.$last;
    }

    /** @param array<string, mixed> $trigger */
    private static function reading(array $trigger, ?string $device): string
    {
        $metric = (string) ($trigger['metric'] ?? '');
        $label = AutomationDefinition::METRICS[$metric]['label'] ?? 'Reading';
        $lower = $metric === 'co2' || $metric === 'pm25' ? $label : mb_strtolower($label);
        $direction = ($trigger['direction'] ?? 'above') === 'below' ? 'below' : 'above';
        $value = self::value($metric, (float) ($trigger['value'] ?? 0));

        return $device !== null ? "{$device}: {$lower} {$direction} {$value}" : ucfirst($lower)." {$direction} {$value} on any sensor";
    }
}
