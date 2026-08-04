<?php

namespace App\Domain\Monitoring\Data;

use Carbon\CarbonImmutable;

final readonly class CollectorCertificateBundle
{
    public function __construct(
        public string $certificatePem,
        public string $privateKeyPem,
        public string $fingerprint,
        public CarbonImmutable $expiresAt,
    ) {}
}
