<?php

namespace App\Domain\Monitoring\Protocols\Syslog;

use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Protocols\InboundTelemetryScopeResolver;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use RuntimeException;
use Throwable;

final class SyslogIntakeService
{
    public function __construct(
        private readonly CidrMatcher $cidrs,
        private readonly InboundTelemetryScopeResolver $scopes,
        private readonly SyslogDecoder $decoder,
        private readonly MonitoringOutboxPublisher $outbox,
    ) {}

    /**
     * @return array{site_id: int, source: string, idempotency_key: string, payload: array<string, mixed>, envelope: RuntimeEnvelope}
     */
    public function buildCollectorEnvelope(
        MonitoringCollector $collector,
        int $siteId,
        string $datagram,
        string $senderAddress,
        int $sequence,
        string $itemId,
    ): array {
        $maximum = (int) config('monitoring.inbound.syslog.max_datagram_bytes', SyslogDecoder::MAX_DATAGRAM_BYTES);
        if ($maximum !== SyslogDecoder::MAX_DATAGRAM_BYTES || $datagram === '' || strlen($datagram) > $maximum) {
            throw new RuntimePayloadInvalid('Collector Syslog datagram exceeds the configured limit.');
        }

        $scope = $this->scopes->resolveForCollector($collector, $siteId, $senderAddress, 'syslog');
        $canonicalSender = $this->cidrs->canonicalAddress($senderAddress);

        try {
            $message = $this->decoder->decode($datagram);
        } catch (Throwable $exception) {
            throw new RuntimePayloadInvalid('Collector Syslog datagram could not be decoded.', previous: $exception);
        }

        $sourceHash = substr(hash('sha256', $canonicalSender), 0, 24);
        $source = "collector:{$collector->collector_uuid}:syslog:site:{$scope->siteId}:{$sourceHash}";
        $idempotencyKey = "collector-syslog:{$collector->collector_uuid}:{$itemId}";
        $payload = [
            ...$message->payload(),
            'event_family' => 'syslog',
            'site_id' => $scope->siteId,
            'source_address' => $canonicalSender,
        ];

        return [
            'site_id' => $scope->siteId,
            'source' => $source,
            'idempotency_key' => $idempotencyKey,
            'payload' => $payload,
            'envelope' => RuntimeEnvelope::new(
                type: RuntimeMessageType::Event,
                source: $source,
                sequence: max(1, $sequence),
                idempotencyKey: $idempotencyKey,
                payload: $payload,
            ),
        ];
    }

    public function ingestFromCollector(
        MonitoringCollector $collector,
        int $siteId,
        string $datagram,
        string $senderAddress,
        string $itemId,
    ): MonitoringOutbox {
        $built = $this->buildCollectorEnvelope(
            collector: $collector,
            siteId: $siteId,
            datagram: $datagram,
            senderAddress: $senderAddress,
            sequence: 1,
            itemId: $itemId,
        );

        return $this->outbox->stage(
            type: RuntimeMessageType::Event,
            stream: (string) config('monitoring.queues.events', 'monitoring-events'),
            source: $built['source'],
            idempotencyKey: $built['idempotency_key'],
            payload: $built['payload'],
        );
    }

    public function ingest(string $datagram, string $senderAddress): MonitoringOutbox
    {
        $maximum = (int) config('monitoring.inbound.syslog.max_datagram_bytes', SyslogDecoder::MAX_DATAGRAM_BYTES);
        if ($maximum !== SyslogDecoder::MAX_DATAGRAM_BYTES || $datagram === '' || strlen($datagram) > $maximum) {
            throw new RuntimeException('Syslog datagram exceeds the configured limit.');
        }
        try {
            $senderAddress = $this->cidrs->canonicalAddress($senderAddress);
        } catch (Throwable) {
            throw new RuntimeException('Syslog sender address is invalid.');
        }
        $scope = $this->scopes->resolve($senderAddress, 'syslog');
        $message = $this->decoder->decode($datagram);
        $sourceHash = substr(hash('sha256', $senderAddress), 0, 24);

        return $this->outbox->stage(
            type: RuntimeMessageType::Event,
            stream: (string) config('monitoring.queues.events', 'monitoring-events'),
            source: "central:syslog:site:{$scope->siteId}:{$sourceHash}",
            idempotencyKey: 'syslog:'.$message->rawHash,
            payload: [
                ...$message->payload(),
                'event_family' => 'syslog',
                'site_id' => $scope->siteId,
                'source_address' => $senderAddress,
            ],
        );
    }
}
