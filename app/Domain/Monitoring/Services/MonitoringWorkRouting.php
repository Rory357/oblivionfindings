<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalRule;
use DomainException;

/** A persisted operational decision on the canonical signal, shared by both destinations. */
final class MonitoringWorkRouting
{
    public static function decide(Signal $signal, DeviceEvent $event, bool $recovered): ?array
    {
        if ($event->device?->domain !== 'it_infrastructure') {
            return null;
        }
        if (! MonitoringAvailabilityEpisode::hasCanonicalObservations($event, (int) $signal->site_id)) {
            throw new DomainException('source_scope_changed');
        }
        $rule = SignalRule::findMatchingRules($signal)->first();
        // An absent operational assessment preserves Control Room's existing fallback.
        // A transport hint is never sufficient to bypass operational coordination.
        $severity = $rule?->output_severity;
        $nonurgent = in_array($severity, ['info', 'low', 'medium'], true);

        return [
            'version' => 1,
            'destination' => ($recovered || $nonurgent) ? 'it' : 'control_room',
            'reason' => $recovered ? 'recovered_before_delivery' : ($nonurgent ? 'nonurgent_technical' : 'operational_coordination'),
            'severity' => $severity,
            'rule_id' => $rule?->id,
            'episode_key' => MonitoringAvailabilityEpisode::fromEvent($event, (int) $signal->site_id)['key'],
        ];
    }

    public static function directDecision(Signal $signal, DeviceEvent $event): ?array
    {
        $decision = data_get($signal->normalized_data, 'it_work_routing');
        if (is_array($decision) && ($decision['version'] ?? null) === 2) {
            $current = self::recoveredLegacyDecision($signal, $event);

            return $current !== null && ($decision['destination'] ?? null) === 'it'
                && ($decision['reason'] ?? null) === 'recovered_before_delivery'
                && ($decision['source_event_id'] ?? null) === $current['source_event_id']
                && ($decision['recovery_event_id'] ?? null) === $current['recovery_event_id']
                && ($decision['recovery_identity'] ?? null) === $current['recovery_identity']
                    ? $decision : null;
        }
        if (! is_array($decision) || ($decision['version'] ?? null) !== 1 || ($decision['destination'] ?? null) !== 'it'
            || ! in_array($decision['reason'] ?? null, ['nonurgent_technical', 'recovered_before_delivery'], true)
            || ($decision['episode_key'] ?? null) !== (MonitoringAvailabilityEpisode::fromEvent($event, (int) $signal->site_id)['key'] ?? null)
            || ! MonitoringAvailabilityEpisode::hasCanonicalObservations($event, (int) $signal->site_id)) {
            return null;
        }

        return $decision;
    }

    /** A recovered legacy source can create verification work without a stale operational alarm. */
    public static function recoveredLegacyDecision(Signal $signal, DeviceEvent $event): ?array
    {
        if ($event->device?->domain !== 'it_infrastructure' || $event->event_type !== 'offline'
            || data_get($event->payload, 'availability_episode_version') !== null
            || $signal->signalSource?->slug !== 'security_devices'
            || $signal->signal_type_code !== 'device_offline' || $signal->external_ref !== 'device_event_'.$event->id
            || data_get($signal->normalized_data, 'device_event_id') !== (int) $event->id
            || data_get($signal->normalized_data, 'canonical_device_id') !== (int) $event->device_id) {
            return null;
        }
        $recovery = MonitoringAvailabilityEpisode::recoveryFor($event, (int) $signal->site_id);
        if ($recovery === null) {
            return null;
        }
        $rule = SignalRule::findMatchingRules($signal)->first();

        return [
            'version' => 2, 'destination' => 'it', 'reason' => 'recovered_before_delivery',
            'severity' => $rule?->output_severity, 'rule_id' => $rule?->id,
            'source_event_id' => (int) $event->id, 'recovery_event_id' => (int) $recovery->id,
            'recovery_identity' => hash('sha256', json_encode([
                $recovery->device_id, $recovery->source, $recovery->event_type, $recovery->occurred_at->toIso8601String(),
                data_get($recovery->payload, 'site_id'), data_get($recovery->payload, 'monitor_correlation_key'),
                data_get($recovery->payload, 'legacy_monitoring_recovery'),
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    public static function hasDirectSourceEvidence(DeviceEvent $event, int $siteId): bool
    {
        return MonitoringAvailabilityEpisode::hasCanonicalObservations($event, $siteId)
            || ($event->event_type === 'offline' && data_get($event->payload, 'availability_episode_version') === null
                && MonitoringAvailabilityEpisode::recoveryFor($event, $siteId) !== null);
    }
}
