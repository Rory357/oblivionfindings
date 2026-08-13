<?php

namespace App\Services\Consents;

final readonly class ConsentConsumabilityDecision
{
    private function __construct(
        public bool $allowed,
        public string $reason,
    ) {}

    public static function allow(): self
    {
        return new self(true, 'authoritative_consent_current');
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
