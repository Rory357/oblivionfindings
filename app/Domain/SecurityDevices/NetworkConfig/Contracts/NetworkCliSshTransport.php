<?php

namespace App\Domain\SecurityDevices\NetworkConfig\Contracts;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\SecurityDevices\NetworkConfig\Data\NetworkCliCommandResult;

interface NetworkCliSshTransport
{
    /**
     * Execute one allowlisted read-only CLI export command over a non-interactive SSH exec channel.
     *
     * @param  array<string, scalar|null>  $credentialMaterial
     */
    public function runReadOnlyExport(
        AuthorizedProbeTarget $target,
        array $credentialMaterial,
        string $command,
        ?string $expectedHostKeyFingerprint = null,
        int $timeoutSeconds = 15,
    ): NetworkCliCommandResult;
}
