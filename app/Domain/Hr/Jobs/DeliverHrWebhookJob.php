<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrWebhookDelivery;
use App\Domain\Hr\Models\HrWebhookEndpoint;
use App\Domain\Hr\Services\HrWebhookHeaderPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DeliverHrWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $deliveryId) {}

    public function handle(HrWebhookHeaderPolicy $headerPolicy): void
    {
        $delivery = HrWebhookDelivery::query()->with('endpoint')->find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        if ($delivery->status === HrWebhookDelivery::STATUS_SUCCESS) {
            return;
        }

        /** @var HrWebhookEndpoint|null $endpoint */
        $endpoint = $delivery->endpoint;
        if (! $endpoint || ! $endpoint->is_active) {
            $delivery->update([
                'status' => HrWebhookDelivery::STATUS_FAILED,
                'failed_at' => now(),
                'error_message' => 'Webhook endpoint is inactive or missing.',
            ]);

            return;
        }

        $attempt = (int) $delivery->attempts + 1;
        $delivery->update([
            'attempts' => $attempt,
            'status' => $attempt > 1 ? HrWebhookDelivery::STATUS_RETRYING : HrWebhookDelivery::STATUS_PENDING,
            'queued_at' => now(),
        ]);

        $body = [
            'id' => $delivery->event_uuid,
            'type' => $delivery->event_type,
            'occurred_at' => optional($delivery->created_at)->toIso8601String(),
            'data' => $delivery->payload ?? [],
        ];

        $payloadJson = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $signature = hash_hmac('sha256', $payloadJson, (string) ($endpoint->signing_secret ?? ''));

        $headers = [
            'X-Oblivion-Webhook-Signature' => $signature,
            'X-Oblivion-Webhook-Event' => $delivery->event_type,
            'X-Oblivion-Webhook-Delivery' => (string) $delivery->id,
            'X-Oblivion-Webhook-Idempotency' => $delivery->idempotency_key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $customHeaders = $headerPolicy->safeForDelivery($endpoint->headers);

        $headers = array_merge($headers, $customHeaders);

        try {
            $response = Http::timeout(max(2, (int) $endpoint->timeout_seconds))
                ->withHeaders($headers)
                ->post($endpoint->target_url, $body);

            if ($response->successful()) {
                $delivery->update([
                    'status' => HrWebhookDelivery::STATUS_SUCCESS,
                    'delivered_at' => now(),
                    'failed_at' => null,
                    'response_code' => $response->status(),
                    'response_body' => null,
                    'error_message' => null,
                ]);

                $endpoint->update([
                    'last_delivery_at' => now(),
                    'last_status' => HrWebhookDelivery::STATUS_SUCCESS,
                    'last_error' => null,
                ]);

                return;
            }

            $this->markFailure(
                $delivery,
                $endpoint,
                $attempt,
                "Webhook endpoint returned HTTP {$response->status()}.",
                $response->status(),
            );
        } catch (\Throwable $exception) {
            report($exception);
            $this->markFailure(
                $delivery,
                $endpoint,
                $attempt,
                'Webhook delivery failed before a response was received.',
                null,
            );
        }

        if ($attempt < (int) $delivery->max_attempts) {
            $this->release($this->backoffSeconds($attempt));
        }
    }

    private function markFailure(
        HrWebhookDelivery $delivery,
        HrWebhookEndpoint $endpoint,
        int $attempt,
        string $error,
        ?int $responseCode,
    ): void {
        $isFinal = $attempt >= (int) $delivery->max_attempts;

        $delivery->update([
            'status' => $isFinal ? HrWebhookDelivery::STATUS_FAILED : HrWebhookDelivery::STATUS_RETRYING,
            'failed_at' => $isFinal ? now() : null,
            'response_code' => $responseCode,
            'response_body' => null,
            'error_message' => mb_substr($error, 0, 2000),
        ]);

        $endpoint->update([
            'last_status' => $isFinal ? HrWebhookDelivery::STATUS_FAILED : HrWebhookDelivery::STATUS_RETRYING,
            'last_error' => mb_substr($error, 0, 2000),
        ]);
    }

    private function backoffSeconds(int $attempt): int
    {
        return match ($attempt) {
            1 => 10,
            2 => 60,
            default => 300,
        };
    }
}
