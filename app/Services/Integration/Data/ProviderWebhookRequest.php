<?php

namespace App\Services\Integration\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ProviderWebhookRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $body,
        public array $headers,
        public CarbonImmutable $receivedAt,
    ) {
        if (strlen($body) > 262144 || count($headers) > 16) {
            throw new InvalidArgumentException('Provider webhook request is invalid.');
        }

        foreach ($headers as $name => $value) {
            if (! is_string($name) || $name === '' || strlen($name) > 64
                || ! is_string($value) || strlen($value) > 4096) {
                throw new InvalidArgumentException('Provider webhook request is invalid.');
            }
        }
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
