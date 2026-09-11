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
        if (! is_array($decision) || ($decision['version'] ?? null) !== 1 || ($decision['destination'] ?? null) !== 'it'
            || ! in_array($decision['reason'] ?? null, ['nonurgent_technical', 'recovered_before_delivery'], true)
            || ($decision['episode_key'] ?? null) !== (MonitoringAvailabilityEpisode::fromEvent($event, (int) $signal->site_id)['key'] ?? null)
            || ! MonitoringAvailabilityEpisode::hasCanonicalObservations($event, (int) $signal->site_id)) {
            return null;
        }

        return $decision;
    }
}
