<?php

namespace App\Observers;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Events\DeviceSignalPublished;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Services\ControlRoom\SignalProcessingService;
use Illuminate\Support\Facades\Log;

/**
 * Bridges DeviceEvent rows into the Control Room signal pipeline.
 *
 * On DeviceEvent::created(), we build a Signal payload using the
 * `device_<event_type>` signal type (or the generic catch-all) and call
 * SignalProcessingService::ingest() → process(). The processing service
 * applies any matching SignalRule and creates a ControlRoomAlert when
 * appropriate.
 *
 * Heartbeat noise is suppressed at the observer level rather than the
 * rule level to avoid even inserting a Signal row for every heartbeat.
 * Operators who want heartbeat alerts can flip HEARTBEAT_FORWARD below
 * and rely on the `device_heartbeat` rule.
 */
class DeviceEventObserver
{
    /** If true, heartbeat events are forwarded into the signal pipeline. */
    private const HEARTBEAT_FORWARD = false;

    /** Map of DeviceEvent.event_type → signal type code. */
    private const TYPE_MAP = [
        'alarm_trigger' => 'device_alarm_trigger',
        'tamper' => 'device_tamper',
        'motion_detected' => 'device_motion_detected',
        'door_opened' => 'device_door_opened',
        'door_closed' => 'device_door_closed',
        'battery_low' => 'device_battery_low',
        'offline' => 'device_offline',
        'online' => 'device_online',
        'heartbeat' => 'device_heartbeat',
        'firmware_updated' => 'device_firmware_updated',
        'maintenance_due' => 'device_maintenance_due',
        'config_changed' => 'device_config_changed',
        // `signal` is a generic bucket; route to the catch-all.
        'signal' => 'device_signal_generic',
    ];

    public function __construct(
        private readonly SignalProcessingService $processor,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
    ) {}

    public function created(DeviceEvent $event): void
    {
        try {
            if ($event->event_type === 'heartbeat' && ! self::HEARTBEAT_FORWARD) {
                return;
            }

            $signalTypeCode = self::TYPE_MAP[$event->event_type] ?? 'device_signal_generic';

            // Resolve the signal source once; cached by SignalSource singleton.
            $source = SignalSource::where('slug', 'security_devices')->first();
            if (! $source) {
                Log::info('DeviceEventObserver: security_devices signal source not seeded yet; skipping.', [
                    'device_event_id' => $event->id,
                ]);

                return;
            }

            $siteId = $this->siteResolver->resolve((int) $event->device_id);

            // Retained Control Room Device rows enrich that workspace only.
            // Native monitoring identity and Site scope never depend on one.
            $controlRoomDevices = ControlRoomDevice::query()
                ->where('canonical_device_id', $event->device_id)
                ->limit(2)
                ->get(['id', 'site_id']);
            if ($controlRoomDevices->contains(
                fn (ControlRoomDevice $projection): bool => (int) $projection->site_id !== $siteId,
            )) {
                throw new \UnexpectedValueException(
                    'Control Room Device projection conflicts with the canonical Site.',
                );
            }
            $controlRoomDeviceId = $controlRoomDevices->count() === 1
                ? (int) $controlRoomDevices->first()->id
                : null;

            $signalTypeId = SignalType::where('code', $signalTypeCode)->value('id');

            $payload = [
                'signal_source_id' => $source->id,
                'signal_type_code' => $signalTypeCode,
                'signal_type_id' => $signalTypeId,
                'severity_hint' => $event->severity ?: 'info',
                'external_ref' => 'device_event_'.$event->id,
                'payload' => $event->payload ?? [],
                'normalized_data' => array_filter([
                    'device_event_id' => $event->id,
                    'canonical_device_id' => $event->device_id,
                    'source' => $event->source,
                    'original_event_type' => $event->event_type,
                    'monitor_correlation_key' => $this->monitorCorrelationKey($event),
                    'legacy_monitoring_recovery' => data_get($event->payload, 'legacy_monitoring_recovery') === true
                        ? true
                        : null,
                ], fn (mixed $value): bool => $value !== null),
                'occurred_at' => $event->occurred_at,
                'received_at' => now(),
                'device_id' => $controlRoomDeviceId,
                'site_id' => $siteId,
            ];

            $signal = $this->processor->ingest($payload);
            $alert = null;

            if ($event->event_type === 'online') {
                $this->processor->processDeviceRecovery($signal);
            } else {
                $alert = $this->processor->process($signal);
            }

            $event->forceFill(['processed_at' => now()])->saveQuietly();

            // Broadcast the domain event for cross-module consumers (Care,
            // Fleet, etc.). Listeners register via the standard Laravel event
            // system — see DeviceSignalPublished docblock for the pattern.
            if ($event->device) {
                DeviceSignalPublished::dispatch(
                    $event->device,
                    $event,
                    $signal->fresh() ?? $signal,
                    $alert !== null,
                );
            }
        } catch (\Throwable $e) {
            // Never block the DeviceEvent insert on a bridge failure.
            // The event row remains, processed_at stays null, and can be
            // retried later (or surfaced in the Alerts & Events page).
            Log::error('DeviceEventObserver bridge failed', [
                'device_event_id' => $event->id,
                'event_type' => $event->event_type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function monitorCorrelationKey(DeviceEvent $event): ?string
    {
        $key = data_get($event->payload, 'monitor_correlation_key');

        return is_string($key) && preg_match('/\A[a-f0-9]{64}\z/', $key) === 1
            ? $key
            : null;
    }
}
