<?php

namespace App\Domain\SecurityDevices\Credentials\Services;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerRestoreProbe;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use RuntimeException;

final class UnavailableSecretManagerLeaseIssuer implements SecretManagerLeaseIssuer, SecretManagerRestoreProbe
{
    public function healthy(): bool
    {
        return false;
    }

    public function issue(SecretLeaseRequest $request): CredentialLease
    {
        throw new RuntimeException('Secret manager lease issuer is not configured.');
    }

    public function revoke(#[\SensitiveParameter] string $leaseId): void
    {
        // No external lease can exist when this fail-closed issuer is active.
    }
}
