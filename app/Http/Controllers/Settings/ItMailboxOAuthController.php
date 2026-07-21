<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ItMailboxConnection;
use App\Support\LegacyStorageContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

/**
 * Admin OAuth connect/disconnect for the IT support-mailbox connection
 * (email-to-ticket, E6a). Mirrors CalendarSyncOAuthController — never logs a
 * user in; stores an application-level {@see ItMailboxConnection} whose token the
 * hourly PollItMailboxJob uses to read the support inbox.
 *
 * Scope note: markRead WRITES (Graph isRead / Gmail label removal), so the
 * consent is Mail.ReadWrite(.Shared) and gmail.modify — read-only scopes
 * cannot silence a message for the next poll.
 */
class ItMailboxOAuthController extends Controller
{
    /** Provider keys → Socialite driver. */
    private const DRIVERS = [
        ItMailboxConnection::PROVIDER_GOOGLE => 'google',
        ItMailboxConnection::PROVIDER_MICROSOFT => 'microsoft',
    ];

    /** Mail scopes requested per provider (beyond the OIDC basics). */
    private const SCOPES = [
        ItMailboxConnection::PROVIDER_GOOGLE => [
            'openid', 'email', 'profile',
            'https://www.googleapis.com/auth/gmail.modify',
        ],
        ItMailboxConnection::PROVIDER_MICROSOFT => [
            'openid', 'email', 'profile', 'offline_access',
            'https://graph.microsoft.com/Mail.ReadWrite',
            'https://graph.microsoft.com/Mail.ReadWrite.Shared',
        ],
    ];

    public function redirect(Request $request, string $provider)
    {
        $this->authorizeManage($request);
        $driver = $this->driver($provider);

        if (! $this->isConfigured($provider)) {
            return redirect()->route('settings.it-mailbox')
                ->withErrors([$provider => ucfirst($provider).' is not configured. Set its OAuth client credentials first.']);
        }

        $socialite = Socialite::driver($driver)
            ->scopes(self::SCOPES[$provider])
            ->redirectUrl($this->callbackUrl($provider));

        // Force a refresh token from Google.
        if ($provider === ItMailboxConnection::PROVIDER_GOOGLE) {
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
            return redirect()->route('settings.it-mailbox')
                ->withErrors([$provider => 'Could not connect '.ucfirst($provider).': '.$e->getMessage()]);
        }

        // mailbox_email is deliberately NOT touched: a previously configured
        // delegated support mailbox survives a token reconnect.
        ItMailboxConnection::updateOrCreate(
            [
                'provider' => $provider,
            ],
            [
                'tenant_id' => LegacyStorageContext::id(),
                'status' => ItMailboxConnection::STATUS_CONNECTED,
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

        return redirect()->route('settings.it-mailbox')
            ->with('success', ucfirst($provider).' support mailbox connected.');
    }

    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        $this->authorizeManage($request);
        $this->driver($provider); // validates provider

        ItMailboxConnection::query()
            ->where('provider', $provider)
            ->delete();

        return redirect()->route('settings.it-mailbox')
            ->with('success', ucfirst($provider).' support mailbox disconnected.');
    }

    private function driver(string $provider): string
    {
        abort_unless(isset(self::DRIVERS[$provider]), 404);

        return self::DRIVERS[$provider];
    }

    private function callbackUrl(string $provider): string
    {
        return route('settings.it-mailbox.callback', ['provider' => $provider]);
    }

    private function isConfigured(string $provider): bool
    {
        return ! empty(config("services.{$provider}.client_id"))
            && ! empty(config("services.{$provider}.client_secret"));
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->canDo('integrations.manage_tenant_secrets'), 403);
    }
}
