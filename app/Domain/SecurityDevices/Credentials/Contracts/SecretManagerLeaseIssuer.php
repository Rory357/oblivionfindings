<?php

namespace App\Domain\SecurityDevices\Credentials\Contracts;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;

interface SecretManagerLeaseIssuer
{
    public function issue(SecretLeaseRequest $request): CredentialLease;

    public function revoke(#[\SensitiveParameter] string $leaseId): void;
}
