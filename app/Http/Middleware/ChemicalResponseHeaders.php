<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route-local response privacy; outer auth/login responses remain outside this group. */
final class ChemicalResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Laravel's routing pipeline reports/renders inner exceptions before
        // returning them here. Preserve that handler and the actual status/body.
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
