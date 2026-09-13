<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class ProtectProvisioningResponses
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        return $response;
    }
}
