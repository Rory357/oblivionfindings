<?php

namespace App\Http\Middleware;

use App\Services\Medication\Connected\ConnectedCareSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A connected-care route runs only while its feature is switched on in
 * Settings › Connected services and configured (D4). Otherwise it is "not
 * found", like any surface that isn't there. Several features may be
 * listed: the route runs while any of them does.
 *
 * Usage: EnsureConnectedCareFeature::class.':prescriber_portal'. An "on-"
 * prefix asks only that the feature is switched on (not that it is still
 * configured): an already-encrypted backup stays downloadable in an outage
 * even if the encryption tool later becomes unavailable.
 */
final class EnsureConnectedCareFeature
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $settings = app(ConnectedCareSettings::class);
        abort_unless(collect($features)->contains(fn (string $feature): bool => str_starts_with($feature, 'on-')
            ? $settings->switchedOn(substr($feature, 3))
            : $settings->enabled($feature)), 404);

        return $next($request);
    }
}
