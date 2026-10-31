<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Notifications\MonitoringCheckAlertNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class MonitoringAlertNotificationDispatcher
{
    public function __construct(
        private readonly EffectiveMonitoringPolicyResolver $policies,
    ) {}

    public function dispatchTransition(
        Monitor $monitor,
        MonitorObservation $observation,
        int $siteId,
        MonitorState $from,
        MonitorState $to,
    ): void {
        $monitor->loadMissing('device');
        $device = $monitor->device;
        if ($device === null || (string) $device->domain !== 'it_infrastructure') {
            return;
        }

        if ($to === MonitorState::Suppressed || $to === $from) {
            return;
        }

        if (! in_array($to, [MonitorState::Degraded, MonitorState::Failed], true)) {
            return;
        }

        $route = $this->policies->resolveAlertRoute($device, $monitor, $siteId);
        $isCritical = $to === MonitorState::Failed;
        $notifyInApp = $isCritical ? $route['critical_notify_in_app'] : $route['warning_notify_in_app'];
        $notifyEmail = $isCritical ? $route['critical_notify_email'] : $route['warning_notify_email'];

        $channels = [];
        if ($notifyInApp) {
            $channels[] = 'database';
        }
        if ($notifyEmail) {
            $channels[] = 'mail';
        }

        if ($channels === []) {
            return;
        }

        $recipients = $this->policies->resolveItRecipients($siteId, $route['recipient_role']);
        if ($recipients->isEmpty()) {
            return;
        }

        $severity = $isCritical ? 'critical' : 'warning';
        $kind = $monitor->kind instanceof MonitorKind ? $monitor->kind->value : (string) $monitor->kind;
        $valueLabel = $observation->value !== null
            ? ' (reading '.rtrim(rtrim((string) $observation->value, '0'), '.').($observation->unit ? ' '.$observation->unit : '').')'
            : '';
        $title = $device->name.' · '.$monitor->name;
        $summary = ($isCritical ? 'Critical technical check failure' : 'Warning threshold reached')
            .' on '.$device->name.' — '.$monitor->name.$valueLabel.'.';
        $actionUrl = '/security-devices/devices/'.$device->id.'?section=monitors';

        try {
            Notification::send($recipients, new MonitoringCheckAlertNotification(
                severity: $severity,
                title: $title,
                summary: $summary,
                actionUrl: $actionUrl,
                channels: $channels,
                context: [
                    'site_id' => $siteId,
                    'device_id' => (int) $device->id,
                    'monitor_id' => (int) $monitor->id,
                    'monitor_kind' => $kind,
                    'observation_id' => (int) $observation->id,
                    'state' => $to->value,
                    'alert_route_source' => $route['source'],
                    'alert_route_id' => $route['rule_id'],
                ],
            ));
        } catch (Throwable $exception) {
            Log::warning('Technical monitoring alert notification could not be dispatched', [
                'monitor_id' => $monitor->id,
                'device_id' => $device->id,
                'site_id' => $siteId,
                'state' => $to->value,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
