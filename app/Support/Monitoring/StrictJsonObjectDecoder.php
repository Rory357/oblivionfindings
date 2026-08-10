<?php

namespace App\Support\Monitoring;

use InvalidArgumentException;
use JsonException;

final class StrictJsonObjectDecoder
{
    private int $offset = 0;

    private int $length = 0;

    private string $json = '';

    /** @return array<string, mixed> */
    public function decode(string $json, int $maximumDepth = 128): array
    {
        if ($maximumDepth < 1) {
            throw new InvalidArgumentException('JSON evidence is invalid.');
        }

        $this->json = $json;
        $this->length = strlen($json);
        $this->offset = 0;
        $this->scanValue(1, $maximumDepth);
        $this->whitespace();
        if ($this->offset !== $this->length) {
            throw new InvalidArgumentException('JSON evidence is invalid.');
        }

        try {
            $decoded = json_decode($json, true, $maximumDepth, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('JSON evidence is invalid.', previous: $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('JSON evidence must be an object.');
        }

        return $decoded;
    }

    private function scanValue(int $depth, int $maximumDepth): void
    {
        if ($depth > $maximumDepth) {
            throw new InvalidArgumentException('JSON evidence is invalid.');
        }
        $this->whitespace();
        $character = $this->json[$this->offset] ?? null;
        match ($character) {
            '{' => $this->scanObject($depth, $maximumDepth),
            '[' => $this->scanArray($depth, $maximumDepth),
            '"' => $this->scanString(),
            't' => $this->literal('true'),
            'f' => $this->literal('false'),
            'n' => $this->literal('null'),
            default => $this->scanNumber(),
        };
    }

    private function scanObject(int $depth, int $maximumDepth): void
    {
        $this->offset++;
        $this->whitespace();
        if (($this->json[$this->offset] ?? null) === '}') {
            $this->offset++;

            return;
        }

        $keys = [];
        while (true) {
            $this->whitespace();
            if (($this->json[$this->offset] ?? null) !== '"') {
                throw new InvalidArgumentException('JSON evidence is invalid.');
            }
            $key = $this->scanString();
            if (array_key_exists($key, $keys)) {
                throw new InvalidArgumentException('JSON evidence contains a duplicate object key.');
            }
            $keys[$key] = true;
            $this->whitespace();
            if (($this->json[$this->offset] ?? null) !== ':') {
                throw new InvalidArgumentException('JSON evidence is invalid.');
            }
            $this->offset++;
            $this->scanValue($depth + 1, $maximumDepth);
            $this->whitespace();
            $separator = $this->json[$this->offset] ?? null;
            if ($separator === '}') {
                $this->offset++;

                return;
            }
            if ($separator !== ',') {
                throw new InvalidArgumentException('JSON evidence is invalid.');
            }
            $this->offset++;
        }
    }

    private function scanArray(int $depth, int $maximumDepth): void
    {
        $this->offset++;
        $this->whitespace();
        if (($this->json[$this->offset] ?? null) === ']') {
            $this->offset++;

            return;
        }

        while (true) {
            $this->scanValue($depth + 1, $maximumDepth);
            $this->whitespace();
            $separator = $this->json[$this->offset] ?? null;
            if ($separator === ']') {
                $this->offset++;

                return;
            }
            if ($separator !== ',') {
                throw new InvalidArgumentException('JSON evidence is invalid.');
            }
            $this->offset++;
        }
    }

    private function scanString(): string
    {
        $start = $this->offset;
        $this->offset++;
        while ($this->offset < $this->length) {
            $character = $this->json[$this->offset];
            if ($character === '"') {
                $this->offset++;
                $token = substr($this->json, $start, $this->offset - $start);
                try {
                    $decoded = json_decode($token, true, 2, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new InvalidArgumentException('JSON evidence is invalid.', previous: $exception);
                }
                if (! is_string($decoded)) {
                    throw new InvalidArgumentException('JSON evidence is invalid.');
                }

                return $decoded;
            }
            if ($character === '\\') {
                $this->offset++;
                $escape = $this->json[$this->offset] ?? null;
                if ($escape === 'u') {
                    $hex = substr($this->json, $this->offset + 1, 4);
                    if (strlen($hex) !== 4 || preg_match('/\A[0-9a-fA-F]{4}\z/', $hex) !== 1) {
                        throw new InvalidArgumentException('JSON evidence is invalid.');
                    }
                    $this->offset += 5;

                    continue;
                }
                if (! in_array($escape, ['"', '\\', '/', 'b', 'f', 'n', 'r', 't'], true)) {
                    throw new InvalidArgumentException('JSON evidence is invalid.');
                }
                $this->offset++;

                continue;
            }
            if (ord($character) < 0x20) {
                throw new InvalidArgumentException('JSON evidence is invalid.');
            }
            $this->offset++;
        }

        throw new InvalidArgumentException('JSON evidence is invalid.');
    }

    private function scanNumber(): void
    {
        $remaining = substr($this->json, $this->offset);
        if (preg_match('/\A-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', $remaining, $match) !== 1) {
            throw new InvalidArgumentException('JSON evidence is invalid.');
        }
        $this->offset += strlen($match[0]);
    }

    private function literal(string $literal): void
    {
        if (substr($this->json, $this->offset, strlen($literal)) !== $literal) {
            throw new InvalidArgumentException('JSON evidence is invalid.');
        }
        $this->offset += strlen($literal);
    }

    private function whitespace(): void
    {
        while ($this->offset < $this->length
            && str_contains(" \t\r\n", $this->json[$this->offset])) {
            $this->offset++;
        }
    }
}
