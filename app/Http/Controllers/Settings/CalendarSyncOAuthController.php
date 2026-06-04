<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\CalendarSyncConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

/**
 * Admin OAuth connect/disconnect for the org-level calendar-sync connection.
 *
 * Distinct from the user-login Auth\GoogleController / Auth\MicrosoftController:
 * this never creates or logs in a user — it stores an org-level
 * {@see CalendarSyncConnection} whose tokens can read/write house resource calendars.
 */
class CalendarSyncOAuthController extends Controller
{
    /** Provider keys → Socialite driver. */
    private const DRIVERS = [
        CalendarSyncConnection::PROVIDER_GOOGLE => 'google',
        CalendarSyncConnection::PROVIDER_MICROSOFT => 'microsoft',
    ];

    /** Calendar API scopes requested per provider (beyond the OIDC basics). */
    private const SCOPES = [
        CalendarSyncConnection::PROVIDER_GOOGLE => [
            'openid', 'email', 'profile',
            'https://www.googleapis.com/auth/calendar',
        ],
        CalendarSyncConnection::PROVIDER_MICROSOFT => [
            'openid', 'email', 'profile', 'offline_access',
            'https://graph.microsoft.com/Calendars.ReadWrite',
            'https://graph.microsoft.com/Calendars.ReadWrite.Shared',
            'https://graph.microsoft.com/Place.Read.All',
        ],
    ];

    public function redirect(Request $request, string $provider)
    {
        $this->authorizeManage($request);
        $driver = $this->driver($provider);

        if (! $this->isConfigured($provider)) {
            return redirect()->route('settings.calendar-sync')
                ->withErrors([$provider => ucfirst($provider).' is not configured. Set its OAuth client credentials first.']);
        }

        $socialite = Socialite::driver($driver)
            ->scopes(self::SCOPES[$provider])
            ->redirectUrl($this->callbackUrl($provider));

        // Force a refresh token from Google.
        if ($provider === CalendarSyncConnection::PROVIDER_GOOGLE) {
            $socialite->with(['access_type' => 'offline', 'prompt' => 'consent']);
        }

        return $socialite->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->authorizeManage($request);
        $driver = $this->driver($provider);

        try {
            // NOT stateless: redirect() stored a state token in the session, so the
            // callback verifies it (guards against OAuth login-CSRF / code injection).
            $oauthUser = Socialite::driver($driver)
                ->redirectUrl($this->callbackUrl($provider))
                ->user();
        } catch (\Throwable $e) {
            return redirect()->route('settings.calendar-sync')
                ->withErrors([$provider => 'Could not connect '.ucfirst($provider).': '.$e->getMessage()]);
        }

        CalendarSyncConnection::updateOrCreate(
            [
                'tenant_id' => $this->tenantId($request),
                'provider' => $provider,
            ],
            [
                'status' => CalendarSyncConnection::STATUS_CONNECTED,
                'access_token' => $oauthUser->token,
                'refresh_token' => $oauthUser->refreshToken,
                'token_expires_at' => $oauthUser->expiresIn ? now()->addSeconds($oauthUser->expiresIn) : null,
                'scopes' => self::SCOPES[$provider],
                'account_email' => $oauthUser->getEmail(),
                'account_name' => $oauthUser->getName(),
                'last_error' => null,
                'created_by' => $request->user()->id,
            ],
        );

        return redirect()->route('settings.calendar-sync')
            ->with('success', ucfirst($provider).' calendar connected.');
    }

    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        $this->authorizeManage($request);
        $this->driver($provider); // validates provider

        CalendarSyncConnection::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('provider', $provider)
            ->delete();

        return redirect()->route('settings.calendar-sync')
            ->with('success', ucfirst($provider).' calendar disconnected.');
    }

    private function driver(string $provider): string
    {
        abort_unless(isset(self::DRIVERS[$provider]), 404);

        return self::DRIVERS[$provider];
    }

    private function callbackUrl(string $provider): string
    {
        return route('settings.calendar-sync.callback', ['provider' => $provider]);
    }

    private function isConfigured(string $provider): bool
    {
        return ! empty(config("services.{$provider}.client_id"))
            && ! empty(config("services.{$provider}.client_secret"));
    }

    private function tenantId(Request $request): int
    {
        return (int) ($request->user()->tenant_id ?? 0);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->canDo('integrations.manage_tenant_secrets'), 403);
    }
}
