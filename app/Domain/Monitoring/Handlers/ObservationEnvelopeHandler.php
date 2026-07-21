<?php

namespace App\Domain\Monitoring\Handlers;

use App\Domain\Monitoring\Contracts\RuntimeEnvelopeHandler;
use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Exceptions\RuntimeScopeViolation;
use App\Domain\Monitoring\Exceptions\RuntimeSiteScopeViolation;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use Carbon\CarbonImmutable;

final class ObservationEnvelopeHandler implements RuntimeEnvelopeHandler
{
    public function __construct(private readonly MonitoringObservationIngestor $ingestor) {}

    public function handle(RuntimeEnvelope $envelope, ?int $trustedSiteId = null): void
    {
        $monitorId = $envelope->payload['monitor_id'] ?? null;
        $sourceKey = $envelope->payload['source_key'] ?? null;
        $state = MonitorState::tryFrom($envelope->payload['state'] ?? '');
        $observedAt = $envelope->payload['observed_at'] ?? null;

        if (! is_int($monitorId) || $monitorId < 1 || ! is_string($sourceKey) || $sourceKey === ''
            || $state === null || ! is_string($observedAt)) {
            throw new RuntimePayloadInvalid('Observation envelope payload is invalid.');
        }

        try {
            $timestamp = CarbonImmutable::parse($observedAt)->utc();
        } catch (\Throwable $exception) {
            throw new RuntimePayloadInvalid('Observation envelope timestamp is invalid.', previous: $exception);
        }

        $monitor = Monitor::query()
            ->with(['collector', 'device.assignments'])
            ->findOrFail($monitorId);
        $this->assertCanonicalScope($monitor, $envelope, $trustedSiteId);

        $this->ingestor->ingest($monitor, new ObservationInput(
            sourceKey: $sourceKey,
            state: $state,
            observedAt: $timestamp,
            value: $this->number($envelope->payload['value'] ?? null),
            unit: $this->string($envelope->payload['unit'] ?? null),
            latencyMs: $this->integer($envelope->payload['latency_ms'] ?? null),
            message: $this->string($envelope->payload['message'] ?? null),
            metrics: is_array($envelope->payload['metrics'] ?? null) ? $envelope->payload['metrics'] : [],
        ));
    }

    private function assertCanonicalScope(
        Monitor $monitor,
        RuntimeEnvelope $envelope,
        ?int $trustedSiteId,
    ): void {
        $deviceId = $envelope->payload['device_id'] ?? null;

        if (! is_int($deviceId) || $deviceId !== $monitor->device_id) {
            throw new RuntimeScopeViolation('Observation device does not match its canonical monitor.');
        }

        $collectorReference = $envelope->payload['collector_uuid'] ?? null;

        if ($monitor->collector !== null
            && (! is_string($collectorReference)
                || ! hash_equals($monitor->collector->collector_uuid, $collectorReference))) {
            throw new RuntimeScopeViolation('Observation collector does not match its canonical monitor.');
        }

        if ($monitor->collector === null && $collectorReference !== null) {
            throw new RuntimeScopeViolation('Observation collector does not match its canonical monitor.');
        }

        $payloadSiteId = $envelope->payload['site_id'] ?? null;

        if (! is_int($payloadSiteId) || $payloadSiteId < 1) {
            throw new RuntimeSiteScopeViolation('Observation site reference is invalid.');
        }

        if ($trustedSiteId !== null && $payloadSiteId !== null && $trustedSiteId !== $payloadSiteId) {
            throw new RuntimeSiteScopeViolation('Observation site does not match trusted routing context.');
        }

        $canonicalSiteIds = $monitor->device->assignments
            ->whereNull('released_at')
            ->where('assignable_type', DeviceAssignment::TARGET_SITE)
            ->pluck('assignable_id')
            ->filter(fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->unique()
            ->values();

        if ($monitor->collector !== null && (int) $monitor->collector->site_id !== $payloadSiteId) {
            throw new RuntimeSiteScopeViolation('Observation collector site does not match its canonical device site.');
        }

        foreach (array_filter([$trustedSiteId, $payloadSiteId]) as $siteId) {
            if (! $canonicalSiteIds->contains((int) $siteId)) {
                throw new RuntimeSiteScopeViolation('Observation site does not match its canonical active assignment.');
            }
        }
    }

    private function number(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
