<?php

namespace App\Services\Integration;

class IntegrationAdapterRegistry
{
    /** @var array<string, class-string<IntegrationAdapterInterface>> */
    private array $adapters = [];

    public function register(string $provider, string $adapterClass): void
    {
        $this->adapters[$provider] = $adapterClass;
    }

    public function resolve(string $provider): IntegrationAdapterInterface
    {
        if (!isset($this->adapters[$provider])) {
            throw new \RuntimeException("No adapter registered for provider: {$provider}");
        }

        return app($this->adapters[$provider]);
    }

    public function has(string $provider): bool
    {
        return isset($this->adapters[$provider]);
    }

    public function providers(): array
    {
        return array_keys($this->adapters);
    }
}
