<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Data\DependencyDecision;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDependency;
use DateTimeInterface;

final class DependencyEvaluator
{
    public function evaluate(Monitor $monitor, DateTimeInterface $at): DependencyDecision
    {
        return $this->evaluateMonitor($monitor->fresh() ?? $monitor, []);
    }

    /** @param array<int, true> $visited */
    private function evaluateMonitor(Monitor $monitor, array $visited): DependencyDecision
    {
        $underlying = $monitor->current_state;
        if (! $underlying->isFailure()) {
            return new DependencyDecision($underlying, $underlying, null, true);
        }

        if (isset($visited[$monitor->id])) {
            return new DependencyDecision($underlying, $underlying, null, true, 'cycle_guard');
        }
        $visited[$monitor->id] = true;

        $minimumConfidence = (float) config('monitoring.policy.topology_dependency_minimum_confidence', 0.85);
        $dependencies = MonitorDependency::query()
            ->where('site_id', app(CanonicalDeviceSiteResolver::class)->resolve((int) $monitor->device_id))
            ->where('downstream_monitor_id', $monitor->id)
            ->where('is_active', true)
            ->where('policy', MonitorDependency::POLICY_SUPPRESS)
            ->orderByDesc('confidence')
            ->orderBy('id')
            ->get();

        foreach ($dependencies as $dependency) {
            if ($dependency->source === 'topology' && (float) $dependency->confidence < $minimumConfidence) {
                continue;
            }

            $upstream = Monitor::query()->findOrFail($dependency->upstream_monitor_id);
            if (! $upstream->current_state->isFailure()) {
                continue;
            }

            $upstreamDecision = $this->evaluateMonitor($upstream, $visited);
            $rootCause = $upstreamDecision->rootCauseMonitorId ?? $upstream->id;

            return new DependencyDecision(
                effectiveState: MonitorState::Suppressed,
                underlyingState: $underlying,
                rootCauseMonitorId: $rootCause,
                symptomVisible: true,
                reason: 'upstream_root_cause',
            );
        }

        return new DependencyDecision($underlying, $underlying, null, true);
    }
}
