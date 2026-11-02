<?php

namespace App\Services\Push;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Oblivion's web push sender: one encrypted message to one browser subscription
 * (RFC 8030 delivery, RFC 8291 encryption, RFC 8292 VAPID).
 *
 * Callers check the endpoint first (PushEndpoints::allows(), which ChatBrowserPushTransport::allowsEndpoint() uses).
 * The payload is never logged and push-service response bodies are never read.
 */
final class WebPushSender
{
    public const URGENCIES = ['very-low', 'low', 'normal', 'high'];

    /** Tokens last 12 hours (RFC 8292 allows 24) and are reused until an hour before they expire. */
    private const TOKEN_LIFETIME = 12 * 3600;

    private const TOKEN_REUSE_MARGIN = 3600;

    /** @var array<string, array{expires: int, header: string}> */
    private array $tokens = [];

    /** @var array{0: string, 1: VapidKeys}|null */
    private ?array $keys = null;

    public function __construct(
        private readonly Repository $config,
        private readonly HttpFactory $http,
        private readonly WebPushEncryption $encryption = new WebPushEncryption,
    ) {}

    /**
     * @param  string  $endpoint  the subscription's push resource URL
     * @param  string  $p256dh  the subscription's public key, base64url
     * @param  string  $auth  the subscription's auth secret, base64url
     * @param  string  $payload  at most WebPushEncryption::MAX_PAYLOAD_BYTES bytes
     * @param  array{ttl: int, urgency: 'very-low'|'low'|'normal'|'high', topic?: string}  $options
     */
    public function send(string $endpoint, string $p256dh, #[\SensitiveParameter] string $auth, #[\SensitiveParameter] string $payload, array $options): PushResult
    {
        $audience = self::audience($endpoint);
        $headers = self::deliveryHeaders($options);
        $uaPublic = Base64Url::decode($p256dh);
        $authSecret = Base64Url::decode($auth);
        if ($audience === null || $headers === null || $uaPublic === null || $authSecret === null) {
            return PushResult::Rejected;
        }

        try {
            $body = $this->encryption->encrypt($uaPublic, $authSecret, $payload);
        } catch (InvalidArgumentException) {
            return PushResult::Rejected;
        } catch (Throwable) {
            return PushResult::Unavailable;
        }

        try {
            $headers['Authorization'] = $this->authorization($audience);
        } catch (Throwable) {
            // Missing or unreadable VAPID settings: fixable without losing the notice.
            return PushResult::Unavailable;
        }

        try {
            $response = $this->http
                ->withHeaders($headers + ['Content-Encoding' => 'aes128gcm'])
                ->withBody($body, 'application/octet-stream')
                ->connectTimeout(3)
                ->timeout(10)
                ->withoutRedirecting()
                ->post($endpoint);
        } catch (Throwable) {
            // No answer: the push service may still have taken it, so callers resend with the same topic.
            return PushResult::Retry;
        }

        return PushResult::fromStatus($response->status());
    }

    /** RFC 6454 origin of the push resource, which the token's "aud" must name. */
    private static function audience(string $endpoint): ?string
    {
        $url = parse_url($endpoint);
        if (! is_array($url) || strtolower($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') === '') {
            return null;
        }
        $port = $url['port'] ?? 443;

        return 'https://'.strtolower($url['host']).($port === 443 ? '' : ':'.$port);
    }

    /** @return array<string, string>|null RFC 8030 TTL, Urgency and Topic headers; null when an option is invalid */
    private static function deliveryHeaders(array $options): ?array
    {
        $ttl = $options['ttl'] ?? null;
        $urgency = $options['urgency'] ?? null;
        if (! is_int($ttl) || $ttl < 0 || ! in_array($urgency, self::URGENCIES, true)) {
            return null;
        }
        $headers = ['TTL' => (string) $ttl, 'Urgency' => $urgency];
        if (array_key_exists('topic', $options)) {
            $topic = $options['topic'];
            if (! is_string($topic) || ! preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $topic)) {
                return null;
            }
            $headers['Topic'] = $topic;
        }

        return $headers;
    }

    /** The "vapid" Authorization value: a signed ES256 JWT and the key that signed it. */
    private function authorization(string $audience): string
    {
        $keys = $this->vapidKeys();
        $subject = trim((string) $this->config->get('services.webpush.subject', ''));
        if ($subject === '') {
            throw new InvalidArgumentException('VAPID_SUBJECT is empty.');
        }
        $now = Carbon::now()->getTimestamp();
        $cacheKey = hash('sha256', implode("\n", [$keys->publicKey, $keys->privateKey, $subject, $audience]));
        $cached = $this->tokens[$cacheKey] ?? null;
        if ($cached !== null && $now < $cached['expires'] - self::TOKEN_REUSE_MARGIN) {
            return $cached['header'];
        }

        $expires = $now + self::TOKEN_LIFETIME;
        $signingInput = Base64Url::encode('{"typ":"JWT","alg":"ES256"}').'.'
            .Base64Url::encode(json_encode(['aud' => $audience, 'exp' => $expires, 'sub' => $subject], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (! openssl_sign($signingInput, $signature, $keys->signingKey(), OPENSSL_ALGO_SHA256)) {
            throw new InvalidArgumentException('The VAPID token could not be signed. OpenSSL said: '.P256::errors());
        }
        $header = 'vapid t='.$signingInput.'.'.Base64Url::encode(P256::signatureToRaw($signature)).', k='.$keys->publicKey;

        if (count($this->tokens) >= 32) {
            $this->tokens = [];
        }
        $this->tokens[$cacheKey] = ['expires' => $expires, 'header' => $header];

        return $header;
    }

    private function vapidKeys(): VapidKeys
    {
        $public = (string) $this->config->get('services.webpush.public_key', '');
        $private = (string) $this->config->get('services.webpush.private_key', '');
        $fingerprint = hash('sha256', $public."\n".$private);
        if ($this->keys === null || $this->keys[0] !== $fingerprint) {
            $this->keys = [$fingerprint, VapidKeys::fromStored($public, $private)];
        }

        return $this->keys[1];
    }
}
