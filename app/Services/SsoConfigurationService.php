<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Canonical, single-organisation sign-in configuration. Never returns a secret to the browser. */
class SsoConfigurationService
{
    public const PROVIDERS = ['microsoft', 'google'];

    /** Public login pages receive availability only, never configuration. */
    public function availability(string $audience): array
    {
        abort_unless(in_array($audience, ['staff', 'portal'], true), 404);
        $available = [];
        foreach (self::PROVIDERS as $provider) {
            $config = $this->provider($provider);
            $available[$provider] = $config[$audience.'_enabled'] && $this->configurationChecks($provider, $config) === [];
        }

        return $available;
    }

    public function provider(string $provider): array
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $stored = $this->stored($provider);
        $secretSource = $stored['secret_source'] ?? 'deployment';
        $secret = '';
        $secretReadable = true;
        try {
            $secret = match ($secretSource) {
                'saved' => Crypt::decryptString((string) ($stored['secret_ciphertext'] ?? '')),
                'removed' => '',
                default => (string) config("services.{$provider}.client_secret", ''),
            };
        } catch (DecryptException) {
            $secretReadable = false;
        }

        $defaults = [
            'client_id' => (string) config("services.{$provider}.client_id", ''),
            'directory_id' => $provider === 'microsoft' ? (string) config('services.microsoft.tenant', '') : '',
            'domain' => strtolower((string) config('sso.staff_domain', '')),
            // Deployment configuration previously enabled both routes. Retain that
            // choice only when credentials exist; a local save has explicit flags.
            'staff_enabled' => filled(config("services.{$provider}.client_id")) && filled(config("services.{$provider}.client_secret")),
            'portal_enabled' => filled(config("services.{$provider}.client_id")) && filled(config("services.{$provider}.client_secret")),
        ];
        $resolved = array_replace($defaults, array_intersect_key($stored, $defaults));
        $resolved['version'] = (int) ($stored['version'] ?? 0);
        $resolved['source'] = $stored === [] ? 'deployment' : 'saved';
        $resolved['saved_at'] = $stored['saved_at'] ?? null;
        $resolved['secret_source'] = $secretSource;
        $resolved['client_secret'] = $secret;
        $resolved['secret_readable'] = $secretReadable;
        $resolved['callback_urls'] = $this->callbackUrls($provider);

        return $resolved;
    }

    public function presentProvider(string $provider): array
    {
        $config = $this->provider($provider);
        $config['secret_present'] = $config['client_secret'] !== '';
        $config['checks'] = $this->configurationChecks($provider, $config);
        // No identity/token count is evidence of present provider consent.
        $config['consent_status'] = 'unverified';
        $config['sign_in_status'] = 'unverified';
        unset($config['client_secret']);

        return $config;
    }

    public function provisioning(): array
    {
        $stored = $this->stored('provisioning');

        return [
            'version' => (int) ($stored['version'] ?? 0),
            'source' => $stored === [] ? 'deployment' : 'saved',
            'saved_at' => $stored['saved_at'] ?? null,
            'auto_create_staff' => (bool) ($stored['auto_create_staff'] ?? true),
            'auto_link_existing' => (bool) ($stored['auto_link_existing'] ?? true),
            'portal_auto_create' => (bool) ($stored['portal_auto_create'] ?? true),
            // These are existing governed access boundaries, never user-selected
            // arbitrary roles or automatic approval through a settings form.
            'require_admin_approval' => true,
            'default_role_name' => 'support_worker',
            'portal_role_name' => 'next_of_kin',
        ];
    }

    public function saveProvider(User $actor, string $provider, array $input): array
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $data = Validator::make($input, [
            'expected_version' => ['required', 'integer', 'min:0'],
            'client_id' => ['nullable', 'string', 'max:255', $provider === 'microsoft' ? 'uuid' : 'regex:/^[A-Za-z0-9._-]+$/'],
            'directory_id' => $provider === 'microsoft' ? ['nullable', 'uuid'] : ['prohibited'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/'],
            'staff_enabled' => ['required', 'boolean'],
            'portal_enabled' => ['required', 'boolean'],
            'secret_action' => ['required', Rule::in(['keep', 'replace', 'remove'])],
            'client_secret' => ['nullable', 'string', 'max:4096', 'required_if:secret_action,replace', 'prohibited_unless:secret_action,replace'],
            'confirm_secret_removal' => ['accepted_if:secret_action,remove'],
            'redirect' => ['prohibited'],
            'redirect_uri' => ['prohibited'],
            'callback_urls' => ['prohibited'],
        ])->validate();

        return $this->mutate($actor, $provider, (int) $data['expected_version'], function (array $stored) use ($provider, $data): array {
            $current = $this->provider($provider);
            $next = [
                'client_id' => trim((string) ($data['client_id'] ?? '')),
                'directory_id' => $provider === 'microsoft' ? strtolower((string) ($data['directory_id'] ?? '')) : '',
                'domain' => strtolower((string) ($data['domain'] ?? '')),
                'staff_enabled' => (bool) $data['staff_enabled'],
                'portal_enabled' => (bool) $data['portal_enabled'],
                'secret_source' => $stored['secret_source'] ?? 'deployment',
            ];
            if (isset($stored['secret_ciphertext'])) {
                $next['secret_ciphertext'] = $stored['secret_ciphertext'];
            }
            if ($data['secret_action'] === 'replace') {
                $next['secret_source'] = 'saved';
                $next['secret_ciphertext'] = Crypt::encryptString($data['client_secret']);
            } elseif ($data['secret_action'] === 'remove') {
                $next['secret_source'] = 'removed';
                unset($next['secret_ciphertext']);
                if ($next['staff_enabled'] || $next['portal_enabled']) {
                    throw ValidationException::withMessages(['secret_action' => 'Disable staff and portal sign-in before removing the secret.']);
                }
            } elseif ($current['secret_readable'] === false) {
                throw ValidationException::withMessages(['client_secret' => 'The saved secret cannot be read. Explicitly replace or remove it.']);
            } elseif ($next['client_id'] !== $current['client_id'] && $current['client_secret'] !== '') {
                throw ValidationException::withMessages(['client_secret' => 'Replace the secret when changing the client ID.']);
            }

            $secret = $data['secret_action'] === 'replace' ? $data['client_secret'] : ($data['secret_action'] === 'remove' ? '' : $current['client_secret']);
            $checks = $this->configurationChecks($provider, [...$next, 'client_secret' => $secret, 'secret_readable' => true, 'callback_urls' => $this->callbackUrls($provider)]);
            if (($next['staff_enabled'] || $next['portal_enabled']) && $checks !== []) {
                throw ValidationException::withMessages($checks);
            }

            return $next;
        }, $data['secret_action']);
    }

    public function saveProvisioning(User $actor, array $input): array
    {
        $data = Validator::make($input, [
            'expected_version' => ['required', 'integer', 'min:0'],
            'auto_create_staff' => ['required', 'boolean'],
            'auto_link_existing' => ['required', 'boolean'],
            'portal_auto_create' => ['required', 'boolean'],
            'require_admin_approval' => ['prohibited'],
            'default_role_id' => ['prohibited'],
            'default_role_name' => ['prohibited'],
            'portal_role_name' => ['prohibited'],
        ])->validate();

        return $this->mutate($actor, 'provisioning', (int) $data['expected_version'], fn (): array => [
            'auto_create_staff' => (bool) $data['auto_create_staff'],
            'auto_link_existing' => (bool) $data['auto_link_existing'],
            'portal_auto_create' => (bool) $data['portal_auto_create'],
        ]);
    }

    /** No provider request, client secret or token leaves this local check. */
    public function check(string $provider): array
    {
        $presented = $this->presentProvider($provider);

        return [
            'provider' => $provider,
            'version' => $presented['version'],
            'checked_at' => now()->toIso8601String(),
            'configuration_valid' => $presented['checks'] === [],
            'checks' => $presented['checks'],
            'consent_status' => 'unverified',
            'sign_in_status' => 'unverified',
            'message' => 'Local configuration checked. Provider consent and a successful sign-in have not been verified.',
        ];
    }

    public function callbackUrls(string $provider): array
    {
        $base = rtrim((string) config('app.url'), '/');

        return [
            'staff' => $base.'/auth/'.$provider.'/callback',
            'portal' => $base.'/portal/auth/'.$provider.'/callback',
        ];
    }

    private function configurationChecks(string $provider, array $config): array
    {
        $errors = [];
        if ($config['client_id'] === '') {
            $errors['client_id'] = 'Enter the provider client ID.';
        }
        if ($config['client_secret'] === '' || ! $config['secret_readable']) {
            $errors['client_secret'] = 'A readable client secret is required.';
        }
        if ($provider === 'microsoft' && ! Str::isUuid($config['directory_id'])) {
            $errors['directory_id'] = 'Use the organisation’s Microsoft directory UUID.';
        }
        if ($config['staff_enabled'] && $config['domain'] === '') {
            $errors['domain'] = 'An exact organisation email domain is required for staff sign-in.';
        }
        foreach ($config['callback_urls'] as $url) {
            $parts = parse_url($url);
            $scheme = $parts['scheme'] ?? '';
            $host = $parts['host'] ?? '';
            $localHttp = app()->environment(['local', 'testing']) && $scheme === 'http'
                && ($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test'));
            if (! $parts || ! $host || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ($scheme !== 'https' && ! $localHttp)) {
                $errors['callback_urls'] = 'The deployment application URL must be a valid HTTPS origin (local test hosts may use HTTP).';
            }
        }

        return $errors;
    }

    private function stored(string $section): array
    {
        $value = AppSetting::query()->useWritePdo()->where('key', 'settings.sso.'.$section)->value('value');

        return is_array($value) ? $value : [];
    }

    private function mutate(User $actor, string $section, int $expectedVersion, callable $next, string $secretAction = 'unchanged'): array
    {
        $actorId = (int) $actor->id;
        DB::transaction(function () use ($actorId, $section, $expectedVersion, $next, $secretAction): void {
            app(SsoGroupMappingLockService::class)->lockMappingSet();
            $locked = app(AuthorizationEvidenceLockService::class)->lockForUsers([$actorId], ['settings.access.manage']);
            abort_unless($locked->get($actorId)?->isApproved(), 403);
            abort_unless($locked->get($actorId)?->canDo('settings.access.manage'), 403);
            $setting = AppSetting::query()->where('key', 'settings.sso.'.$section)->lockForUpdate()->first();
            $stored = is_array($setting?->value) ? $setting->value : [];
            abort_unless((int) ($stored['version'] ?? 0) === $expectedVersion, 409, 'These SSO settings changed. Review the current saved configuration before saving again.');
            $data = $next($stored);
            $changed = array_keys(array_filter($data, fn ($value, $key): bool => $key !== 'secret_ciphertext' && ($stored[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
            $data['version'] = $expectedVersion + 1;
            $data['saved_at'] = now()->toIso8601String();
            AppSetting::query()->updateOrCreate(['key' => 'settings.sso.'.$section], ['value' => $data]);
            AuditLogger::logOrFail('settings.sso.updated', null, [
                'actor_id' => $actorId,
                'section' => $section,
                'version' => $data['version'],
                'changed_fields' => $changed,
                'secret_action' => $secretAction,
            ]);
        }, 3);

        return $section === 'provisioning' ? $this->provisioning() : $this->presentProvider($section);
    }
}
