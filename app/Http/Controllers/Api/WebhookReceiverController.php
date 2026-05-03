<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationTenantSecret;
use App\Services\Integration\AlertRoutingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookReceiverController extends Controller
{
    /**
     * Receive a webhook from an external provider.
     *
     * This is the ONLY entry point for integration-originated events.
     * The flow is:
     *   1. Authenticate via X-Integration-Key header
     *   2. Parse provider-specific payload
     *   3. Deduplicate via source_event_id
     *   4. Persist as IntegrationEvent
     *   5. Route through AlertRoutingService → signal pipeline → ControlRoomAlert
     */
    public function receive(Request $request, string $provider)
    {
        try {
            // --- Step 1: Authenticate ---
            $apiKey = $request->header('X-Integration-Key');

            if (! $apiKey) {
                return response()->json(['error' => 'Missing integration key'], 401);
            }

            // Quick-filter by last 4 characters, then decrypt to confirm
            $last4 = substr($apiKey, -4);

            $tenantSecret = IntegrationTenantSecret::where('secret_last4', $last4)
                ->where('provider', $provider)
                ->connected()
                ->get()
                ->first(function (IntegrationTenantSecret $secret) use ($apiKey) {
                    try {
                        return decrypt($secret->secret_encrypted) === $apiKey;
                    } catch (\Throwable $e) {
                        Log::warning('WebhookReceiver: failed to decrypt tenant secret', [
                            'secret_id' => $secret->id,
                            'error' => $e->getMessage(),
                        ]);

                        return false;
                    }
                });

            if (! $tenantSecret) {
                return response()->json(['error' => 'Invalid integration key'], 401);
            }

            // Verify webhook signature if X-Webhook-Signature header present
            $signature = $request->header('X-Webhook-Signature');
            if ($signature && $apiKey) {
                $expectedSignature = hash_hmac('sha256', $request->getContent(), $apiKey);
                if (! hash_equals($expectedSignature, $signature)) {
                    return response()->json(['error' => 'Invalid signature'], 401);
                }
            }

            $tenantId = $tenantSecret->tenant_id;
            $payload = $request->all();

            // --- Step 2: Parse provider-specific payload ---
            $parsed = $this->parsePayload($provider, $payload);

            // Ensure event_type is never empty — critical for signal classification
            if (empty($parsed['event_type']) || $parsed['event_type'] === 'unknown') {
                $parsed['event_type'] = $this->inferEventType($provider, $payload) ?? 'unknown';
            }

            // --- Step 3: Deduplicate ---
            if (! empty($parsed['source_event_id'])) {
                $existing = IntegrationEvent::where('provider', $provider)
                    ->where('tenant_id', $tenantId)
                    ->where('source_event_id', $parsed['source_event_id'])
                    ->first();

                if ($existing) {
                    return response()->json([
                        'status' => 'duplicate',
                        'event_id' => $existing->id,
                    ], 200);
                }
            } else {
                Log::info('WebhookReceiver: no source_event_id — dedup check skipped', [
                    'provider' => $provider,
                    'event_type' => $parsed['event_type'],
                ]);
            }

            // --- Step 4: Persist the integration event ---
            $event = IntegrationEvent::create([
                'tenant_id' => $tenantId,
                'site_id' => $parsed['site_id'],
                'provider' => $provider,
                'source_app' => $parsed['source_app'] ?? $provider,
                'source_event_id' => $parsed['source_event_id'],
                'occurred_at' => $this->parseTimestamp($parsed['occurred_at']),
                'received_at' => now(),
                'severity' => $parsed['severity'] ?? IntegrationEvent::SEVERITY_INFO,
                'event_type' => $parsed['event_type'],
                'normalized_payload' => $parsed['normalized_payload'] ?? [],
                'raw_payload' => $payload,
            ]);

            // --- Step 5: Route through signal pipeline ---
            $routingService = app(AlertRoutingService::class);
            $routingService->processEvent($event);

            return response()->json([
                'status' => 'accepted',
                'event_id' => $event->id,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('WebhookReceiver: unhandled error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload_keys' => array_keys($request->all()),
            ]);

            return response()->json([
                'error' => 'Internal server error',
            ], 500);
        }
    }

    /**
     * Parse the webhook payload based on the provider.
     */
    protected function parsePayload(string $provider, array $payload): array
    {
        return match ($provider) {
            'gallagher' => $this->parseGallagherPayload($payload),
            'hikvision' => $this->parseHikvisionPayload($payload),
            'unifi' => $this->parseUnifiPayload($payload),
            'queclink' => $this->parseQueclinkPayload($payload),
            'milesight' => $this->parseMilesightPayload($payload),
            'axis' => $this->parseAxisPayload($payload),
            'paradox' => $this->parseParadoxPayload($payload),
            'dsc' => $this->parseDscPayload($payload),
            'bosch' => $this->parseBoschPayload($payload),
            default => $this->parseGenericPayload($provider, $payload),
        };
    }

    /**
     * Queclink GV/GL-series cellular tracker payloads.
     *
     * Typical fields from GV series: imei, event, alarm, gps_time, lat/lng,
     * speed, battery, device_name. Personal trackers may send sos/panic/
     * fall/man_down alarms; vehicle trackers add ignition/tow/harsh driving.
     */
    protected function parseQueclinkPayload(array $payload): array
    {
        $alarm = strtolower((string) ($payload['alarm'] ?? $payload['event'] ?? ''));
        $isSafety = in_array($alarm, ['sos', 'panic', 'emergency', 'man_down', 'fall'], true);
        $isTamper = in_array($alarm, ['tamper', 'power_cut', 'powercut', 'cut'], true);

        $eventType = match (true) {
            $isSafety => 'sos_triggered',
            $isTamper => 'tamper_detected',
            in_array($alarm, ['geofence_enter', 'geofence-in'], true) => 'geofence_enter',
            in_array($alarm, ['geofence_exit', 'geofence-out'], true) => 'geofence_exit',
            in_array($alarm, ['low_battery', 'battery_low'], true) => 'battery_low',
            $alarm !== '' => $alarm,
            default => 'unknown',
        };

        return [
            'site_id' => $payload['site_id'] ?? null,
            'source_app' => 'queclink',
            'source_event_id' => $payload['message_id']
                ?? $payload['msg_id']
                ?? $payload['event_id']
                ?? null,
            'occurred_at' => $payload['gps_time']
                ?? $payload['time']
                ?? $payload['timestamp']
                ?? null,
            'severity' => $isSafety
                ? IntegrationEvent::SEVERITY_CRITICAL
                : ($isTamper ? IntegrationEvent::SEVERITY_WARN : IntegrationEvent::SEVERITY_INFO),
            'event_type' => $eventType,
            'normalized_payload' => [
                'summary' => $payload['message'] ?? ($eventType . ' from Queclink device'),
                'imei' => $payload['imei'] ?? $payload['device_id'] ?? null,
                'latitude' => $payload['lat'] ?? $payload['latitude'] ?? null,
                'longitude' => $payload['lng'] ?? $payload['lon'] ?? $payload['longitude'] ?? null,
                'battery' => $payload['battery'] ?? $payload['battery_pct'] ?? null,
            ],
        ];
    }

    /**
     * Milesight LoRaWAN Cloud / Development Platform payloads.
     *
     * Typical decoded payload has devEUI, applicationID, object (decoded
     * sensor data), and an optional alarm code. The Milesight Cloud posts
     * webhooks for both uplink data and device-lifecycle events.
     */
    protected function parseMilesightPayload(array $payload): array
    {
        $object = is_array($payload['object'] ?? null) ? $payload['object'] : [];
        $alarm = strtolower((string) ($object['alarm']
            ?? $object['alarm_type']
            ?? $payload['alarm']
            ?? ''));

        $isFall = in_array($alarm, ['fall', 'fall_detected'], true);
        $isBedExit = in_array($alarm, ['bed_exit', 'bed_exited'], true);
        $isLeak = in_array($alarm, ['leak', 'leak_detected', 'water_leak'], true);
        $isLowBattery = in_array($alarm, ['low_battery', 'battery_low'], true);

        $eventType = match (true) {
            $isFall => 'fall_detected',
            $isBedExit => 'bed_exit',
            $isLeak => 'water_leak',
            $isLowBattery => 'battery_low',
            ! empty($alarm) => $alarm,
            isset($payload['type']) => strtolower((string) $payload['type']),
            default => 'uplink',
        };

        $severity = match (true) {
            $isFall, $isBedExit => IntegrationEvent::SEVERITY_CRITICAL,
            $isLeak => IntegrationEvent::SEVERITY_WARN,
            $isLowBattery => IntegrationEvent::SEVERITY_WARN,
            default => IntegrationEvent::SEVERITY_INFO,
        };

        return [
            'site_id' => $payload['site_id'] ?? null,
            'source_app' => 'milesight',
            'source_event_id' => $payload['fCnt']
                ?? $payload['frameCount']
                ?? $payload['id']
                ?? null,
            'occurred_at' => $payload['timestamp']
                ?? $payload['time']
                ?? $payload['publishedAt']
                ?? null,
            'severity' => $severity,
            'event_type' => $eventType,
            'normalized_payload' => [
                'summary' => 'Milesight '.$eventType,
                'devEUI' => $payload['devEUI']
                    ?? $payload['deviceEUI']
                    ?? $payload['dev_eui']
                    ?? null,
                'applicationID' => $payload['applicationID']
                    ?? $payload['applicationId']
                    ?? null,
                'object' => $object,
            ],
        ];
    }

    /**
     * Axis VAPIX HTTP event notification / ONVIF event payloads.
     *
     * Typical fields: timestamp, eventName, source (camera ID/name),
     * serialNumber, deviceId, optional severity. AI-analytics events nest
     * channel/trigger/analytics under data.*.
     */
    protected function parseAxisPayload(array $payload): array
    {
        $eventName = (string) ($payload['eventName'] ?? $payload['event'] ?? '');
        $eventKey = strtolower($eventName);

        $isTamper = in_array($eventKey, ['tamperalarm', 'tamper', 'tamper_alarm'], true);
        $isMotion = in_array($eventKey, ['motiontrigger', 'objectdetected', 'motion_trigger', 'object_detected'], true);
        $isModeChange = in_array($eventKey, ['daynightmode', 'day_night_mode'], true);

        $eventType = match (true) {
            $isTamper => 'tamper_detected',
            $isMotion => 'motion_detected',
            $isModeChange => 'mode_change',
            $eventName !== '' => $eventKey,
            default => 'unknown',
        };

        $severity = match (true) {
            $isTamper => IntegrationEvent::SEVERITY_CRITICAL,
            $isMotion, $isModeChange => IntegrationEvent::SEVERITY_INFO,
            // Fall back to vendor-provided severity if present (some models include it).
            isset($payload['severity']) => $this->mapSeverity((string) $payload['severity']),
            default => IntegrationEvent::SEVERITY_INFO,
        };

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return [
            'site_id' => $payload['site_id'] ?? null,
            'source_app' => 'axis',
            'source_event_id' => $payload['eventId']
                ?? $payload['event_id']
                ?? $payload['id']
                ?? null,
            'occurred_at' => $payload['timestamp']
                ?? $payload['time']
                ?? $payload['occurredAt']
                ?? null,
            'severity' => $severity,
            'event_type' => $eventType,
            'normalized_payload' => [
                'summary' => $payload['message'] ?? ('Axis '.($eventName !== '' ? $eventName : $eventType)),
                'source' => $payload['source'] ?? null,
                'serialNumber' => $payload['serialNumber'] ?? null,
                'deviceId' => $payload['deviceId'] ?? null,
                'channel' => $data['channel'] ?? null,
                'trigger' => $data['trigger'] ?? null,
                'analytics' => $data['analytics'] ?? null,
            ],
        ];
    }

    /**
     * Paradox Insight Gold / IP150 webhook payloads.
     *
     * Typical fields: event_code, event_description, panel_id, partition,
     * user_id, zone_id, timestamp (epoch ms), event_group. event_description
     * is free-form; always drive classification from event_group.
     */
    protected function parseParadoxPayload(array $payload): array
    {
        $group = strtolower((string) ($payload['event_group'] ?? ''));

        $isAlarm = in_array($group, ['alarm', 'duress'], true);
        $isWarn = in_array($group, ['tamper', 'low battery', 'low_battery', 'phone line', 'phone_line'], true);

        $eventType = match (true) {
            $group === 'duress' => 'duress_triggered',
            $group === 'alarm' => 'alarm_triggered',
            $group === 'tamper' => 'tamper_detected',
            in_array($group, ['low battery', 'low_battery'], true) => 'battery_low',
            in_array($group, ['phone line', 'phone_line'], true) => 'phone_line_trouble',
            $group === 'arm' => 'panel_armed',
            $group === 'disarm' => 'panel_disarmed',
            in_array($group, ['zone open/closed', 'zone_open_closed', 'zone open', 'zone closed'], true) => 'zone_state_change',
            $group !== '' => str_replace(' ', '_', $group),
            default => 'unknown',
        };

        $severity = match (true) {
            $isAlarm => IntegrationEvent::SEVERITY_CRITICAL,
            $isWarn => IntegrationEvent::SEVERITY_WARN,
            default => IntegrationEvent::SEVERITY_INFO,
        };

        return [
            'site_id' => $payload['site_id'] ?? null,
            'source_app' => 'paradox',
            'source_event_id' => $payload['event_id']
                ?? $payload['event_code']
                ?? null,
            'occurred_at' => $payload['timestamp']
                ?? $payload['time']
                ?? null,
            'severity' => $severity,
            'event_type' => $eventType,
            'normalized_payload' => [
                'summary' => $payload['event_description']
                    ?? ('Paradox '.$eventType),
                'event_code' => $payload['event_code'] ?? null,
                'event_group' => $payload['event_group'] ?? null,
                'panel_id' => $payload['panel_id'] ?? null,
                'partition' => $payload['partition'] ?? null,
                'user_id' => $payload['user_id'] ?? null,
                'zone_id' => $payload['zone_id'] ?? null,
            ],
        ];
    }

    /**
     * DSC PowerSeries Neo IP / Envisalink TPI webhook payloads.
     *
     * TPI event code ranges drive severity and event_type:
     *   601-610 → zone alarm (critical)
     *   621-624 → tamper (warn)
     *   650-657 → ready/arm/disarm (info)
     *   800-899 → panic/medical/fire (critical)
     */
    protected function parseDscPayload(array $payload): array
    {
        $code = (int) ($payload['code'] ?? 0);

        $isZoneAlarm = $code >= 601 && $code <= 610;
        $isTamper = $code >= 621 && $code <= 624;
        $isReadyArm = $code >= 650 && $code <= 657;
        $isPanicMedicalFire = $code >= 800 && $code <= 899;

        $eventType = match (true) {
            $isZoneAlarm => 'zone_alarm',
            $isTamper => 'tamper_detected',
            $isReadyArm => 'panel_state_change',
            $isPanicMedicalFire => 'panic_triggered',
            $code > 0 => 'dsc_code_'.$code,
            default => 'unknown',
        };

        $severity = match (true) {
            $isZoneAlarm, $isPanicMedicalFire => IntegrationEvent::SEVERITY_CRITICAL,
            $isTamper => IntegrationEvent::SEVERITY_WARN,
            $isReadyArm => IntegrationEvent::SEVERITY_INFO,
            default => IntegrationEvent::SEVERITY_INFO,
        };

        return [
            'site_id' => $payload['site_id'] ?? null,
            'source_app' => 'dsc',
            'source_event_id' => $payload['event_id']
                ?? (isset($payload['code'], $payload['timestamp'])
                    ? $payload['code'].'-'.$payload['timestamp']
                    : null),
            'occurred_at' => $payload['timestamp']
                ?? $payload['time']
                ?? null,
            'severity' => $severity,
            'event_type' => $eventType,
            'normalized_payload' => [
                'summary' => $payload['message'] ?? ('DSC '.$eventType.' (code '.$code.')'),
                'code' => $code,
                'partition' => $payload['partition'] ?? null,
                'zone' => $payload['zone'] ?? null,
                'account' => $payload['account'] ?? null,
            ],
        ];
    }

    /**
     * Bosch B/G/D-series panel payloads via Remote Portal webhook.
     *
     * Typical fields: eventType, panelSerial, area, point, occurredAt,
     * priority (1-255 with <50 = critical, 50-100 = high, >100 = info).
     * eventType strings drive primary classification; priority is a fallback.
     */
    protected function parseBoschPayload(array $payload): array
    {
        $eventType = (string) ($payload['eventType'] ?? '');
        $eventKey = strtolower($eventType);
        $priority = isset($payload['priority']) ? (int) $payload['priority'] : null;

        $criticalTypes = ['intrusion', 'fire', 'medical', 'panic'];
        $warnTypes = ['trouble', 'tamper', 'lowbattery', 'low_battery'];
        $infoTypes = ['arm', 'disarm', 'pointok', 'point_ok'];

        $isCritical = in_array($eventKey, $criticalTypes, true);
        $isWarn = in_array($eventKey, $warnTypes, true);
        $isInfo = in_array($eventKey, $infoTypes, true);

        $mappedEventType = match (true) {
            $eventKey === 'intrusion' => 'intrusion_detected',
            $eventKey === 'fire' => 'fire_alarm',
            $eventKey === 'medical' => 'medical_alarm',
            $eventKey === 'panic' => 'panic_triggered',
            $eventKey === 'trouble' => 'panel_trouble',
            $eventKey === 'tamper' => 'tamper_detected',
            in_array($eventKey, ['lowbattery', 'low_battery'], true) => 'battery_low',
            $eventKey === 'arm' => 'panel_armed',
            $eventKey === 'disarm' => 'panel_disarmed',
            in_array($eventKey, ['pointok', 'point_ok'], true) => 'point_ok',
            $eventType !== '' => $eventKey,
            default => 'unknown',
        };

        $severity = match (true) {
            $isCritical => IntegrationEvent::SEVERITY_CRITICAL,
            $isWarn => IntegrationEvent::SEVERITY_WARN,
            $isInfo => IntegrationEvent::SEVERITY_INFO,
            // Priority-based fallback when eventType is unrecognised.
            $priority !== null && $priority < 50 => IntegrationEvent::SEVERITY_CRITICAL,
            $priority !== null && $priority <= 100 => IntegrationEvent::SEVERITY_WARN,
            default => IntegrationEvent::SEVERITY_INFO,
        };

        return [
            'site_id' => $payload['site_id'] ?? null,
            'source_app' => 'bosch',
            'source_event_id' => $payload['eventId']
                ?? $payload['event_id']
                ?? $payload['id']
                ?? null,
            'occurred_at' => $payload['occurredAt']
                ?? $payload['timestamp']
                ?? $payload['time']
                ?? null,
            'severity' => $severity,
            'event_type' => $mappedEventType,
            'normalized_payload' => [
                'summary' => $payload['message'] ?? ('Bosch '.$mappedEventType),
                'panelSerial' => $payload['panelSerial'] ?? null,
                'area' => $payload['area'] ?? null,
                'point' => $payload['point'] ?? null,
                'priority' => $priority,
            ],
        ];
    }

    protected function parseGallagherPayload(array $payload): array
    {
        return [
            'site_id' => $payload['siteId'] ?? null,
            'source_app' => 'gallagher',
            'source_event_id' => $payload['eventId'] ?? $payload['id'] ?? null,
            'occurred_at' => $payload['occurredAt'] ?? $payload['time'] ?? null,
            'severity' => $this->mapSeverity($payload['severity'] ?? $payload['priority'] ?? null),
            'event_type' => $payload['eventType'] ?? $payload['type'] ?? 'unknown',
            'normalized_payload' => [
                'summary' => $payload['message'] ?? $payload['description'] ?? 'Gallagher event received',
                'source' => $payload['source'] ?? null,
                'zone' => $payload['zone'] ?? null,
            ],
        ];
    }

    protected function parseHikvisionPayload(array $payload): array
    {
        return [
            'site_id' => $payload['siteId'] ?? null,
            'source_app' => 'hikvision',
            'source_event_id' => $payload['eventId'] ?? $payload['id'] ?? null,
            'occurred_at' => $payload['dateTime'] ?? $payload['time'] ?? null,
            'severity' => $this->mapSeverity($payload['eventLevel'] ?? null),
            'event_type' => $payload['eventType'] ?? $payload['type'] ?? 'unknown',
            'normalized_payload' => [
                'summary' => $payload['eventDescription'] ?? $payload['description'] ?? 'Hikvision event received',
                'channel' => $payload['channelID'] ?? null,
                'device' => $payload['deviceName'] ?? null,
            ],
        ];
    }

    protected function parseUnifiPayload(array $payload): array
    {
        return [
            'site_id' => $payload['site_id'] ?? null,
            'source_app' => 'unifi',
            'source_event_id' => $payload['_id'] ?? $payload['event_id'] ?? null,
            'occurred_at' => isset($payload['time']) ? date('Y-m-d H:i:s', $payload['time'] / 1000) : null,
            'severity' => $this->mapSeverity($payload['severity'] ?? null),
            'event_type' => $payload['key'] ?? $payload['event_type'] ?? 'unknown',
            'normalized_payload' => [
                'summary' => $payload['msg'] ?? $payload['message'] ?? 'UniFi event received',
                'subsystem' => $payload['subsystem'] ?? null,
                'device_mac' => $payload['mac'] ?? null,
            ],
        ];
    }

    protected function parseGenericPayload(string $provider, array $payload): array
    {
        return [
            'site_id' => $payload['site_id'] ?? $payload['siteId'] ?? null,
            'source_app' => $provider,
            'source_event_id' => $payload['event_id'] ?? $payload['eventId'] ?? $payload['id'] ?? null,
            'occurred_at' => $payload['occurred_at'] ?? $payload['time'] ?? $payload['timestamp'] ?? null,
            'severity' => $this->mapSeverity($payload['severity'] ?? $payload['priority'] ?? null),
            'event_type' => $payload['event_type'] ?? $payload['type'] ?? 'unknown',
            'normalized_payload' => [
                'summary' => $payload['message'] ?? $payload['description'] ?? $payload['summary'] ?? "{$provider} event received",
            ],
        ];
    }

    /**
     * Map provider severity values to our internal 3-level severity.
     *
     * This is the raw provider → IntegrationEvent mapping. The final
     * canonical 4-level mapping (low/medium/high/critical) happens in
     * IntegrationSignalNormaliser::resolveSeverity().
     */
    protected function mapSeverity(?string $value): string
    {
        if (! $value) {
            return IntegrationEvent::SEVERITY_INFO;
        }

        $value = strtolower(trim($value));

        return match (true) {
            in_array($value, ['critical', 'emergency', 'fatal', 'urgent', '1'], true) => IntegrationEvent::SEVERITY_CRITICAL,
            in_array($value, ['warn', 'warning', 'high', 'major', '2', '3'], true) => IntegrationEvent::SEVERITY_WARN,
            default => IntegrationEvent::SEVERITY_INFO,
        };
    }

    /**
     * Attempt to infer event type from raw payload when parsers return 'unknown'.
     *
     * Looks for common field patterns across providers.
     */
    protected function inferEventType(string $provider, array $payload): ?string
    {
        // Try common field names
        foreach (['event_type', 'eventType', 'type', 'key', 'alarm_type', 'alarmType', 'action'] as $field) {
            if (! empty($payload[$field]) && is_string($payload[$field])) {
                return strtolower($payload[$field]);
            }
        }

        // Check nested structures
        if (isset($payload['event']['type']) && is_string($payload['event']['type'])) {
            return strtolower($payload['event']['type']);
        }

        if (isset($payload['data']['type']) && is_string($payload['data']['type'])) {
            return strtolower($payload['data']['type']);
        }

        return null;
    }

    /**
     * Safely parse a timestamp value that may be a string, int (unix), or null.
     */
    protected function parseTimestamp(mixed $value): ?\Illuminate\Support\Carbon
    {
        if (empty($value)) {
            return now();
        }

        try {
            if (is_numeric($value)) {
                // Unix timestamp (seconds or milliseconds)
                $ts = (int) $value;
                if ($ts > 1e12) {
                    $ts = (int) ($ts / 1000); // milliseconds → seconds
                }

                return \Illuminate\Support\Carbon::createFromTimestamp($ts);
            }

            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable $e) {
            Log::warning('WebhookReceiver: unparseable timestamp, using now()', [
                'value' => $value,
                'error' => $e->getMessage(),
            ]);

            return now();
        }
    }
}
