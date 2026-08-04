<?php

namespace App\Domain\Monitoring\Protocols\Flow;

use InvalidArgumentException;

final readonly class FlowTemplateField
{
    public function __construct(
        public int $type,
        public int $length,
        public ?int $enterprise,
    ) {
        if ($type < 0 || $type > 0x7FFF || $length < 1 || $length > 65_535
            || ($enterprise !== null && ($enterprise < 0 || $enterprise > 0xFFFFFFFF))) {
            throw new InvalidArgumentException('Flow template field is invalid.');
        }
    }
}
