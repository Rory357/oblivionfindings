<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

interface SnmpCompatibilityAuthorizer
{
    public function authorize(int $siteId, int $deviceId, string $version, string $credentialReference): void;
}
