<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use Illuminate\Support\Collection;

final class MonitoringRetentionPolicyMatcher
{
    /** @param Collection<int, MonitoringRetentionPolicy> $policies
     * @return Collection<int, MonitoringRetentionPolicy>
     */
    public function matchingSeries(MetricSeries $series, Collection $policies): Collection
    {
        return $policies
            ->filter(fn (MonitoringRetentionPolicy $policy): bool => $this->matchesSeries($policy, $series))
            ->values();
    }

    public function matchesSeries(MonitoringRetentionPolicy $policy, MetricSeries $series): bool
    {
        return match ($policy->scope_kind) {
            'application' => true,
            'site' => (int) $policy->site_id === (int) $series->site_id,
            'device' => (int) $policy->device_id === (int) $series->device_id,
            'data_class' => $policy->data_class === $series->data_class,
            'privacy' => $policy->privacy_class === $series->privacy_class,
            default => false,
        };
    }

    public function matchesSnapshot(MonitoringRetentionPolicy $policy, ConfigurationSnapshot $snapshot): bool
    {
        return match ($policy->scope_kind) {
            'application' => true,
            'site' => (int) $policy->site_id === (int) $snapshot->site_id,
            'device' => (int) $policy->device_id === (int) $snapshot->device_id,
            'data_class' => $policy->data_class === 'configuration',
            default => false,
        };
    }
}
