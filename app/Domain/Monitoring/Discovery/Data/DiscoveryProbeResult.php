<?php

namespace App\Domain\Monitoring\Discovery\Data;

use InvalidArgumentException;

final readonly class DiscoveryProbeResult
{
    private function __construct(
        public string $outcome,
        public ?DiscoveredIdentity $identity,
        public ?string $failureCode,
    ) {
        if (! in_array($outcome, ['found', 'excluded', 'failed', 'unresolved'], true)
            || ($outcome === 'found') !== ($identity !== null)
            || ($failureCode !== null && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $failureCode) !== 1)
            || ($outcome !== 'found' && $failureCode === null)) {
            throw new InvalidArgumentException('Discovery probe result is invalid.');
        }
    }

    public static function found(DiscoveredIdentity $identity): self
    {
        return new self('found', $identity, null);
    }

    public static function excluded(string $reason = 'scope_exclusion'): self
    {
        return new self('excluded', null, $reason);
    }

    public static function failed(string $reason): self
    {
        return new self('failed', null, $reason);
    }

    public static function unresolved(string $reason): self
    {
        return new self('unresolved', null, $reason);
    }
}
