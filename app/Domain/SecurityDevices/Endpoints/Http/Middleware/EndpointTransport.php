<?php

namespace App\Domain\SecurityDevices\Endpoints\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EndpointTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        // JSON errors prevent credentials being flashed into a browser session.
        $request->headers->set('Accept', 'application/json');
        abort_unless(config('endpoint_management.enabled'), 404);
        abort_unless($request->isSecure(), 400, 'Endpoint management requires HTTPS.');
        abort_if(strlen($request->getContent()) > 1048576, 413);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
