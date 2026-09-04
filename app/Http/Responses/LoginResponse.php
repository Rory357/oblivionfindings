<?php

namespace App\Http\Responses;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Custom Fortify LoginResponse — decides where the user lands after a
 * successful login.
 *
 * Resolution order:
 *   1. User's explicit landing_route_preference (from their profile) — if
 *      they still have the role that owns it and the target route exists.
 *   2. Their highest-level role's landing_route (roles.landing_route) — if
 *      it exists in config('landing_routes').
 *   3. Fall through to the canonical dashboard.
 *
 * If any resolved route requires a permission the user lacks, we keep
 * falling through candidates rather than sending them to a 403 page.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $user = $request->user();
        $url = config('fortify.home', '/dashboard');

        if ($user instanceof User) {
            $resolved = $this->resolveLandingUrl($user);
            if ($resolved !== null) {
                $url = $resolved;
            } elseif (! $user->hasRole('client', 'next_of_kin')) {
                // No explicit landing preference — staff (including managers
                // and admins) land on My Day. Portal users keep the
                // fortify.home fallback, which DashboardController turns into
                // the family-portal redirect.
                $url = route('my-day');
            }
        }

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended($url);
    }

    private function resolveLandingUrl(User $user): ?string
    {
        $landingRoutes = (array) config('landing_routes', []);

        if (empty($landingRoutes)) {
            return null;
        }

        $userRoles = $user->roles()->orderByDesc('level')->get(['id', 'name', 'level', 'landing_route']);

        // 1. User's own preference wins, if they still have the role that offered it.
        $pref = (string) ($user->landing_route_preference ?? '');
        if ($pref !== '' && isset($landingRoutes[$pref])) {
            $offeredByRole = $userRoles->contains(fn (Role $r) => (string) $r->landing_route === $pref);
            if ($offeredByRole) {
                $url = $this->routeUrlIfAccessible($user, $pref, $landingRoutes[$pref]);
                if ($url !== null) {
                    return $url;
                }
            }
        }

        // 2. Highest-level role's landing_route.
        foreach ($userRoles as $role) {
            $key = (string) ($role->landing_route ?? '');
            if ($key === '' || ! isset($landingRoutes[$key])) {
                continue;
            }
            $url = $this->routeUrlIfAccessible($user, $key, $landingRoutes[$key]);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param  array{route: string, label?: string, permission?: string|null}  $config
     */
    private function routeUrlIfAccessible(User $user, string $key, array $config): ?string
    {
        $permission = $config['permission'] ?? null;
        if ($permission !== null && method_exists($user, 'canDo') && ! $user->canDo($permission)) {
            return null;
        }

        $routeName = $config['route'] ?? null;
        if (! $routeName) {
            return null;
        }

        try {
            return route($routeName);
        } catch (UrlGenerationException $e) {
            // Named route is declared in config but missing from the app — log
            // once so admins can spot stale config and keep redirecting.
            Log::warning('Landing route resolution failed', [
                'key' => $key,
                'route' => $routeName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
