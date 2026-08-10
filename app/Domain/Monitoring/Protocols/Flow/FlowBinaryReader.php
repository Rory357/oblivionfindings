<?php

namespace App\Domain\Monitoring\Protocols\Flow;

use RuntimeException;

final class FlowBinaryReader
{
    private int $offset = 0;

    public function __construct(private readonly string $bytes) {}

    public function remaining(): int
    {
        return strlen($this->bytes) - $this->offset;
    }

    public function position(): int
    {
        return $this->offset;
    }

    public function uint8(): int
    {
        return ord($this->read(1));
    }

    public function uint16(): int
    {
        return unpack('nvalue', $this->read(2))['value'];
    }

    public function uint32(): int
    {
        return unpack('Nvalue', $this->read(4))['value'];
    }

    public function read(int $length): string
    {
        if ($length < 0 || $this->remaining() < $length) {
            throw new RuntimeException('Flow packet is truncated.');
        }
        $value = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }

    public function skip(int $length): void
    {
        $this->read($length);
    }

    public function subReader(int $length): self
    {
        return new self($this->read($length));
    }

    public function assertFinished(bool $allowZeroPadding = false): void
    {
        if ($this->remaining() === 0) {
            return;
        }
        if ($allowZeroPadding && $this->remaining() <= 3
            && trim($this->read($this->remaining()), "\0") === '') {
            return;
        }

        throw new RuntimeException('Flow packet contains trailing bytes.');
    }
}
