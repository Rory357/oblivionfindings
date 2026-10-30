<?php

namespace App\Services\Maps;

use RuntimeException;

/** Bounded XY reader for OGC GeoPackage simple geometries; no spatial extension is loaded. */
final class GeoPackageGeometry
{
    private string $bytes;

    private int $offset;

    private int $remaining;

    /** @return list<array{type:string,coordinates:array}> */
    public function decode(string $bytes, int $expectedSrs = 4326): array
    {
        if (strlen($bytes) < 13 || strlen($bytes) > 8_000_000 || substr($bytes, 0, 3) !== "GP\0") {
            throw new RuntimeException('Unsupported map geometry.');
        }
        $flags = ord($bytes[3]);
        $envelope = ($flags >> 1) & 7;
        if (($flags & 0xE0) !== 0 || $envelope > 4) {
            throw new RuntimeException('Unsupported map geometry flags.');
        }
        $srs = unpack(($flags & 1) ? 'V' : 'N', substr($bytes, 4, 4))[1];
        if ($srs !== $expectedSrs) {
            throw new RuntimeException('Map coordinates must be WGS84.');
        }
        if ($flags & 16) {
            return [];
        }
        $this->bytes = $bytes;
        $this->offset = 8 + [0, 32, 48, 48, 64][$envelope];
        $this->remaining = 100_000;
        $shapes = $this->geometry(0);
        if ($this->offset !== strlen($bytes)) {
            throw new RuntimeException('Unexpected map geometry content.');
        }

        return $shapes;
    }

    private function geometry(int $depth): array
    {
        if ($depth > 4) {
            throw new RuntimeException('Map geometry is too complex.');
        }
        $order = ord($this->read(1));
        if ($order > 1) {
            throw new RuntimeException('Invalid map geometry byte order.');
        }
        $type = $this->integer($order);
        if ($type === 1) {
            return [['type' => 'point', 'coordinates' => $this->point($order)]];
        }
        if ($type === 2) {
            return [['type' => 'line', 'coordinates' => $this->points($order)]];
        }
        if ($type === 3) {
            $rings = [];
            for ($n = $this->count($order); $n > 0; $n--) {
                $rings[] = $this->points($order);
            }

            return [['type' => 'polygon', 'coordinates' => $rings]];
        }
        if (in_array($type, [4, 5, 6, 7], true)) {
            $shapes = [];
            for ($n = $this->count($order); $n > 0; $n--) {
                array_push($shapes, ...$this->geometry($depth + 1));
            }

            return $shapes;
        }
        throw new RuntimeException('Only XY map geometry is supported.');
    }

    private function points(int $order): array
    {
        $points = [];
        for ($n = $this->count($order); $n > 0; $n--) {
            $points[] = $this->point($order);
        }

        return $points;
    }

    private function point(int $order): array
    {
        if (--$this->remaining < 0) {
            throw new RuntimeException('Map geometry exceeds the point limit.');
        }
        $x = unpack($order ? 'e' : 'E', $this->read(8))[1];
        $y = unpack($order ? 'e' : 'E', $this->read(8))[1];
        if (! is_finite($x) || ! is_finite($y) || abs($x) > 180 || abs($y) > 90) {
            throw new RuntimeException('Invalid map coordinates.');
        }

        return [$x, $y];
    }

    private function count(int $order): int
    {
        $count = $this->integer($order);
        if ($count > 100_000) {
            throw new RuntimeException('Map geometry exceeds the element limit.');
        }

        return $count;
    }

    private function integer(int $order): int
    {
        return unpack($order ? 'V' : 'N', $this->read(4))[1];
    }

    private function read(int $length): string
    {
        if ($this->offset + $length > strlen($this->bytes)) {
            throw new RuntimeException('Truncated map geometry.');
        }
        $part = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;

        return $part;
    }
}
