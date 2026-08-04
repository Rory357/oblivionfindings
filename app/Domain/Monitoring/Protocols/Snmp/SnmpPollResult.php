<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use App\Domain\Monitoring\Data\ProtocolObservation;
use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use Carbon\CarbonImmutable;
use JsonSerializable;
use RuntimeException;

final readonly class SnmpPollResult implements JsonSerializable
{
    /**
     * @param  list<SnmpInterfaceSample>  $interfaces
     * @param  list<SnmpSensorSample>  $sensors
     * @param  list<SnmpTopologyObservation>  $topologyObservations
     * @param  list<string>  $topologyCompletedSources
     */
    public function __construct(
        public ProtocolObservation $summary,
        public array $interfaces,
        public array $sensors,
        public ?DiscoveredIdentity $identity,
        public int $uptimeTicks,
        public CarbonImmutable $observedAt,
        public array $topologyObservations = [],
        public array $topologyCompletedSources = [],
    ) {}

    /** @param array<string, int|float|string|bool|null> $previous */
    public function interfaceObservation(int $ifIndex, array $previous = []): ProtocolObservation
    {
        $sample = collect($this->interfaces)->first(
            fn (SnmpInterfaceSample $candidate): bool => $candidate->ifIndex === $ifIndex,
        );
        if (! $sample instanceof SnmpInterfaceSample) {
            throw new RuntimeException('SNMP interface was not present in the bounded poll result.');
        }

        return $sample->observation($this->observedAt, $this->uptimeTicks, $previous);
    }

    public function sensorObservation(int $index): ProtocolObservation
    {
        $sample = collect($this->sensors)->first(
            fn (SnmpSensorSample $candidate): bool => $candidate->index === $index,
        );
        if (! $sample instanceof SnmpSensorSample) {
            throw new RuntimeException('SNMP sensor was not present in the bounded poll result.');
        }

        return $sample->observation($this->observedAt);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'summary' => $this->summary,
            'interfaces' => $this->interfaces,
            'sensors' => $this->sensors,
            'identity' => $this->identity?->snapshot(),
            'uptime_ticks' => $this->uptimeTicks,
            'observed_at' => $this->observedAt->toISOString(),
            'topology_observation_count' => count($this->topologyObservations),
            'topology_completed_sources' => $this->topologyCompletedSources,
        ];
    }
}
