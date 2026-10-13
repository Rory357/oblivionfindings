<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        $timeoutMinutes = SecurityPolicy::sessionTimeoutMinutes();
        if ($timeoutMinutes <= 0 || $request->is('logout')) {
            return $next($request);
        }

        $lastActivity = $request->session()->get('last_activity_at');
        if ($lastActivity && (now()->timestamp - (int) $lastActivity) > ($timeoutMinutes * 60)) {
            // Family portal accounts go back to the family sign-in, not staff.
            $user = $request->user();
            $route = $user instanceof User && $user->isPortalAccount() ? 'portal.login' : 'login';

            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route($route)
                ->withErrors(['session' => "You were signed out after {$timeoutMinutes} minutes without activity, to keep people's information safe."]);
        }

        $request->session()->put('last_activity_at', now()->timestamp);

        return $next($request);
    }
}
