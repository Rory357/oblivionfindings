<?php

namespace App\Services\Integration;

use App\Enums\AlertSeverity;
use App\Models\ControlRoomAlert;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationProviderConnection;
use App\Services\ControlRoom\SignalProcessingService;
use App\Support\SafeOperationalData;
use App\Support\WorkerClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;

/**
 * Routes integration events into the canonical Control Room signal pipeline.
 *
 * This service applies business-level filtering (quiet hours, severity gating)
 * and then delegates normalisation to IntegrationSignalNormaliser and signal
 * processing to SignalProcessingService.
 *
 * Flow: IntegrationEvent → shouldAlert? → normalise → ingest → process → ControlRoomAlert
 */
class AlertRoutingService
{
    public function __construct(
        protected SignalProcessingService $signalProcessor,
        protected IntegrationSignalNormaliser $normaliser,
    ) {}

    /**
     * Evaluate an integration event and route it through the signal pipeline.
     *
     * Returns the resulting ControlRoomAlert if one was created, or null if
     * the event was suppressed (info severity, quiet hours, or dedup).
     */
    public function processEvent(IntegrationEvent $event): ?ControlRoomAlert
    {
        // Step 1: Determine whether this event should create an alert
        $decision = $this->normaliser->shouldAlert($event);

        if (! $decision['alert']) {
            // Check quiet hours: even warn-level events may be suppressed
            Log::debug('AlertRoutingService: integration event suppressed', SafeOperationalData::logContext([
                'integration_event_id' => $event->id,
                'event_type' => $event->event_type,
                'severity' => $event->severity,
                'reason' => $decision['reason'],
                'provider' => $event->provider,
            ]));

            return null;
        }

        // Step 2: Apply quiet hours suppression for non-critical events. Use the
        // canonical severity, so a fall or panic a device reports as "info" is
        // still critical and is never muted by quiet hours.
        if ($this->normaliser->resolveSeverity($event) !== AlertSeverity::CRITICAL) {
            if ($this->isQuietHours($event->provider)) {
                Log::info('AlertRoutingService: event suppressed during quiet hours', SafeOperationalData::logContext([
                    'integration_event_id' => $event->id,
                    'event_type' => $event->event_type,
                    'severity' => $event->severity,
                    'provider' => $event->provider,
                ]));

                return null;
            }
        }

        // Step 3: Normalise the event into canonical signal data
        $signalData = $this->normaliser->normalise($event);

        // Step 4: Ingest through canonical signal pipeline
        try {
            $signal = $this->signalProcessor->ingest($signalData);

            // Step 5: Process the signal to create/correlate a ControlRoomAlert
            $alert = $this->signalProcessor->process($signal);

            if ($alert) {
                Log::info('AlertRoutingService: integration event → alert created', SafeOperationalData::logContext([
                    'integration_event_id' => $event->id,
                    'signal_id' => $signal->id,
                    'alert_id' => $alert->id,
                    'alert_type' => $alert->alert_type,
                    'severity' => $alert->severity,
                    'provider' => $event->provider,
                    'signal_type' => $signalData['signal_type_code'],
                ]));
            } else {
                Log::info('AlertRoutingService: signal processed but no alert created', SafeOperationalData::logContext([
                    'integration_event_id' => $event->id,
                    'signal_id' => $signal->id,
                    'signal_status' => $signal->status,
                    'reason' => $signal->processing_notes ?? 'maintenance_window_or_suppression',
                    'provider' => $event->provider,
                ]));
            }

            return $alert;
        } catch (\Throwable $e) {
            Log::error('AlertRoutingService: signal processing failed', SafeOperationalData::logContext([
                'integration_event_id' => $event->id,
                'provider' => $event->provider,
                'error_category' => SafeOperationalData::failureCategory($e),
            ]));

            // The monitoring consumer owns retry and dead-letter state. Let it
            // roll back this projection boundary instead of acknowledging a
            // persisted IntegrationEvent whose alert was never completed.
            throw $e;
        }
    }

    /**
     * Check if the current time falls within the provider's configured quiet hours.
     */
    protected function isQuietHours(string $provider): bool
    {
        $connection = IntegrationProviderConnection::query()
            ->forProvider($provider)
            ->connected()
            ->first();

        if (! $connection || ! $connection->config) {
            return false;
        }

        $quietStart = $connection->config['quiet_hours_start'] ?? null;
        $quietEnd = $connection->config['quiet_hours_end'] ?? null;

        return self::withinQuietHours(
            is_string($quietStart) ? $quietStart : null,
            is_string($quietEnd) ? $quietEnd : null,
            now(),
            WorkerClock::timezone(),
        );
    }

    /**
     * Whether an instant falls inside quiet hours.
     *
     * Quiet hours are entered as New Zealand wall-clock times ("22:00" to
     * "06:00"), so they are compared with the local time in the organisation's
     * timezone (Pacific/Auckland by default), never with UTC. Comparing the
     * local time on the day handles NZST and NZDT alike. A start later than
     * the end crosses midnight; the end time itself is outside quiet hours.
     * Missing, malformed or identical ends mean no quiet hours, so alerts are
     * never muted by a half-set configuration.
     */
    public static function withinQuietHours(
        ?string $start,
        ?string $end,
        DateTimeInterface $at,
        string $timezone,
    ): bool {
        if (! self::isClockTime($start) || ! self::isClockTime($end) || $start === $end) {
            return false;
        }

        $local = CarbonImmutable::instance($at)->setTimezone($timezone)->format('H:i');

        if ($start > $end) {
            return $local >= $start || $local < $end;
        }

        return $local >= $start && $local < $end;
    }

    /** @phpstan-assert-if-true string $value */
    private static function isClockTime(?string $value): bool
    {
        return is_string($value) && preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $value) === 1;
    }
}
