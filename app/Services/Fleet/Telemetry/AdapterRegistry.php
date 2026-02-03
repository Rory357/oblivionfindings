<?php

namespace App\Services\Fleet\Telemetry;

use App\Services\TelemetryNormalizer;

class AdapterRegistry
{
    public function __construct(protected TelemetryNormalizer $normalizer)
    {
    }

    public function adapterFor(string $vendor): TelemetryAdapterInterface
    {
        $vendor = strtolower($vendor);

        return match ($vendor) {
            'queclink' => new QueclinkAdapter(),
            default => new GenericAdapter($this->normalizer, $vendor),
        };
    }
}
