<?php

namespace App\Services\Catering\DeliveryProviders;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

class DeliveryProviderManager
{
    /** @var array<string, DeliveryProviderContract> */
    private array $instances = [];

    public function __construct(private Application $app) {}

    public function resolve(?string $key = null): DeliveryProviderContract
    {
        $key = $key ?? (string) config('catering.default_provider', 'manual');
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        $providers = (array) config('catering.providers', []);
        if (!isset($providers[$key])) {
            throw new InvalidArgumentException("Unknown delivery provider [{$key}].");
        }

        $instance = $this->app->make($providers[$key]);
        if (!$instance instanceof DeliveryProviderContract) {
            throw new InvalidArgumentException("Provider [{$key}] does not implement DeliveryProviderContract.");
        }

        return $this->instances[$key] = $instance;
    }

    /**
     * @return array<array{key:string, label:string}>
     */
    public function available(): array
    {
        $providers = (array) config('catering.providers', []);
        $out = [];
        foreach (array_keys($providers) as $key) {
            $instance = $this->resolve($key);
            $out[] = ['key' => $instance->key(), 'label' => $instance->label()];
        }
        return $out;
    }
}
