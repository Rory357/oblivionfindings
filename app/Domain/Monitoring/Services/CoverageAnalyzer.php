<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Data\CoverageResult;
use App\Domain\Monitoring\Models\MonitoringCoverageExpectation;
use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Support\Collection;

final class CoverageAnalyzer
{
    /** @return Collection<int, CoverageResult> */
    public function analyze(Device $device): Collection
    {
        $siteId = app(CanonicalDeviceSiteResolver::class)->resolve((int) $device->id);
        $expectations = MonitoringCoverageExpectation::query()
            ->where('is_active', true)
            ->where('device_domain', $device->domain)
            ->where(fn ($query) => $query->whereNull('device_category')->orWhere('device_category', $device->category))
            ->where(fn ($query) => $query->whereNull('site_id')->orWhere('site_id', $siteId))
            ->orderByRaw('site_id IS NULL')
            ->orderBy('capability')
            ->get()
            ->unique('capability')
            ->values();

        return $expectations->map(function (MonitoringCoverageExpectation $expectation) use ($device, $siteId): CoverageResult {
            $baseEvidence = [
                'site_id' => $siteId,
                'expectation_id' => $expectation->id,
                'monitor_kind' => $expectation->monitor_kind->value,
                'minimum_count' => $expectation->minimum_count,
                'support' => $expectation->support_evidence,
            ];

            if ($expectation->support_status === 'unsupported') {
                return new CoverageResult($expectation->capability, 'unsupported', $baseEvidence);
            }

            $monitors = $device->monitors()
                ->where('kind', $expectation->monitor_kind->value)
                ->get();

            if ($monitors->isEmpty()) {
                return new CoverageResult(
                    $expectation->capability,
                    'missing',
                    [...$baseEvidence, 'reason_code' => 'monitor_not_configured'],
                );
            }

            $enabled = $monitors->where('is_enabled', true)->values();
            if ($enabled->isEmpty()) {
                return new CoverageResult(
                    $expectation->capability,
                    'paused',
                    [...$baseEvidence, 'monitor_ids' => $monitors->pluck('id')->all()],
                );
            }

            if ($enabled->count() < (int) $expectation->minimum_count) {
                return new CoverageResult(
                    $expectation->capability,
                    'missing',
                    [...$baseEvidence, 'configured_count' => $enabled->count()],
                );
            }

            foreach ($enabled as $monitor) {
                $latest = $monitor->observations()->latest('observed_at')->first();
                if ($latest === null) {
                    if ($monitor->last_observation_at === null) {
                        return new CoverageResult(
                            $expectation->capability,
                            'collection_failed',
                            [...$baseEvidence, 'monitor_id' => $monitor->id, 'reason_code' => 'no_observation'],
                        );
                    }

                    continue;
                }

                $reason = strtolower(trim((string) $latest->message));
                if ($latest->state->isFailure() && $this->isCollectionFailure($reason)) {
                    return new CoverageResult(
                        $expectation->capability,
                        'collection_failed',
                        [...$baseEvidence, 'monitor_id' => $monitor->id, 'reason_code' => $reason],
                    );
                }
            }

            return new CoverageResult(
                $expectation->capability,
                'covered',
                [...$baseEvidence, 'monitor_ids' => $enabled->pluck('id')->all()],
            );
        });
    }

    private function isCollectionFailure(string $reason): bool
    {
        foreach (['transport', 'unavailable', 'authentication', 'unauthorised', 'unauthorized', 'timeout', 'connection_error'] as $marker) {
            if (str_contains($reason, $marker)) {
                return true;
            }
        }

        return false;
    }
}
