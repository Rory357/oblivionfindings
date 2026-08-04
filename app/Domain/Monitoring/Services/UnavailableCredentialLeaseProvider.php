<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use RuntimeException;

final class UnavailableCredentialLeaseProvider implements CredentialLeaseProvider
{
    public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
    {
        throw new RuntimeException('Credential lease provider is not configured.');
    }
}
