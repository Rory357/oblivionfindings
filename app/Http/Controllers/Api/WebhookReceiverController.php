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
            default => $this->parseGenericPayload($provider, $payload),
        };
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
