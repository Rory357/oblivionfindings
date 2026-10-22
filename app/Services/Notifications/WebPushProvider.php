<?php

namespace App\Services\Notifications;

use App\Models\UserPushSubscription;
use App\Services\Push\PushEndpoints;
use App\Services\Push\PushResult;
use App\Services\Push\WebPushSender;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Browser push for Laravel notifications sent through PushChannel: medication alerts,
 * second-person confirmations, witness PIN reminders and shift task reminders.
 */
class WebPushProvider
{
    /** Every notice sent this way is a time-sensitive care reminder: deliver within a shift or not at all. */
    public const TTL_SECONDS = 12 * 3600;

    public const URGENCY = 'high';

    public function __construct(
        private readonly WebPushSender $sender,
        private readonly PushEndpoints $endpoints,
    ) {}

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

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Log::warning('Web push skipped: the notification payload could not be encoded.');

            return;
        }

        foreach ($subscriptions as $subscription) {
            $keys = $subscription->keys ?? [];
            if (empty($keys['p256dh']) || empty($keys['auth']) || ! is_string($keys['p256dh']) || ! is_string($keys['auth'])) {
                continue;
            }

            // The server posts to whatever URL the browser registered, so only known push services are contacted.
            if (! $this->endpoints->allows((string) $subscription->token)) {
                Log::warning('Web push skipped: the subscription is not on an allowed push service.', [
                    'subscription_id' => $subscription->id,
                ]);

                continue;
            }

            $result = $this->sender->send((string) $subscription->token, $keys['p256dh'], $keys['auth'], $body, [
                'ttl' => self::TTL_SECONDS,
                'urgency' => self::URGENCY,
            ]);

            if ($result === PushResult::Accepted) {
                continue;
            }

            if ($result === PushResult::Expired) {
                UserPushSubscription::query()
                    ->where('provider', 'webpush')
                    ->where('token', $subscription->token)
                    ->update(['enabled' => false]);
            }

            // Endpoints are bearer addresses and provider bodies may echo them: log the row and result only.
            Log::warning('Web push delivery failed.', [
                'subscription_id' => $subscription->id,
                'result' => $result->value,
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
