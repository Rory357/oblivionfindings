<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

final readonly class BerElement
{
    public function __construct(
        public int $tag,
        public string $value,
        public int $absoluteValueOffset,
        public int $absoluteEndOffset,
    ) {}
}
