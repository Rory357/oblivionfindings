<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrWebhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookDispatchService
{
    /**
     * All available HR webhook events.
     */
    public const EVENTS = [
        'employee.created',
        'employee.updated',
        'employee.terminated',
        'leave.submitted',
        'leave.approved',
        'leave.declined',
        'timesheet.submitted',
        'timesheet.approved',
        'expense.submitted',
        'expense.approved',
        'compliance.expired',
        'compliance.completed',
    ];

    /**
     * Dispatch webhook payloads to all active subscribers for a given event.
     */
    public function dispatch(string $event, array $payload, ?int $tenantId = null): void
    {
        $webhooks = HrWebhook::query()
            ->when($tenantId, fn ($q) => $q->forTenant($tenantId))
            ->active()
            ->forEvent($event)
            ->get();

        foreach ($webhooks as $webhook) {
            $this->send($webhook, $event, $payload);
        }
    }

    /**
     * Returns the list of all available HR events.
     */
    public function getAvailableEvents(): array
    {
        return self::EVENTS;
    }

    /**
     * Send a webhook payload to a single endpoint.
     */
    protected function send(HrWebhook $webhook, string $event, array $payload): void
    {
        $body = json_encode([
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'data' => $payload,
        ]);

        $signature = hash_hmac('sha256', $body, $webhook->secret);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-HR-Webhook-Signature' => $signature,
                    'X-HR-Webhook-Event' => $event,
                ])
                ->withBody($body, 'application/json')
                ->post($webhook->url);

            if ($response->successful()) {
                $webhook->update([
                    'last_triggered_at' => now(),
                    'failure_count' => 0,
                ]);
            } else {
                $webhook->increment('failure_count');
                $webhook->update(['last_triggered_at' => now()]);

                Log::warning('Webhook delivery failed', [
                    'webhook_id' => $webhook->id,
                    'url' => $webhook->url,
                    'event' => $event,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            $webhook->increment('failure_count');

            Log::error('Webhook delivery exception', [
                'webhook_id' => $webhook->id,
                'url' => $webhook->url,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            // Disable webhook after 10 consecutive failures
            if ($webhook->failure_count >= 10) {
                $webhook->update(['is_active' => false]);
            }
        }
    }

    /**
     * Send a test payload to a webhook endpoint.
     */
    public function sendTest(HrWebhook $webhook): array
    {
        $body = json_encode([
            'event' => 'webhook.test',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'message' => 'This is a test webhook payload.',
            ],
        ]);

        $signature = hash_hmac('sha256', $body, $webhook->secret);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-HR-Webhook-Signature' => $signature,
                    'X-HR-Webhook-Event' => 'webhook.test',
                ])
                ->withBody($body, 'application/json')
                ->post($webhook->url);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'body' => $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 0,
                'body' => $e->getMessage(),
            ];
        }
    }
}
