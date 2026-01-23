<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permissionKey): Response
    {
        $user = $request->user();

        // Allow simple OR checks: "permission:a|b|c"
        $keys = array_values(array_filter(array_map('trim', explode('|', $permissionKey))));
        $allowed = $user && (
            empty($keys)
                ? false
                : collect($keys)->some(fn ($k) => $user->canDo($k))
        );

        if (!$allowed) {
            abort(403);
        }

        return $next($request);
    }
}
