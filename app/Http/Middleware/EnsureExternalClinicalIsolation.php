<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Dedicated external identities can never enter the internal application. */
final class EnsureExternalClinicalIsolation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isExternalClinicianAccount()) {
            return $next($request);
        }
        $accountRoutes = [
            'logout', 'login', 'two-factor-challenge', 'confirm-password',
            'user/confirm-password', 'user/confirmed-password-status',
            'forgot-password', 'reset-password', 'reset-password/*',
            'email/verify', 'email/verify/*', 'email/verification-notification',
            'settings/password', 'settings/two-factor',
            'user/two-factor-authentication', 'user/confirmed-two-factor-authentication',
            'user/two-factor-qr-code', 'user/two-factor-secret-key', 'user/two-factor-recovery-codes',
        ];
        if ($request->is(...$accountRoutes)) {
            return $next($request);
        }
        abort_unless($request->is('clinical-portal', 'clinical-portal/*'), 403, 'This account can open only the clinical portal.');
        if (! $user->hasVerifiedEmail()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Verify your email before opening clinical records.'], 403)
                : redirect()->route('verification.notice');
        }
        if (! $user->two_factor_confirmed_at || blank($user->two_factor_secret)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Set up two-factor authentication before opening clinical records.'], 403)
                : redirect('/settings/two-factor');
        }

        return $next($request);
    }
}
