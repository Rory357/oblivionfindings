<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emits an application-wide Content-Security-Policy on HTML document responses.
 *
 * Defence-in-depth: React escapes interpolated values and Blade escapes with
 * e() by default, so the CSP is the second wall that neutralises an injected
 * <script>, a smuggled <object>/<embed>, a <base> hijack or a clickjacking
 * frame even if some escaping is ever missed.
 *
 * Every source below is required by something the app actually ships
 * (see resources/views/app.blade.php):
 *   • script-src 'self' 'nonce-…' — the only inline <script> is the dark-mode
 *     bootstrap in the <head>; it (and every @vite-injected module/preload tag)
 *     carries the per-request nonce, so scripts never need 'unsafe-inline'.
 *   • style-src 'unsafe-inline' — React / Radix inject inline style="" ATTRIBUTES
 *     pervasively and nonces never apply to attributes, so 'unsafe-inline' is the
 *     only workable option for a component-driven SPA (style injection is far
 *     lower risk than script injection). fonts.bunny.net serves the web-font CSS.
 *   • font-src fonts.bunny.net — the Instrument Sans web font.
 *   • img-src data: blob: — data-URI icons and object-URL upload previews;
 *     tile.openstreetmap.org and server.arcgisonline.com serve Leaflet map tiles.
 *   • media-src 'self' — the in-app alert chime (/sounds/alert.mp3).
 *   • object-src 'none' / base-uri 'self' / frame-ancestors 'none' — kill the
 *     plugin, <base>-hijack and clickjacking classes outright.
 *
 * Served report-only until CSP_ENFORCE is set, so violations are observable in
 * the browser console before the policy is enforced. Never overwrites a CSP that
 * a downstream response already set (e.g. the locked-down attachment streamer in
 * {@see ServesPrivateAttachments}).
 */
class AddContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        // Generate the per-request nonce up front so @vite and the inline
        // dark-mode <script> can stamp it while the view renders inside $next.
        $nonce = Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        if (! config('app.csp_enabled', true)) {
            return $response;
        }

        // CSP is meaningful on the HTML document; skip JSON (Inertia XHR),
        // file downloads, and any response that already carries its own
        // (stricter) policy.
        $contentType = (string) $response->headers->get('Content-Type');
        if (! str_contains($contentType, 'text/html')
            || $response->headers->has('Content-Security-Policy')
            || $response->headers->has('Content-Security-Policy-Report-Only')
        ) {
            return $response;
        }

        $header = config('app.csp_enforce', false)
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';

        $response->headers->set($header, $this->policy($nonce));

        return $response;
    }

    /**
     * Build the policy string for this request's nonce.
     */
    private function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net data:",
            // Map tiles: OpenStreetMap (street/dark basemap) and Esri ArcGIS
            // (satellite layer) are fetched as <img> by Leaflet.
            'img-src '.implode(' ', [
                "'self'",
                'data:',
                'blob:',
                'https://tile.openstreetmap.org',
                'https://*.tile.openstreetmap.org',
                'https://server.arcgisonline.com',
            ]),
            "media-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
