<?php

namespace App\Domain\Monitoring\Contracts;

use App\Domain\Monitoring\Data\CredentialLease;

interface CredentialLeaseProvider
{
    /** @param list<string> $capabilities */
    public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease;
}
