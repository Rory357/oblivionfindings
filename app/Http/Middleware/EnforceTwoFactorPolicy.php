<?php

namespace App\Http\Middleware;

use App\Support\SecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceTwoFactorPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! SecurityPolicy::forceTwoFactor() || $user->two_factor_confirmed_at) {
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        return redirect('/settings/two-factor')
            ->with('warning', 'Two-factor authentication is required for your account.');
    }

    private function isExempt(Request $request): bool
    {
        return $request->is(
            'settings/two-factor',
            'user/two-factor-authentication*',
            'two-factor-challenge',
            'logout',
            'login',
            'register',
            'forgot-password',
            'reset-password*',
            'confirm-password',
            'user/confirm-password*',
            'email/verification*'
        );
    }
}
