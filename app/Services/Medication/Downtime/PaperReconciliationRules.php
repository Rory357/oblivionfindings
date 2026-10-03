<?php

namespace App\Services\Medication\Downtime;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class PaperReconciliationRules
{
    public const TIMEZONE = 'Pacific/Auckland';

    public static function instant(string $value): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/D', $value)) {
            throw new InvalidArgumentException('Choose a complete date and time in Pacific/Auckland.');
        }
        try {
            $at = CarbonImmutable::parse($value, self::TIMEZONE);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Choose a valid date and time.');
        }
        $hasSeconds = (bool) preg_match('/T\d{2}:\d{2}:\d{2}/', $value);
        if ($at->format($hasSeconds ? 'Y-m-d\TH:i:s' : 'Y-m-d\TH:i') !== substr($value, 0, $hasSeconds ? 19 : 16)) {
            throw new InvalidArgumentException('Choose a valid calendar date and clock time.');
        }
        if (! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            $wall = substr($value, 0, 16);
            if ($at->format('Y-m-d\TH:i') !== $wall) {
                throw new InvalidArgumentException('That NZ clock time does not exist because the clocks changed. Check the paper.');
            }
            // A repeated wall time has two possible actual instants: never guess which was on paper.
            $utcWall = CarbonImmutable::parse($wall, 'UTC');
            $matches = collect([12, 13])->map(fn ($offset) => $utcWall->subHours($offset))
                ->filter(fn ($candidate) => $candidate->setTimezone(self::TIMEZONE)->format('Y-m-d\TH:i') === $wall);
            if ($matches->count() > 1) {
                throw new InvalidArgumentException('That NZ clock time happened twice. Confirm NZST (+12:00) or NZDT (+13:00) on the paper.');
            }
        }

        return $at->utc();
    }

    public static function inside(CarbonImmutable $at, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $at->betweenIncluded($start, $end);
    }

    public static function identity(int $orderId, ?int $slotId, CarbonImmutable $givenAt): string
    {
        return $slotId !== null ? 'slot:'.$slotId : 'prn:'.$orderId.':'.$givenAt->utc()->format('YmdHi');
    }

    public static function fingerprint(array $facts): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($facts), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function state(bool $posted, bool $giverConfirmed, bool $needsWitness, bool $witnessConfirmed): string
    {
        return match (true) {
            $posted => 'entered_from_paper',
            ! $giverConfirmed => 'giver_to_confirm',
            $needsWitness && ! $witnessConfirmed => 'witness_to_confirm',
            default => 'ready_to_reconcile',
        };
    }
}
