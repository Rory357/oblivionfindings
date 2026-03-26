<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class MicrosoftController extends Controller
{
    public function redirect()
    {
        // If ?link=1, store the current user ID so callback links the identity
        if (request()->query('link') == '1' && auth()->check()) {
            session(['oauth_link_user' => auth()->id()]);
        }

        return Socialite::driver('microsoft')
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function callback()
    {
        $m = Socialite::driver('microsoft')->stateless()->user();

        $email = strtolower(
            $m->getEmail()
                ?? ($m->user['mail'] ?? null)
                ?? ($m->user['userPrincipalName'] ?? null)
                ?? ''
        );

        abort_unless($email !== '', 401, 'No email returned from Microsoft.');

        // If linking to an existing user (came from ?link=1)
        $linkUserId = session()->pull('oauth_link_user');
        if ($linkUserId) {
            $linkUser = User::findOrFail($linkUserId);
            $linkUser->identities()->updateOrCreate(
                ['provider' => 'microsoft', 'provider_user_id' => $m->getId()],
                [
                    'email' => $email,
                    'access_token' => $m->token,
                    'refresh_token' => $m->refreshToken,
                    'token_expires_at' => $m->expiresIn ? now()->addSeconds($m->expiresIn) : null,
                ]
            );
            return redirect('/settings/profile')->with('success', 'Microsoft account linked.');
        }

        // Org-only rule: domain must match
        $orgDomain = strtolower(env('ORG_DOMAIN', ''));
        abort_unless($orgDomain !== '', 500, 'ORG_DOMAIN is not set.');

        $domain = Str::after($email, '@');
        abort_unless($domain === $orgDomain, 403, 'Microsoft SSO is restricted to the organization.');

        // Create or find user
        $user = User::where('email', $email)->first();

        if (!$user) {
            $user = User::create([
                'name' => $m->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'password' => bcrypt(Str::random(32)),
                'role' => 'support_worker', // legacy column used in some UI checks
            ]);

            // Attach RBAC role for hasRole()/canDo()
            $defaultRole = Role::where('name', 'support_worker')->first();
            if ($defaultRole) {
                $user->roles()->syncWithoutDetaching([$defaultRole->id]);
            }
        } else {
            // Ensure legacy role is set (optional but helps your existing checks)
            if (!$user->role) {
                $user->forceFill(['role' => 'support_worker'])->save();
            }
        }

        // Store identity for the user
        $user->identities()->updateOrCreate(
            ['provider' => 'microsoft', 'provider_user_id' => $m->getId()],
            [
                'email' => $email,
                'access_token' => $m->token,
                'refresh_token' => $m->refreshToken,
                'token_expires_at' => $m->expiresIn ? now()->addSeconds($m->expiresIn) : null,
            ]
        );

        Auth::login($user, remember: true);

        return redirect()->intended('/dashboard');
    }
}
