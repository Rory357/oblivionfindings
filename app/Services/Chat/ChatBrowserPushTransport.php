<?php

namespace App\Services\Chat;

use App\Models\UserPushSubscription;
use App\Services\Push\PushEndpoints;
use App\Services\Push\WebPushSender;
use Illuminate\Contracts\Config\Repository;

class ChatBrowserPushTransport
{
    public function __construct(protected readonly Repository $config, protected readonly WebPushSender $sender) {}

    public function configured(): bool
    {
        foreach (['subject', 'public_key', 'private_key'] as $key) {
            if (trim((string) $this->config->get('services.webpush.'.$key, '')) === '') {
                return false;
            }
        }

        return count((array) $this->config->get('chat.notifications.push_hosts', [])) > 0;
    }

    public function allowsEndpoint(string $endpoint): bool
    {
        return (new PushEndpoints($this->config))->allows($endpoint);
    }

    /** Provider acceptance is not proof of device display. Never retain provider bodies or endpoints in diagnostics. */
    public function send(UserPushSubscription $subscription, array $payload): string
    {
        if (! $this->configured()) {
            return 'unavailable';
        }
        if ($subscription->provider !== 'webpush' || ! $subscription->enabled || ! $this->allowsEndpoint($subscription->token)) {
            return 'rejected';
        }
        $p256dh = $subscription->keys['p256dh'] ?? null;
        $auth = $subscription->keys['auth'] ?? null;
        if (! is_string($p256dh) || ! is_string($auth)) {
            return 'rejected';
        }
        try {
            // The sender rejects malformed keys before any network call, and maps 401/403 to
            // 'unavailable' so a configuration fix doesn't lose the pending notice.
            return $this->sender->send($subscription->token, $p256dh, $auth, json_encode($payload, JSON_THROW_ON_ERROR), [
                // Keep provider queues short; Oblivion rechecks current authority before retrying.
                'ttl' => 60, 'urgency' => 'normal',
                'topic' => substr(hash('sha256', (string) ($payload['data']['notification_id'] ?? '')), 0, 32),
            ])->value;
        } catch (\Throwable) {
            // An ambiguous result may already have reached the provider; reuse the notification ID/topic.
            return 'retry';
        }
    }
}
