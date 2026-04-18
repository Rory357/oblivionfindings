<?php

namespace App\Domain\SecurityDevices\Events;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\Signal;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by DeviceEventObserver after a DeviceEvent has been successfully
 * ingested into the Control Room signal pipeline.
 *
 * This is the **public extension point** for other modules that want to
 * react to device-level events without coupling to the `devices` or
 * `device_events` tables directly. For example:
 *
 *   // app/Providers/EventServiceProvider.php
 *   protected $listen = [
 *       DeviceSignalPublished::class => [
 *           \App\Listeners\Care\NotifyOnFallDetected::class,
 *           \App\Listeners\Fleet\RecordTrackerSignal::class,
 *       ],
 *   ];
 *
 * Listeners receive:
 *  • `device`         — canonical Device the signal originated from
 *  • `deviceEvent`    — the raw DeviceEvent row (event_type, payload, severity)
 *  • `signal`         — the normalised Signal row (with signal_type_code)
 *  • `alertCreated`   — whether the signal produced a Control Room alert
 *                       (useful for "fire only when escalated" listeners)
 *
 * The event is *always* dispatched on successful observer processing,
 * regardless of whether an alert was created. Listeners should opt in via
 * `alertCreated` if they only care about escalated signals.
 *
 * Heartbeat events are suppressed by the observer before reaching this
 * dispatch, so listeners never see routine keep-alive chatter.
 */
class DeviceSignalPublished
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Device $device,
        public readonly DeviceEvent $deviceEvent,
        public readonly Signal $signal,
        public readonly bool $alertCreated,
    ) {}

    /**
     * Convenience: the canonical signal type code (e.g. `device_alarm_trigger`).
     */
    public function signalTypeCode(): string
    {
        return (string) $this->signal->signal_type_code;
    }

    /**
     * Convenience: original DeviceEvent.event_type (may differ from signal
     * type code when the observer routes unknown types to the catch-all).
     */
    public function originalEventType(): string
    {
        return (string) $this->deviceEvent->event_type;
    }
}
