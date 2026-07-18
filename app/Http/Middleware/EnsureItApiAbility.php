<?php

namespace App\Http\Middleware;

use App\Models\ItServiceIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureItApiAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $identity = $request->attributes->get('it_service_identity');
        if (! $identity instanceof ItServiceIdentity || ! $identity->hasAbility($ability)) {
            return response()->json([
                'message' => 'This service identity is not allowed to perform that operation.',
                'code' => 'ability_denied',
            ], 403);
        }

        return $next($request);
    }
}
