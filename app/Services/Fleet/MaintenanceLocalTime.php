<?php

namespace App\Services\Fleet;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

class MaintenanceLocalTime
{
    /**
     * Convert an exact Auckland wall minute to UTC. Ambiguous fall-back
     * minutes require the selected offset; spring-forward gaps are rejected.
     */
    public static function toUtc(string $local, ?string $selectedOffset = null): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local)) {
            throw ValidationException::withMessages(['time' => 'Choose an exact local date and minute.']);
        }
        $parts = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, new DateTimeZone('UTC'));
        if (! $parts || $parts->format('Y-m-d\TH:i') !== $local) {
            throw ValidationException::withMessages(['time' => 'This local date or time is invalid.']);
        }

        $zone = new DateTimeZone('Pacific/Auckland');
        $wallAsUtc = $parts->getTimestamp();
        $offsets = array_unique(array_map(static fn (array $transition): int => (int) $transition['offset'],
            $zone->getTransitions($wallAsUtc - 3 * 86400, $wallAsUtc + 3 * 86400) ?: []));
        $matches = [];
        foreach ($offsets as $seconds) {
            $utc = new DateTimeImmutable('@'.($wallAsUtc - $seconds));
            $atSite = $utc->setTimezone($zone);
            if ($atSite->format('Y-m-d\TH:i') === $local) {
                $matches[$atSite->format('P')] = $utc;
            }
        }
        if ($matches === []) {
            throw ValidationException::withMessages(['time' => 'This Auckland time does not exist because the clock moves forward. Choose another minute.']);
        }
        if (count($matches) > 1 && $selectedOffset === null) {
            throw ValidationException::withMessages(['time' => 'This Auckland time occurs twice. Choose the displayed UTC offset.']);
        }
        if ($selectedOffset !== null) {
            if (! isset($matches[$selectedOffset])) {
                throw ValidationException::withMessages(['time' => 'The selected UTC offset does not match this Auckland time.']);
            }
            return $matches[$selectedOffset]->format('Y-m-d H:i:s');
        }

        return reset($matches)->format('Y-m-d H:i:s');
    }

}
