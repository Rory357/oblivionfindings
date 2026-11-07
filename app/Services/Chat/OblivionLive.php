<?php

namespace App\Services\Chat;

use App\Models\User;
use App\Services\Operations\OpsMessageVisibilityService;
use App\Support\Signing\CompactToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The publisher for Oblivion Live (spec sections 3, 5 and 7): signs connection tickets and sends
 * content-free events to the gateway's loopback API. A failed publish is reported, never thrown
 * into the person's action; the database stays the source of truth and browsers catch up.
 */
class OblivionLive
{
    public const AUDIENCE = 'oblivion-live';

    public const TICKET_SECONDS = 60;

    public const MAX_RECIPIENTS = 500;

    /** Is the gateway switched on and fully configured? (Shared Chat is checked by callers.) */
    public function enabled(): bool
    {
        return (bool) config('chat.live.enabled') && filled(config('chat.live.secret')) && filled(config('chat.live.key_id'))
            && $this->endpoints() !== [];
    }

    /** @return array{url: string, ticket: string, expires_in: int} */
    public function ticket(User $user, ?string $sessionId): array
    {
        $now = now()->getTimestamp();
        $claims = [
            'v' => 1,
            'sub' => (int) $user->id,
            'aud' => self::AUDIENCE,
            // Ties the ticket to one sign-in without revealing the session ID.
            'sid' => self::sessionHash($sessionId),
            'iat' => $now,
            'exp' => $now + self::TICKET_SECONDS,
            'jti' => CompactToken::encode(random_bytes(16)),
            // Family see "Care team is typing" and never staff presence (decision D8).
            'fam' => ! app(OpsMessageVisibilityService::class)->isCurrentStaff($user),
        ];

        return [
            'url' => (string) config('chat.live.path'),
            'ticket' => CompactToken::issue($claims, (string) config('chat.live.key_id'), (string) config('chat.live.secret')),
            'expires_in' => self::TICKET_SECONDS,
        ];
    }

    public static function sessionHash(?string $sessionId): string
    {
        return hash('sha256', (string) $sessionId);
    }

    /**
     * Send one event to these people's open connections, after the current transaction commits.
     *
     * @param  array<int, int>  $userIds
     * @param  array<string, mixed>  $data  IDs, counts and kinds only; never content.
     */
    public function publish(array $userIds, string $type, array $data = []): void
    {
        $this->publishMany([['to' => $userIds, 'type' => $type, 'data' => $data]]);
    }

    /** @param  array<int, array{to: array<int, int>, type: string, data?: array<string, mixed>}>  $events */
    public function publishMany(array $events): void
    {
        $batch = [];
        foreach ($events as $event) {
            $ids = array_values(array_unique(array_map('intval', $event['to'])));
            foreach (array_chunk(array_filter($ids, fn (int $id) => $id > 0), self::MAX_RECIPIENTS) as $chunk) {
                $batch[] = ['to' => $chunk, 'type' => $event['type'], 'data' => (object) ($event['data'] ?? [])];
            }
        }
        if ($batch === [] || ! $this->enabled()) {
            return;
        }
        $this->afterCommit(function () use ($batch): void {
            foreach (array_chunk($batch, 200) as $events) {
                $this->quietly(fn () => $this->call('POST', '/internal/publish', ['events' => $events]));
            }
        });
    }

    /**
     * Close these people's connections with 4403. With session hashes, only those sign-ins close.
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, string>  $sessionHashes
     */
    public function revoke(array $userIds, array $sessionHashes = []): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $id) => $id > 0)));
        if ($ids === [] || ! $this->enabled()) {
            return;
        }
        $body = ['user_ids' => $ids] + ($sessionHashes ? ['session_ids' => array_values($sessionHashes)] : []);
        $this->afterCommit(fn () => $this->quietly(fn () => $this->call('POST', '/internal/revoke', $body)));
    }

    /** @param  array<int, int>  $userIds */
    public function grantCall(string $callId, array $userIds, int $until): void
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if ($ids === [] || ! $this->enabled()) {
            return;
        }
        $this->afterCommit(fn () => $this->quietly(fn () => $this->call('POST', '/internal/call-grant', ['call_id' => $callId, 'user_ids' => $ids, 'until' => $until])));
    }

    /** @param  array<int, int>  $userIds */
    public function revokeCall(string $callId, array $userIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if ($ids === [] || ! $this->enabled()) {
            return;
        }
        $this->afterCommit(fn () => $this->quietly(fn () => $this->call('POST', '/internal/call-revoke', ['call_id' => $callId, 'user_ids' => $ids])));
    }

    /**
     * Read each node's health. Throws when a node cannot be read.
     *
     * @return array<int, array<string, mixed>>
     */
    public function health(): array
    {
        return array_map(fn (string $endpoint) => $this->call('GET', '/internal/health', null, $endpoint), $this->endpoints());
    }

    /** Check a request the gateway signed (presence reports). Each signature is accepted once. */
    public function verifyGatewayRequest(Request $request): bool
    {
        $timestamp = (string) $request->header('X-Live-Timestamp', '');
        $signature = (string) $request->header('X-Live-Signature', '');
        if (preg_match('/\A\d{1,12}\z/', $timestamp) !== 1 || abs(now()->getTimestamp() - (int) $timestamp) > 30
            || preg_match('/\A[a-f0-9]{64}\z/', $signature) !== 1) {
            return false;
        }
        $matched = false;
        foreach ($this->secrets() as $secret) {
            $matched = hash_equals(hash_hmac('sha256', $timestamp."\n".$request->getContent(), $secret), $signature) || $matched;
        }

        return $matched && Cache::add('chat:live:seen:'.$signature, 1, 70);
    }

    /** @return array<int, string> */
    private function secrets(): array
    {
        return array_values(array_filter([(string) config('chat.live.secret'), (string) config('chat.live.previous_secret')], 'strlen'));
    }

    /** @return array<int, string> */
    private function endpoints(): array
    {
        return array_values(array_filter(array_map(fn ($url) => rtrim(trim($url), '/'), explode(',', (string) config('chat.live.internal_url')))));
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    protected function call(string $method, string $path, ?array $body = null, ?string $only = null): array
    {
        $raw = $body === null ? '' : json_encode(['nonce' => bin2hex(random_bytes(12))] + $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $result = [];
        $failure = null;
        // Each node holds only its own connections, so every node gets every call; one node
        // being down does not stop the others.
        foreach ($only ? [$only] : $this->endpoints() as $endpoint) {
            try {
                $timestamp = (string) now()->getTimestamp();
                $request = Http::withHeaders([
                    'X-Live-Timestamp' => $timestamp,
                    'X-Live-Signature' => hash_hmac('sha256', $timestamp."\n".$raw, (string) config('chat.live.secret')),
                    'Accept' => 'application/json',
                ])->connectTimeout(1)->timeout(2)->withoutRedirecting();
                $response = $method === 'GET'
                    ? $request->get($endpoint.$path)
                    : $request->withBody($raw, 'application/json')->post($endpoint.$path);
                $result = (array) $response->throw()->json();
            } catch (Throwable $error) {
                $failure ??= $error;
            }
        }
        if ($failure) {
            throw $failure;
        }

        return $result;
    }

    private function afterCommit(\Closure $send): void
    {
        DB::transactionLevel() > 0 ? DB::afterCommit($send) : $send();
    }

    /** Report a failed call at most once a minute; never let it reach the person's action. */
    private function quietly(\Closure $send): void
    {
        try {
            $send();
        } catch (Throwable $error) {
            try {
                if (Cache::add('chat:live:failure-reported', 1, 60)) {
                    report(new \RuntimeException('Oblivion Live could not be reached; Chat continues by polling.', 0, $error));
                }
            } catch (Throwable) {
                // Reporting must not fail the action either.
            }
        }
    }
}
