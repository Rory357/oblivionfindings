<?php

namespace App\Domain\Monitoring\Protocols\Flow;

use RuntimeException;

final class FlowRecordDecoder
{
    public function decode(FlowBinaryReader $reader, FlowTemplate $template): FlowRecord
    {
        /** @var array<int, int|string> $values */
        $values = [];
        foreach ($template->fields as $field) {
            $length = $field->length;
            if ($length === 65_535) {
                $first = $reader->uint8();
                $length = $first < 255 ? $first : $reader->uint16();
            }
            $bytes = $reader->read($length);
            if ($field->enterprise !== null) {
                continue;
            }
            if (in_array($field->type, [8, 12], true) && $length === 4) {
                $values[$field->type] = $this->address($bytes);
            } elseif (in_array($field->type, [27, 28], true) && $length === 16) {
                $values[$field->type] = $this->address($bytes);
            } elseif (in_array($field->type, [1, 2, 4, 7, 10, 11, 14, 34, 85], true)
                && $length <= 8) {
                $values[$field->type] = $this->unsigned($bytes);
            }
        }

        $source = $values[8] ?? $values[27] ?? null;
        $destination = $values[12] ?? $values[28] ?? null;
        $protocol = $values[4] ?? null;
        $bytes = $values[1] ?? $values[85] ?? null;
        $packets = $values[2] ?? null;
        if (! is_string($source) || ! is_string($destination)
            || ! is_int($protocol) || ! is_int($bytes) || ! is_int($packets)) {
            throw new RuntimeException('Flow data record is missing required fields.');
        }

        return new FlowRecord(
            sourceIp: $source,
            destinationIp: $destination,
            sourcePort: isset($values[7]) && is_int($values[7]) ? $values[7] : null,
            destinationPort: isset($values[11]) && is_int($values[11]) ? $values[11] : null,
            protocol: $protocol,
            bytes: $bytes,
            packets: $packets,
            inputInterface: isset($values[10]) && is_int($values[10]) ? $values[10] : null,
            outputInterface: isset($values[14]) && is_int($values[14]) ? $values[14] : null,
            samplingRate: isset($values[34]) && is_int($values[34]) ? max(1, $values[34]) : 1,
        );
    }

    private function address(string $bytes): string
    {
        $address = inet_ntop($bytes);
        if (! is_string($address)) {
            throw new RuntimeException('Flow data address is invalid.');
        }

        return $address;
    }

    private function unsigned(string $bytes): int
    {
        $value = 0;
        foreach (unpack('C*', $bytes) as $byte) {
            if ($value > intdiv(PHP_INT_MAX - $byte, 256)) {
                throw new RuntimeException('Flow data integer exceeds the supported range.');
            }
            $value = ($value * 256) + $byte;
        }

        return $value;
    }
}
