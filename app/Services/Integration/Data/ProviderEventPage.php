<?php

namespace App\Services\Integration\Data;

use JsonSerializable;

final readonly class ProviderEventPage implements JsonSerializable
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
        public bool $partial = false,
        public ?int $retryAfterSeconds = null,
    ) {
        ProviderPageGuard::validateItems($items, 1000, 'Provider event page is invalid.');
        ProviderPageGuard::validateCursorAndRetry($nextCursor, $retryAfterSeconds, 'Provider event page is invalid.');
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
