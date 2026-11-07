<?php

namespace App\Services\Chat;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\SchemaCache;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Chat calling settings an administrator keeps on Settings › Chat instead of in the server's
 * .env (Rory, 3 Nov 2026): the Oblivion Relay connection and the self-hosted voice-call server.
 * A value saved on the page replaces the .env value for that setting; clearing it falls back to
 * .env. The row is encrypted with the app key, keys never go back to the browser, and the audit
 * records which keys changed, never their values.
 *
 * The saved values are laid over the config whenever one of READERS is resolved, so a request
 * that never touches calling pays nothing and a queue worker picks up a change at its next job.
 */
final class ChatCallingSettings
{
    /** Page field => config key. */
    public const FIELDS = [
        'relay_urls' => 'chat.relay.urls',
        'relay_secret' => 'chat.relay.secret',
        'relay_credential_seconds' => 'chat.relay.credential_seconds',
        'relay_health_url' => 'chat.relay.health_url',
        'relay_control_urls' => 'chat.relay.control.urls',
        'relay_control_secret' => 'chat.relay.control.secret',
        'relay_control_key_id' => 'chat.relay.control.key_id',
        'calling_url' => 'chat.calling.url',
        'calling_api_url' => 'chat.calling.api_url',
        'calling_api_key' => 'chat.calling.api_key',
        'calling_api_secret' => 'chat.calling.api_secret',
    ];

    public const SECRETS = ['relay_secret', 'relay_control_secret', 'calling_api_secret'];

    /** Every class that reads these settings from the config. */
    public const READERS = [ChatRelayCredentials::class, OblivionRelay::class, SelfHostedCalling::class, ChatServiceHealth::class, ChatWebhookVerifier::class];

    private const CACHE_KEY = 'chat:calling-settings:v1';

    /** The .env value of each field this instance has laid a saved value over, restored when that value is cleared. */
    private array $server = [];

    public function __construct(private readonly Repository $config) {}

    /** Lay the saved values over the config. Never throws: without the table or the database, .env stays in charge. */
    public function apply(): void
    {
        try {
            $saved = $this->stored()['settings'];
        } catch (\Throwable) {
            return;
        }
        foreach (self::FIELDS as $field => $key) {
            if (array_key_exists($field, $saved)) {
                if (! array_key_exists($field, $this->server)) {
                    $this->server[$field] = $this->config->get($key);
                }
                $this->config->set($key, $saved[$field]);
            } elseif (array_key_exists($field, $this->server)) {
                $this->config->set($key, $this->server[$field]);
                unset($this->server[$field]);
            }
        }
    }

    /** What the page shows: each value and where it comes from, and whether each part is ready. Never a key. */
    public function page(): array
    {
        $this->apply();
        $stored = $this->stored();
        $fields = [];
        foreach (self::FIELDS as $field => $key) {
            $onPage = array_key_exists($field, $stored['settings']);
            $server = $this->serverValue($field);
            $fields[$field] = in_array($field, self::SECRETS, true)
                ? ['on_page' => $onPage, 'on_server' => self::present($server)]
                : ['on_page' => $onPage, 'value' => $onPage ? $stored['settings'][$field] : null, 'server' => self::present($server) ? $server : null];
        }

        return [
            'version' => $stored['version'],
            'updated_at' => $stored['updated_at'],
            'updated_by' => $stored['updated_by'] === null ? null : (User::whereKey($stored['updated_by'])->value('name') ?? 'Former staff member'),
            'fields' => $fields,
            'status' => [
                'relay' => app(ChatRelayCredentials::class)->configured(),
                'control' => app(OblivionRelay::class)->configured(),
                'calling' => app(SelfHostedCalling::class)->configured(),
                'calling_verified' => (bool) $this->config->get('chat.calling.verified') && (bool) $this->config->get('chat.calling.authorization_gateway_verified'),
            ],
        ];
    }

    /**
     * Save a reviewed change. $values holds the plain fields being set (null: use .env), $secrets the
     * keys being replaced, and $clear the keys going back to .env. Returns the page.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $secrets
     * @param  list<string>  $clear
     */
    public function save(User $actor, int $version, array $values, array $secrets, array $clear): array
    {
        DB::transaction(function () use ($actor, $version, $values, $secrets, $clear): void {
            $row = DB::table('chat_calling_settings')->where('id', 1)->lockForUpdate()->first();
            $current = (int) ($row->version ?? 0);
            if ($current !== $version) {
                abort(response()->json(['message' => 'Someone else saved the calling settings while you were editing. Reload them and make your change again.', 'code' => 'chat_calling_conflict'], 409));
            }
            $before = $row ? self::decode($row->settings) : [];
            $after = $before;
            foreach (self::FIELDS as $field => $key) {
                if (in_array($field, self::SECRETS, true)) {
                    if (array_key_exists($field, $secrets)) {
                        $after[$field] = $secrets[$field];
                    } elseif (in_array($field, $clear, true)) {
                        unset($after[$field]);
                    }
                } elseif (array_key_exists($field, $values)) {
                    if ($values[$field] === null) {
                        unset($after[$field]);
                    } else {
                        $after[$field] = $values[$field];
                    }
                }
            }
            $relayKey = $after['relay_secret'] ?? $this->serverValue('relay_secret');
            $controlKey = $after['relay_control_secret'] ?? $this->serverValue('relay_control_secret');
            if (self::present($relayKey) && $relayKey === $controlKey) {
                throw ValidationException::withMessages(['secrets' => 'The relay key and the control key must be different. Generate a new control key.']);
            }
            $payload = ['settings' => Crypt::encryptString(json_encode($after, JSON_THROW_ON_ERROR)), 'version' => $current + 1, 'updated_by' => $actor->id, 'updated_at' => now()];
            if ($row) {
                DB::table('chat_calling_settings')->where('id', 1)->update($payload);
            } else {
                DB::table('chat_calling_settings')->insert($payload + ['id' => 1, 'created_at' => now()]);
            }
            AuditLog::create(['user_id' => $actor->id, 'action' => 'chat.calling.updated', 'meta' => ['changes' => self::changes($before, $after, $secrets)]]);
        });
        Cache::forget(self::CACHE_KEY);

        return $this->page();
    }

    /** @return array{settings: array<string, mixed>, version: int, updated_at: ?string, updated_by: ?int} */
    private function stored(): array
    {
        // A short cache, so a reader that raced a save can't keep the old values for long.
        $row = Cache::remember(self::CACHE_KEY, 60, fn (): array => SchemaCache::hasTable('chat_calling_settings')
            ? (array) (DB::table('chat_calling_settings')->where('id', 1)->first() ?? [])
            : []);

        return [
            'settings' => isset($row['settings']) ? self::decode($row['settings']) : [],
            'version' => (int) ($row['version'] ?? 0),
            'updated_at' => isset($row['updated_at']) ? CarbonImmutable::parse($row['updated_at'])->toISOString() : null,
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
        ];
    }

    private function serverValue(string $field): mixed
    {
        return array_key_exists($field, $this->server) ? $this->server[$field] : $this->config->get(self::FIELDS[$field]);
    }

    private static function decode(string $settings): array
    {
        $decoded = json_decode(Crypt::decryptString($settings), true, 8, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_intersect_key($decoded, self::FIELDS) : [];
    }

    private static function present(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * Plain fields record their before and after (null: the .env value); keys record only that they
     * were added, replaced or removed.
     *
     * @return list<array<string, mixed>>
     */
    private static function changes(array $before, array $after, array $secrets): array
    {
        $changes = [];
        foreach (self::FIELDS as $field => $key) {
            if (in_array($field, self::SECRETS, true)) {
                $change = match (true) {
                    array_key_exists($field, $secrets) => array_key_exists($field, $before) ? 'replaced' : 'added',
                    array_key_exists($field, $before) && ! array_key_exists($field, $after) => 'removed',
                    default => null,
                };
                if ($change !== null) {
                    $changes[] = ['field' => $field, 'change' => $change];
                }
            } elseif (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[] = ['field' => $field, 'before' => $before[$field] ?? null, 'after' => $after[$field] ?? null];
            }
        }

        return $changes;
    }
}
