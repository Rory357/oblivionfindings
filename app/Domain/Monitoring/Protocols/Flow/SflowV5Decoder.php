<?php

namespace App\Domain\Monitoring\Protocols\Flow;

use Carbon\CarbonImmutable;
use RuntimeException;

final class SflowV5Decoder
{
    public function decode(string $packet, string $exporterAddress): FlowDatagram
    {
        if ($packet === '' || strlen($packet) > 65_507 || filter_var($exporterAddress, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('sFlow v5 packet is invalid.');
        }
        $reader = new FlowBinaryReader($packet);
        if ($reader->uint32() !== 5) {
            throw new RuntimeException('sFlow v5 version is unsupported.');
        }
        $addressType = $reader->uint32();
        $agentBytes = match ($addressType) {
            1 => $reader->read(4),
            2 => $reader->read(16),
            default => throw new RuntimeException('sFlow agent address type is unsupported.'),
        };
        if (! is_string(inet_ntop($agentBytes))) {
            throw new RuntimeException('sFlow agent address is invalid.');
        }
        $sourceId = $reader->uint32();
        $sequence = $reader->uint32();
        $uptime = $reader->uint32();
        $sampleCount = $reader->uint32();
        if ($sampleCount > 1000) {
            throw new RuntimeException('sFlow sample limit is exceeded.');
        }

        $records = [];
        for ($sampleIndex = 0; $sampleIndex < $sampleCount; $sampleIndex++) {
            $sampleType = $reader->uint32();
            $sampleLength = $reader->uint32();
            $sample = $reader->subReader($sampleLength);
            $enterprise = $sampleType >> 12;
            $format = $sampleType & 0x0FFF;
            if ($enterprise !== 0 || ! in_array($format, [1, 3], true)) {
                $sample->skip($sample->remaining());
                $sample->assertFinished();

                continue;
            }
            $this->decodeFlowSample($sample, $format, $records);
            $sample->assertFinished();
        }
        $reader->assertFinished();

        return new FlowDatagram(
            family: 'sflow-v5',
            exporterAddress: $exporterAddress,
            sourceId: $sourceId,
            sequence: $sequence,
            uptimeMillis: $uptime,
            exportedAt: CarbonImmutable::now('UTC'),
            records: $records,
        );
    }

    /** @param list<FlowRecord> $records */
    private function decodeFlowSample(FlowBinaryReader $sample, int $format, array &$records): void
    {
        $sample->uint32();
        if ($format === 1) {
            $sample->uint32();
        } else {
            $sample->uint32();
            $sample->uint32();
        }
        $samplingRate = max(1, $sample->uint32());
        if ($samplingRate > 1_000_000_000) {
            throw new RuntimeException('sFlow sampling rate is invalid.');
        }
        $sample->skip(8);
        if ($format === 1) {
            $input = $this->interfaceIndex($sample->uint32());
            $output = $this->interfaceIndex($sample->uint32());
        } else {
            $inputFormat = $sample->uint32();
            $input = $inputFormat === 0 ? $sample->uint32() : null;
            $outputFormat = $sample->uint32();
            $output = $outputFormat === 0 ? $sample->uint32() : null;
        }
        $recordCount = $sample->uint32();
        if ($recordCount > 1000) {
            throw new RuntimeException('sFlow record limit is exceeded.');
        }
        for ($recordIndex = 0; $recordIndex < $recordCount; $recordIndex++) {
            $recordType = $sample->uint32();
            $recordLength = $sample->uint32();
            $record = $sample->subReader($recordLength);
            if (($recordType >> 12) === 0 && ($recordType & 0x0FFF) === 1) {
                if (count($records) >= 1000) {
                    throw new RuntimeException('sFlow record limit is exceeded.');
                }
                $decoded = $this->rawPacket($record, $samplingRate, $input, $output);
                if ($decoded instanceof FlowRecord) {
                    $records[] = $decoded;
                }
            } else {
                $record->skip($record->remaining());
            }
            $record->assertFinished(true);
        }
    }

    private function interfaceIndex(int $encoded): ?int
    {
        return ($encoded >> 30) === 0 ? ($encoded & 0x3FFFFFFF) : null;
    }

    private function rawPacket(
        FlowBinaryReader $record,
        int $samplingRate,
        ?int $input,
        ?int $output,
    ): ?FlowRecord {
        $headerProtocol = $record->uint32();
        $frameLength = $record->uint32();
        $record->uint32();
        $headerLength = $record->uint32();
        if ($headerLength > 65_507 || $frameLength < 1) {
            throw new RuntimeException('sFlow raw packet header is invalid.');
        }
        $header = $record->read($headerLength);
        $packet = new FlowBinaryReader($header);
        if ($headerProtocol === 1) {
            if ($packet->remaining() < 14) {
                throw new RuntimeException('sFlow Ethernet header is truncated.');
            }
            $packet->skip(12);
            $etherType = $packet->uint16();
            for ($tag = 0; $tag < 2 && in_array($etherType, [0x8100, 0x88A8], true); $tag++) {
                $packet->skip(2);
                $etherType = $packet->uint16();
            }
            if ($etherType === 0x0800) {
                return $this->ipv4($packet, $frameLength, $samplingRate, $input, $output);
            }
            if ($etherType === 0x86DD) {
                return $this->ipv6($packet, $frameLength, $samplingRate, $input, $output);
            }

            return null;
        }
        if ($headerProtocol === 2) {
            return $this->ipv4($packet, $frameLength, $samplingRate, $input, $output);
        }
        if ($headerProtocol === 11) {
            return $this->ipv6($packet, $frameLength, $samplingRate, $input, $output);
        }

        return null;
    }

    private function ipv4(
        FlowBinaryReader $packet,
        int $frameLength,
        int $samplingRate,
        ?int $input,
        ?int $output,
    ): FlowRecord {
        $versionAndLength = $packet->uint8();
        $headerLength = ($versionAndLength & 0x0F) * 4;
        if (($versionAndLength >> 4) !== 4 || $headerLength < 20 || $packet->remaining() < $headerLength - 1) {
            throw new RuntimeException('sFlow IPv4 header is invalid.');
        }
        $packet->skip(8);
        $protocol = $packet->uint8();
        $packet->skip(2);
        $source = inet_ntop($packet->read(4));
        $destination = inet_ntop($packet->read(4));
        $packet->skip($headerLength - 20);

        return $this->transportRecord($packet, $source, $destination, $protocol, $frameLength, $samplingRate, $input, $output);
    }

    private function ipv6(
        FlowBinaryReader $packet,
        int $frameLength,
        int $samplingRate,
        ?int $input,
        ?int $output,
    ): FlowRecord {
        $version = $packet->uint32();
        if (($version >> 28) !== 6 || $packet->remaining() < 36) {
            throw new RuntimeException('sFlow IPv6 header is invalid.');
        }
        $packet->skip(2);
        $protocol = $packet->uint8();
        $packet->skip(1);
        $source = inet_ntop($packet->read(16));
        $destination = inet_ntop($packet->read(16));

        return $this->transportRecord($packet, $source, $destination, $protocol, $frameLength, $samplingRate, $input, $output);
    }

    private function transportRecord(
        FlowBinaryReader $packet,
        string|false $source,
        string|false $destination,
        int $protocol,
        int $frameLength,
        int $samplingRate,
        ?int $input,
        ?int $output,
    ): FlowRecord {
        if (! is_string($source) || ! is_string($destination)) {
            throw new RuntimeException('sFlow network address is invalid.');
        }
        $sourcePort = null;
        $destinationPort = null;
        if (in_array($protocol, [6, 17, 132], true) && $packet->remaining() >= 4) {
            $sourcePort = $packet->uint16();
            $destinationPort = $packet->uint16();
        }
        if ($frameLength > intdiv(PHP_INT_MAX, $samplingRate)) {
            throw new RuntimeException('sFlow sampled byte count exceeds the supported range.');
        }

        return new FlowRecord(
            sourceIp: $source,
            destinationIp: $destination,
            sourcePort: $sourcePort,
            destinationPort: $destinationPort,
            protocol: $protocol,
            bytes: $frameLength * $samplingRate,
            packets: $samplingRate,
            inputInterface: $input,
            outputInterface: $output,
            samplingRate: $samplingRate,
        );
    }
}
