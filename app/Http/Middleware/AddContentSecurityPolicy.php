<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Services\Chat\SelfHostedCalling;
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

        $response->headers->set($header, $this->policy($nonce, $request));

        return $response;
    }

    /**
     * Build the policy string for this request's nonce.
     */
    private function policy(string $nonce, Request $request): string
    {
        // The optional provider is never loaded without application configuration.
        // Keep OSM-only deployments on their existing policy; no unsafe script execution is added.
        $google = filled(config('fleet.maps.api_key'));
        $googleSources = $google ? ' https://*.googleapis.com https://*.gstatic.com https://*.google.com' : '';
        // Oblivion Live (and Reverb until it is removed) use a same-origin socket only.
        $chatSocket = config('chat.shared_experience') && (config('chat.live.enabled') || config('chat.realtime.enabled'))
            ? ' '.($request->isSecure() ? 'wss://' : 'ws://').$request->getHttpHost() : '';
        $chatSocket .= $this->callingSources();

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".$googleSources,
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net".($google ? ' https://fonts.googleapis.com' : ''),
            "font-src 'self' https://fonts.bunny.net data:".($google ? ' https://fonts.gstatic.com' : ''),
            // Map tiles: OpenStreetMap (street/dark basemap) and Esri ArcGIS
            // (satellite layer) are fetched as <img> by Leaflet.
            'img-src '.implode(' ', [
                "'self'",
                'data:',
                'blob:',
                'https://tile.openstreetmap.org',
                'https://*.tile.openstreetmap.org',
                'https://server.arcgisonline.com',
            ]).$googleSources.($google ? ' https://*.googleusercontent.com' : ''),
            "media-src 'self'",
            // The document preconnects to Bunny Fonts before loading its stylesheet.
            // Browsers evaluate that connection hint against connect-src.
            "connect-src 'self' https://fonts.bunny.net".$googleSources.$chatSocket,
            "worker-src 'self'".($google ? ' blob:' : ''),
            "frame-src 'self'".($google ? ' https://*.google.com' : ''),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * The self-hosted voice server usually lives on its own host. Allow its
     * signalling socket and HTTPS validation origin only once calling is ready.
     */
    private function callingSources(): string
    {
        if (! config('chat.shared_experience') || ! app(SelfHostedCalling::class)->ready()) {
            return '';
        }
        $url = parse_url((string) config('chat.calling.url'));
        $host = $url['host'] ?? null;
        if (! is_string($host) || $host === '' || ! in_array($url['scheme'] ?? '', ['ws', 'wss', 'http', 'https'], true)) {
            return '';
        }
        $authority = $host.(isset($url['port']) ? ':'.$url['port'] : '');
        $secure = in_array($url['scheme'], ['wss', 'https'], true);

        return ' '.($secure ? 'wss://' : 'ws://').$authority.' '.($secure ? 'https://' : 'http://').$authority;
    }
}
