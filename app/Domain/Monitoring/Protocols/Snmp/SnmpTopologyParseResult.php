<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use InvalidArgumentException;

final readonly class SnmpTopologyParseResult
{
    /**
     * @param  list<SnmpTopologyObservation>  $observations
     * @param  list<string>  $completedSources
     */
    public function __construct(
        public array $observations,
        public array $completedSources,
    ) {
        if (! array_is_list($observations)
            || collect($observations)->contains(fn (mixed $item): bool => ! $item instanceof SnmpTopologyObservation)
            || ! array_is_list($completedSources)
            || collect($completedSources)->unique()->count() !== count($completedSources)
            || collect($completedSources)->contains(
                fn (mixed $source): bool => ! is_string($source)
                    || ! in_array($source, ['lldp', 'cdp', 'arp', 'forwarding_table', 'route'], true),
            )) {
            throw new InvalidArgumentException('SNMP topology parse result is invalid.');
        }
    }
}
