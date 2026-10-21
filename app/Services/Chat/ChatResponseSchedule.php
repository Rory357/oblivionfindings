<?php

namespace App\Services\Chat;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Working time is elapsed time within local opening intervals, excluding local holiday dates. */
final class ChatResponseSchedule
{
    public function validate(mixed $input, string $path = 'response_schedule'): ?array
    {
        if ($input === null) {
            return null;
        }
        if (is_array($input) && isset($input['holidays']) && is_array($input['holidays'])) {
            $input['holidays'] = array_values(array_filter(array_map(fn ($date) => is_string($date) ? trim($date) : $date, $input['holidays']), fn ($date) => $date !== '' && $date !== null));
        }
        $value = Validator::make([$path => $input], [
            $path => ['required', 'array:timezone,response_minutes,weekly,holidays'],
            "$path.timezone" => ['required', 'string', 'timezone:all'],
            "$path.response_minutes" => ['required', 'integer', 'between:1,10080'],
            "$path.weekly" => ['required', 'array', 'min:1', 'max:28'],
            "$path.weekly.*" => ['required', 'array:day,start,end'],
            "$path.weekly.*.day" => ['required', 'integer', 'between:1,7'],
            "$path.weekly.*.start" => ['required', 'date_format:H:i'],
            "$path.weekly.*.end" => ['required', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$|^24:00$/'],
            "$path.holidays" => ['present', 'array', 'max:366'],
            "$path.holidays.*" => ['required', 'date_format:Y-m-d', 'distinct'],
        ])->validate()[$path];
        $weekly = array_values($value['weekly']);
        foreach ($weekly as $index => $interval) {
            if ($interval['start'] >= $interval['end']) {
                throw ValidationException::withMessages(["$path.weekly.$index.end" => 'Closing must be after opening. Split overnight hours across two days.']);
            }
        }
        usort($weekly, fn ($a, $b) => [(int) $a['day'], $a['start']] <=> [(int) $b['day'], $b['start']]);
        foreach ($weekly as $index => $interval) {
            $previous = $weekly[$index - 1] ?? null;
            if ($previous && (int) $previous['day'] === (int) $interval['day'] && $previous['end'] > $interval['start']) {
                throw ValidationException::withMessages(["$path.weekly" => 'Opening intervals on the same day must not overlap.']);
            }
        }
        $holidays = array_values($value['holidays']);
        sort($holidays);

        return ['timezone' => $value['timezone'], 'response_minutes' => (int) $value['response_minutes'],
            'weekly' => array_map(fn ($interval) => [...$interval, 'day' => (int) $interval['day']], $weekly), 'holidays' => $holidays];
    }

    public function dueAt(CarbonImmutable $receivedAt, ?array $schedule): ?CarbonImmutable
    {
        if ($schedule === null) {
            return null;
        }
        // Fail closed for malformed historical settings; never manufacture a deadline.
        try {
            $schedule = $this->validate($schedule);
        } catch (ValidationException) {
            return null;
        }
        $remaining = $schedule['response_minutes'] * 60;
        $cursor = $receivedAt->setTimezone($schedule['timezone']);
        $day = $cursor->startOfDay();
        $holidays = array_fill_keys($schedule['holidays'], true);
        $weeklyMinutes = array_sum(array_map(fn ($interval) => $this->minutes($interval['end']) - $this->minutes($interval['start']), $schedule['weekly']));
        // A bounded horizon derived from actual weekly capacity, plus holiday and DST slack.
        $days = 7 * ((int) ceil($schedule['response_minutes'] / $weeklyMinutes) + count($holidays) + 4) * 2;
        for ($offset = 0; $offset < $days; $offset++, $day = $day->addDay()->startOfDay()) {
            if (isset($holidays[$day->toDateString()])) {
                continue;
            }
            foreach ($schedule['weekly'] as $interval) {
                if ($interval['day'] !== $day->dayOfWeekIso) {
                    continue;
                }
                $start = $day->setTimeFromTimeString($interval['start']);
                $end = $interval['end'] === '24:00' ? $day->addDay()->startOfDay() : $day->setTimeFromTimeString($interval['end']);
                $start = $cursor->greaterThan($start) ? $cursor : $start;
                $seconds = $end->getTimestamp() - $start->getTimestamp();
                if ($seconds <= 0) {
                    continue;
                }
                if ($remaining <= $seconds) {
                    return CarbonImmutable::createFromTimestampUTC($start->getTimestamp() + $remaining);
                }
                $remaining -= $seconds;
            }
        }

        return null;
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }
}
