<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

final readonly class SnmpTrapScope
{
    public function __construct(
        public int $siteId,
        public int $scopeId,
        public string $credentialReference,
        public ?int $candidateDeviceId,
    ) {}
}
