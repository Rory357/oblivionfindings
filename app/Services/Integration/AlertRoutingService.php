<?php

namespace App\Services\Integration;

use App\Models\ControlRoom\Alert;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationTenantSecret;
use Illuminate\Support\Facades\Log;

class AlertRoutingService
{
    /**
     * Important event types that should create alerts even at info severity.
     */
    protected const IMPORTANT_EVENT_TYPES = [
        'device_offline',
        'door_forced',
        'sos_triggered',
        'tamper_detected',
        'panic_alarm',
        'duress_alarm',
        'communication_failure',
        'power_failure',
    ];

    /**
     * Evaluate an integration event and create an alert if warranted.
     */
    public function processEvent(IntegrationEvent $event): ?Alert
    {
        // Info severity only creates alerts for important event types
        if ($event->severity === IntegrationEvent::SEVERITY_INFO) {
            if (! in_array($event->event_type, self::IMPORTANT_EVENT_TYPES)) {
                return null;
            }
        }

        // Check quiet hours (skip non-critical alerts during quiet hours)
        if ($event->severity !== IntegrationEvent::SEVERITY_CRITICAL) {
            if ($this->isQuietHours($event->tenant_id, $event->provider)) {
                Log::info('Alert suppressed during quiet hours', [
                    'event_id' => $event->id,
                    'event_type' => $event->event_type,
                    'severity' => $event->severity,
                ]);

                return null;
            }
        }

        $alert = Alert::create([
            'tenant_id' => $event->tenant_id,
            'site_id' => $event->site_id,
            'hardware_id' => $event->hardware_id,
            'integration_event_id' => $event->id,
            'title' => $this->generateTitle($event->event_type),
            'description' => $event->normalized_payload['summary'] ?? 'Alert generated from integration event',
            'severity' => $event->severity,
            'status' => Alert::STATUS_NEW,
            'provider' => $event->provider,
            'source_event_id' => $event->source_event_id,
        ]);

        $this->routeAlert($alert);

        return $alert;
    }

    /**
     * Apply routing rules to a newly created alert.
     *
     * TODO: Implement notification dispatch based on tenant/site config (email, push, etc.)
     */
    public function routeAlert(Alert $alert): void
    {
        Log::info('Alert created and routed', [
            'alert_id' => $alert->id,
            'title' => $alert->title,
            'severity' => $alert->severity,
            'site_id' => $alert->site_id,
            'provider' => $alert->provider,
        ]);
    }

    /**
     * Convert a snake_case event type to a human-readable title.
     *
     * e.g., 'camera_offline' => 'Camera Offline'
     *       'door_forced'    => 'Door Forced'
     *       'sos_triggered'  => 'SOS Triggered'
     */
    public function generateTitle(string $eventType): string
    {
        // Handle common acronyms
        $acronyms = ['sos', 'nfc', 'pir', 'ptz', 'ups', 'ip', 'api'];

        $words = explode('_', $eventType);

        $words = array_map(function (string $word) use ($acronyms) {
            if (in_array(strtolower($word), $acronyms)) {
                return strtoupper($word);
            }

            return ucfirst($word);
        }, $words);

        return implode(' ', $words);
    }

    /**
     * Check if the current time falls within the tenant's configured quiet hours.
     */
    protected function isQuietHours(int $tenantId, string $provider): bool
    {
        $secret = IntegrationTenantSecret::where('tenant_id', $tenantId)
            ->where('provider', $provider)
            ->connected()
            ->first();

        if (! $secret || ! $secret->config) {
            return false;
        }

        $quietStart = $secret->config['quiet_hours_start'] ?? null;
        $quietEnd = $secret->config['quiet_hours_end'] ?? null;

        if (! $quietStart || ! $quietEnd) {
            return false;
        }

        $now = now()->format('H:i');

        // Handle overnight quiet hours (e.g., 22:00 - 06:00)
        if ($quietStart > $quietEnd) {
            return $now >= $quietStart || $now < $quietEnd;
        }

        return $now >= $quietStart && $now < $quietEnd;
    }
}
