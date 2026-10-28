<?php

namespace App\Domain\SecurityDevices\NetworkConfig\Data;

final readonly class NetworkCliCommandResult
{
    public function __construct(
        public bool $ok,
        public string $reasonCode,
        public string $output,
        public ?string $hostKeyFingerprint = null,
        public bool $hostKeyPinned = false,
        public int $latencyMs = 0,
    ) {}
}
