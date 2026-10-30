<?php

namespace App\Services\Medication\BackupDelivery;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** One NZ date identity, earliest repeated minute, first valid minute after a DST gap. */
final class BackupScheduleClock
{
    public const TIMEZONE = 'Pacific/Auckland';

    public function resolve(string $day, string $time): array
    {
        $nominal = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $day.' '.$time, new DateTimeZone('UTC'));
        if (! $nominal || $nominal->format('Y-m-d H:i') !== $day.' '.$time) {
            throw new InvalidArgumentException('Use a valid NZ date and 24-hour HH:mm time.');
        }
        $zone = new DateTimeZone(self::TIMEZONE);
        $offsets = array_unique(array_column($zone->getTransitions($nominal->getTimestamp() - 172800, $nominal->getTimestamp() + 172800), 'offset'));
        for ($minute = 0; $minute <= 180; $minute++) {
            $wall = $nominal->modify('+'.$minute.' minutes');
            $matches = [];
            foreach ($offsets as $offset) {
                $candidate = (new DateTimeImmutable('@'.($wall->getTimestamp() - $offset)))->setTimezone($zone);
                if ($candidate->format('Y-m-d H:i') === $wall->format('Y-m-d H:i')) {
                    $matches[] = $candidate->getTimestamp();
                }
            }
            if ($matches !== []) {
                sort($matches, SORT_NUMERIC);

                return ['instant' => CarbonImmutable::createFromTimestampUTC($matches[0]), 'adjusted' => $minute > 0, 'ambiguous' => count($matches) > 1];
            }
        }
        throw new InvalidArgumentException('The scheduled local time could not be resolved safely.');
    }
}
