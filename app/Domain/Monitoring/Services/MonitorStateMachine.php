<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Data\MonitorTransitionDecision;
use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use Carbon\CarbonImmutable;

final class MonitorStateMachine
{
    public function decide(Monitor $monitor, ObservationInput $input): MonitorTransitionDecision
    {
        $monitor->loadMissing('profile');
        [$candidate, $reason, $evidence] = $this->policyState($monitor, $input);
        $current = $monitor->current_state;

        if (in_array($candidate, [MonitorState::Unknown, MonitorState::Stale], true) && $current->isFailure()) {
            $candidate = $current;
            $reason = 'uncertain_state_cannot_improve_failure';
        }

        if ($candidate === $current) {
            return new MonitorTransitionDecision(
                reportedState: $candidate,
                confirmedState: $current,
                reason: $reason,
                stateChanged: false,
                pendingState: null,
                pendingCount: 0,
                pendingSinceAt: null,
                evidence: $evidence,
            );
        }

        $pendingSince = $monitor->pending_state === $candidate && $monitor->pending_since_at !== null
            ? CarbonImmutable::instance($monitor->pending_since_at)
            : $input->observedAt;
        $pendingCount = $monitor->pending_state === $candidate
            ? (int) $monitor->pending_count + 1
            : 1;
        $requiredCount = $this->requiredConfirmations($monitor, $candidate);
        $requiredDuration = $this->requiredDuration($monitor, $candidate);
        $elapsed = max(0, $pendingSince->diffInSeconds($input->observedAt, false));
        $confirmed = $pendingCount >= $requiredCount && $elapsed >= $requiredDuration;

        return new MonitorTransitionDecision(
            reportedState: $candidate,
            confirmedState: $confirmed ? $candidate : $current,
            reason: $confirmed ? $reason : 'awaiting_confirmation',
            stateChanged: $confirmed,
            pendingState: $confirmed ? null : $candidate,
            pendingCount: $confirmed ? 0 : $pendingCount,
            pendingSinceAt: $confirmed ? null : $pendingSince,
            evidence: [
                ...$evidence,
                'candidate_reason' => $reason,
                'confirmation_count' => $pendingCount,
                'required_confirmations' => $requiredCount,
                'elapsed_seconds' => $elapsed,
                'required_duration_seconds' => $requiredDuration,
            ],
        );
    }

    /** @return array{MonitorState, string, array<string, mixed>} */
    private function policyState(Monitor $monitor, ObservationInput $input): array
    {
        $threshold = $this->resolveThresholdPolicy($monitor);
        $measured = $this->extractMetricValue($input, $threshold['metric_key']);

        if ($measured !== null && ($threshold['critical_rising'] !== null || $threshold['warning_rising'] !== null)) {
            $direction = $threshold['direction'];
            $criticalRising = $threshold['critical_rising'];
            $criticalFalling = $threshold['critical_falling'] ?? $criticalRising;
            $warningRising = $threshold['warning_rising'];
            $warningFalling = $threshold['warning_falling'] ?? $warningRising;

            $evidence = array_filter([
                'value' => $measured,
                'metric_key' => $threshold['metric_key'],
                'direction' => $direction,
                'threshold_source' => $threshold['source'],
                'threshold_rule_id' => $threshold['rule_id'],
                'rising_threshold' => $criticalRising,
                'falling_threshold' => $criticalFalling,
                'warning_rising_threshold' => $warningRising,
                'warning_falling_threshold' => $warningFalling,
            ], fn (mixed $v): bool => $v !== null);

            if ($direction === 'below') {
                if ($criticalRising !== null && $measured <= $criticalRising) {
                    return [MonitorState::Failed, 'below_critical_threshold', $evidence];
                }
                if ($monitor->current_state === MonitorState::Failed
                    && $criticalFalling !== null
                    && $measured < $criticalFalling) {
                    return [MonitorState::Failed, 'hysteresis_hold', $evidence];
                }
                if ($warningRising !== null && $measured <= $warningRising) {
                    return [MonitorState::Degraded, 'below_warning_threshold', $evidence];
                }
                if ($monitor->current_state->isFailure()
                    && $warningFalling !== null
                    && $measured < $warningFalling) {
                    return [MonitorState::Degraded, 'warning_hysteresis_hold', $evidence];
                }
                if ($monitor->current_state->isFailure()) {
                    return [MonitorState::Healthy, 'falling_threshold_cleared', $evidence];
                }
            } else {
                if ($criticalRising !== null && $measured >= $criticalRising) {
                    return [MonitorState::Failed, 'rising_threshold_exceeded', $evidence];
                }
                if ($monitor->current_state === MonitorState::Failed
                    && $criticalFalling !== null
                    && $measured > $criticalFalling) {
                    return [MonitorState::Failed, 'hysteresis_hold', $evidence];
                }
                if ($warningRising !== null && $measured >= $warningRising) {
                    return [MonitorState::Degraded, 'warning_threshold_exceeded', $evidence];
                }
                if ($monitor->current_state->isFailure()
                    && $warningFalling !== null
                    && $measured > $warningFalling) {
                    return [MonitorState::Degraded, 'warning_hysteresis_hold', $evidence];
                }
                if ($monitor->current_state->isFailure()) {
                    return [MonitorState::Healthy, 'falling_threshold_cleared', $evidence];
                }
            }
        }

        $baseline = $this->baselineDecision($monitor, $input);
        if ($baseline !== null) {
            return $baseline;
        }

        return [$input->state, 'reported_state', []];
    }

    /**
     * @return array{
     *     source: string,
     *     rule_id: ?int,
     *     metric_key: ?string,
     *     direction: string,
     *     warning_rising: ?float,
     *     warning_falling: ?float,
     *     critical_rising: ?float,
     *     critical_falling: ?float
     * }
     */
    private function resolveThresholdPolicy(Monitor $monitor): array
    {
        if ($monitor->exists && Monitor::getConnectionResolver() !== null) {
            return app(EffectiveMonitoringPolicyResolver::class)->resolveThreshold($monitor);
        }

        $profile = $monitor->profile;
        $direction = in_array($profile?->threshold_direction ?? 'above', ['above', 'below'], true)
            ? (string) ($profile?->threshold_direction ?? 'above')
            : 'above';

        return [
            'source' => 'profile',
            'rule_id' => $profile?->id ? (int) $profile->id : null,
            'metric_key' => null,
            'direction' => $direction,
            'warning_rising' => $profile?->warning_rising_threshold === null ? null : (float) $profile->warning_rising_threshold,
            'warning_falling' => $profile?->warning_falling_threshold === null ? null : (float) $profile->warning_falling_threshold,
            'critical_rising' => $profile?->rising_threshold === null ? null : (float) $profile->rising_threshold,
            'critical_falling' => $profile?->falling_threshold === null ? null : (float) $profile->falling_threshold,
        ];
    }

    private function extractMetricValue(ObservationInput $input, ?string $metricKey): ?float
    {
        if ($metricKey === null || $metricKey === '' || $metricKey === 'value') {
            return $input->value === null ? null : (float) $input->value;
        }
        if ($metricKey === 'latency_ms') {
            return $input->latencyMs === null ? null : (float) $input->latencyMs;
        }
        $candidate = $input->metrics[$metricKey] ?? null;

        return is_int($candidate) || is_float($candidate) || (is_string($candidate) && is_numeric($candidate))
            ? (float) $candidate
            : null;
    }

    /** @return null|array{MonitorState, string, array<string, mixed>} */
    private function baselineDecision(Monitor $monitor, ObservationInput $input): ?array
    {
        $profile = $monitor->profile;
        if ($input->value === null || $profile->baseline_deviation_multiplier === null) {
            return null;
        }

        $window = max(1, (int) $profile->baseline_window_seconds);
        $minimum = max(2, (int) $profile->baseline_minimum_samples);
        $values = $monitor->observations()
            ->whereNotNull('value')
            ->where('observed_at', '<', $input->observedAt)
            ->where('observed_at', '>=', $input->observedAt->subSeconds($window))
            ->orderByDesc('observed_at')
            ->pluck('value')
            ->map(fn (mixed $value): float => (float) $value)
            ->values();

        if ($values->count() < $minimum) {
            return null;
        }

        $mean = $values->average();
        $variance = $values->sum(fn (float $value): float => ($value - $mean) ** 2) / max(1, $values->count() - 1);
        $standardDeviation = sqrt($variance);
        $deviation = (float) $profile->baseline_deviation_multiplier;
        $lower = $mean - ($deviation * $standardDeviation);
        $upper = $mean + ($deviation * $standardDeviation);
        $value = (float) $input->value;
        $evidence = [
            'sample_count' => $values->count(),
            'mean' => $mean,
            'standard_deviation' => $standardDeviation,
            'deviation_multiplier' => $deviation,
            'lower_bound' => $lower,
            'upper_bound' => $upper,
            'value' => $value,
        ];

        return $value < $lower || $value > $upper
            ? [MonitorState::Degraded, 'baseline_deviation', $evidence]
            : [$input->state, 'within_baseline', $evidence];
    }

    private function requiredConfirmations(Monitor $monitor, MonitorState $candidate): int
    {
        if ($candidate === MonitorState::Healthy) {
            return max(1, (int) $monitor->profile->recovery_confirmations);
        }

        if ($candidate->isFailure()) {
            return max(1, (int) $monitor->profile->failure_confirmations);
        }

        return 1;
    }

    private function requiredDuration(Monitor $monitor, MonitorState $candidate): int
    {
        if ($candidate === MonitorState::Healthy) {
            return max(0, (int) $monitor->profile->recovery_duration_seconds);
        }

        if ($candidate->isFailure()) {
            return max(0, (int) $monitor->profile->failure_duration_seconds);
        }

        return 0;
    }
}
