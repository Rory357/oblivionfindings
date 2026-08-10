<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Exceptions\UnsafeWebhookHeaders;

final class HrWebhookHeaderPolicy
{
    /** @var list<string> */
    private const RESERVED_HEADERS = [
        'accept',
        'authorization',
        'connection',
        'content-length',
        'content-type',
        'cookie',
        'host',
        'proxy-authorization',
        'set-cookie',
        'transfer-encoding',
        'x-oblivion-webhook-delivery',
        'x-oblivion-webhook-event',
        'x-oblivion-webhook-idempotency',
        'x-oblivion-webhook-signature',
    ];

    /**
     * @param  array<array-key, mixed>|null  $headers
     * @return array<string, string>|null
     */
    public function normalize(?array $headers): ?array
    {
        if ($headers === null || $headers === []) {
            return null;
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! is_string($value) || ! $this->isAllowed($name, $value)) {
                throw new UnsafeWebhookHeaders(
                    'Custom webhook headers cannot replace delivery authentication or contain reusable credentials.',
                );
            }

            $normalized[trim($name)] = $value;
        }

        ksort($normalized, SORT_NATURAL | SORT_FLAG_CASE);

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Defensively filters historical configuration at dispatch time. Current
     * writes use normalize() and fail visibly instead of silently dropping it.
     *
     * @return array<string, string>
     */
    public function safeForDelivery(mixed $headers): array
    {
        if (! is_array($headers)) {
            return [];
        }

        $safe = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value) && $this->isAllowed($name, $value)) {
                $safe[trim($name)] = $value;
            }
        }

        ksort($safe, SORT_NATURAL | SORT_FLAG_CASE);

        return $safe;
    }

    private function isAllowed(string $name, string $value): bool
    {
        $name = trim($name);
        $normalizedName = strtolower($name);

        return $name !== ''
            && preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name) === 1
            && ! in_array($normalizedName, self::RESERVED_HEADERS, true)
            && preg_match('/(?:authorization|credential|password|secret|token|api[-_]?key|cookie)/i', $normalizedName) !== 1
            && strlen($value) <= 500
            && ! str_contains($value, "\r")
            && ! str_contains($value, "\n");
    }
}
