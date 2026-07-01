<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user whose approval has been withdrawn mid-session.
 *
 * Login is already blocked for unapproved accounts (Fortify pipeline +
 * OAuth controllers all check `approved_at`), so any authenticated session
 * belongs to a user who WAS approved — if `approved_at` is now null their
 * access was revoked after login (e.g. offboarding completed) and the
 * session must not outlive the revocation.
 */
class EnsureAccountStillApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && is_null($user->approved_at)) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Your account access has been revoked. Contact your administrator if this is unexpected.']);
        }

        return $next($request);
    }
}
