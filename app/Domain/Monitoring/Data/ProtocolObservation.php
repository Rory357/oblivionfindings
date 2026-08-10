<?php

namespace App\Domain\Monitoring\Data;

use App\Domain\Monitoring\Enums\MonitorState;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ProtocolObservation
{
    /**
     * @param  array<string, int|float|string|bool|null>  $evidence
     */
    public function __construct(
        public MonitorState $state,
        public CarbonImmutable $observedAt,
        public int|float|null $value,
        public ?string $unit,
        public ?int $latencyMs,
        public string $reasonCode,
        public array $evidence,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Probe reason code is invalid.');
        }

        foreach ($evidence as $key => $evidenceValue) {
            if (! is_string($key)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1
                || preg_match('/body|authorization|cookie|credential|password|secret|token|certificate|raw_/i', $key) === 1
                || (! is_scalar($evidenceValue) && $evidenceValue !== null)) {
                throw new InvalidArgumentException('Probe evidence is not safe to persist.');
            }
        }
    }
}
