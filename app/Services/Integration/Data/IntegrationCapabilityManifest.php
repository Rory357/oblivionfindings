<?php

namespace App\Services\Integration\Data;

use App\Services\Integration\Contracts\ConnectionHealthCapability;
use App\Services\Integration\Contracts\DeviceSyncCapability;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\Contracts\InventoryDiscoveryCapability;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use App\Services\Integration\Contracts\TopologyCollectionCapability;
use App\Services\Integration\Contracts\WebhookVerificationCapability;
use InvalidArgumentException;
use JsonSerializable;

final readonly class IntegrationCapabilityManifest implements JsonSerializable
{
    private const CONTRACTS = [
        ConnectionHealthCapability::class,
        InventoryDiscoveryCapability::class,
        DeviceSyncCapability::class,
        ObservationCollectionCapability::class,
        EventCollectionCapability::class,
        WebhookVerificationCapability::class,
        TopologyCollectionCapability::class,
        SnapshotCollectionCapability::class,
    ];

    private const PERMISSIONS = [
        'securityDevices.integrations.view',
        'securityDevices.integrations.manage',
    ];

    private const SENSITIVITY_LABELS = [
        'provider_credentials',
        'device_identifiers',
        'operational_observations',
        'event_metadata',
        'site_topology',
        'configuration_snapshots',
    ];

    /**
     * @param  list<class-string>  $capabilities
     * @param  list<string>  $requiredPermissions
     * @param  list<string>  $sensitivityLabels
     */
    public function __construct(
        public string $provider,
        public string $version,
        public array $capabilities,
        public array $requiredPermissions,
        public array $sensitivityLabels,
        public int $pageLimit,
        public int $minimumIntervalSeconds,
        public int $backfillLimit,
    ) {
        if (! $this->isValid()) {
            throw new InvalidArgumentException('Integration capability manifest is invalid.');
        }
    }

    public static function compatibility(string $provider): self
    {
        return new self(
            provider: $provider,
            version: '0.0',
            capabilities: [],
            requiredPermissions: ['securityDevices.integrations.view'],
            sensitivityLabels: ['provider_credentials'],
            pageLimit: 1,
            minimumIntervalSeconds: 60,
            backfillLimit: 1,
        );
    }

    public function supports(string $contract): bool
    {
        return in_array($contract, $this->capabilities, true);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'provider' => $this->provider,
            'version' => $this->version,
            'capabilities' => $this->capabilities,
            'required_permissions' => $this->requiredPermissions,
            'sensitivity_labels' => $this->sensitivityLabels,
            'page_limit' => $this->pageLimit,
            'minimum_interval_seconds' => $this->minimumIntervalSeconds,
            'backfill_limit' => $this->backfillLimit,
        ];
    }

    private function isValid(): bool
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $this->provider) !== 1
            || preg_match('/^\d+\.\d+$/', $this->version) !== 1
            || ! $this->validUniqueSubset($this->capabilities, self::CONTRACTS)
            || ! $this->validUniqueSubset($this->requiredPermissions, self::PERMISSIONS)
            || $this->requiredPermissions === []
            || ! $this->validUniqueSubset($this->sensitivityLabels, self::SENSITIVITY_LABELS)
            || $this->sensitivityLabels === []) {
            return false;
        }

        return $this->pageLimit >= 1
            && $this->pageLimit <= 1000
            && $this->minimumIntervalSeconds >= 30
            && $this->minimumIntervalSeconds <= 86400
            && $this->backfillLimit >= 1
            && $this->backfillLimit <= 1000000;
    }

    /** @param array<int, mixed> $values @param list<string> $allowed */
    private function validUniqueSubset(array $values, array $allowed): bool
    {
        if (! array_is_list($values) || count($values) !== count(array_unique($values, SORT_REGULAR))) {
            return false;
        }

        foreach ($values as $value) {
            if (! is_string($value) || ! in_array($value, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
