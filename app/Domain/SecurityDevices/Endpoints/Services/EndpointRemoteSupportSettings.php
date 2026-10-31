<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class EndpointRemoteSupportSettings
{
    public const KEY = 'endpoint.remote_support.configuration';

    private const RELAY_SECRET_KEY = 'endpoint.remote_support.relay_secret';

    private const COMMAND_SIGNING_SECRET_KEY = 'endpoint.remote_support.command_signing_secret';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'version' => 0,
            'endpoint_management_enabled' => false,
            'obli_helper_enabled' => false,
            'obli_technician_enabled' => false,
            'remote_support_enabled' => false,
            'relay_urls' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function current(bool $lock = false): array
    {
        if (! Schema::hasTable('app_settings')) {
            return self::defaults();
        }

        $row = AppSetting::query()
            ->where('key', self::KEY)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();

        $stored = is_array($row?->value) ? $row->value : [];
        $merged = array_intersect_key($stored, self::defaults()) + self::defaults();

        $urls = [];
        if (is_array($merged['relay_urls'])) {
            foreach ($merged['relay_urls'] as $url) {
                if (is_string($url) && trim($url) !== '') {
                    $urls[] = trim($url);
                }
            }
        }

        return [
            'version' => max(0, (int) $merged['version']),
            'endpoint_management_enabled' => (bool) $merged['endpoint_management_enabled'],
            'obli_helper_enabled' => (bool) $merged['obli_helper_enabled'],
            'obli_technician_enabled' => (bool) $merged['obli_technician_enabled'],
            'remote_support_enabled' => (bool) $merged['remote_support_enabled'],
            'relay_urls' => array_values($urls),
        ];
    }

    public function administrator(User $actor): User
    {
        $actor = app(AuthorizationEvidenceLockService::class)->lockForUser($actor, ['*']);
        abort_unless(
            $actor->approved_at
            && $actor->email_verified_at
            && ! $actor->isExternalClinicianAccount()
            && $actor->canDo('settings.access.manage')
            && $actor->canDo('integrations.manage_secrets'),
            403,
        );

        return $actor;
    }

    public function canManage(User $actor): bool
    {
        return (bool) (
            $actor->approved_at
            && $actor->email_verified_at
            && ! $actor->isExternalClinicianAccount()
            && $actor->canDo('settings.access.manage')
            && $actor->canDo('integrations.manage_secrets')
        );
    }

    public function endpointEnabled(): bool
    {
        return (bool) config('endpoint_management.enabled')
            || (bool) $this->current()['endpoint_management_enabled'];
    }

    public function obliHelperEnabled(): bool
    {
        return $this->endpointEnabled()
            && ((bool) config('endpoint_management.obli.enabled') || (bool) $this->current()['obli_helper_enabled']);
    }

    public function obliTechnicianEnabled(): bool
    {
        return $this->endpointEnabled()
            && ((bool) config('endpoint_management.obli.technician_enabled', false) || (bool) $this->current()['obli_technician_enabled']);
    }

    public function sessionBrokerEnabled(): bool
    {
        return $this->endpointEnabled()
            && ((bool) config('endpoint_management.remote_support.enabled') || (bool) $this->current()['remote_support_enabled']);
    }

    /** @return list<string> */
    public function relayUrls(): array
    {
        $envUrls = config('endpoint_management.remote_support.relay_urls', []);
        if (is_array($envUrls) && $envUrls !== []) {
            $filtered = [];
            foreach ($envUrls as $url) {
                if (is_string($url) && trim($url) !== '') {
                    $filtered[] = trim($url);
                }
            }
            if ($filtered !== []) {
                return array_values($filtered);
            }
        }

        /** @var list<string> $storedUrls */
        $storedUrls = $this->current()['relay_urls'];

        return $storedUrls;
    }

    public function relaySecret(): ?string
    {
        $envSecret = config('endpoint_management.remote_support.relay_secret');
        if (is_string($envSecret) && $envSecret !== '') {
            return $envSecret;
        }

        if (! Schema::hasTable('app_settings')) {
            return null;
        }

        $encrypted = AppSetting::query()->where('key', self::RELAY_SECRET_KEY)->value('value');
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($encrypted);

            return $decrypted !== '' ? $decrypted : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function commandSigningSecret(): ?string
    {
        $envSecret = config('endpoint_management.command_signing_secret');
        if (is_string($envSecret) && $envSecret !== '') {
            return $envSecret;
        }

        if (! Schema::hasTable('app_settings')) {
            return null;
        }

        $encrypted = AppSetting::query()->where('key', self::COMMAND_SIGNING_SECRET_KEY)->value('value');
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($encrypted);

            return $decrypted !== '' ? $decrypted : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function hasValidRelay(): bool
    {
        $urls = $this->relayUrls();
        $secret = $this->relaySecret();
        if ($urls === [] || count($urls) > 4 || ! is_string($secret) || strlen($secret) < 32 || strlen($secret) > 512) {
            return false;
        }

        foreach ($urls as $url) {
            if (! EndpointRemoteRelay::isValidRelayUrl($url)) {
                return false;
            }
        }

        return true;
    }

    public function isAvailableFor(User $actor): bool
    {
        return $this->sessionBrokerEnabled()
            && $this->hasValidRelay()
            && $actor->canDo('securityDevices.remote.view');
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        $current = $this->current();
        $envRelayUrls = config('endpoint_management.remote_support.relay_urls', []);
        $envRelaySecret = config('endpoint_management.remote_support.relay_secret');
        $hasStoredSecret = Schema::hasTable('app_settings')
            && AppSetting::query()->where('key', self::RELAY_SECRET_KEY)->exists();

        $commandSigningReady = false;
        try {
            app(EndpointCommandSigner::class)->publicKey();
            $commandSigningReady = true;
        } catch (Throwable) {
            $commandSigningReady = false;
        }

        $serverOrigin = null;
        try {
            $serverOrigin = EndpointBootstrap::serverOrigin((string) config('endpoint_management.server_url'));
        } catch (RuntimeException) {
            $serverOrigin = null;
        }

        return $current + [
            'effective_endpoint_enabled' => $this->endpointEnabled(),
            'effective_obli_helper_enabled' => $this->obliHelperEnabled(),
            'effective_obli_technician_enabled' => $this->obliTechnicianEnabled(),
            'effective_remote_support_enabled' => $this->sessionBrokerEnabled(),
            'relay_ready' => $this->hasValidRelay(),
            'relay_secret_saved' => $hasStoredSecret || (is_string($envRelaySecret) && strlen($envRelaySecret) >= 32),
            'relay_configured_via_env' => (is_array($envRelayUrls) && $envRelayUrls !== [])
                || (is_string($envRelaySecret) && $envRelaySecret !== ''),
            'effective_relay_urls' => $this->relayUrls(),
            'command_signing_ready' => $commandSigningReady,
            'server_url' => $serverOrigin,
            'organisation_label' => trim((string) config('endpoint_management.organisation_label', config('app.name'))),
            'script_execution_enabled' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{remote_support: array<string, mixed>, generated_relay_secret: string|null}
     */
    public function save(User $actor, #[\SensitiveParameter] array $input): array
    {
        $data = Validator::make($input, [
            'expected_actor_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'endpoint_management_enabled' => ['required', 'boolean'],
            'obli_helper_enabled' => ['required', 'boolean'],
            'obli_technician_enabled' => ['required', 'boolean'],
            'remote_support_enabled' => ['required', 'boolean'],
            'relay_urls' => ['present', 'array', 'max:4'],
            'relay_urls.*' => ['required', 'string', 'max:512'],
            'relay_secret' => ['nullable', 'string', 'min:32', 'max:512', 'regex:/\A[\x21-\x7e]{32,512}\z/'],
            'generate_relay_secret' => ['sometimes', 'boolean'],
            'clear_relay_secret' => ['required', 'boolean'],
        ])->validate();

        abort_unless((int) $data['expected_actor_id'] === (int) $actor->id, 403);

        $urls = [];
        foreach ($data['relay_urls'] as $index => $rawUrl) {
            $trimmed = trim((string) $rawUrl);
            if (! EndpointRemoteRelay::isValidRelayUrl($trimmed)) {
                throw ValidationException::withMessages([
                    "relay_urls.{$index}" => 'Enter a valid turn:host:port or turns:host:port URL (for example turns:turn.example.com:5349?transport=tcp).',
                ]);
            }
            $urls[] = $trimmed;
        }
        $urls = array_values(array_unique($urls));

        $endpointEnabled = (bool) $data['endpoint_management_enabled'];
        $obliHelperEnabled = (bool) $data['obli_helper_enabled'];
        $obliTechnicianEnabled = (bool) $data['obli_technician_enabled'];
        $remoteSupportEnabled = (bool) $data['remote_support_enabled'];
        $clearSecret = (bool) $data['clear_relay_secret'];
        $generateSecret = (bool) ($data['generate_relay_secret'] ?? false);
        $enteredSecret = is_string($data['relay_secret'] ?? null) && $data['relay_secret'] !== ''
            ? $data['relay_secret']
            : null;

        return DB::transaction(function () use (
            $actor,
            $data,
            $urls,
            $endpointEnabled,
            $obliHelperEnabled,
            $obliTechnicianEnabled,
            $remoteSupportEnabled,
            $clearSecret,
            $generateSecret,
            $enteredSecret,
        ): array {
            $actor = $this->administrator($actor);
            AppSetting::query()->insertOrIgnore([
                'key' => self::KEY,
                'value' => json_encode(self::defaults()),
            ]);
            $current = $this->current(true);
            abort_if((int) $data['expected_version'] !== (int) $current['version'], 409);

            $secretRow = AppSetting::query()->where('key', self::RELAY_SECRET_KEY)->lockForUpdate()->first();
            $envSecret = config('endpoint_management.remote_support.relay_secret');
            $hasEnvSecret = is_string($envSecret) && strlen($envSecret) >= 32 && strlen($envSecret) <= 512;
            $envUrls = config('endpoint_management.remote_support.relay_urls', []);
            $hasEnvUrls = is_array($envUrls) && $envUrls !== [];

            $generatedSecret = null;
            if ($generateSecret && $enteredSecret === null && ! $clearSecret) {
                $generatedSecret = bin2hex(random_bytes(32));
                $enteredSecret = $generatedSecret;
            }

            $willHaveSecret = $hasEnvSecret || (! $clearSecret && ($enteredSecret !== null || $secretRow !== null));
            $willHaveUrls = $hasEnvUrls || $urls !== [];
            $willHaveEndpoint = (bool) config('endpoint_management.enabled') || $endpointEnabled;

            if (($obliHelperEnabled || $obliTechnicianEnabled || $remoteSupportEnabled) && ! $willHaveEndpoint) {
                throw ValidationException::withMessages([
                    'endpoint_management_enabled' => 'Turn on Windows computer management before enabling Obli helper or remote support.',
                ]);
            }

            if ($remoteSupportEnabled && (! $willHaveUrls || ! $willHaveSecret)) {
                throw ValidationException::withMessages([
                    'remote_support_enabled' => 'Attended remote support requires at least one turn: or turns: relay URL and a 32+ character shared secret.',
                ]);
            }

            if ($clearSecret) {
                AppSetting::query()->where('key', self::RELAY_SECRET_KEY)->delete();
            } elseif ($enteredSecret !== null) {
                AppSetting::query()->updateOrCreate(
                    ['key' => self::RELAY_SECRET_KEY],
                    ['value' => Crypt::encryptString($enteredSecret)],
                );
            }

            if ($willHaveEndpoint) {
                $this->ensureCommandSigningKey();
            }

            $next = [
                'version' => (int) $current['version'] + 1,
                'endpoint_management_enabled' => $endpointEnabled,
                'obli_helper_enabled' => $obliHelperEnabled,
                'obli_technician_enabled' => $obliTechnicianEnabled,
                'remote_support_enabled' => $remoteSupportEnabled,
                'relay_urls' => $urls,
            ];

            AppSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $next]);

            AuditLogger::logOrFail('endpoint.remote_support.settings_changed', null, [
                'actor_id' => (int) $actor->id,
                'version' => $next['version'],
                'endpoint_management_enabled' => $endpointEnabled,
                'obli_helper_enabled' => $obliHelperEnabled,
                'obli_technician_enabled' => $obliTechnicianEnabled,
                'remote_support_enabled' => $remoteSupportEnabled,
                'relay_url_count' => count($urls),
                'relay_secret_action' => $clearSecret
                    ? 'cleared'
                    : ($generatedSecret !== null ? 'generated' : ($enteredSecret !== null ? 'replaced' : 'unchanged')),
            ]);

            return [
                'remote_support' => $this->present(),
                'generated_relay_secret' => $generatedSecret,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function verifyRelay(User $actor): array
    {
        DB::transaction(fn () => $this->administrator($actor));

        $urls = $this->relayUrls();
        $secret = $this->relaySecret();
        if ($urls === [] || ! is_string($secret) || strlen($secret) < 32 || strlen($secret) > 512) {
            return [
                'ok' => false,
                'status' => 'not_configured',
                'message' => 'Save at least one turn: or turns: relay URL and a 32+ character shared secret before testing.',
            ];
        }

        foreach ($urls as $url) {
            if (! EndpointRemoteRelay::isValidRelayUrl($url)) {
                return [
                    'ok' => false,
                    'status' => 'invalid_url',
                    'message' => "Relay URL '{$url}' is not a valid turn: or turns: address.",
                ];
            }
        }

        $sampleExpiry = now()->addMinutes(15)->utc()->getTimestamp();
        $sampleUser = $sampleExpiry.':obli-technician-'.str_repeat('a', 64);
        $sampleCredential = base64_encode(hash_hmac('sha1', $sampleUser, $secret, true));
        $credentialValid = preg_match('/\A[A-Za-z0-9+\/]{27}=\z/', $sampleCredential) === 1;

        $signingReady = false;
        try {
            app(EndpointCommandSigner::class)->publicKey();
            $signingReady = true;
        } catch (Throwable) {
            $signingReady = false;
        }

        $httpsReady = false;
        try {
            EndpointBootstrap::serverOrigin((string) config('endpoint_management.server_url'));
            $httpsReady = true;
        } catch (RuntimeException) {
            $httpsReady = false;
        }

        $ok = $credentialValid && $signingReady && $httpsReady;

        return [
            'ok' => $ok,
            'status' => $ok ? 'verified' : 'attention',
            'relay_url_count' => count($urls),
            'turn_rest_hmac_ready' => $credentialValid,
            'command_signing_ready' => $signingReady,
            'https_origin_ready' => $httpsReady,
            'message' => $ok
                ? sprintf(
                    'Verified Coturn TURN REST HMAC-SHA1 credential signing across %d relay %s, Ed25519 command signing, and public HTTPS origin.',
                    count($urls),
                    count($urls) === 1 ? 'URL' : 'URLs',
                )
                : 'Relay credentials validated, but either the public HTTPS server address or Ed25519 command-signing key still needs attention.',
        ];
    }

    private function ensureCommandSigningKey(): void
    {
        $envSecret = config('endpoint_management.command_signing_secret');
        if (is_string($envSecret) && $envSecret !== '') {
            return;
        }

        $existing = AppSetting::query()->where('key', self::COMMAND_SIGNING_SECRET_KEY)->lockForUpdate()->first();
        if ($existing !== null && is_string($existing->value) && $existing->value !== '') {
            return;
        }

        $pair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($pair);
        try {
            AppSetting::query()->updateOrCreate(
                ['key' => self::COMMAND_SIGNING_SECRET_KEY],
                ['value' => Crypt::encryptString(base64_encode($secretKey))],
            );
        } finally {
            sodium_memzero($secretKey);
            sodium_memzero($pair);
        }
    }
}
