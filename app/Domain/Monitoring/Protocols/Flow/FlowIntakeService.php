<?php

namespace App\Domain\Monitoring\Protocols\Flow;

use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Protocols\InboundTelemetryScopeResolver;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use RuntimeException;
use Throwable;

final class FlowIntakeService
{
    public function __construct(
        private readonly CidrMatcher $cidrs,
        private readonly InboundTelemetryScopeResolver $scopes,
        private readonly NetFlowV5Decoder $netFlowV5,
        private readonly NetFlowV9Decoder $netFlowV9,
        private readonly IpfixDecoder $ipfix,
        private readonly SflowV5Decoder $sflowV5,
        private readonly FlowAggregator $aggregator,
        private readonly FlowExporterSequenceGuard $sequences,
        private readonly MonitoringOutboxPublisher $outbox,
    ) {}

    /** @return list<MonitoringOutbox> */
    public function ingest(string $packet, string $senderAddress): array
    {
        $maximum = (int) config('monitoring.inbound.flow.max_datagram_bytes', 65_507);
        if ($maximum !== 65_507 || $packet === '' || strlen($packet) > $maximum) {
            throw new RuntimeException('Flow datagram exceeds the configured limit.');
        }
        try {
            $senderAddress = $this->cidrs->canonicalAddress($senderAddress);
        } catch (Throwable) {
            throw new RuntimeException('Flow sender address is invalid.');
        }
        $scope = $this->scopes->resolve($senderAddress, 'flow');
        $datagram = $this->decode($packet, $senderAddress);
        $packetHash = hash('sha256', $packet);
        $health = $this->sequences->accept($scope->siteId, $senderAddress, $datagram, $packetHash);
        $outboxes = [];
        $sourceHash = substr(hash('sha256', $senderAddress), 0, 24);
        $source = "central:flow:site:{$scope->siteId}:{$sourceHash}";
        if ($datagram->records !== []) {
            $aggregate = $this->aggregator->aggregate($scope->siteId, $senderAddress, $datagram);
            $outboxes[] = $this->outbox->stage(
                type: RuntimeMessageType::Event,
                stream: (string) config('monitoring.queues.events', 'monitoring-events'),
                source: $source,
                idempotencyKey: 'flow-metric:'.$packetHash,
                payload: [
                    'event_family' => 'flow_metric',
                    'site_id' => $scope->siteId,
                    'source_address' => $senderAddress,
                    'protocol_family' => $aggregate->family,
                    'source_id' => $aggregate->sourceId,
                    'sequence' => $aggregate->sequence,
                    'buckets' => $aggregate->buckets,
                ],
            );
        }
        if (in_array($health->status, ['gap', 'reset', 'out_of_order'], true)) {
            $classification = match ($health->status) {
                'reset' => ['exporter_reset', 'config_changed', 'info'],
                'out_of_order' => ['sequence_out_of_order', 'signal', 'warning'],
                default => ['sequence_gap', 'signal', 'warning'],
            };
            $outboxes[] = $this->outbox->stage(
                type: RuntimeMessageType::Event,
                stream: (string) config('monitoring.queues.events', 'monitoring-events'),
                source: $source,
                idempotencyKey: 'flow-health:'.$packetHash,
                payload: array_filter([
                    'event_family' => 'flow_health',
                    'site_id' => $scope->siteId,
                    'source_address' => $senderAddress,
                    'protocol_family' => $datagram->family,
                    'source_id' => $datagram->sourceId,
                    'reason' => $classification[0],
                    'expected_sequence' => $health->expectedSequence,
                    'actual_sequence' => $health->actualSequence,
                    'gap_count' => $health->gapCount,
                    'event_type' => $classification[1],
                    'severity' => $classification[2],
                ], fn (mixed $value): bool => $value !== null),
            );
        }

        return $outboxes;
    }

    private function decode(string $packet, string $senderAddress): FlowDatagram
    {
        if (strlen($packet) < 4) {
            throw new RuntimeException('Flow datagram is truncated.');
        }
        $version16 = unpack('nversion', substr($packet, 0, 2))['version'];
        if ($version16 === 5) {
            return $this->netFlowV5->decode($packet, $senderAddress);
        }
        if ($version16 === 9) {
            return $this->netFlowV9->decode($packet, $senderAddress);
        }
        if ($version16 === 10) {
            return $this->ipfix->decode($packet, $senderAddress);
        }
        if (unpack('Nversion', substr($packet, 0, 4))['version'] === 5) {
            return $this->sflowV5->decode($packet, $senderAddress);
        }

        throw new RuntimeException('Flow datagram version is unsupported.');
    }
}
