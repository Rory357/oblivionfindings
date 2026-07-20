<?php

namespace App\Domain\Monitoring\Data;

use App\Domain\Monitoring\Enums\RuntimeMessageType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class RuntimeEnvelope
{
    /**
     * @param  array<string|int, mixed>  $payload
     */
    public function __construct(
        public int $schemaVersion,
        public string $messageId,
        public RuntimeMessageType $type,
        public string $source,
        public int $sequence,
        public CarbonImmutable $occurredAt,
        public CarbonImmutable $ingestedAt,
        public string $idempotencyKey,
        public string $traceId,
        public array $payload,
        public string $keyId = '',
        public string $signature = '',
    ) {}

    /**
     * @param  array<string|int, mixed>  $payload
     */
    public static function new(
        RuntimeMessageType $type,
        string $source,
        int $sequence,
        string $idempotencyKey,
        array $payload,
    ): self {
        $now = CarbonImmutable::now('UTC');

        return new self(
            schemaVersion: 1,
            messageId: (string) Str::orderedUuid(),
            type: $type,
            source: $source,
            sequence: $sequence,
            occurredAt: $now,
            ingestedAt: $now,
            idempotencyKey: $idempotencyKey,
            traceId: (string) Str::orderedUuid(),
            payload: $payload,
        );
    }
}
