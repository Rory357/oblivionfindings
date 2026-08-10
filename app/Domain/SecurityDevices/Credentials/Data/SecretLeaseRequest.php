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
        public ?int $siteId,
        public string $provider,
        public string $purpose,
        public array $capabilities,
        #[\SensitiveParameter] private string $externalReference,
        public CarbonImmutable $expiresAt,
        public ?int $secretVersion = null,
    ) {
        if ($referenceUuid === '' || ($siteId !== null && $siteId < 1) || $provider === '' || $purpose === ''
            || $capabilities === [] || $externalReference === '' || ($secretVersion !== null && $secretVersion < 1)) {
            throw new InvalidArgumentException('Secret lease request is invalid.');
        }
    }

    public function secretManagerReference(): string
    {
        return $this->externalReference;
    }

    public function secretVersion(): ?int
    {
        return $this->secretVersion;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $payload = [
            'reference_uuid' => $this->referenceUuid,
            'site_id' => $this->siteId,
            'provider' => $this->provider,
            'purpose' => $this->purpose,
            'capabilities' => $this->capabilities,
            'expires_at' => $this->expiresAt->utc()->toISOString(),
        ];
        if ($this->secretVersion !== null) {
            $payload['secret_version'] = $this->secretVersion;
        }

        return $payload;
    }

    public function __serialize(): array
    {
        throw new RuntimeException('Secret lease requests cannot be serialized.');
    }
}
