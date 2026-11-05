<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Notifications\MonitoringCheckAlertNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class MonitoringAlertNotificationDispatcher
{
    public function __construct(
        private readonly EffectiveMonitoringPolicyResolver $policies,
    ) {}

    /**
     * Tell the people the alert route names. Returns who was told and how, for the alert timeline
     * (S&D Phase D), or null when nobody was.
     *
     * @return array{names: list<string>, channels: list<string>}|null
     */
    public function dispatchTransition(
        Monitor $monitor,
        MonitorObservation $observation,
        int $siteId,
        MonitorState $from,
        MonitorState $to,
    ): ?array {
        $monitor->loadMissing('device');
        $device = $monitor->device;
        if ($device === null || (string) $device->domain !== 'it_infrastructure') {
            return null;
        }

        if ($to === MonitorState::Suppressed || $to === $from) {
            return null;
        }

        if (! in_array($to, [MonitorState::Degraded, MonitorState::Failed], true)) {
            return null;
        }

        $observedAt = $observation->observed_at !== null
            ? CarbonImmutable::parse($observation->observed_at)->utc()
            : CarbonImmutable::now('UTC');
        if (app(SecurityDevicesSettings::class)->isQuietHours($observedAt)) {
            return null;
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
        if ($isCritical && (bool) ($route['critical_notify_push'] ?? false)) {
            $channels[] = 'push';
        }

        if ($channels === []) {
            return null;
        }

        // S&D 13d: the IT person on call (Rostering) is told first; the rest hear at the one-hour reminder.
        $recipients = app(\App\Domain\SecurityDevices\Alerts\ItOnCall::class)->first($this->policies->resolveItRecipients($siteId, $route['recipient_role']), $observedAt);
        if ($recipients->isEmpty()) {
            return null;
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

            return ['names' => $recipients->pluck('name')->map(fn (mixed $name): string => (string) $name)->values()->all(), 'channels' => $channels];
        } catch (Throwable $exception) {
            Log::warning('Technical monitoring alert notification could not be dispatched', [
                'monitor_id' => $monitor->id,
                'device_id' => $device->id,
                'site_id' => $siteId,
                'state' => $to->value,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
