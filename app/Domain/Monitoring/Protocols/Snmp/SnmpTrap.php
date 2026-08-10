<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use JsonSerializable;

final readonly class SnmpTrap implements JsonSerializable
{
    public function __construct(
        public string $version,
        public int $requestId,
        public string $trapOid,
        public ?int $uptimeTicks,
        public ?string $systemName,
        public ?int $ifIndex,
        public ?string $ifName,
        public int $varbindCount,
        public ?string $engineId,
        public ?int $engineBoots,
        public ?int $engineTime,
    ) {}

    /** @return array<string, int|string|null> */
    public function jsonSerialize(): array
    {
        return [
            'version' => $this->version,
            'request_id' => $this->requestId,
            'trap_oid' => $this->trapOid,
            'uptime_ticks' => $this->uptimeTicks,
            'system_name' => $this->systemName,
            'if_index' => $this->ifIndex,
            'if_name' => $this->ifName,
            'varbind_count' => $this->varbindCount,
            'engine_id_hash' => $this->engineId === null ? null : hash('sha256', $this->engineId),
            'engine_boots' => $this->engineBoots,
            'engine_time' => $this->engineTime,
        ];
    }
}
