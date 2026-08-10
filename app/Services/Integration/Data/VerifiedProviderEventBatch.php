<?php

namespace App\Services\Integration\Data;

use InvalidArgumentException;
use JsonSerializable;

final readonly class VerifiedProviderEventBatch implements JsonSerializable
{
    /** @param list<VerifiedProviderEvent> $events */
    public function __construct(
        public array $events,
        public int $ignoredCount = 0,
        public int $acknowledgementStatus = 202,
    ) {
        if (! array_is_list($events)
            || count($events) > 100
            || $ignoredCount < 0
            || count($events) + $ignoredCount < 1
            || count($events) + $ignoredCount > 100
            || ! in_array($acknowledgementStatus, [200, 202], true)) {
            throw new InvalidArgumentException('Verified provider event batch is invalid.');
        }

        $identities = [];
        foreach ($events as $event) {
            if (! $event instanceof VerifiedProviderEvent) {
                throw new InvalidArgumentException('Verified provider event batch is invalid.');
            }

            if (isset($identities[$event->sourceEventId])) {
                throw new InvalidArgumentException('Verified provider event batch contains a duplicate identity.');
            }
            $identities[$event->sourceEventId] = true;
        }
    }

    /** @return array{events: list<array<string, mixed>>, ignored_count: int, acknowledgement_status: int} */
    public function jsonSerialize(): array
    {
        return [
            'events' => array_map(
                static fn (VerifiedProviderEvent $event): array => $event->jsonSerialize(),
                $this->events,
            ),
            'ignored_count' => $this->ignoredCount,
            'acknowledgement_status' => $this->acknowledgementStatus,
        ];
    }
}
