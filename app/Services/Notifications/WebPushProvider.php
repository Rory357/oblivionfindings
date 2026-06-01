<?php

namespace App\Services\Notifications;

use App\Models\UserPushSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class WebPushProvider
{
    /**
     * @param  Collection<int, UserPushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload
     */
    public function sendToSubscriptions(Collection $subscriptions, array $payload): void
    {
        $subscriptions = $subscriptions
            ->filter(fn (UserPushSubscription $subscription) => filled($subscription->token))
            ->values();

        if ($subscriptions->isEmpty() || ! $this->isConfigured()) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ],
        ]);

        foreach ($subscriptions as $subscription) {
            $keys = $subscription->keys ?? [];
            if (empty($keys['p256dh']) || empty($keys['auth'])) {
                continue;
            }

            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->token,
                        'keys' => [
                            'p256dh' => $keys['p256dh'],
                            'auth' => $keys['auth'],
                        ],
                    ]),
                    json_encode($payload, JSON_THROW_ON_ERROR),
                );
            } catch (Throwable $exception) {
                Log::warning('Web push subscription could not be queued.', [
                    'subscription_id' => $subscription->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            $endpoint = (string) $report->getRequest()->getUri();
            $reason = $report->getReason();
            $statusCode = $report->getResponse()?->getStatusCode();

            if (in_array($statusCode, [404, 410], true)) {
                UserPushSubscription::query()
                    ->where('provider', 'webpush')
                    ->where('token', $endpoint)
                    ->update(['enabled' => false]);
            }

            Log::warning('Web push delivery failed.', [
                'endpoint' => $endpoint,
                'status' => $statusCode,
                'reason' => $reason,
            ]);
        }
    }

    private function isConfigured(): bool
    {
        return filled(config('services.webpush.public_key'))
            && filled(config('services.webpush.private_key'))
            && filled(config('services.webpush.subject'));
    }
}
