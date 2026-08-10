<?php

namespace App\Services\Integration\Data;

use InvalidArgumentException;

final class ProviderPageGuard
{
    /** @param array<int, mixed> $items */
    public static function validateItems(array $items, int $maximum, string $message): void
    {
        if (! array_is_list($items) || count($items) > $maximum) {
            throw new InvalidArgumentException($message);
        }

        foreach ($items as $item) {
            if (! is_array($item) || ! self::safeValue($item, 0)) {
                throw new InvalidArgumentException($message);
            }

            try {
                if (strlen(json_encode($item, JSON_THROW_ON_ERROR)) > 65536) {
                    throw new InvalidArgumentException($message);
                }
            } catch (\JsonException) {
                throw new InvalidArgumentException($message);
            }
        }
    }

    public static function validateCursorAndRetry(?string $cursor, ?int $retryAfterSeconds, string $message): void
    {
        if (($cursor !== null && ($cursor === '' || strlen($cursor) > 2048))
            || ($retryAfterSeconds !== null && ($retryAfterSeconds < 1 || $retryAfterSeconds > 86400))) {
            throw new InvalidArgumentException($message);
        }
    }

    private static function safeValue(mixed $value, int $depth): bool
    {
        if ($depth > 6) {
            return false;
        }

        if (is_array($value)) {
            if (count($value) > 500) {
                return false;
            }

            foreach ($value as $key => $child) {
                if (is_string($key)
                    && (strlen($key) > 128
                        || preg_match('/password|secret|token|credential|authorization|cookie|^raw_/i', $key) === 1)) {
                    return false;
                }

                if (! self::safeValue($child, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_bool($value) || is_int($value) || is_float($value)
            || (is_string($value) && mb_strlen($value) <= 4096);
    }
}
