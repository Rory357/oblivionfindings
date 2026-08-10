<?php

namespace App\Domain\Monitoring\Protocols\Flow;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

final class FlowAggregator
{
    public const int MAX_BUCKETS = 256;

    public function aggregate(
        int $siteId,
        string $exporterAddress,
        FlowDatagram $datagram,
        ?CarbonInterface $receivedAt = null,
    ): FlowAggregate {
        if ($siteId < 1 || $exporterAddress !== $datagram->exporterAddress) {
            throw new RuntimeException('Flow aggregation scope is invalid.');
        }
        $bucketStart = CarbonImmutable::instance($receivedAt ?? $datagram->exportedAt)
            ->utc()
            ->startOfMinute()
            ->format('Y-m-d\TH:i:s.u\Z');
        $buckets = [];
        foreach ($datagram->records as $record) {
            $application = $this->application($record->sourcePort, $record->destinationPort, $record->protocol);
            $direction = $record->inputInterface !== null ? 'ingress' : 'unknown';
            $key = implode('|', [
                $record->inputInterface ?? '-',
                $record->outputInterface ?? '-',
                $direction,
                $record->protocol,
                $application,
            ]);
            if (! array_key_exists($key, $buckets)) {
                if (count($buckets) >= self::MAX_BUCKETS) {
                    throw new RuntimeException('Flow aggregation bucket limit is exceeded.');
                }
                $buckets[$key] = [
                    'application' => $application,
                    'bucket_start' => $bucketStart,
                    'bytes' => 0,
                    'direction' => $direction,
                    'flow_count' => 0,
                    'input_interface' => $record->inputInterface,
                    'output_interface' => $record->outputInterface,
                    'packets' => 0,
                    'protocol' => $record->protocol,
                ];
            }
            $buckets[$key]['bytes'] += $record->bytes;
            $buckets[$key]['packets'] += $record->packets;
            $buckets[$key]['flow_count']++;
        }
        ksort($buckets, SORT_STRING);

        return new FlowAggregate(
            siteId: $siteId,
            exporterAddress: $exporterAddress,
            family: $datagram->family,
            sourceId: $datagram->sourceId,
            sequence: $datagram->sequence,
            buckets: array_values($buckets),
        );
    }

    public function sequenceHealth(FlowDatagram $previous, FlowDatagram $current): FlowSequenceHealth
    {
        if ($previous->family !== $current->family
            || $previous->exporterAddress !== $current->exporterAddress
            || $previous->sourceId !== $current->sourceId) {
            throw new RuntimeException('Flow sequence scope is invalid.');
        }
        if ($previous->uptimeMillis !== null && $current->uptimeMillis !== null
            && $current->uptimeMillis < $previous->uptimeMillis) {
            return new FlowSequenceHealth('reset', null, $current->sequence);
        }

        $expected = ($previous->sequence + $previous->sequenceIncrement()) % 4_294_967_296;
        if ($current->sequence === $expected) {
            return new FlowSequenceHealth('ok', $expected, $current->sequence);
        }
        $distance = ($current->sequence - $expected + 4_294_967_296) % 4_294_967_296;
        if ($distance > 0 && $distance < 2_147_483_648) {
            return new FlowSequenceHealth('gap', $expected, $current->sequence, $distance);
        }

        return new FlowSequenceHealth('out_of_order', $expected, $current->sequence);
    }

    private function application(?int $sourcePort, ?int $destinationPort, int $protocol): string
    {
        $port = $destinationPort ?? $sourcePort;
        if ($protocol === 1 || $protocol === 58) {
            return 'icmp';
        }

        return match ($port) {
            22 => 'ssh',
            25, 465, 587 => 'smtp',
            53 => 'dns',
            67, 68, 546, 547 => 'dhcp',
            80, 8080 => 'http',
            123 => 'ntp',
            161, 162 => 'snmp',
            389, 636 => 'ldap',
            443, 8443 => 'https',
            445 => 'smb',
            3389 => 'rdp',
            default => 'other',
        };
    }
}
