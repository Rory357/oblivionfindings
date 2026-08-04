<?php

namespace App\Services\Integration\Data;

use App\Domain\Monitoring\Enums\MonitorState;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonSerializable;

final readonly class ProviderObservationPage implements JsonSerializable
{
    private const MAX_ITEMS = 1000;

    private const MAX_CURSOR_LENGTH = 2048;

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array{code: string, item_reference: ?string}>  $exceptions
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
        public bool $partial = false,
        public ?int $retryAfterSeconds = null,
        public array $exceptions = [],
    ) {
        if (! $this->isValid()) {
            throw new InvalidArgumentException('Provider observation page is invalid.');
        }
    }

    public function lastSafeCursor(): ?string
    {
        $last = $this->items[array_key_last($this->items)] ?? null;

        return is_array($last) && is_string($last['cursor'] ?? null)
            ? $last['cursor']
            : null;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'items' => $this->items,
            'next_cursor' => $this->nextCursor,
            'partial' => $this->partial,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'exceptions' => $this->exceptions,
        ];
    }

    private function isValid(): bool
    {
        if (! array_is_list($this->items) || count($this->items) > self::MAX_ITEMS
            || ! $this->validCursor($this->nextCursor)
            || ($this->retryAfterSeconds !== null
                && ($this->retryAfterSeconds < 1 || $this->retryAfterSeconds > 86400))
            || ! array_is_list($this->exceptions) || count($this->exceptions) > 100) {
            return false;
        }

        foreach ($this->items as $item) {
            if (! $this->validItem($item)) {
                return false;
            }
        }

        foreach ($this->exceptions as $exception) {
            if (! is_array($exception)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $exception['code'] ?? '') !== 1
                || ! array_key_exists('item_reference', $exception)
                || ($exception['item_reference'] !== null
                    && (! is_string($exception['item_reference'])
                        || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $exception['item_reference']) !== 1))) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $item */
    private function validItem(array $item): bool
    {
        $required = ['cursor', 'monitor_id', 'device_id', 'site_id', 'source_key', 'state', 'observed_at', 'value', 'unit', 'latency_ms', 'message', 'metrics'];
        if (array_diff($required, array_keys($item)) !== []
            || ! $this->validCursor($item['cursor'])
            || ! $this->positiveInteger($item['monitor_id'])
            || ! $this->positiveInteger($item['device_id'])
            || ! $this->positiveInteger($item['site_id'])
            || ! $this->boundedString($item['source_key'], 1, 255)
            || ! is_string($item['state'])
            || MonitorState::tryFrom($item['state']) === null
            || ! $this->validTimestamp($item['observed_at'])
            || ! (is_int($item['value']) || is_float($item['value']) || $item['value'] === null)
            || ! ($item['unit'] === null || $this->boundedString($item['unit'], 1, 50))
            || ! ($item['latency_ms'] === null || (is_int($item['latency_ms']) && $item['latency_ms'] >= 0 && $item['latency_ms'] <= 86400000))
            || ! ($item['message'] === null || $this->boundedString($item['message'], 1, 1000))
            || ! is_array($item['metrics'])
            || ! $this->safeValue($item['metrics'], 0)) {
            return false;
        }

        try {
            return strlen(json_encode($item, JSON_THROW_ON_ERROR)) <= 16384;
        } catch (\JsonException) {
            return false;
        }
    }

    private function validCursor(mixed $cursor): bool
    {
        return $cursor === null
            || (is_string($cursor) && $cursor !== '' && strlen($cursor) <= self::MAX_CURSOR_LENGTH);
    }

    private function positiveInteger(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function boundedString(mixed $value, int $minimum, int $maximum): bool
    {
        return is_string($value) && mb_strlen($value) >= $minimum && mb_strlen($value) <= $maximum;
    }

    private function validTimestamp(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 64) {
            return false;
        }

        try {
            CarbonImmutable::parse($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function safeValue(mixed $value, int $depth): bool
    {
        if ($depth > 4) {
            return false;
        }

        if (is_array($value)) {
            if (count($value) > 100) {
                return false;
            }

            foreach ($value as $key => $child) {
                if (is_string($key)
                    && (strlen($key) > 64
                        || preg_match('/password|secret|token|credential|authorization|cookie|^raw_/i', $key) === 1)) {
                    return false;
                }

                if (! $this->safeValue($child, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_bool($value) || is_int($value) || is_float($value)
            || (is_string($value) && mb_strlen($value) <= 1024);
    }
}
