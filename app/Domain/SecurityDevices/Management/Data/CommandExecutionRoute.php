<?php

namespace App\Domain\SecurityDevices\Management\Data;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;

final readonly class CommandExecutionRoute
{
    private function __construct(
        public string $mode,
        public bool $available,
        public string $reason,
        public ?CommandExecutionAdapter $adapter = null,
        public ?MonitoringCollector $collector = null,
    ) {}

    public static function central(CommandExecutionAdapter $adapter): self
    {
        return new self(
            mode: 'central',
            available: true,
            reason: 'The main Oblivion Findings runtime can reach this Device over the approved Site network path.',
            adapter: $adapter,
        );
    }

    public static function collector(MonitoringCollector $collector): self
    {
        return new self(
            mode: 'collector',
            available: true,
            reason: 'This remote-only Device will use its current Site-scoped collector and encrypted ordered result path.',
            collector: $collector,
        );
    }

    public static function unavailable(string $reason): self
    {
        return new self('unavailable', false, $reason);
    }
}
