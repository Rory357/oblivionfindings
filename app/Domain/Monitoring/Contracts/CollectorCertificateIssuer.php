<?php

namespace App\Domain\Monitoring\Contracts;

use App\Domain\Monitoring\Data\CollectorCertificateBundle;

interface CollectorCertificateIssuer
{
    public function issue(string $collectorUuid): CollectorCertificateBundle;
}
