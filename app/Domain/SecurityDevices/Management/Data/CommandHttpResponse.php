<?php

namespace App\Domain\SecurityDevices\Management\Data;

use RuntimeException;

final readonly class CommandHttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public ?string $location = null,
    ) {
        if ($status < 100 || $status > 599) {
            throw new RuntimeException('Command HTTP response status is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Command provider returned an invalid response.');
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Command provider returned an invalid response.');
        }

        return $decoded;
    }
}
