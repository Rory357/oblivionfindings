<?php

namespace Tests\Support\ChatVoice;

/** Collects the extents and critical byte ranges of a file while the fixture builders write it. */
final class StructureMap
{
    /** @var list<array{int, int}> */
    public array $extents = [];

    /** @var list<array{int, int}> */
    public array $critical = [];

    /** @var list<array{int, int, string}> size fields: [start, end, 'ebml' or 'box'] */
    public array $sizes = [];

    public function size(int $start, int $end, string $kind): void
    {
        $this->sizes[] = [$start, $end, $kind];
    }

    public function extent(int $start, int $end): void
    {
        $this->extents[] = [$start, $end];
    }

    public function critical(int $start, int $end): void
    {
        if ($end > $start) {
            $this->critical[] = [$start, $end];
        }
    }

    public function merge(self $other, int $shift): void
    {
        foreach ($other->extents as [$start, $end]) {
            $this->extents[] = [$start + $shift, $end + $shift];
        }
        foreach ($other->critical as [$start, $end]) {
            $this->critical[] = [$start + $shift, $end + $shift];
        }
        foreach ($other->sizes as [$start, $end, $kind]) {
            $this->sizes[] = [$start + $shift, $end + $shift, $kind];
        }
    }
}
