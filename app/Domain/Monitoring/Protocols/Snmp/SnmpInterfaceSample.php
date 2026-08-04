<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use App\Domain\Monitoring\Data\ProtocolObservation;
use App\Domain\Monitoring\Enums\MonitorState;
use Carbon\CarbonImmutable;

final readonly class SnmpInterfaceSample
{
    public function __construct(
        public int $ifIndex,
        public string $name,
        public ?string $alias,
        public string $adminStatus,
        public string $operStatus,
        public int $speedBps,
        public int $inOctets,
        public int $outOctets,
        public int $counterBits,
        public int $inErrors,
        public int $outErrors,
        public int $inDiscards,
        public int $outDiscards,
        public int $discontinuityTicks,
    ) {}

    /** @param array<string, int|float|string|bool|null> $previous */
    public function observation(
        CarbonImmutable $observedAt,
        int $uptimeTicks,
        array $previous = [],
    ): ProtocolObservation {
        $rates = (new SnmpCounterNormalizer)->rates(
            currentIn: $this->inOctets,
            currentOut: $this->outOctets,
            previous: $previous,
            observedUnix: $observedAt->timestamp,
            uptimeTicks: $uptimeTicks,
            discontinuityTicks: $this->discontinuityTicks,
            counterBits: $this->counterBits,
            speedBps: $this->speedBps,
        );
        [$state, $reason] = match (true) {
            $this->adminStatus === 'down' => [MonitorState::Healthy, 'interface_administratively_down'],
            $this->adminStatus === 'up' && $this->operStatus === 'up' => [MonitorState::Healthy, 'interface_up'],
            $this->adminStatus === 'up' && $this->operStatus === 'down' => [MonitorState::Failed, 'interface_down'],
            default => [MonitorState::Degraded, 'interface_state_unknown'],
        };
        $maximumUtilisation = collect([
            $rates['in_utilization_pct'],
            $rates['out_utilization_pct'],
        ])->filter(fn (mixed $value): bool => is_float($value) || is_int($value))->max();

        return new ProtocolObservation(
            state: $state,
            observedAt: $observedAt,
            value: is_numeric($maximumUtilisation) ? (float) $maximumUtilisation : null,
            unit: is_numeric($maximumUtilisation) ? 'percent' : null,
            latencyMs: null,
            reasonCode: $reason,
            evidence: [
                'if_index' => $this->ifIndex,
                'interface_name' => $this->name,
                'interface_alias' => $this->alias,
                'admin_status' => $this->adminStatus,
                'operational_status' => $this->operStatus,
                'speed_bps' => $this->speedBps,
                'counter_in_octets' => $this->inOctets,
                'counter_out_octets' => $this->outOctets,
                'counter_bits' => $this->counterBits,
                'uptime_ticks' => $uptimeTicks,
                'counter_discontinuity_ticks' => $this->discontinuityTicks,
                'observed_unix' => $observedAt->timestamp,
                'in_bps' => $rates['in_bps'],
                'out_bps' => $rates['out_bps'],
                'in_utilization_pct' => $rates['in_utilization_pct'],
                'out_utilization_pct' => $rates['out_utilization_pct'],
                'counter_discontinuity' => $rates['counter_discontinuity'],
                'errors' => $this->inErrors + $this->outErrors,
                'discards' => $this->inDiscards + $this->outDiscards,
            ],
        );
    }
}
