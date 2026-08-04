<?php

namespace App\Domain\Monitoring\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class MetricSample
{
    /** @param array<string, string|int|bool> $dimensions */
    public function __construct(
        public string $metric,
        public int|float $value,
        public string $unit,
        public array $dimensions = [],
        public ?CarbonImmutable $observedAt = null,
        public string $source = 'native_monitoring',
        public string $dataClass = 'operational',
        public string $privacyClass = 'standard',
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/', $metric) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]{0,31}$/', $unit) !== 1
            || preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/', $source) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $dataClass) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]{0,31}$/', $privacyClass) !== 1
            || ! is_finite((float) $value)
            || count($dimensions) > 32) {
            throw new InvalidArgumentException('Metric sample is invalid.');
        }

        foreach ($dimensions as $key => $dimension) {
            if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', (string) $key) !== 1
                || preg_match('/credential|password|secret|token|authorization|cookie|raw/i', (string) $key) === 1
                || ! is_scalar($dimension)
                || strlen((string) $dimension) > 128) {
                throw new InvalidArgumentException('Metric dimensions are invalid.');
            }
        }
    }
}
