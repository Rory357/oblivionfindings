<?php

namespace App\Domain\SecurityDevices\Credentials\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

final readonly class SecretLeaseRequest implements JsonSerializable
{
    /** @param list<string> $capabilities */
    public function __construct(
        public string $referenceUuid,
        public int $siteId,
        public string $provider,
        public string $purpose,
        public array $capabilities,
        #[\SensitiveParameter] private string $externalReference,
        public CarbonImmutable $expiresAt,
    ) {
        if ($referenceUuid === '' || $siteId < 1 || $provider === '' || $purpose === ''
            || $capabilities === [] || $externalReference === '') {
            throw new InvalidArgumentException('Secret lease request is invalid.');
        }
    }

    public function secretManagerReference(): string
    {
        return $this->externalReference;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'reference_uuid' => $this->referenceUuid,
            'site_id' => $this->siteId,
            'provider' => $this->provider,
            'purpose' => $this->purpose,
            'capabilities' => $this->capabilities,
            'expires_at' => $this->expiresAt->utc()->toISOString(),
        ];
    }

    public function __serialize(): array
    {
        throw new RuntimeException('Secret lease requests cannot be serialized.');
    }
}
