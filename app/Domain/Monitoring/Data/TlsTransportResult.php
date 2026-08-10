<?php

namespace App\Domain\Monitoring\Data;

use Carbon\CarbonImmutable;

final readonly class TlsTransportResult
{
    public function __construct(
        public bool $verified,
        public ?int $latencyMs,
        public ?CarbonImmutable $validTo,
        public ?string $issuerHash,
        public bool $sanMatches,
        public ?string $protocol,
        public string $reasonCode,
        public ?string $peerFingerprintSha256 = null,
    ) {}
}
