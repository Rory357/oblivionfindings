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
     */
    public function receive(Request $request, string $provider)
    {
        try {
            // Validate API key from request header
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
                    return decrypt($secret->secret_encrypted) === $apiKey;
                });

            if (! $tenantSecret) {
                return response()->json(['error' => 'Invalid integration key'], 401);
            }

            $tenantId = $tenantSecret->tenant_id;
            $payload = $request->all();

            // Parse provider-specific payload
            $parsed = $this->parsePayload($provider, $payload);

            // Check for duplicate (idempotent)
            if ($parsed['source_event_id']) {
                $existing = IntegrationEvent::where('provider', $provider)
                    ->where('source_event_id', $parsed['source_event_id'])
                    ->first();

                if ($existing) {
                    return response()->json([
                        'status' => 'duplicate',
                        'event_id' => $existing->id,
                    ], 200);
                }
            }

            // Create the integration event
            $event = IntegrationEvent::create([
                'tenant_id' => $tenantId,
                'site_id' => $parsed['site_id'],
                'provider' => $provider,
                'source_app' => $parsed['source_app'],
                'source_event_id' => $parsed['source_event_id'],
                'occurred_at' => $parsed['occurred_at'] ?? now(),
                'received_at' => now(),
                'severity' => $parsed['severity'] ?? IntegrationEvent::SEVERITY_INFO,
                'event_type' => $parsed['event_type'],
                'normalized_payload' => $parsed['normalized_payload'],
                'raw_payload' => $payload,
            ]);

            // Route through AlertRoutingService if severity warrants
            $routingService = app(AlertRoutingService::class);
            $alert = $routingService->processEvent($event);

            return response()->json([
                'status' => 'accepted',
                'event_id' => $event->id,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Webhook receiver error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
     * Map provider severity values to our internal severity levels.
     */
    protected function mapSeverity(?string $value): string
    {
        if (! $value) {
            return IntegrationEvent::SEVERITY_INFO;
        }

        $value = strtolower($value);

        return match (true) {
            in_array($value, ['critical', 'emergency', 'fatal', 'urgent', '1']) => IntegrationEvent::SEVERITY_CRITICAL,
            in_array($value, ['warn', 'warning', 'high', 'major', '2', '3']) => IntegrationEvent::SEVERITY_WARN,
            default => IntegrationEvent::SEVERITY_INFO,
        };
    }
}
