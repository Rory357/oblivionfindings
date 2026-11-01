<?php

namespace App\Domain\SecurityDevices\Alerts;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use Carbon\CarbonImmutable;

/**
 * Safety events from a device become an alert in Security & devices › Alerts and a notice to the
 * staff on shift at that house (S&D Phase E). Control Room's own DeviceEventObserver keeps sending
 * the same events to Control Room straight away; nothing here delays or replaces that.
 */
final class SafetyEventObserver
{
    public function __construct(private readonly DeviceAlertEngine $alerts) {}

    public function created(DeviceEvent $event): void
    {
        $type = strtolower(trim((string) $event->event_type));
        if (! array_key_exists($type, DeviceAlertEngine::SAFETY_EVENTS) || $event->device_id === null) {
            return;
        }
        $device = Device::query()->find($event->device_id);
        if (! $device instanceof Device) {
            return;
        }
        $at = $event->occurred_at !== null ? CarbonImmutable::parse($event->occurred_at)->setTimezone('UTC') : CarbonImmutable::now('UTC');
        // A replayed or late report (more than a day old) is history, not a new alert.
        if ($at->lt(CarbonImmutable::now('UTC')->subDay())) {
            return;
        }
        $this->alerts->onSafetyEvent($device, $type, $at);
    }
}
