<?php

namespace App\Domain\It\Services;

use App\Support\It\BusinessHours;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Actual elapsed minutes, independent of payroll or the SLA clock. */
final class ItTicketWorkTime
{
    public function instant(string $value, string $field): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            $parsed = date_parse($value);
            if ($parsed['warning_count'] || $parsed['error_count']) {
                $this->invalid($field, 'Enter a valid date and time.');
            }
            try {
                return CarbonImmutable::parse($value)->utc();
            } catch (\Throwable) {
                $this->invalid($field, 'Enter a valid date and time.');
            }
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
            $this->invalid($field, 'Enter a complete date and time.');
        }
        $wall = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, 'UTC');
        if (! $wall || $wall->format('Y-m-d\TH:i') !== $value) {
            $this->invalid($field, 'Enter a valid date and time.');
        }
        $zone = new DateTimeZone(config('app.worker_timezone', 'Pacific/Auckland'));
        $offsets = array_unique(array_column($zone->getTransitions($wall->timestamp - 172800, $wall->timestamp + 172800), 'offset'));
        $matches = [];
        foreach ($offsets as $offset) {
            $candidate = $wall->subSeconds($offset);
            if ($candidate->setTimezone($zone)->format('Y-m-d\TH:i') === $value) {
                $matches[] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            $this->invalid($field, 'This local time is missing or occurs twice when daylight saving changes. Enter an ISO time with its UTC offset to identify the actual instant.');
        }

        return $matches[0];
    }

    public function period(array $input, string $prefix = 'work', bool $actual = true): array
    {
        $safe = Validator::make($input, [
            'starts_at' => ['required', 'string', 'max:40'], 'ends_at' => ['required', 'string', 'max:40'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:1439'],
            'work_type' => ['sometimes', Rule::in(['remote', 'onsite', 'travel'])],
            'after_hours' => ['sometimes', 'boolean'],
        ])->validate();
        $start = $this->instant($safe['starts_at'], $prefix.'.starts_at');
        $end = $this->instant($safe['ends_at'], $prefix.'.ends_at');
        $seconds = $end->timestamp - $start->timestamp;
        if ($seconds < 60 || $seconds > 86400) {
            $this->invalid($prefix.'.ends_at', 'End must be after start, with a period between one minute and 24 hours. Set the end date for overnight work.');
        }
        if ($actual && $end->greaterThan(CarbonImmutable::now()->addMinute())) {
            $this->invalid($prefix.'.ends_at', 'Actual work cannot end in the future. Use Schedule technician for planned work.');
        }
        $breaks = (int) ($safe['break_minutes'] ?? 0);
        $gross = intdiv($seconds, 60);
        if ($breaks >= $gross) {
            $this->invalid($prefix.'.break_minutes', 'Breaks must be shorter than the recorded period.');
        }

        return ['starts_at' => $start, 'ends_at' => $end, 'break_minutes' => $breaks,
            'minutes' => $gross - $breaks, 'after_hours' => (bool) ($safe['after_hours'] ?? false),
            'work_type' => $safe['work_type'] ?? 'remote'];
    }

    /** Suggest boundaries using the ticket's real SLA calendar; users review flags and breaks. */
    public function split(array $input, ?array $calendar): array
    {
        if (! BusinessHours::hasWindows($calendar)) {
            $this->invalid('work', 'This ticket has no configured business-hours calendar. Add periods and mark after hours explicitly.');
        }
        $period = $this->period($input);
        $segments = [];
        for ($cursor = $period['starts_at']; $cursor->lessThan($period['ends_at']); $cursor = $next) {
            $minute = $cursor->startOfMinute();
            $next = $minute->addMinute()->min($period['ends_at']);
            $after = BusinessHours::workingMinutesBetween($minute, $minute->addMinute(), $calendar) === 0;
            $last = array_key_last($segments);
            if ($last !== null && $segments[$last]['after_hours'] === $after) {
                $segments[$last]['ends_at'] = $next->toISOString();
            } else {
                $segments[] = ['starts_at' => $cursor->toISOString(), 'ends_at' => $next->toISOString(),
                    'after_hours' => $after, 'break_minutes' => 0, 'work_type' => $period['work_type']];
            }
        }

        $gross = array_map(fn (array $segment) => intdiv(
            CarbonImmutable::parse($segment['ends_at'])->timestamp - CarbonImmutable::parse($segment['starts_at'])->timestamp,
            60,
        ), $segments);
        $total = $period['minutes'] + $period['break_minutes'];
        if (min($gross) < 1 || array_sum($gross) !== $total) {
            $this->invalid('work', 'Partial minutes cross a business-hours boundary. Review start and end at minute precision, or mark after hours manually, so no recorded time is lost.');
        }
        if ($period['minutes'] < count($segments)) {
            $this->invalid('work', 'These breaks leave too little work for separate entries. Review the times and breaks, or mark after hours manually.');
        }
        $remaining = $period['break_minutes'];
        foreach ($segments as $index => &$segment) {
            $segment['break_minutes'] = min($gross[$index] - 1, intdiv($period['break_minutes'] * $gross[$index], $total));
            $remaining -= $segment['break_minutes'];
        }
        unset($segment);
        // Keep the full break total while leaving every suggested entry positive.
        while ($remaining > 0) {
            foreach ($segments as $index => &$segment) {
                if ($remaining > 0 && $segment['break_minutes'] < $gross[$index] - 1) {
                    $segment['break_minutes']++;
                    $remaining--;
                }
            }
            unset($segment);
        }

        return ['periods' => $segments, 'allocated_break_minutes' => $period['break_minutes'], 'break_minutes_to_allocate' => 0];
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
