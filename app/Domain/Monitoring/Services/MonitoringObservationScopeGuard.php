<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Exceptions\RuntimeScopeViolation;
use App\Domain\Monitoring\Exceptions\RuntimeSiteScopeViolation;
use App\Domain\Monitoring\Models\Monitor;
use Throwable;

final class MonitoringObservationScopeGuard
{
    public function __construct(private readonly CanonicalDeviceSiteResolver $deviceSiteResolver) {}

    public function assertCanonicalSite(Monitor $monitor, int $siteId): void
    {
        if ($siteId < 1) {
            throw new RuntimeSiteScopeViolation('Observation site reference is invalid.');
        }

        $monitor->loadMissing('collector');

        try {
            $canonicalSiteId = $this->deviceSiteResolver->resolve($monitor->device_id);
        } catch (Throwable) {
            throw new RuntimeSiteScopeViolation('Observation site does not match its canonical active assignment.');
        }

        if ($canonicalSiteId !== $siteId) {
            throw new RuntimeSiteScopeViolation('Observation site does not match its canonical active assignment.');
        }

        if ($monitor->collector !== null && (int) $monitor->collector->site_id !== $siteId) {
            throw new RuntimeSiteScopeViolation('Observation collector site does not match its canonical device site.');
        }
    }

    public function assertCollectorReference(Monitor $monitor, mixed $collectorReference): void
    {
        $monitor->loadMissing('collector');

        if ($monitor->collector !== null
            && (! is_string($collectorReference)
                || ! hash_equals($monitor->collector->collector_uuid, $collectorReference))) {
            throw new RuntimeScopeViolation('Observation collector does not match its canonical monitor.');
        }

        if ($monitor->collector === null && $collectorReference !== null) {
            throw new RuntimeScopeViolation('Observation collector does not match its canonical monitor.');
        }
    }
}
