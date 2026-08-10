<?php

namespace App\Domain\Monitoring\Data;

use App\Domain\Monitoring\Enums\MonitorKind;

final readonly class AuthorisedProbeContext
{
    /** @param array<string, mixed> $config */
    public function __construct(
        public int $monitorId,
        public int $siteId,
        public int $deviceId,
        public MonitorKind $kind,
        public AuthorizedProbeTarget $target,
        public array $config,
    ) {}
}
