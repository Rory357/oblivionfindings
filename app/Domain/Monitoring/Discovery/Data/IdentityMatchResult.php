<?php

namespace App\Domain\Monitoring\Discovery\Data;

use InvalidArgumentException;

final readonly class IdentityMatchResult
{
    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $matchedEvidenceHashes
     */
    public function __construct(
        public string $decision,
        public ?int $deviceId,
        public int $confidence,
        public array $reasons,
        public array $matchedEvidenceHashes = [],
    ) {
        if (! in_array($decision, ['matched', 'review', 'proposed', 'excluded', 'rejected'], true)
            || ($deviceId !== null && $deviceId < 1)
            || $confidence < 0
            || $confidence > 100
            || $reasons === []) {
            throw new InvalidArgumentException('Discovery identity decision is invalid.');
        }
    }
}
