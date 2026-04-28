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
            unset($parameters['canonical']);

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
