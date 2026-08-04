<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use App\Domain\Monitoring\Data\ProtocolObservation;
use App\Domain\Monitoring\Enums\MonitorState;
use Carbon\CarbonImmutable;

final readonly class SnmpSensorSample
{
    public function __construct(
        public int $index,
        public string $name,
        public string $type,
        public float $value,
        public string $unit,
        public string $status,
    ) {}

    public function observation(CarbonImmutable $observedAt): ProtocolObservation
    {
        $healthy = $this->status === 'ok';

        return new ProtocolObservation(
            state: $healthy ? MonitorState::Healthy : MonitorState::Degraded,
            observedAt: $observedAt,
            value: $this->value,
            unit: $this->unit,
            latencyMs: null,
            reasonCode: $healthy ? 'sensor_ok' : 'sensor_status_abnormal',
            evidence: [
                'sensor_index' => $this->index,
                'sensor_name' => $this->name,
                'sensor_type' => $this->type,
                'sensor_status' => $this->status,
            ],
        );
    }
}
