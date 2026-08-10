<?php

namespace App\Services\Integration;

use App\Services\Integration\Data\IntegrationCapabilityManifest;
use App\Services\Integration\Exceptions\CapabilityUnavailable;
use InvalidArgumentException;

class IntegrationAdapterRegistry
{
    /** @var array<string, class-string<IntegrationAdapterInterface>> */
    private array $adapters = [];

    /** @var array<string, IntegrationCapabilityManifest> */
    private array $manifests = [];

    /** @var array<string, IntegrationAdapterInterface> */
    private array $instances = [];

    /** @param class-string<IntegrationAdapterInterface> $adapterClass */
    public function register(
        string $provider,
        string $adapterClass,
        ?IntegrationCapabilityManifest $manifest = null,
    ): void {
        $manifest ??= IntegrationCapabilityManifest::compatibility($provider);

        if ($manifest->provider !== $provider || ! is_subclass_of($adapterClass, IntegrationAdapterInterface::class)) {
            throw new InvalidArgumentException('Integration adapter registration is invalid.');
        }

        foreach ($manifest->capabilities as $contract) {
            if (! is_subclass_of($adapterClass, $contract)) {
                throw new InvalidArgumentException('Integration adapter registration is invalid.');
            }
        }

        $this->adapters[$provider] = $adapterClass;
        $this->manifests[$provider] = $manifest;
        unset($this->instances[$provider]);
    }

    public function resolve(string $provider): IntegrationAdapterInterface
    {
        if (! isset($this->adapters[$provider])) {
            throw new \RuntimeException("No adapter registered for provider: {$provider}");
        }

        return $this->instances[$provider] ??= app($this->adapters[$provider]);
    }

    public function has(string $provider): bool
    {
        return isset($this->adapters[$provider]);
    }

    public function manifest(string $provider): IntegrationCapabilityManifest
    {
        if (! isset($this->manifests[$provider])) {
            throw new \RuntimeException("No adapter registered for provider: {$provider}");
        }

        return $this->manifests[$provider];
    }

    public function hasCapability(string $provider, string $contract): bool
    {
        return isset($this->manifests[$provider])
            && $this->manifests[$provider]->supports($contract);
    }

    public function capability(string $provider, string $contract): object
    {
        if (! $this->hasCapability($provider, $contract)) {
            throw new CapabilityUnavailable;
        }

        $adapter = $this->resolve($provider);

        if (! $adapter instanceof $contract) {
            throw new CapabilityUnavailable;
        }

        return $adapter;
    }

    public function providers(): array
    {
        return array_keys($this->adapters);
    }
}
