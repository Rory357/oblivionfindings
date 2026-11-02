<?php

namespace App\Services\Chat;

use App\Jobs\SendChatRelayRevocation;
use App\Models\AuditLog;
use App\Models\ChatCall;
use App\Models\ChatCallParticipant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The app's side of Oblivion Relay's signed control channel (spec 3.7): ends a person's relay use
 * at once, for example when they're offboarded during a call. Each revoke is sent to every node
 * after the database commit and after the browser has its response, with a 3-second timeout; a
 * node that can't be reached gets a queued retry. The person's action never fails or waits on it.
 * Bodies hold only opaque ids, times and a reason word.
 */
final class OblivionRelay
{
    public const REASONS = ['offboarded', 'left_call', 'call_ended', 'access_removed', 'hand'];

    /** The relay's chat grace (spec 3.2): one-hour credentials plus four hours make the five-hour call. */
    public const GRACE_SECONDS = 14_400;

    /** Retry delays after a failed send: 5, 15 and 30 seconds, then every minute. */
    public const RETRY_DELAYS = [5, 15, 30];

    private const WAITING_KEY = 'chat:relay:waiting:v1';

    private const BATCH = 500;

    public function __construct(private readonly ChatRelayCredentials $credentials) {}

    /** True when every control URL is valid and the secret and key id are set. */
    public function configured(): bool
    {
        $secret = (string) config('chat.relay.control.secret');

        return $this->controlUrls() !== [] && strlen($secret) >= 32 && strlen($secret) <= 512
            && preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', (string) config('chat.relay.control.key_id')) === 1;
    }

    /**
     * One to four node URLs such as http://127.0.0.1:9479: http, a literal loopback or private IP
     * with a port, no path or query. Anything else refuses the whole list.
     *
     * @return list<string>
     */
    public function controlUrls(): array
    {
        $urls = config('chat.relay.control.urls');
        if (! is_array($urls) || ! array_is_list($urls) || count($urls) < 1 || count($urls) > 4) {
            return [];
        }
        foreach ($urls as $url) {
            if (! is_string($url) || ! self::isValidControlUrl($url)) {
                return [];
            }
        }

        return array_values(array_unique($urls));
    }

    public static function isValidControlUrl(string $url): bool
    {
        if (! preg_match('/\Ahttp:\/\/(\[[0-9a-fA-F:]+\]|[0-9.]+):([0-9]{1,5})\z/', $url, $parts) || (int) $parts[2] < 1 || (int) $parts[2] > 65535) {
            return false;
        }
        $ip = trim($parts[1], '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // Loopback, or private: 10/8, 172.16/12, 192.168/16, 100.64/10 or fc00::/7 (spec 3.7).
        if ($ip === '::1' || str_starts_with($ip, '127.')) {
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false
                || ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255'));
        }
        $first = hexdec(substr(bin2hex((string) inet_pton($ip)), 0, 2));

        return ($first & 0xFE) === 0xFC;
    }

    /** base64url (no padding) of HMAC-SHA256(secret, method \n path \n key id \n timestamp \n nonce \n body). */
    public static function sign(string $secret, string $method, string $path, string $keyId, string $timestamp, string $nonce, string $body): string
    {
        $mac = hash_hmac('sha256', $method."\n".$path."\n".$keyId."\n".$timestamp."\n".$nonce."\n".$body, $secret, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /**
     * Revoke opaque ids. $audit carries person_id, call_ids and the actor for the audit row.
     *
     * @param  list<string>  $opaqueIds
     * @param  array{person_id?: int, call_ids?: list<string>, actor?: ?User}  $audit
     */
    public function revoke(array $opaqueIds, string $reason, array $audit = []): void
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Unknown relay revocation reason.');
        }
        $ids = array_values(array_unique(array_filter($opaqueIds, fn ($id) => is_string($id) && preg_match('/\Aobli-chat-[0-9a-f]{64}\z/', $id))));
        if ($ids === [] || ! $this->configured()) {
            return;
        }
        // The latest credential expiry the app could have issued for each id so far.
        $now = CarbonImmutable::now()->getTimestamp();
        $entries = [];
        foreach ($ids as $id) {
            $until = max($now + $this->credentials->seconds(), ($this->credentials->revokedUntil($id) ?? 0) + 1);
            $this->credentials->rememberRevocation($id, $until);
            $entries[] = ['id' => $id, 'expires_up_to' => $until];
        }
        $send = function () use ($entries, $reason, $audit): void {
            if (app()->runningInConsole()) {
                $this->deliver($entries, $reason, $audit);
            } else {
                // After the browser has its response (spec 3.7).
                app()->terminating(fn () => $this->deliver($entries, $reason, $audit));
            }
        };
        DB::afterCommit($send);
    }

    /** The person's ids for every call they were admitted to whose credentials could still be in use. */
    public function revokePerson(User $user, string $reason, ?User $actor = null): void
    {
        $since = CarbonImmutable::now()->subSeconds($this->credentials->seconds() + self::GRACE_SECONDS);
        $callIds = ChatCallParticipant::query()->where('user_id', $user->id)
            ->whereHas('call', fn ($calls) => $calls->whereNull('ended_at')->orWhere('ended_at', '>=', $since))
            ->distinct()->pluck('call_id')->map(fn ($id) => (string) $id)->values()->all();
        if ($callIds === []) {
            return;
        }
        $this->revoke(array_map(fn (string $callId) => ChatRelayCredentials::opaqueId((int) $user->id, $callId), $callIds), $reason,
            ['person_id' => (int) $user->id, 'call_ids' => $callIds, 'actor' => $actor]);
    }

    public function revokeCallMember(ChatCall $call, User|int $user, string $reason, ?User $actor = null): void
    {
        $userId = $user instanceof User ? (int) $user->id : $user;
        $this->revoke([ChatRelayCredentials::opaqueId($userId, (string) $call->id)], $reason,
            ['person_id' => $userId, 'call_ids' => [(string) $call->id], 'actor' => $actor]);
    }

    /** A call ended: every participant's id for it, so nothing outlives the call. */
    public function revokeCall(ChatCall $call, string $reason = 'call_ended'): void
    {
        $userIds = ChatCallParticipant::query()->where('call_id', $call->id)->distinct()->pluck('user_id')->map(fn ($id) => (int) $id);
        $this->revoke($userIds->map(fn (int $id) => ChatRelayCredentials::opaqueId($id, (string) $call->id))->values()->all(), $reason,
            ['call_ids' => [(string) $call->id]]);
    }

    /** First attempt to every node; failures are queued. Never throws. */
    public function deliver(array $entries, string $reason, array $audit = []): void
    {
        try {
            $confirmed = [];
            $waiting = [];
            foreach ($this->controlUrls() as $node) {
                if ($this->send($node, $entries, $reason)) {
                    $confirmed[] = $node;
                } else {
                    $waiting[] = $node;
                }
            }
            if ($waiting !== []) {
                $this->queueRetries((string) Str::uuid(), $waiting, $entries, $reason);
            }
            $actor = $audit['actor'] ?? null;
            AuditLog::create(['user_id' => $actor?->id, 'action' => 'chat.relay.revoked', 'meta' => [
                'actor' => $actor ? 'user' : 'system',
                'person_id' => $audit['person_id'] ?? null,
                'call_ids' => array_values($audit['call_ids'] ?? []),
                'reason' => $reason,
                'nodes_confirmed' => $confirmed,
                'nodes_waiting' => $waiting,
            ]]);
        } catch (Throwable $error) {
            report($error);
        }
    }

    /** One signed POST /control/revoke to one node, in batches of 500. */
    public function send(string $node, array $entries, string $reason): bool
    {
        foreach (array_chunk($entries, self::BATCH) as $batch) {
            $body = json_encode(['revocations' => $batch, 'reason' => $reason], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            try {
                $response = $this->request($node, 'POST', '/control/revoke', $body);
            } catch (Throwable) {
                return false;
            }
            if ($response === null || $response->status() !== 200) {
                return false;
            }
        }

        return true;
    }

    /** Called by the queued job: one more attempt for one node, then the next delay or give up. */
    public function retry(string $revocationId, string $node, array $entries, string $reason, int $attempt, int $giveUpAt): void
    {
        if (CarbonImmutable::now()->getTimestamp() > $giveUpAt) {
            // Nothing the revoke covers can still be relaying by now.
            $this->settle($revocationId, $node);

            return;
        }
        if ($this->send($node, $entries, $reason)) {
            $this->settle($revocationId, $node);
            Log::info('Chat relay revocation delivered after a retry.', ['revocation' => $revocationId, 'attempt' => $attempt]);

            return;
        }
        $this->dispatchRetry($revocationId, $node, $entries, $reason, $attempt + 1, $giveUpAt);
    }

    private function queueRetries(string $revocationId, array $nodes, array $entries, string $reason): void
    {
        $giveUpAt = max(array_column($entries, 'expires_up_to')) + self::GRACE_SECONDS;
        $this->updateWaiting(function (array $waiting) use ($revocationId, $nodes, $giveUpAt): array {
            $waiting[$revocationId] = ['queued_at' => CarbonImmutable::now()->getTimestamp(), 'give_up_at' => $giveUpAt, 'nodes' => $nodes, 'reported' => false];

            return $waiting;
        });
        foreach ($nodes as $node) {
            $this->dispatchRetry($revocationId, $node, $entries, $reason, 1, $giveUpAt);
        }
    }

    private function dispatchRetry(string $revocationId, string $node, array $entries, string $reason, int $attempt, int $giveUpAt): void
    {
        $connection = (string) config('queue.default');
        // A sync queue would run every retry at once, forever; the waiting count and the
        // five-minute error report still show it.
        if (config("queue.connections.{$connection}.driver") === 'sync') {
            return;
        }
        $delay = self::RETRY_DELAYS[$attempt - 1] ?? 60;
        SendChatRelayRevocation::dispatch($revocationId, $node, $entries, $reason, $attempt, $giveUpAt)->delay($delay);
    }

    private function settle(string $revocationId, string $node): void
    {
        $this->updateWaiting(function (array $waiting) use ($revocationId, $node): array {
            if (isset($waiting[$revocationId])) {
                $waiting[$revocationId]['nodes'] = array_values(array_diff($waiting[$revocationId]['nodes'], [$node]));
                if ($waiting[$revocationId]['nodes'] === []) {
                    unset($waiting[$revocationId]);
                }
            }

            return $waiting;
        });
    }

    private function updateWaiting(callable $change): void
    {
        Cache::lock(self::WAITING_KEY.':lock', 5)->block(3, function () use ($change): void {
            $waiting = Cache::get(self::WAITING_KEY, []);
            Cache::forever(self::WAITING_KEY, $change(is_array($waiting) ? $waiting : []));
        });
    }

    /** Revocations still waiting for a node: how many, and the oldest one's age in seconds. */
    public function waiting(): array
    {
        $now = CarbonImmutable::now()->getTimestamp();
        $waiting = array_filter((array) Cache::get(self::WAITING_KEY, []), fn ($entry) => is_array($entry) && ($entry['give_up_at'] ?? 0) >= $now);
        $oldest = $waiting === [] ? null : min(array_column($waiting, 'queued_at'));

        return ['count' => count($waiting), 'oldest_age_seconds' => $oldest === null ? null : $now - $oldest];
    }

    /** Scheduled every minute: one error for each revocation that has waited more than 5 minutes. */
    public function reportStale(): int
    {
        $reported = 0;
        $now = CarbonImmutable::now()->getTimestamp();
        $this->updateWaiting(function (array $waiting) use ($now, &$reported): array {
            foreach ($waiting as $id => $entry) {
                if (($entry['give_up_at'] ?? 0) < $now) {
                    unset($waiting[$id]);
                } elseif (! ($entry['reported'] ?? false) && $now - ($entry['queued_at'] ?? $now) > 300) {
                    Log::error('A Chat relay revocation has waited more than 5 minutes for a relay node.', [
                        'revocation' => $id, 'nodes' => $entry['nodes'] ?? [], 'waiting_seconds' => $now - $entry['queued_at'],
                    ]);
                    $waiting[$id]['reported'] = true;
                    $reported++;
                }
            }

            return $waiting;
        });

        return $reported;
    }

    /** The signed GET /control/status from the first node, or null when it can't be read. */
    public function status(): ?array
    {
        $node = $this->controlUrls()[0] ?? null;
        if ($node === null || ! $this->configured()) {
            return null;
        }
        try {
            $response = $this->request($node, 'GET', '/control/status', '');
        } catch (Throwable) {
            return null;
        }

        return $response?->status() === 200 && is_array($response->json()) ? $response->json() : null;
    }

    private function request(string $node, string $method, string $path, string $body): ?Response
    {
        $secret = (string) config('chat.relay.control.secret');
        $keyId = (string) config('chat.relay.control.key_id');
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $nonce = bin2hex(random_bytes(16));
        $client = Http::timeout(3)->connectTimeout(3)->withoutRedirecting()->withHeaders([
            'X-Relay-Key' => $keyId,
            'X-Relay-Timestamp' => $timestamp,
            'X-Relay-Nonce' => $nonce,
            'X-Relay-Signature' => self::sign($secret, $method, $path, $keyId, $timestamp, $nonce, $body),
        ]);

        return $method === 'GET'
            ? $client->get($node.$path)
            : $client->withBody($body, 'application/json')->post($node.$path);
    }
}
