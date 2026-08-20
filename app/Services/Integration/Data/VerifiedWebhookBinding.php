<?php

namespace App\Services\Integration\Data;

use InvalidArgumentException;
use JsonSerializable;

final readonly class VerifiedWebhookBinding implements JsonSerializable
{
    public function __construct(
        public int $siteConfigId,
        public string $externalSiteId,
        public ?int $canonicalDeviceId = null,
        public ?string $providerEntityId = null,
    ) {
        if ($siteConfigId < 1
            || $externalSiteId === ''
            || mb_strlen($externalSiteId) > 255
            || (($canonicalDeviceId === null) !== ($providerEntityId === null))
            || ($canonicalDeviceId !== null && $canonicalDeviceId < 1)
            || ($providerEntityId !== null && ($providerEntityId === '' || mb_strlen($providerEntityId) > 255))) {
            throw new InvalidArgumentException('Verified webhook binding is invalid.');
        }
    }

    /** @return array<string, int|string|null> */
    public function runtimePayload(int $providerConnectionId): array
    {
        if ($providerConnectionId < 1) {
            throw new InvalidArgumentException('Verified webhook binding is invalid.');
        }

        return [
            'provider_connection_id' => $providerConnectionId,
            ...$this->jsonSerialize(),
        ];
    }

    /**
     * @param  array<string|int, mixed>  $payload
     * @return array{int, self}
     */
    public static function fromRuntimePayload(array $payload): array
    {
        $allowed = [
            'provider_connection_id',
            'site_config_id',
            'external_site_id',
            'canonical_device_id',
            'provider_entity_id',
        ];
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw new InvalidArgumentException('Verified webhook binding is invalid.');
        }

        $connectionId = $payload['provider_connection_id'] ?? null;
        $siteConfigId = $payload['site_config_id'] ?? null;
        $externalSiteId = $payload['external_site_id'] ?? null;
        $canonicalDeviceId = $payload['canonical_device_id'] ?? null;
        $providerEntityId = $payload['provider_entity_id'] ?? null;

        if (! is_int($connectionId) || ! is_int($siteConfigId) || ! is_string($externalSiteId)
            || ($canonicalDeviceId !== null && ! is_int($canonicalDeviceId))
            || ($providerEntityId !== null && ! is_string($providerEntityId))) {
            throw new InvalidArgumentException('Verified webhook binding is invalid.');
        }

        return [$connectionId, new self(
            siteConfigId: $siteConfigId,
            externalSiteId: $externalSiteId,
            canonicalDeviceId: $canonicalDeviceId,
            providerEntityId: $providerEntityId,
        )];
    }

    /** @return array<string, int|string|null> */
    public function jsonSerialize(): array
    {
        return [
            'site_config_id' => $this->siteConfigId,
            'external_site_id' => $this->externalSiteId,
            'canonical_device_id' => $this->canonicalDeviceId,
            'provider_entity_id' => $this->providerEntityId,
        ];
    }
}
