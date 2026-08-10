<?php

namespace App\Services\Integration\Data;

use JsonSerializable;

final readonly class ProviderTopologyPage implements JsonSerializable
{
    /** @param list<array<string, mixed>> $nodes @param list<array<string, mixed>> $edges */
    public function __construct(
        public array $nodes,
        public array $edges,
        public ?string $nextCursor = null,
        public bool $partial = false,
        public ?int $retryAfterSeconds = null,
    ) {
        ProviderPageGuard::validateItems($nodes, 1000, 'Provider topology page is invalid.');
        ProviderPageGuard::validateItems($edges, 5000, 'Provider topology page is invalid.');
        ProviderPageGuard::validateCursorAndRetry($nextCursor, $retryAfterSeconds, 'Provider topology page is invalid.');
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
