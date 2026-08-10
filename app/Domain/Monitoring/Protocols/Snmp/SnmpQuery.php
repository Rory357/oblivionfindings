<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use InvalidArgumentException;

final readonly class SnmpQuery
{
    public const int MAX_OIDS = 128;

    public const int MAX_VARBINDS = 4096;

    /**
     * @param  list<string>  $scalarOids
     * @param  list<string>  $walkRoots
     * @param  list<string>  $optionalWalkRoots
     */
    public function __construct(
        public string $version,
        public array $scalarOids,
        public array $walkRoots,
        public array $optionalWalkRoots = [],
        public int $maxVarbinds = self::MAX_VARBINDS,
    ) {
        if (! in_array($version, ['v1', 'v2c', 'v3'], true)
            || $scalarOids === []
            || count($scalarOids) + count($walkRoots) + count($optionalWalkRoots) > self::MAX_OIDS
            || $maxVarbinds < 1
            || $maxVarbinds > self::MAX_VARBINDS) {
            throw new InvalidArgumentException('SNMP query is invalid.');
        }

        foreach ([...$scalarOids, ...$walkRoots, ...$optionalWalkRoots] as $oid) {
            if (! is_string($oid) || preg_match('/^\d+(?:\.\d+)+$/', $oid) !== 1) {
                throw new InvalidArgumentException('SNMP query OID is invalid.');
            }
        }
    }

    public static function inventory(string $version): self
    {
        return new self(
            version: $version,
            scalarOids: [
                '1.3.6.1.2.1.1.1.0',
                '1.3.6.1.2.1.1.2.0',
                '1.3.6.1.2.1.1.3.0',
                '1.3.6.1.2.1.1.5.0',
            ],
            walkRoots: [
                '1.3.6.1.2.1.2.2.1',
                '1.3.6.1.2.1.31.1.1.1',
                '1.3.6.1.2.1.47.1.1.1.1',
                '1.3.6.1.2.1.99.1.1.1',
            ],
            optionalWalkRoots: [
                // LLDP local/remote port and neighbour tables.
                '1.0.8802.1.1.2.1.3.7.1',
                '1.0.8802.1.1.2.1.4.1.1',
                // Cisco Discovery Protocol neighbour cache.
                '1.3.6.1.4.1.9.9.23.1.2.1.1',
                // Modern and legacy ARP/ND neighbour tables.
                '1.3.6.1.2.1.4.35.1',
                '1.3.6.1.2.1.4.22.1',
                // Bridge forwarding and bridge-port-to-interface tables.
                '1.3.6.1.2.1.17.1.4.1',
                '1.3.6.1.2.1.17.4.3.1',
                // CIDR and legacy route tables.
                '1.3.6.1.2.1.4.24.4.1',
                '1.3.6.1.2.1.4.21.1',
            ],
        );
    }
}
