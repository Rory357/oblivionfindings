<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventSearchIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! config('app.indexing_enabled')) {
            $response->headers->set(
                'X-Robots-Tag',
                'noindex, nofollow, noarchive, nosnippet'
            );
        }

        return $response;
    }
}
