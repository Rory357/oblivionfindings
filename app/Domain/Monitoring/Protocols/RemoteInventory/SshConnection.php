<?php

namespace App\Domain\Monitoring\Protocols\RemoteInventory;

interface SshConnection
{
    public function fingerprint(): string;

    /** @param array<string, scalar|null> $material */
    public function authenticate(array $material): bool;

    /** @param list<string> $command */
    public function execute(array $command, int $timeoutSeconds, int $maxOutputBytes): SshCommandResponse;

    public function close(): void;
}
