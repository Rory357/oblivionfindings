<?php

namespace App\Domain\Monitoring\Protocols\Syslog;

use App\Domain\Monitoring\Enums\RuntimeMessageType;
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
