<?php

namespace App\Domain\Monitoring\Protocols\RemoteInventory;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;

interface WinRmHttpClient
{
    /** @param array<string, scalar|null> $material */
    public function exchange(
        AuthorizedProbeTarget $target,
        string $address,
        string $soap,
        array $material,
        int $maxResponseBytes,
    ): WinRmHttpResponse;
}
