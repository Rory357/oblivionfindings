<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use RuntimeException;

final class SnmpTrapIntakeService
{
    public function __construct(
        private readonly CidrMatcher $cidrs,
        private readonly SnmpTrapScopeResolver $scopes,
        private readonly SnmpCompatibilityAuthorizer $compatibility,
        private readonly CredentialLeaseProvider $credentials,
        private readonly SnmpTrapDecoder $decoder,
        private readonly SnmpEngineReplayGuard $replayGuard,
        private readonly MonitoringOutboxPublisher $outbox,
    ) {}

    public function ingest(string $datagram, string $senderAddress): MonitoringOutbox
    {
        $maximum = (int) config('monitoring.snmp.traps.max_datagram_bytes', 65_507);
        if ($maximum !== 65_507 || strlen($datagram) > $maximum) {
            throw new RuntimeException('SNMP trap datagram exceeds the configured limit.');
        }
        if ($datagram === '') {
            throw new RuntimeException('SNMP trap datagram is invalid.');
        }
        try {
            $senderAddress = $this->cidrs->canonicalAddress($senderAddress);
        } catch (\Throwable) {
            throw new RuntimeException('SNMP trap sender address is invalid.');
        }
        $scope = $this->scopes->resolve($senderAddress);
        $version = $this->decoder->version($datagram);
        $capabilities = ['snmp:trap:v3:auth_priv'];
        if ($version !== 'v3') {
            if ($scope->candidateDeviceId === null) {
                throw new RuntimeException('SNMP compatibility exception is not active.');
            }
            $this->compatibility->authorize(
                $scope->siteId,
                $scope->candidateDeviceId,
                $version,
                $scope->credentialReference,
            );
            $capabilities = ["snmp:trap:{$version}:compatibility"];
        }

        $lease = $this->credentials->acquire(
            $scope->siteId,
            $scope->credentialReference,
            $capabilities,
        );
        $trap = $this->decoder->decode($datagram, $lease);
        if ($trap->version !== $version) {
            throw new RuntimeException('SNMP trap version changed during validation.');
        }
        $this->replayGuard->accept($scope->siteId, $senderAddress, $trap);
        [$eventType, $severity] = $this->eventClassification($trap->trapOid);
        $payload = array_filter([
            'event_family' => 'snmp_trap',
            'site_id' => $scope->siteId,
            'source_address' => $senderAddress,
            'version' => $trap->version,
            'request_id' => $trap->requestId,
            'trap_oid' => $trap->trapOid,
            'uptime_ticks' => $trap->uptimeTicks,
            'system_name' => $trap->systemName,
            'if_index' => $trap->ifIndex,
            'if_name' => $trap->ifName,
            'varbind_count' => $trap->varbindCount,
            'event_type' => $eventType,
            'severity' => $severity,
        ], fn (mixed $value): bool => $value !== null);
        $sourceHash = substr(hash('sha256', $senderAddress), 0, 24);

        return $this->outbox->stage(
            type: RuntimeMessageType::Event,
            stream: (string) config('monitoring.queues.events', 'monitoring-events'),
            source: "central:snmp-traps:site:{$scope->siteId}:{$sourceHash}",
            idempotencyKey: 'snmp-trap:'.hash('sha256', $datagram),
            payload: $payload,
        );
    }

    /** @return array{string, string} */
    private function eventClassification(string $trapOid): array
    {
        return match ($trapOid) {
            '1.3.6.1.6.3.1.1.5.1', '1.3.6.1.6.3.1.1.5.2' => ['config_changed', 'info'],
            '1.3.6.1.6.3.1.1.5.3' => ['offline', 'warning'],
            '1.3.6.1.6.3.1.1.5.4' => ['online', 'info'],
            '1.3.6.1.6.3.1.1.5.5' => ['signal', 'warning'],
            '1.3.6.1.6.3.1.1.5.6' => ['tamper', 'critical'],
            default => ['signal', 'warning'],
        };
    }
}
