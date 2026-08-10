<?php

namespace App\Domain\Monitoring\Protocols\RemoteInventory;

final readonly class SshCommandResponse
{
    public function __construct(
        public string $output,
        public ?int $exitStatus,
        public bool $timedOut,
        public bool $truncated,
        public int $latencyMs,
    ) {}
}
