<?php

namespace App\Services\Medication\Controlled;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/** Operational timestamps are Auckland wall times; UTC Z identifies an absolute instant. */
final class ControlledLocalTime
{
    public function parse(?string $value, string $field, bool $defaultNow = false, bool $allowFuture = false): CarbonImmutable
    {
        if (blank($value) && $defaultNow) {
            return CarbonImmutable::now('UTC');
        }
        if (! is_string($value) || ! preg_match('/^(?<minute>\d{4}-\d{2}-\d{2}T\d{2}:\d{2})(?::(?<second>\d{2})(?:\.(?<fraction>\d{1,6}))?)?(?<offset>Z|[+-]\d{2}:\d{2})?$/D', $value, $parts)) {
            throw ValidationException::withMessages([$field => 'Enter a valid New Zealand date and time.']);
        }

        // Canonical precision allows strict round-trip validation without
        // discarding milliseconds from browser or external ISO timestamps.
        $format = 'Y-m-d\TH:i:s.u';
        $wall = $parts['minute'].':'.(($parts['second'] ?? '') !== '' ? $parts['second'] : '00')
            .'.'.str_pad($parts['fraction'] ?? '', 6, '0');
        $naive = DateTimeImmutable::createFromFormat('!'.$format, $wall, new DateTimeZone('UTC'));
        if ($naive === false || $naive->format($format) !== $wall) {
            throw ValidationException::withMessages([$field => 'Enter a valid New Zealand date and time.']);
        }

        $zone = new DateTimeZone('Pacific/Auckland');
        $offsets = array_unique(array_column($zone->getTransitions($naive->getTimestamp() - 172800, $naive->getTimestamp() + 172800), 'offset'));
        $candidates = [];
        foreach ($offsets as $offset) {
            // modify preserves the fraction; setTimestamp would reset it.
            $candidate = $naive->modify(($offset >= 0 ? '-' : '+').abs($offset).' seconds');
            if ($candidate->setTimezone($zone)->format($format) === $wall) {
                $candidates[] = $candidate;
            }
        }
        if (($parts['offset'] ?? '') !== '') {
            try {
                $chosen = new DateTimeImmutable($value);
            } catch (\Exception) {
                throw ValidationException::withMessages([$field => 'Enter a valid date and time with its New Zealand offset.']);
            }
            if ($parts['offset'] === 'Z') {
                $candidates = [$chosen];
            }
            $candidates = array_values(array_filter($candidates, fn (DateTimeImmutable $at): bool => $at->format('U.u') === $chosen->format('U.u')));
        }
        if (count($candidates) !== 1) {
            throw ValidationException::withMessages([$field => $candidates === []
                ? 'This time does not exist in New Zealand when daylight saving changes. Choose a valid time.'
                : 'This New Zealand time occurs twice when daylight saving ends. Include +12:00 or +13:00 to identify which one.']);
        }
        $instant = CarbonImmutable::instance($candidates[0])->utc();
        if (! $allowFuture && $instant->greaterThan(CarbonImmutable::now('UTC'))) {
            throw ValidationException::withMessages([$field => 'This event cannot be recorded in the future.']);
        }

        return $instant;
    }
}
