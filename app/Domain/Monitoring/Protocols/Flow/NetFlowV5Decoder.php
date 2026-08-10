<?php

namespace App\Domain\Monitoring\Protocols\Flow;

use Carbon\CarbonImmutable;
use RuntimeException;

final class NetFlowV5Decoder
{
    public function decode(string $packet, string $exporterAddress): FlowDatagram
    {
        if ($packet === '' || strlen($packet) > 65_507 || filter_var($exporterAddress, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('NetFlow v5 packet is invalid.');
        }
        $reader = new FlowBinaryReader($packet);
        if ($reader->uint16() !== 5) {
            throw new RuntimeException('NetFlow v5 version is unsupported.');
        }
        $count = $reader->uint16();
        if ($count > 1000) {
            throw new RuntimeException('NetFlow v5 record limit is exceeded.');
        }
        $uptime = $reader->uint32();
        $seconds = $reader->uint32();
        $nanoseconds = $reader->uint32();
        $sequence = $reader->uint32();
        $engineType = $reader->uint8();
        $engineId = $reader->uint8();
        $reader->uint16();
        if ($seconds < 1 || $nanoseconds >= 1_000_000_000) {
            throw new RuntimeException('NetFlow v5 export timestamp is invalid.');
        }

        $records = [];
        for ($index = 0; $index < $count; $index++) {
            $source = inet_ntop($reader->read(4));
            $destination = inet_ntop($reader->read(4));
            $reader->skip(4);
            $input = $reader->uint16();
            $output = $reader->uint16();
            $packets = $reader->uint32();
            $bytes = $reader->uint32();
            $reader->skip(8);
            $sourcePort = $reader->uint16();
            $destinationPort = $reader->uint16();
            $reader->skip(1);
            $reader->skip(1);
            $protocol = $reader->uint8();
            $reader->skip(1);
            $reader->skip(8);
            if (! is_string($source) || ! is_string($destination)) {
                throw new RuntimeException('NetFlow v5 address is invalid.');
            }
            $records[] = new FlowRecord(
                sourceIp: $source,
                destinationIp: $destination,
                sourcePort: $sourcePort,
                destinationPort: $destinationPort,
                protocol: $protocol,
                bytes: $bytes,
                packets: $packets,
                inputInterface: $input,
                outputInterface: $output,
            );
        }
        $reader->assertFinished();

        return new FlowDatagram(
            family: 'netflow-v5',
            exporterAddress: $exporterAddress,
            sourceId: ($engineType << 8) | $engineId,
            sequence: $sequence,
            uptimeMillis: $uptime,
            exportedAt: CarbonImmutable::createFromTimestampUTC($seconds)->addMicroseconds(intdiv($nanoseconds, 1000)),
            records: $records,
        );
    }
}
