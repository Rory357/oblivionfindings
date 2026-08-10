<?php

namespace App\Domain\Monitoring\Data;

final readonly class MonitorScheduleResult
{
    public function __construct(
        public bool $lockAcquired,
        public int $scanned = 0,
        public int $chunks = 0,
        public int $directDispatched = 0,
        public int $collectorConfigurations = 0,
        public int $omitted = 0,
    ) {}

    public static function locked(): self
    {
        return new self(lockAcquired: false);
    }
}
