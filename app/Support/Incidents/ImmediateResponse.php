<?php

namespace App\Support\Incidents;

use App\Support\WorkerClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Decision 18 (Stephan, 1 Nov 2026, H&S board G4-2): the incident report's "Straight away" part — quick ticks, each
 * with the time it was done, for what the reporter did straight away. A tick left unticked didn't happen; an unticked
 * row may still carry a short note ("Hemi will ring tomorrow morning" → "Not yet").
 *
 * The form sends a wall-clock time (HH:mm, worker timezone). It is stored as the instant it happened: on the day the
 * incident happened, or the next day when the time is earlier than the incident's own time (just after midnight).
 */
final class ImmediateResponse
{
    /** The ticks, in the order the form asks them. */
    public const KEYS = ['made_safe', 'first_aid', 'medical_rung', 'manager_told', 'family_told'];

    /**
     * A saved recovery copy of the form may hold a tick whose time isn't chosen yet; a report never does.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix = 'immediate_response', bool $recoveryCopy = false): array
    {
        return [
            $prefix => ['nullable', 'array', 'max:'.count(self::KEYS)],
            $prefix.'.*' => ['array:key,done,time,detail'],
            $prefix.'.*.key' => ['required', 'string', 'distinct', 'in:'.implode(',', self::KEYS)],
            $prefix.'.*.done' => ['required', 'boolean'],
            $prefix.'.*.time' => $recoveryCopy
                ? ['nullable', 'date_format:H:i']
                : ['nullable', 'required_if_accepted:'.$prefix.'.*.done', 'date_format:H:i'],
            $prefix.'.*.detail' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The ticks to store, in form order: done rows with their instant, and unticked rows that carry a note.
     *
     * @param  array<int, array<string, mixed>>|null  $rows
     * @return list<array{key: string, done: bool, at: string|null, detail: string|null}>|null
     */
    public static function normalise(?array $rows, ?CarbonImmutable $occurredAtUtc): ?array
    {
        if (! $rows) {
            return null;
        }

        $zone = WorkerClock::timezone();
        $occurredLocal = ($occurredAtUtc ?? CarbonImmutable::now())->setTimezone($zone);
        $latest = CarbonImmutable::now()->addMinutes(5);
        $byKey = [];
        foreach ($rows as $index => $row) {
            $key = (string) ($row['key'] ?? '');
            $done = filter_var($row['done'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $detail = trim((string) ($row['detail'] ?? ''));
            $at = null;
            if ($done) {
                [$hour, $minute] = array_map('intval', explode(':', (string) $row['time']));
                $local = $occurredLocal->setTime($hour, $minute);
                if ($local->lt($occurredLocal->startOfMinute())) {
                    $local = $local->addDay();
                }
                $at = $local->utc();
                if ($at->gt($latest)) {
                    throw ValidationException::withMessages([
                        "immediate_response.$index.time" => 'A time done straight away can’t be later than now.',
                    ]);
                }
            }
            if (! $done && $detail === '') {
                continue;
            }
            $byKey[$key] = [
                'key' => $key,
                'done' => $done,
                'at' => $at?->toIso8601String(),
                'detail' => $detail === '' ? null : $detail,
            ];
        }

        $ordered = array_values(array_filter(array_map(fn (string $key) => $byKey[$key] ?? null, self::KEYS)));

        return $ordered === [] ? null : $ordered;
    }
}
