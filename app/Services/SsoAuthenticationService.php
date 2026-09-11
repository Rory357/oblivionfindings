<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use SocialiteProviders\Google\Provider as GoogleProvider;
use SocialiteProviders\Manager\Config;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

/** Resolves only sign-in drivers; mailbox/calendar transports retain their own configuration. */
class SsoAuthenticationService
{
    public function __construct(private SsoConfigurationService $settings) {}

    public function redirect(Request $request, string $provider, string $audience = 'staff'): RedirectResponse
    {
        $config = $this->usableConfiguration($provider, $audience);
        $link = $request->query('link') === '1';
        abort_if($link && (! $request->user() || ! $request->user()->approved_at), 403);
        if ($link) {
            $portalActor = $request->user()->hasRole('client', 'next_of_kin') || in_array($request->user()->role, ['client', 'next_of_kin'], true);
            abort_unless($portalActor === ($audience === 'portal'), 403, 'Use your profile to link a provider for the correct account surface.');
        }
        $request->session()->forget(['oauth_link_user', 'sso.flow']);
        $request->session()->put('sso.flow', [
            'provider' => $provider,
            'audience' => $audience,
            'configuration' => $this->fingerprint($config),
            'started_at' => now()->timestamp,
            'link_user_id' => $link ? (int) $request->user()->id : null,
            'link_binding' => $link ? $this->identityBinding($request->user(), $provider) : null,
        ]);

        return $this->driver($provider, $config, $audience)
            ->with($provider === 'microsoft' ? ['prompt' => 'select_account'] : ['prompt' => 'select_account', ...($audience === 'staff' ? ['hd' => $config['domain']] : [])])
            ->redirect();
    }

    /** @return array{user: mixed, link_user_id: int|null} */
    public function callback(Request $request, string $provider, string $audience = 'staff'): array
    {
        $flow = $request->session()->pull('sso.flow');
        $config = $this->usableConfiguration($provider, $audience);
        if (! is_array($flow)
            || ($flow['provider'] ?? null) !== $provider
            || ($flow['audience'] ?? null) !== $audience
            || ! is_int($flow['started_at'] ?? null)
            || $flow['started_at'] > now()->timestamp
            || $flow['started_at'] < now()->subMinutes(10)->timestamp
            || ! hash_equals($this->fingerprint($config), (string) ($flow['configuration'] ?? ''))
            || (($flow['link_user_id'] ?? null) !== null && (int) $request->user()?->id !== (int) $flow['link_user_id'])) {
            throw new InvalidStateException;
        }

        // The web application keeps Socialite's session state verification.
        // Never use stateless() for these browser callbacks.
        $user = $this->driver($provider, $config, $audience)->user();
        abort_unless(is_string($user->getId()) && $user->getId() !== '' && strlen($user->getId()) <= 255, 401);
        $email = strtolower(trim((string) ($user->getEmail() ?? '')));
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 401, 'The provider did not return a valid email address.');
        if ($provider === 'google') {
            abort_unless(($user->user['email_verified'] ?? false) === true, 403, 'Google must verify this email address.');
            if ($audience === 'staff') {
                abort_unless(strtolower((string) ($user->user['hd'] ?? '')) === $config['domain'], 403, 'Google sign-in is restricted to the configured Workspace domain.');
            }
        }
        if ($audience === 'staff') {
            abort_unless($config['domain'] !== '' && substr(strrchr($email, '@') ?: '', 1) === $config['domain'], 403, 'Staff sign-in is restricted to the configured organisation domain.');
        }

        return ['user' => $user, 'link_user_id' => $flow['link_user_id'] ?? null, 'link_binding' => $flow['link_binding'] ?? null, 'configuration_fingerprint' => $this->fingerprint($config)];
    }

    /** Called again while the shared mapping and current User locks are held. */
    public function assertLinkCurrent(User $user, string $provider, ?string $binding): void
    {
        abort_unless($binding !== null && hash_equals($binding, $this->identityBinding($user, $provider)), 409, 'The identity link changed or was disconnected. Start linking again from your profile.');
    }

    private function identityBinding(User $user, string $provider): string
    {
        $identities = Identity::query()->useWritePdo()->where('user_id', $user->id)->where('provider', $provider)->orderBy('id')->get(['id', 'provider_user_id'])->toArray();
        $lastDisconnect = AuditLog::query()->useWritePdo()->where('action', 'identity.disconnected')->where('auditable_type', $user->getMorphClass())->where('auditable_id', $user->id)->where('meta->provider', $provider)->max('id');

        return hash_hmac('sha256', json_encode([$identities, $lastDisconnect], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function assertCurrent(string $provider, string $audience, string $fingerprint): void
    {
        abort_unless(hash_equals($fingerprint, $this->fingerprint($this->usableConfiguration($provider, $audience))), 409, 'Sign-in settings changed. Start sign-in again.');
    }

    public function usableConfiguration(string $provider, string $audience): array
    {
        abort_unless(in_array($audience, ['staff', 'portal'], true), 404);
        $config = $this->settings->provider($provider);
        abort_unless($config[$audience.'_enabled'], 403, 'This sign-in provider is disabled for this application surface.');
        $check = $this->settings->check($provider);
        abort_unless($check['configuration_valid'], 503, 'This sign-in provider needs configuration review by an administrator.');

        return $config;
    }

    /** Builds a fresh driver without mutating global or cached services config. */
    public function driver(string $provider, array $config, string $audience): mixed
    {
        $credentials = [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect' => $config['callback_urls'][$audience],
            'tenant' => $config['directory_id'],
        ];

        return Socialite::buildProvider($provider === 'microsoft' ? MicrosoftProvider::class : GoogleProvider::class, $credentials)
            ->setConfig(new Config($credentials['client_id'], $credentials['client_secret'], $credentials['redirect'], ['tenant' => $credentials['tenant']]))
            ->setScopes($provider === 'microsoft' ? ['openid', 'profile', 'User.Read'] : ['openid', 'profile', 'email']);
    }

    private function fingerprint(array $config): string
    {
        return hash_hmac('sha256', json_encode($config, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
