<?php

namespace App\Domain\SecurityDevices\Management\Contracts;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Management\Data\CollectorCommandOutcome;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;

/**
 * One kind of job a hub runs for a governed command (S&D Phase B, routing through hubs).
 *
 * The server builds the signed job, the hub runs it inside its own typed checks, and the hub's
 * result comes back here to be checked against this job's exact result shapes.
 */
interface CollectorCommandDescriptor
{
    public function capability(): string;

    /**
     * True when the job must go through the hub that covers the Site, and never straight from the
     * server, whenever such a hub exists.
     */
    public function routesThroughCoveringHub(): bool;

    public function supports(Device $device, MonitoringCollector $collector, int $siteId): bool;

    /** @return array<string, mixed> The signed job, exactly as the hub receives it. */
    public function build(
        DeviceCommandRequest $request,
        DeviceCommandAttempt $attempt,
        MonitoringCollector $collector,
    ): array;

    /** Largest attachment the hub may send back, in encoded bytes; 0 when the job has none. */
    public function maxAttachmentBytes(): int;

    /**
     * Check the hub's typed result and turn it into what is stored. Runs inside the result
     * transaction, once, the first time this attempt's result arrives.
     *
     * @param  array<string, scalar|null>  $safeResult
     * @param  array<string, mixed>|null  $reconciliation
     */
    public function interpret(
        DeviceCommandRequest $request,
        MonitoringCollector $collector,
        string $executionStatus,
        array $safeResult,
        ?string $providerReference,
        ?string $failure,
        ?array $reconciliation,
        ?string $attachment,
    ): CollectorCommandOutcome;

    /**
     * The same result arriving again (the hub re-sends after a lost reply) must match what was stored.
     *
     * @param  array<string, scalar|null>  $safeResult
     */
    public function sameEvidence(
        DeviceCommandAttempt $attempt,
        string $executionStatus,
        array $safeResult,
        ?string $failure,
    ): bool;
}
