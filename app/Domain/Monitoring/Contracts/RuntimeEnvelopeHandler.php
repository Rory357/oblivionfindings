<?php

namespace App\Domain\Monitoring\Contracts;

use App\Domain\Monitoring\Data\RuntimeEnvelope;

interface RuntimeEnvelopeHandler
{
    public function handle(RuntimeEnvelope $envelope, ?int $trustedSiteId = null): void;
}
