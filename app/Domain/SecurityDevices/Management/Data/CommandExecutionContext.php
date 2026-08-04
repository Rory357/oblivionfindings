<?php

namespace App\Domain\SecurityDevices\Management\Data;

use App\Domain\SecurityDevices\Models\Device;
use Carbon\CarbonImmutable;

final readonly class CommandExecutionContext
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, scalar|null>  $expectedState
     */
    public function __construct(
        public string $commandUuid,
        public string $attemptUuid,
        public int $attemptNumber,
        public Device $device,
        public int $siteId,
        public string $capability,
        public array $parameters,
        public array $expectedState,
        public string $idempotencyKey,
        public CarbonImmutable $expiresAt,
    ) {}
}
