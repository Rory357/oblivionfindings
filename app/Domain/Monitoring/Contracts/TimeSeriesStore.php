<?php

namespace App\Domain\Monitoring\Contracts;

use App\Domain\Monitoring\Data\TimeSeriesPoint;
use Carbon\CarbonImmutable;

interface TimeSeriesStore
{
    /** @param list<TimeSeriesPoint> $points */
    public function writePoints(array $points): void;

    /**
     * Read the half-open interval [from, to).
     *
     * @return list<TimeSeriesPoint>
     */
    public function range(
        string $externalKey,
        string $tier,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array;

    /** Delete the half-open interval [from, to). */
    public function deleteRange(
        string $externalKey,
        string $tier,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): void;

    public function exists(
        string $externalKey,
        string $tier,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): bool;

    public function healthy(): bool;
}
