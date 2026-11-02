<?php

namespace App\Domain\SecurityDevices\Modes;

use App\Domain\SecurityDevices\Automations\SafeActions;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A house mode's actions, checked on every save (S&D Phase F, pop-up 43). Every action must be one of
 * SafeActions::ACTIONS (D12): anything else, a disarm or an unlock above all, is refused with a 422.
 * Doors, cameras and alarm panels must be ones the editor can see at the mode's own Site.
 *
 * Stored shapes:
 *  - notify: {message?: string}: the staff on shift at the house are told of the switch, in the app;
 *  - open_alert: {}: what a check finds opens an alert in Alerts (instead of a message);
 *  - camera_privacy_on: {cameras: list<int>, until: "HH:MM"}: privacy on until that time;
 *  - door_locked_check: {doors: list<int>}: reads each lock, reports any not locked;
 *  - alarm_armed_check: {panels: list<int>, after_minutes: 0|15|30|60}: reads the areas, reminds if not armed.
 */
final class HouseModeActions
{
    /** The order a switch runs them in: privacy first, then the checks, then who is told. */
    public const array ORDER = ['camera_privacy_on', 'door_locked_check', 'alarm_armed_check', 'notify', 'open_alert'];

    public const array AFTER_MINUTES = [0, 15, 30, 60];

    public const int MESSAGE_MAX = 200;

    private const string TIME = '/\A([01]\d|2[0-3]):[0-5]\d\z/';

    /**
     * @param  mixed  $raw  the request's `actions` list
     * @param  array{doors: array<int, string>, cameras: array<int, string>, panels: array<int, string>}  $choosable
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function normalise(mixed $raw, array $choosable): array
    {
        if (! is_array($raw)) {
            throw ValidationException::withMessages(['actions' => 'The mode’s actions aren’t readable.']);
        }
        $out = [];
        foreach (array_values($raw) as $index => $action) {
            $key = is_array($action) && is_string($action['action'] ?? null) ? $action['action'] : '';
            try {
                SafeActions::assertAllowed($key);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(["actions.{$index}.action" => $exception->getMessage()]);
            }
            if (isset($out[$key])) {
                throw ValidationException::withMessages(["actions.{$index}.action" => 'Each action can be on once.']);
            }
            $out[$key] = match ($key) {
                'notify' => self::notify($action, $index),
                'open_alert' => ['action' => 'open_alert'],
                'camera_privacy_on' => [
                    'action' => $key,
                    'cameras' => self::devices($action['cameras'] ?? null, $choosable['cameras'], "actions.{$index}.cameras", 'Choose at least one camera at this Site.'),
                    'until' => self::time($action['until'] ?? null, "actions.{$index}.until"),
                ],
                'door_locked_check' => [
                    'action' => $key,
                    'doors' => self::devices($action['doors'] ?? null, $choosable['doors'], "actions.{$index}.doors", 'Choose at least one door at this Site.'),
                ],
                'alarm_armed_check' => [
                    'action' => $key,
                    'panels' => self::devices($action['panels'] ?? null, $choosable['panels'], "actions.{$index}.panels", 'Choose the alarm at this Site.'),
                    'after_minutes' => self::after($action['after_minutes'] ?? 0, "actions.{$index}.after_minutes"),
                ],
                default => throw ValidationException::withMessages(["actions.{$index}.action" => 'House modes can’t take that action yet.']),
            };
        }

        $ordered = [];
        foreach (self::ORDER as $key) {
            if (isset($out[$key])) {
                $ordered[] = $out[$key];
            }
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{action: string, message: ?string}
     */
    private static function notify(array $action, int $index): array
    {
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

        return ['action' => 'notify', 'message' => $message === '' ? null : $message];
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

    private static function after(mixed $raw, string $field): int
    {
        if (! is_numeric($raw) || ! in_array((int) $raw, self::AFTER_MINUTES, true)) {
            throw ValidationException::withMessages([$field => 'Choose when to check.']);
        }

        return (int) $raw;
    }
}
