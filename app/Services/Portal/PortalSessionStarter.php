<?php

namespace App\Services\Portal;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Starts a family portal session after a passwordless proof (sign-in link or
 * invitation). Two-step verification is never skipped: accounts with it on go
 * to the challenge first, exactly like provider sign-in does.
 */
class PortalSessionStarter
{
    public function start(Request $request, User $user, bool $remember = false): RedirectResponse
    {
        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            $request->session()->put([
                'login.id' => $user->id,
                'login.remember' => $remember,
                'url.intended' => '/dashboard',
            ]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }
}
