<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LegacyRouteRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $route = $request->route();
        $defaults = $route?->defaults ?? [];
        $canonical = $route?->defaults['canonical'] ?? null;

        if (is_string($canonical) && $canonical !== '') {
            $parameters = $route?->parameters() ?? [];
            // Strip controller-side defaults so they don't leak into route() as
            // query string parameters. `route()` treats any extra keys as
            // ?key=value, which would corrupt the redirect URL.
            unset(
                $parameters['canonical'],
                $parameters['status'],
                $parameters['destination'],
                $parameters['destination_prefix'],
            );

            $url = route($canonical, $parameters);
        } elseif (is_string($defaults['destination'] ?? null) && $defaults['destination'] !== '') {
            $url = url($defaults['destination']);
        } else {
            $prefix = $defaults['destination_prefix'] ?? null;
            abort_unless(is_string($prefix) && $prefix !== '', 404);

            $any = trim((string) ($route?->parameter('any') ?? ''), '/');
            $url = url(trim($prefix, '/').($any !== '' ? '/'.$any : ''));
        }

        if ($query = $request->getQueryString()) {
            $url .= str_contains($url, '?') ? '&'.$query : '?'.$query;
        }

        return redirect()->to($url, (int) ($defaults['status'] ?? 301));
    }
}
