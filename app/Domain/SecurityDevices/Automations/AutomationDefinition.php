<?php

namespace App\Domain\SecurityDevices\Automations;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * An automation's when, only if and then, checked on every save (S&D Phase F, pop-up 40). Every
 * action must be one of SafeActions::ACTIONS (D12): anything else, a disarm or an unlock above all,
 * is refused with a 422. Devices must be ones the editor can see at the automation's own Site.
 *
 * Stored shapes:
 *  - trigger: {kind: door_opened|device_offline|device_back, device_id: ?int} (null: any at the Site),
 *    {kind: reading, device_id: ?int, metric, direction: above|below, value: float},
 *    {kind: time, at: "HH:MM", days: list<int 1–7, Monday first>} (NZ wall clock);
 *  - conditions: {between: {from: "HH:MM", to: "HH:MM"}|null (NZ wall clock, may run past midnight),
 *    modes: list<int>} (the Site's house modes it runs in; empty: any mode);
 *  - actions, in the order they run:
 *    notify {who: on_shift|role, role: ?string, email: bool, push: bool, message: ?string};
 *    open_alert {} (an alert on the device that started it, for the staff on shift);
 *    camera_privacy_on {cameras: list<int>, until: "HH:MM"};
 *    door_locked_check {doors: list<int>};
 *    alarm_armed_check {panels: list<int>}.
 */
final class AutomationDefinition
{
    public const array TRIGGERS = ['door_opened', 'reading', 'device_offline', 'device_back', 'time'];

    /** The device group each device trigger picks from (AutomationDevices::GROUPS, or any). */
    public const array TRIGGER_GROUP = ['door_opened' => 'doors', 'reading' => 'sensors', 'device_offline' => 'any', 'device_back' => 'any'];

    /** Readings an automation can watch: the ones sensors send today. */
    public const array METRICS = [
        'temperature' => ['label' => 'Temperature', 'unit' => '°C', 'min' => -40, 'max' => 120],
        'humidity' => ['label' => 'Humidity', 'unit' => '%', 'min' => 0, 'max' => 100],
        'co2' => ['label' => 'CO₂', 'unit' => 'ppm', 'min' => 0, 'max' => 10000],
        'pm25' => ['label' => 'PM2.5', 'unit' => 'µg/m³', 'min' => 0, 'max' => 1000],
    ];

    public const int MAX_ACTIONS = 5;

    public const int MESSAGE_MAX = 200;

    public const int NAME_MAX = 80;

    private const string TIME = '/\A([01]\d|2[0-3]):[0-5]\d\z/';

    /**
     * @param  array<string, mixed>  $input  name, trigger, conditions, actions
     * @param  array{doors: array<int, string>, sensors: array<int, string>, cameras: array<int, string>, panels: array<int, string>, any: array<int, string>}  $choosable  at the automation's Site
     * @param  list<string>  $roles  role names people can be told by
     * @param  array<int, string>  $modes  the Site's house modes, id => name
     * @return array{name: string, trigger: array<string, mixed>, conditions: array{between: array{from: string, to: string}|null, modes: list<int>}, actions: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public static function normalise(array $input, array $choosable, array $roles, array $modes = []): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', is_string($input['name'] ?? null) ? $input['name'] : ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > self::NAME_MAX || preg_match('/[\p{Cc}\p{Cf}]/u', $name) === 1) {
            throw ValidationException::withMessages(['name' => 'Give the automation a name of 3 to '.self::NAME_MAX.' letters.']);
        }
        $trigger = self::trigger(is_array($input['trigger'] ?? null) ? $input['trigger'] : [], $choosable);
        $conditions = self::conditions($input['conditions'] ?? null, $modes);
        $actions = self::actions($input['actions'] ?? null, $choosable, $roles, $trigger['kind'] !== 'time');

        return ['name' => $name, 'trigger' => $trigger, 'conditions' => $conditions, 'actions' => $actions];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, array<int, string>>  $choosable
     * @return array<string, mixed>
     */
    private static function trigger(array $raw, array $choosable): array
    {
        $kind = is_string($raw['kind'] ?? null) ? $raw['kind'] : '';
        if (! in_array($kind, self::TRIGGERS, true)) {
            throw ValidationException::withMessages(['trigger.kind' => 'Choose what starts the automation.']);
        }
        if ($kind === 'time') {
            $days = is_array($raw['days'] ?? null) ? $raw['days'] : [];
            $clean = [];
            foreach ($days as $day) {
                if (! is_numeric($day) || (int) $day < 1 || (int) $day > 7) {
                    throw ValidationException::withMessages(['trigger.days' => 'Choose the days it runs.']);
                }
                $clean[(int) $day] = (int) $day;
            }
            if ($clean === []) {
                throw ValidationException::withMessages(['trigger.days' => 'Choose at least one day.']);
            }
            ksort($clean);

            return ['kind' => 'time', 'at' => self::time($raw['at'] ?? null, 'trigger.at'), 'days' => array_values($clean)];
        }

        $group = self::TRIGGER_GROUP[$kind];
        $deviceId = null;
        if (($raw['device_id'] ?? null) !== null && ($raw['device_id'] ?? '') !== '') {
            if (! is_numeric($raw['device_id']) || ! array_key_exists((int) $raw['device_id'], $choosable[$group] ?? [])) {
                throw ValidationException::withMessages(['trigger.device_id' => match ($group) {
                    'doors' => 'Choose a door at this Site, or any door.',
                    'sensors' => 'Choose a sensor at this Site, or any sensor.',
                    default => 'Choose a device at this Site, or any device.',
                }]);
            }
            $deviceId = (int) $raw['device_id'];
        }
        if ($kind !== 'reading') {
            return ['kind' => $kind, 'device_id' => $deviceId];
        }

        $metric = is_string($raw['metric'] ?? null) ? $raw['metric'] : '';
        if (! array_key_exists($metric, self::METRICS)) {
            throw ValidationException::withMessages(['trigger.metric' => 'Choose which reading to watch.']);
        }
        $direction = $raw['direction'] ?? null;
        if (! in_array($direction, ['above', 'below'], true)) {
            throw ValidationException::withMessages(['trigger.direction' => 'Choose above or below.']);
        }
        $spec = self::METRICS[$metric];
        $value = $raw['value'] ?? null;
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < $spec['min'] || (float) $value > $spec['max']) {
            throw ValidationException::withMessages(['trigger.value' => 'Enter a value from '.$spec['min'].' to '.$spec['max'].' '.$spec['unit'].'.']);
        }

        return ['kind' => 'reading', 'device_id' => $deviceId, 'metric' => $metric, 'direction' => $direction, 'value' => round((float) $value, 1)];
    }

    /**
     * @param  array<int, string>  $modes  the Site's house modes, id => name
     * @return array{between: array{from: string, to: string}|null, modes: list<int>}
     */
    private static function conditions(mixed $raw, array $modes): array
    {
        $chosen = [];
        $rawModes = is_array($raw) ? ($raw['modes'] ?? []) : [];
        if (! is_array($rawModes)) {
            throw ValidationException::withMessages(['conditions.modes' => 'Choose the house modes, or none to run in any mode.']);
        }
        foreach ($rawModes as $id) {
            if (! is_numeric($id) || ! array_key_exists((int) $id, $modes)) {
                throw ValidationException::withMessages(['conditions.modes' => 'Choose house modes of this Site.']);
            }
            $chosen[(int) $id] = (int) $id;
        }
        sort($chosen);
        $chosen = array_values($chosen);

        $between = is_array($raw) ? ($raw['between'] ?? null) : null;
        if ($between === null || $between === []) {
            return ['between' => null, 'modes' => $chosen];
        }
        if (! is_array($between)) {
            throw ValidationException::withMessages(['conditions.between' => 'Choose the hours, or leave them empty to run at any time.']);
        }
        $from = self::time($between['from'] ?? null, 'conditions.between');
        $to = self::time($between['to'] ?? null, 'conditions.between');
        if ($from === $to) {
            throw ValidationException::withMessages(['conditions.between' => 'The start and end can’t be the same time.']);
        }

        return ['between' => ['from' => $from, 'to' => $to], 'modes' => $chosen];
    }

    /**
     * @param  array<string, array<int, string>>  $choosable
     * @param  list<string>  $roles
     * @return list<array<string, mixed>>
     */
    private static function actions(mixed $raw, array $choosable, array $roles, bool $hasDevice): array
    {
        if (! is_array($raw) || $raw === []) {
            throw ValidationException::withMessages(['actions' => 'Add at least one action.']);
        }
        if (count($raw) > self::MAX_ACTIONS) {
            throw ValidationException::withMessages(['actions' => 'An automation can take up to '.self::MAX_ACTIONS.' actions.']);
        }
        $out = [];
        $seen = [];
        foreach (array_values($raw) as $index => $action) {
            $key = is_array($action) && is_string($action['action'] ?? null) ? $action['action'] : '';
            try {
                // D12: only the safe actions. A disarm, an unlock or any governed command is refused here.
                SafeActions::assertAllowed($key);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(["actions.{$index}.action" => $exception->getMessage()]);
            }
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["actions.{$index}.action" => 'Each action can be added once.']);
            }
            $seen[$key] = true;
            $out[] = match ($key) {
                'notify' => self::notify($action, $index, $roles),
                'open_alert' => $hasDevice
                    ? ['action' => 'open_alert']
                    : throw ValidationException::withMessages(["actions.{$index}.action" => 'A time of day has no device to open an alert on. Tell people instead.']),
                'camera_privacy_on' => [
                    'action' => $key,
                    'cameras' => self::devices($action['cameras'] ?? null, $choosable['cameras'] ?? [], "actions.{$index}.cameras", 'Choose at least one camera at this Site.'),
                    'until' => self::time($action['until'] ?? null, "actions.{$index}.until"),
                ],
                'door_locked_check' => [
                    'action' => $key,
                    'doors' => self::devices($action['doors'] ?? null, $choosable['doors'] ?? [], "actions.{$index}.doors", 'Choose at least one door at this Site.'),
                ],
                'alarm_armed_check' => [
                    'action' => $key,
                    'panels' => self::devices($action['panels'] ?? null, $choosable['panels'] ?? [], "actions.{$index}.panels", 'Choose the alarm at this Site.'),
                ],
                default => throw ValidationException::withMessages(["actions.{$index}.action" => 'Automations can’t take that action yet.']),
            };
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<string>  $roles
     * @return array{action: string, who: string, role: ?string, email: bool, push: bool, message: ?string}
     */
    private static function notify(array $action, int $index, array $roles): array
    {
        $who = $action['who'] ?? 'on_shift';
        if (! in_array($who, ['on_shift', 'role'], true)) {
            throw ValidationException::withMessages(["actions.{$index}.who" => 'Choose who is told.']);
        }
        $role = null;
        if ($who === 'role') {
            $role = is_string($action['role'] ?? null) ? $action['role'] : '';
            if (! in_array($role, $roles, true)) {
                throw ValidationException::withMessages(["actions.{$index}.role" => 'Choose the role to tell.']);
            }
        }
        $message = $action['message'] ?? null;
        if ($message !== null && ! is_string($message)) {
            throw ValidationException::withMessages(["actions.{$index}.message" => 'The message isn’t readable.']);
        }
        $message = $message === null ? null : trim((string) preg_replace('/\s+/u', ' ', $message));
        if ($message !== null && preg_match('/[\p{Cc}\p{Cf}]/u', $message) === 1) {
            throw ValidationException::withMessages(["actions.{$index}.message" => 'Take out the special characters from the message.']);
        }
        if ($message !== null && mb_strlen($message) > self::MESSAGE_MAX) {
            throw ValidationException::withMessages(["actions.{$index}.message" => 'Keep the message under '.self::MESSAGE_MAX.' letters.']);
        }

        return [
            'action' => 'notify',
            'who' => $who,
            'role' => $role,
            'email' => (bool) ($action['email'] ?? false),
            'push' => (bool) ($action['push'] ?? false),
            'message' => $message === '' ? null : $message,
        ];
    }

    /**
     * @param  array<int, string>  $choosable
     * @return list<int>
     */
    private static function devices(mixed $raw, array $choosable, string $field, string $empty): array
    {
        if (! is_array($raw) || $raw === []) {
            throw ValidationException::withMessages([$field => $empty]);
        }
        $ids = [];
        foreach ($raw as $id) {
            if (! is_numeric($id) || ! array_key_exists((int) $id, $choosable)) {
                throw ValidationException::withMessages([$field => $empty]);
            }
            $ids[(int) $id] = (int) $id;
        }
        sort($ids);

        return array_values($ids);
    }

    private static function time(mixed $raw, string $field): string
    {
        if (! is_string($raw) || preg_match(self::TIME, $raw) !== 1) {
            throw ValidationException::withMessages([$field => 'Choose a time.']);
        }

        return $raw;
    }
}
