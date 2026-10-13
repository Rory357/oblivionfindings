<?php

namespace App\Http\Middleware;

use App\Services\Medication\Connected\ConnectedCareSettings;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells eMAR pages which connected-care features run now, so navigation and
 * the Connected services menus show only those (hide-unbuilt). Shared under
 * auth.can.medications.connected after HandleInertiaRequests has built
 * auth.can; navigation only — every route re-checks with
 * EnsureConnectedCareFeature.
 */
final class ShareConnectedCareSwitches
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null) {
            Inertia::share('auth.can.medications.connected', app(ConnectedCareSettings::class)->switches());
        }

        return $next($request);
    }
}
