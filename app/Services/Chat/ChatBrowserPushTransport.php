<?php

namespace App\Services\Chat;

use App\Models\UserPushSubscription;
use Illuminate\Contracts\Config\Repository;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\NullLogger;

class ChatBrowserPushTransport
{
    public function __construct(protected readonly Repository $config) {}

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
        if (preg_match('/[\x00-\x20\\\\]/', $endpoint) || ! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }
        $url = parse_url($endpoint);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https'
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || ($url['port'] ?? 443) !== 443) {
            return false;
        }
        $host = strtolower($url['host'] ?? '');

        return $host !== '' && ! filter_var($host, FILTER_VALIDATE_IP)
            && in_array($host, (array) $this->config->get('chat.notifications.push_hosts', []), true);
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
        foreach (['p256dh' => 65, 'auth' => 16] as $key => $length) {
            $value = $subscription->keys[$key] ?? '';
            $decoded = is_string($value) ? base64_decode(strtr($value, '-_', '+/'), true) : false;
            if ($decoded === false || strlen($decoded) !== $length || ($key === 'p256dh' && ord($decoded[0]) !== 4)) {
                return 'rejected';
            }
        }
        try {
            $target = Subscription::create(['endpoint' => $subscription->token, 'keys' => $subscription->keys ?? [], 'contentEncoding' => 'aes128gcm']);
            $client = $this->createClient();
        } catch (\Throwable) {
            return 'unavailable';
        }
        try {
            $report = $client->sendOneNotification($target, json_encode($payload, JSON_THROW_ON_ERROR), [
                // Keep provider queues short; Oblivion rechecks current authority before retrying.
                'TTL' => 60, 'urgency' => 'normal',
                'topic' => substr(hash('sha256', (string) ($payload['data']['notification_id'] ?? '')), 0, 32),
            ]);
            $status = $report->getResponse()?->getStatusCode();
            if ($report->isSuccess() && $status !== null && $status >= 200 && $status < 300) {
                return 'accepted';
            }
            if (in_array($status, [404, 410], true)) {
                return 'expired';
            }
            if ($status === null || $status === 408 || $status === 429 || $status >= 500) {
                return 'retry';
            }

            // Authentication/configuration can be repaired without losing the pending notice.
            return in_array($status, [401, 403], true) ? 'unavailable' : 'rejected';
        } catch (\Throwable) {
            // An ambiguous network result may already have reached the provider; reuse the notification ID/topic.
            return 'retry';
        }
    }

    protected function createClient(): WebPush
    {
        return new WebPush(['VAPID' => [
            'subject' => $this->config->get('services.webpush.subject'),
            'publicKey' => $this->config->get('services.webpush.public_key'),
            'privateKey' => $this->config->get('services.webpush.private_key'),
        ]], [], 10, ['connect_timeout' => 3, 'allow_redirects' => false], new NullLogger);
    }
}
