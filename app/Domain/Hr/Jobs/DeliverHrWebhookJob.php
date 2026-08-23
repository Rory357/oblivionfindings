<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Data\AuthorizedHrWebhookDestination;
use App\Domain\Hr\Exceptions\UnsafeWebhookDestination;
use App\Domain\Hr\Models\HrWebhookDelivery;
use App\Domain\Hr\Models\HrWebhookEndpoint;
use App\Domain\Hr\Services\HrWebhookDestinationPolicy;
use App\Domain\Hr\Services\HrWebhookHeaderPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeliverHrWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_REDIRECTS = 3;

    public function __construct(public int $deliveryId) {}

    public function handle(
        HrWebhookHeaderPolicy $headerPolicy,
        HrWebhookDestinationPolicy $destinationPolicy,
    ): void {
        $delivery = HrWebhookDelivery::query()->with('endpoint')->find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        if (! in_array($delivery->status, [
            HrWebhookDelivery::STATUS_PENDING,
            HrWebhookDelivery::STATUS_RETRYING,
        ], true)) {
            return;
        }

        /** @var HrWebhookEndpoint|null $endpoint */
        $endpoint = $delivery->endpoint;
        if (! $endpoint || ! $endpoint->is_active) {
            $delivery->update([
                'status' => HrWebhookDelivery::STATUS_FAILED,
                'failed_at' => now(),
                'response_code' => null,
                'response_body' => null,
                'error_message' => 'Webhook endpoint is inactive or missing.',
            ]);

            return;
        }

        if ((int) $delivery->attempts >= max(1, (int) $delivery->max_attempts)) {
            $delivery->update([
                'status' => HrWebhookDelivery::STATUS_FAILED,
                'failed_at' => now(),
                'response_code' => null,
                'response_body' => null,
                'error_message' => 'Webhook delivery attempts are exhausted.',
            ]);

            return;
        }

        $attempt = (int) $delivery->attempts + 1;
        $delivery->update([
            'attempts' => $attempt,
            'status' => $attempt > 1 ? HrWebhookDelivery::STATUS_RETRYING : HrWebhookDelivery::STATUS_PENDING,
            'queued_at' => now(),
        ]);

        try {
            $logicalDelivery = $this->logicalDelivery($delivery);
            $body = [
                'id' => $logicalDelivery->event_uuid,
                'type' => $logicalDelivery->event_type,
                'occurred_at' => optional($logicalDelivery->created_at)->toIso8601String(),
                'data' => $logicalDelivery->payload ?? [],
            ];

            $payloadJson = json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $signature = hash_hmac('sha256', $payloadJson, (string) ($endpoint->signing_secret ?? ''));
            $headers = array_merge([
                'X-Oblivion-Webhook-Signature' => $signature,
                'X-Oblivion-Webhook-Event' => $logicalDelivery->event_type,
                'X-Oblivion-Webhook-Delivery' => (string) $logicalDelivery->id,
                'X-Oblivion-Webhook-Idempotency' => $logicalDelivery->idempotency_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $headerPolicy->safeForDelivery($endpoint->headers));

            $response = $this->postWithRedirects(
                $endpoint,
                $destinationPolicy,
                $headers,
                $payloadJson,
            );

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
        } catch (UnsafeWebhookDestination) {
            $this->markFailure(
                $delivery,
                $endpoint,
                $attempt,
                'Webhook destination is not approved.',
                null,
            );
        } catch (\Throwable) {
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

    /**
     * @param  array<string, string>  $headers
     */
    private function postWithRedirects(
        HrWebhookEndpoint $endpoint,
        HrWebhookDestinationPolicy $destinationPolicy,
        array $headers,
        string $payloadJson,
    ): Response {
        $target = $destinationPolicy->authorize((string) $endpoint->target_url);
        $visited = [];

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            if (isset($visited[$target->url])) {
                throw new UnsafeWebhookDestination('Webhook redirect is not approved.');
            }
            $visited[$target->url] = true;

            $response = $this->postToAuthorizedTarget(
                $target,
                max(2, min(30, (int) $endpoint->timeout_seconds)),
                $headers,
                $payloadJson,
            );
            if (! in_array($response->status(), [307, 308], true)) {
                return $response;
            }

            $location = $response->header('Location');
            if (! is_string($location) || $location === '') {
                return $response;
            }
            if ($redirects === self::MAX_REDIRECTS) {
                throw new UnsafeWebhookDestination('Webhook redirect is not approved.');
            }

            $target = $destinationPolicy->authorizeRedirect($target, $location);
        }

        throw new UnsafeWebhookDestination('Webhook redirect is not approved.');
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function postToAuthorizedTarget(
        AuthorizedHrWebhookDestination $target,
        int $timeoutSeconds,
        array $headers,
        string $payloadJson,
    ): Response {
        $options = [
            'allow_redirects' => false,
            'connect_timeout' => min(5, $timeoutSeconds),
            'timeout' => $timeoutSeconds,
            'http_errors' => false,
            'stream' => true,
            'decode_content' => false,
            'proxy' => '',
            'verify' => true,
        ];

        if ($target->requiresDnsPin()) {
            if (! defined('CURLOPT_RESOLVE')) {
                throw new RuntimeException('Pinned webhook transport is unavailable.');
            }
            $options['curl'] = [
                constant('CURLOPT_RESOLVE') => [$target->curlResolveEntry()],
            ];
        }

        return Http::withOptions($options)
            ->withHeaders($headers)
            ->withBody($payloadJson, 'application/json')
            ->post($target->url);
    }

    private function logicalDelivery(HrWebhookDelivery $delivery): HrWebhookDelivery
    {
        $logicalDelivery = $delivery;
        $seen = [];

        while ($logicalDelivery->retry_of_id !== null) {
            if (isset($seen[$logicalDelivery->id])) {
                throw new RuntimeException('Webhook delivery lineage is invalid.');
            }
            $seen[$logicalDelivery->id] = true;

            $parent = HrWebhookDelivery::query()->find($logicalDelivery->retry_of_id);
            if (! $parent || (int) $parent->endpoint_id !== (int) $delivery->endpoint_id) {
                throw new RuntimeException('Webhook delivery lineage is invalid.');
            }
            $logicalDelivery = $parent;
        }

        return $logicalDelivery;
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
