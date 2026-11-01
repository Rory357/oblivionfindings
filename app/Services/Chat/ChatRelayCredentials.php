<?php

namespace App\Services\Chat;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteRelay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * TURN REST credentials for Oblivion Relay (spec 3.6). The username is
 * "<expiry>:obli-chat-<hmac>" with one opaque id per person per call, so the relay can apply its
 * per-person quota within a call but can't link one person's calls. Credentials are computed on
 * demand and never stored, logged or audited.
 */
final class ChatRelayCredentials
{
    public const MAX_SECONDS = 3600;

    private const FLOOR_KEY = 'chat:relay:revoked-until:v1:';

    /** The relay entry for an admitted participant, or [] when the relay isn't configured. */
    public function for(User $user, string $callUuid): array
    {
        $urls = $this->urls();
        $secret = (string) config('chat.relay.secret');
        if ($urls === [] || strlen($secret) < 32 || strlen($secret) > 512) {
            return [];
        }
        $id = self::opaqueId((int) $user->id, $callUuid);
        $expiry = CarbonImmutable::now()->getTimestamp() + $this->seconds();
        // Never at or before an expires_up_to sent in a revocation for this id (spec 3.6).
        $floor = Cache::get(self::FLOOR_KEY.$id);
        if (is_int($floor) && $expiry <= $floor) {
            $expiry = $floor + 1;
        }
        $username = $expiry.':'.$id;

        return [
            'urls' => $urls,
            'username' => $username,
            'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
            'expires_at' => CarbonImmutable::createFromTimestamp($expiry, 'UTC')->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function configured(): bool
    {
        $secret = (string) config('chat.relay.secret');

        return $this->urls() !== [] && strlen($secret) >= 32 && strlen($secret) <= 512;
    }

    /** obli-chat-<HMAC-SHA256 of "chat|call|user" under the app key>. */
    public static function opaqueId(int $userId, string $callUuid): string
    {
        return 'obli-chat-'.hash_hmac('sha256', 'chat|'.$callUuid.'|'.$userId, (string) config('app.key'));
    }

    public function seconds(): int
    {
        return max(60, min(self::MAX_SECONDS, (int) config('chat.relay.credential_seconds', self::MAX_SECONDS)));
    }

    /** Remembered by OblivionRelay when it sends a revocation, until that time passes. */
    public function rememberRevocation(string $opaqueId, int $expiresUpTo): void
    {
        $current = Cache::get(self::FLOOR_KEY.$opaqueId);
        $until = max($expiresUpTo, is_int($current) ? $current : 0);
        $ttl = $until - CarbonImmutable::now()->getTimestamp() + 60;
        if ($ttl > 0) {
            Cache::put(self::FLOOR_KEY.$opaqueId, $until, $ttl);
        }
    }

    public function revokedUntil(string $opaqueId): ?int
    {
        $floor = Cache::get(self::FLOOR_KEY.$opaqueId);

        return is_int($floor) ? $floor : null;
    }

    /**
     * The configured URLs, UDP first, then TCP, then TLS. Any invalid URL, or more than four,
     * means none: a half-valid list is a configuration mistake to report, not to guess around.
     *
     * @return list<string>
     */
    public function urls(): array
    {
        $urls = config('chat.relay.urls');
        if (! is_array($urls) || ! array_is_list($urls) || count($urls) < 1 || count($urls) > 4) {
            return [];
        }
        foreach ($urls as $url) {
            if (! is_string($url) || ! EndpointRemoteRelay::isValidRelayUrl($url)) {
                return [];
            }
        }
        $rank = fn (string $url): int => str_starts_with($url, 'turns:') ? 2 : (str_ends_with($url, '?transport=tcp') ? 1 : 0);
        usort($urls, fn (string $a, string $b): int => $rank($a) <=> $rank($b));

        return $urls;
    }
}
