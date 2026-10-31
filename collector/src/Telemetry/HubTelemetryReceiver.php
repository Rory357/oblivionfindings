<?php

namespace Oblivion\Collector\Telemetry;

use DateTimeImmutable;
use Oblivion\Collector\Data\CollectorConfig;
use Oblivion\Collector\Exceptions\ScopeViolation;
use Oblivion\Collector\Security\ScopeGuard;
use Oblivion\Collector\Spool\EncryptedSpool;
use RuntimeException;

/**
 * Receives private-LAN Syslog (RFC 5424 / RFC 3164) and Flow (NetFlow v5/v9, IPFIX, sFlow v5)
 * UDP datagrams on a Site hub, enforces signed network scope, and spools them for Central upload.
 */
final class HubTelemetryReceiver
{
    public const int MAX_SYSLOG_BYTES = 8192;

    public const int MAX_FLOW_BYTES = 65507;

    public function __construct(
        private readonly CollectorConfig $config,
        private readonly ScopeGuard $scopeGuard,
        private readonly EncryptedSpool $spool,
    ) {}

    public function spoolSyslogDatagram(
        int $siteId,
        string $senderIp,
        string $datagram,
        ?DateTimeImmutable $at = null,
    ): string {
        $at ??= new DateTimeImmutable('now');
        $this->assertSiteAllowed($siteId);
        if ($datagram === '' || strlen($datagram) > self::MAX_SYSLOG_BYTES) {
            throw new ScopeViolation('Inbound Syslog datagram is empty or exceeds 8192 bytes.');
        }

        $this->scopeGuard->assertInboundTelemetrySender('syslog', $senderIp, $at);

        $sequence = $this->spool->nextSourceSequence();
        $id = 'syslog-'.$siteId.'-'.$sequence.'-'.substr(hash('sha256', $senderIp.'|'.$datagram.'|'.$at->format('U.u')), 0, 16);

        $this->spool->append($id, $sequence, [
            'item_type' => 'syslog_event',
            'site_id' => $siteId,
            'sender_ip' => $senderIp,
            'received_at' => $at->format(DATE_ATOM),
            'datagram_base64' => base64_encode($datagram),
        ], $at);

        return $id;
    }

    public function spoolFlowDatagram(
        int $siteId,
        string $senderIp,
        string $packet,
        ?DateTimeImmutable $at = null,
    ): string {
        $at ??= new DateTimeImmutable('now');
        $this->assertSiteAllowed($siteId);
        if (strlen($packet) < 4 || strlen($packet) > self::MAX_FLOW_BYTES) {
            throw new ScopeViolation('Inbound Flow datagram is truncated or exceeds 65507 bytes.');
        }

        $this->scopeGuard->assertInboundTelemetrySender('flow', $senderIp, $at);

        $sequence = $this->spool->nextSourceSequence();
        $id = 'flow-'.$siteId.'-'.$sequence.'-'.substr(hash('sha256', $senderIp.'|'.$packet.'|'.$at->format('U.u')), 0, 16);

        $this->spool->append($id, $sequence, [
            'item_type' => 'flow_datagram',
            'site_id' => $siteId,
            'sender_ip' => $senderIp,
            'received_at' => $at->format(DATE_ATOM),
            'packet_base64' => base64_encode($packet),
        ], $at);

        return $id;
    }

    /**
     * Drain up to `$maxPackets` UDP datagrams from a non-blocking stream socket bound on the Site hub.
     *
     * @param  resource  $udpSocket
     * @return array{accepted: int, dropped_scope: int, item_ids: list<string>}
     */
    public function drainUdpSocket(
        $udpSocket,
        string $protocol,
        int $siteId,
        int $maxPackets = 64,
        ?DateTimeImmutable $at = null,
    ): array {
        if (! is_resource($udpSocket)) {
            throw new RuntimeException('Hub telemetry UDP socket is invalid.');
        }
        if (! in_array($protocol, ['syslog', 'flow'], true)) {
            throw new ScopeViolation('Unsupported hub UDP telemetry protocol.');
        }

        stream_set_blocking($udpSocket, false);
        $maxPackets = max(1, min(512, $maxPackets));
        $maxReadBytes = $protocol === 'syslog' ? self::MAX_SYSLOG_BYTES + 1 : self::MAX_FLOW_BYTES;

        $accepted = 0;
        $dropped = 0;
        $ids = [];

        for ($i = 0; $i < $maxPackets; $i++) {
            $peer = '';
            $datagram = @stream_socket_recvfrom($udpSocket, $maxReadBytes, 0, $peer);
            if (! is_string($datagram) || $datagram === '') {
                break;
            }

            $senderIp = $this->extractPeerIp((string) $peer);
            if ($senderIp === null) {
                $dropped++;

                continue;
            }

            try {
                $ids[] = $protocol === 'syslog'
                    ? $this->spoolSyslogDatagram($siteId, $senderIp, $datagram, $at)
                    : $this->spoolFlowDatagram($siteId, $senderIp, $datagram, $at);
                $accepted++;
            } catch (ScopeViolation) {
                $dropped++;
            }
        }

        return [
            'accepted' => $accepted,
            'dropped_scope' => $dropped,
            'item_ids' => $ids,
        ];
    }

    private function assertSiteAllowed(int $siteId): void
    {
        if (! in_array($siteId, $this->config->allowedSiteIds(), true)) {
            throw new ScopeViolation('Telemetry Site is outside the signed collector scope.');
        }
    }

    private function extractPeerIp(string $peer): ?string
    {
        $trimmed = trim($peer);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '[')) {
            $end = strpos($trimmed, ']');
            if ($end === false) {
                return null;
            }
            $ip = substr($trimmed, 1, $end - 1);

            return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
        }

        $lastColon = strrpos($trimmed, ':');
        $ip = $lastColon !== false ? substr($trimmed, 0, $lastColon) : $trimmed;

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }
}
