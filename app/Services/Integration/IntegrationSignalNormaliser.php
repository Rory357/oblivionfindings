<?php

namespace App\Services\Integration;

use App\Enums\AlertSeverity;
use App\Exceptions\SafetySignalUnroutable;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\Integration\IntegrationEvent;
use App\Support\SafeOperationalData;
use Illuminate\Support\Facades\Log;

/**
 * Centralised normaliser for integration events → canonical Control Room signals.
 *
 * This is the SINGLE place where integration events are translated into
 * the canonical signal format expected by SignalProcessingService::ingest().
 *
 * Responsibilities:
 * - Signal type resolution (known types, fallback to catch-all)
 * - Severity mapping (provider 3-level → canonical 4-level)
 * - Idempotency key generation
 * - Signal source resolution
 * - Context building for operators and auditors
 *
 * To add a new integration event type:
 * 1. Add the signal type code to the SIGNAL_TYPE_MAP or ensure it is seeded in control_room_signal_types
 * 2. If it needs custom severity, add it to SEVERITY_OVERRIDES
 * 3. Create a SignalRule if you need custom queue/SLA/playbook routing
 */
class IntegrationSignalNormaliser
{
    /**
     * Map of known integration event types to their canonical signal type codes.
     *
     * Any event_type NOT in this map will use the generated code "integration_{event_type}".
     * If that generated code doesn't exist in SignalType, it falls back to "integration_unknown".
     */
    public const SIGNAL_TYPE_MAP = [
        'device_offline' => 'integration_device_offline',
        'camera_offline' => 'integration_device_offline',
        'device_online' => 'integration_device_offline',      // same type, rules can differentiate
        'door_forced' => 'integration_door_forced',
        'door_forced_open' => 'integration_door_forced',
        'sos_triggered' => 'integration_sos_triggered',
        'sos' => 'integration_sos_triggered',
        'tamper_detected' => 'integration_tamper_detected',
        'tamper' => 'integration_tamper_detected',
        'panic_alarm' => 'integration_panic_alarm',
        'panic' => 'integration_panic_alarm',
        'duress_alarm' => 'integration_duress_alarm',
        'duress' => 'integration_duress_alarm',
        'communication_failure' => 'integration_communication_failure',
        'comm_failure' => 'integration_communication_failure',
        'power_failure' => 'integration_power_failure',
        'power_loss' => 'integration_power_failure',
        'ups_failure' => 'integration_power_failure',
    ];

    /**
     * Event types that should create alerts even at info severity.
     * Safety-critical events that must never be silently suppressed.
     */
    public const ALWAYS_ALERT_EVENT_TYPES = [
        'device_offline',
        'camera_offline',
        'door_forced',
        'door_forced_open',
        'sos_triggered',
        'sos',
        'tamper_detected',
        'tamper',
        'panic_alarm',
        'panic',
        'duress_alarm',
        'duress',
        'communication_failure',
        'comm_failure',
        'power_failure',
        'power_loss',
        'ups_failure',
    ];

    /**
     * Severity overrides for specific event types.
     * These take precedence over the provider-reported severity.
     *
     * Use this for events where the provider severity is unreliable
     * or where the operational context demands a specific severity floor.
     */
    public const SEVERITY_OVERRIDES = [
        'sos_triggered' => AlertSeverity::CRITICAL,
        'sos' => AlertSeverity::CRITICAL,
        'panic_alarm' => AlertSeverity::CRITICAL,
        'panic' => AlertSeverity::CRITICAL,
        'duress_alarm' => AlertSeverity::CRITICAL,
        'duress' => AlertSeverity::CRITICAL,
        'door_forced' => AlertSeverity::HIGH,
        'door_forced_open' => AlertSeverity::HIGH,
        'tamper_detected' => AlertSeverity::HIGH,
        'tamper' => AlertSeverity::HIGH,
        'power_failure' => AlertSeverity::HIGH,
        'power_loss' => AlertSeverity::HIGH,
        'ups_failure' => AlertSeverity::HIGH,
    ];

    /**
     * Map provider severity values to canonical 4-level severity.
     */
    public const SEVERITY_MAP = [
        IntegrationEvent::SEVERITY_INFO => AlertSeverity::LOW,
        IntegrationEvent::SEVERITY_WARN => AlertSeverity::MEDIUM,
        IntegrationEvent::SEVERITY_CRITICAL => AlertSeverity::CRITICAL,
    ];

    /**
     * Fallback signal type code for unrecognised event types.
     */
    public const FALLBACK_SIGNAL_TYPE = 'integration_unknown';

    /**
     * Normalise an IntegrationEvent into a canonical signal data array
     * ready for SignalProcessingService::ingest().
     */
    public function normalise(IntegrationEvent $event): array
    {
        $signalTypeCode = $this->resolveSignalTypeCode($event);
        $severity = $this->resolveSeverity($event);
        $signalSource = $this->resolveSignalSource($event->provider);
        if ($event->site_id === null || $signalSource === null) {
            throw new SafetySignalUnroutable(
                'Integration event has no canonical Site or active signal source.',
            );
        }
        $idempotencyKey = $this->buildIdempotencyKey($event);

        return [
            'signal_source_id' => $signalSource?->id,
            'signal_type_code' => $signalTypeCode,
            'idempotency_key' => $idempotencyKey,
            'site_id' => $event->site_id,
            // device_id intentionally null — IntegrationEvent.hardware_id references
            // location_hardware, NOT control_room_devices. The hardware_id is preserved
            // in normalized_data for traceability without risking an FK constraint violation.
            'device_id' => null,
            'external_ref' => $event->source_event_id,
            'severity_hint' => $severity,
            'occurred_at' => $event->occurred_at ?? now(),
            'payload' => $event->raw_payload ?? [],
            'normalized_data' => $this->buildNormalisedContext($event, $signalTypeCode, $severity),
        ];
    }

    /**
     * Determine whether this event should create an alert.
     *
     * Returns false (with reason) if the event should be suppressed.
     * Returns true if the event should proceed to signal ingestion.
     */
    public function shouldAlert(IntegrationEvent $event): array
    {
        // Safety-critical events always create alerts regardless of severity
        if (in_array($event->event_type, self::ALWAYS_ALERT_EVENT_TYPES, true)) {
            return ['alert' => true, 'reason' => 'always_alert_event_type'];
        }

        // Critical severity events always create alerts
        if ($event->severity === IntegrationEvent::SEVERITY_CRITICAL) {
            return ['alert' => true, 'reason' => 'critical_severity'];
        }

        // Warn severity events always create alerts
        if ($event->severity === IntegrationEvent::SEVERITY_WARN) {
            return ['alert' => true, 'reason' => 'warn_severity'];
        }

        // Info severity for non-important event types: suppress with trace
        return [
            'alert' => false,
            'reason' => 'info_severity_non_critical_event',
            'event_type' => $event->event_type,
        ];
    }

    /**
     * Resolve the canonical signal type code for an integration event.
     *
     * Priority:
     * 1. Explicit mapping in SIGNAL_TYPE_MAP
     * 2. Generated code "integration_{event_type}" if it exists in SignalType table
     * 3. Fallback to "integration_unknown"
     */
    public function resolveSignalTypeCode(IntegrationEvent $event): string
    {
        $eventType = strtolower(trim($event->event_type ?? 'unknown'));

        // 1. Check explicit mapping
        if (isset(self::SIGNAL_TYPE_MAP[$eventType])) {
            $code = self::SIGNAL_TYPE_MAP[$eventType];

            // Verify the mapped code actually exists in the DB
            if (SignalType::where('code', $code)->where('is_active', true)->exists()) {
                return $code;
            }

            Log::warning('IntegrationSignalNormaliser: mapped signal type not found in DB', SafeOperationalData::logContext([
                'event_type' => $eventType,
                'mapped_code' => $code,
                'provider' => $event->provider,
            ]));
        }

        // 2. Try generated code
        $generatedCode = 'integration_'.$eventType;
        if (SignalType::where('code', $generatedCode)->where('is_active', true)->exists()) {
            return $generatedCode;
        }

        // 3. Fallback to catch-all
        Log::info('IntegrationSignalNormaliser: using fallback signal type', SafeOperationalData::logContext([
            'event_type' => $eventType,
            'tried_codes' => [self::SIGNAL_TYPE_MAP[$eventType] ?? null, $generatedCode],
            'fallback' => self::FALLBACK_SIGNAL_TYPE,
            'integration_event_id' => $event->id,
            'provider' => $event->provider,
        ]));

        return self::FALLBACK_SIGNAL_TYPE;
    }

    /**
     * Resolve canonical severity for an integration event.
     *
     * Priority:
     * 1. Severity override for the event type (safety-critical floor)
     * 2. Mapped provider severity
     * 3. Default from the resolved SignalType
     * 4. Fallback to 'medium'
     */
    public function resolveSeverity(IntegrationEvent $event): string
    {
        $eventType = strtolower(trim($event->event_type ?? 'unknown'));

        // 1. Check severity override (safety-critical events get a floor)
        if (isset(self::SEVERITY_OVERRIDES[$eventType])) {
            $override = self::SEVERITY_OVERRIDES[$eventType];
            $mapped = self::SEVERITY_MAP[$event->severity] ?? AlertSeverity::MEDIUM;

            // Use whichever is higher: the override floor or the provider severity
            return AlertSeverity::higher($override, $mapped);
        }

        // 2. Map provider severity to canonical
        if (isset(self::SEVERITY_MAP[$event->severity])) {
            return self::SEVERITY_MAP[$event->severity];
        }

        // 3. Unrecognised provider severity — log and default
        Log::warning('IntegrationSignalNormaliser: unrecognised provider severity', SafeOperationalData::logContext([
            'severity' => $event->severity,
            'event_type' => $eventType,
            'integration_event_id' => $event->id,
            'provider' => $event->provider,
        ]));

        return AlertSeverity::MEDIUM;
    }

    /**
     * Resolve or create a SignalSource for the integration provider.
     *
     * Each provider gets its own source for independent monitoring,
     * heartbeat tracking, and maintenance window control.
     */
    public function resolveSignalSource(string $provider): ?SignalSource
    {
        $slug = 'integration_'.strtolower(trim($provider));

        try {
            return SignalSource::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => ucfirst($provider).' Integration',
                    'vendor' => $provider,
                    'status' => 'active',
                    'config' => [],
                    'capabilities' => ['webhooks'],
                ]
            );
        } catch (\Throwable $e) {
            Log::error('IntegrationSignalNormaliser: failed to resolve signal source', SafeOperationalData::logContext([
                'provider' => $provider,
                'error_category' => SafeOperationalData::failureCategory($e),
            ]));

            return null;
        }
    }

    /**
     * Build a stable idempotency key for the integration event.
     *
     * Uses the provider + source_event_id as the primary key material.
     * If source_event_id is missing, falls back to provider + event_type + occurred_at + event_id
     * to prevent unbounded duplication while still allowing legitimate distinct events.
     */
    public function buildIdempotencyKey(IntegrationEvent $event): string
    {
        if (! empty($event->source_event_id)) {
            // Strong idempotency: provider + source event ID is globally unique
            return hash('sha256', implode('|', [
                'integration',
                $event->provider,
                $event->source_event_id,
            ]));
        }

        // Weak idempotency: include event DB ID to prevent cross-event collision,
        // but use minute-precision timestamp for same-event replay dedup
        $occurredAt = $event->occurred_at
            ? $event->occurred_at->format('Y-m-d H:i')
            : now()->format('Y-m-d H:i');

        return hash('sha256', implode('|', [
            'integration',
            $event->provider,
            $event->event_type ?? 'unknown',
            $event->site_id ?? '',
            $event->hardware_id ?? '',
            $occurredAt,
            $event->id, // DB ID ensures different events don't collide
        ]));
    }

    /**
     * Build the normalised context that will be stored on the signal and
     * flow through to the ControlRoomAlert.context field.
     *
     * This is what operators and auditors see. It must contain enough
     * information to understand the event without accessing the raw payload.
     */
    public function buildNormalisedContext(
        IntegrationEvent $event,
        string $signalTypeCode,
        string $canonicalSeverity,
    ): array {
        $normalizedPayload = $event->normalized_payload ?? [];

        return [
            // Core traceability
            'integration_event_id' => $event->id,
            'provider' => $event->provider,
            'source_app' => $event->source_app,
            'source_event_id' => $event->source_event_id,

            // Classification
            'original_event_type' => $event->event_type,
            'original_severity' => $event->severity,
            'resolved_signal_type' => $signalTypeCode,
            'resolved_severity' => $canonicalSeverity,

            // Location context
            'site_id' => $event->site_id,
            'room_id' => $event->room_id,
            'hardware_id' => $event->hardware_id,

            // Operator-facing summary
            'title' => $this->generateTitle($event->event_type),
            'description' => $normalizedPayload['summary']
                ?? $normalizedPayload['message']
                ?? $normalizedPayload['description']
                ?? "Integration event from {$event->provider}",

            // Provider-specific context (from parsed payload)
            'provider_context' => array_filter([
                'zone' => $normalizedPayload['zone'] ?? null,
                'source' => $normalizedPayload['source'] ?? null,
                'channel' => $normalizedPayload['channel'] ?? null,
                'device' => $normalizedPayload['device'] ?? null,
                'subsystem' => $normalizedPayload['subsystem'] ?? null,
                'device_mac' => $normalizedPayload['device_mac'] ?? null,
            ]),
        ];
    }

    /**
     * Convert a snake_case event type to a human-readable title.
     */
    public function generateTitle(string $eventType): string
    {
        $acronyms = ['sos', 'nfc', 'pir', 'ptz', 'ups', 'ip', 'api', 'nvr'];

        $words = explode('_', $eventType);

        $words = array_map(function (string $word) use ($acronyms) {
            if (in_array(strtolower($word), $acronyms, true)) {
                return strtoupper($word);
            }

            return ucfirst($word);
        }, $words);

        return implode(' ', $words);
    }
}
