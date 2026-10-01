<?php

namespace App\Services\Medication\DoseSlots;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * When a scheduled dose may be given: from $beforeMinutes before its due time
 * to $afterMinutes after it, both ends included (P01: 30 before, 60 after —
 * config kept, Stephan D4). After the window a dose with no outcome is late.
 */
final class DoseWindow
{
    public const SOURCE_CONFIG = 'config';

    public const SOURCE_TIME_CRITICAL = 'time_critical';

    public function __construct(
        public readonly int $beforeMinutes,
        public readonly int $afterMinutes,
        public readonly string $source = self::SOURCE_CONFIG,
    ) {
        if ($beforeMinutes < 0 || $afterMinutes < 0) {
            throw new InvalidArgumentException('A dose window cannot be negative.');
        }
    }

    public function opensAt(CarbonImmutable $dueAt): CarbonImmutable
    {
        return $dueAt->subMinutes($this->beforeMinutes);
    }

    public function closesAt(CarbonImmutable $dueAt): CarbonImmutable
    {
        return $dueAt->addMinutes($this->afterMinutes);
    }

    public function contains(CarbonImmutable $dueAt, CarbonImmutable $at): bool
    {
        return $at->greaterThanOrEqualTo($this->opensAt($dueAt))
            && $at->lessThanOrEqualTo($this->closesAt($dueAt));
    }
}
