<?php

namespace App\Domain\SecurityDevices\Management\Data;

/**
 * What a hub job's result becomes once its descriptor has checked it.
 *
 * `reconciliation` is the typed evidence to record straight away: the hub's own evidence for jobs
 * that change something (UniFi door), or the server's check of what it stored (a settings backup).
 */
final readonly class CollectorCommandOutcome
{
    /**
     * @param  array<string, scalar|null>  $safeResult
     * @param  array{outcome: string, observed_state: ?array<string, scalar|null>, observation_reference: ?string, safe_evidence_summary: string, observed_at: \Carbon\CarbonImmutable}|null  $reconciliation
     */
    public function __construct(
        public string $executionStatus,
        public array $safeResult,
        public ?string $providerReference,
        public ?string $failure,
        public ?array $reconciliation,
    ) {}
}
