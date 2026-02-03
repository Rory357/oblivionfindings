<?php

namespace App\Services\Fleet\Telemetry;

interface TelemetryAdapterInterface
{
    public function normalize(array $payload): array;
}
