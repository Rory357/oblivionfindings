<?php

namespace App\Domain\Monitoring\Data;

use App\Domain\Monitoring\Models\MonitoringCollector;

final readonly class CollectorEnrollmentResult
{
    public function __construct(
        public MonitoringCollector $collector,
        public CollectorCertificateBundle $certificate,
        public string $centralSigningPublicKey,
    ) {}
}
