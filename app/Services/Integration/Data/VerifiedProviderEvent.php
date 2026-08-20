<?php

namespace App\Services\Integration\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonSerializable;

final readonly class VerifiedProviderEvent implements JsonSerializable
{
    /** @param array<string, mixed> $normalizedPayload */
    public function __construct(
        public int $siteId,
        public string $sourceApp,
        public string $sourceEventId,
        public CarbonImmutable $occurredAt,
        public string $severity,
        public string $eventType,
        public array $normalizedPayload,
        public string $bodyHash,
        public VerifiedWebhookBinding $binding,
    ) {
        if ($siteId < 1 || $sourceApp === '' || strlen($sourceApp) > 64
            || $sourceEventId === '' || strlen($sourceEventId) > 255
            || $eventType === '' || strlen($eventType) > 255
            || ! in_array($severity, ['info', 'warn', 'critical'], true)
            || preg_match('/^[a-f0-9]{64}$/', $bodyHash) !== 1) {
            throw new InvalidArgumentException('Verified provider event is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'site_id' => $this->siteId,
            'source_app' => $this->sourceApp,
            'source_event_id' => $this->sourceEventId,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'severity' => $this->severity,
            'event_type' => $this->eventType,
            'normalized_payload' => $this->normalizedPayload,
            'body_hash' => $this->bodyHash,
            'binding' => $this->binding->jsonSerialize(),
        ];
    }
}
