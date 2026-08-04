<?php

namespace App\Domain\SecurityDevices\Credentials\Contracts;

interface SecretManagerRestoreProbe
{
    /**
     * Perform the provider's documented read-only health check.
     *
     * Implementations must return only a boolean and must not issue a lease,
     * retrieve secret material, or expose connection details.
     */
    public function healthy(): bool;
}
