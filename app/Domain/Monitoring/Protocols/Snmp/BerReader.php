<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use RuntimeException;

final class BerReader
{
    private int $offset = 0;

    public function __construct(
        private readonly string $bytes,
        private readonly int $baseOffset = 0,
    ) {}

    public function read(?int $expectedTag = null): BerElement
    {
        if ($this->offset >= strlen($this->bytes)) {
            throw new RuntimeException('SNMP BER value is truncated.');
        }

        $tag = ord($this->bytes[$this->offset++]);
        if ($expectedTag !== null && $tag !== $expectedTag) {
            throw new RuntimeException('SNMP BER tag is invalid.');
        }
        if ($this->offset >= strlen($this->bytes)) {
            throw new RuntimeException('SNMP BER length is truncated.');
        }

        $first = ord($this->bytes[$this->offset++]);
        if (($first & 0x80) === 0) {
            $length = $first;
        } else {
            $octets = $first & 0x7F;
            if ($octets < 1 || $octets > 4 || $this->offset + $octets > strlen($this->bytes)) {
                throw new RuntimeException('SNMP BER length is invalid.');
            }
            $length = 0;
            for ($index = 0; $index < $octets; $index++) {
                $length = ($length << 8) | ord($this->bytes[$this->offset++]);
            }
            if ($length < 128) {
                throw new RuntimeException('SNMP BER length is not canonical.');
            }
        }

        if ($length < 0 || $this->offset + $length > strlen($this->bytes)) {
            throw new RuntimeException('SNMP BER value is truncated.');
        }
        $valueOffset = $this->offset;
        $value = substr($this->bytes, $valueOffset, $length);
        $this->offset += $length;

        return new BerElement(
            $tag,
            $value,
            $this->baseOffset + $valueOffset,
            $this->baseOffset + $this->offset,
        );
    }

    public function finished(): bool
    {
        return $this->offset === strlen($this->bytes);
    }

    public function assertFinished(): void
    {
        if (! $this->finished()) {
            throw new RuntimeException('SNMP BER value has trailing data.');
        }
    }
}
