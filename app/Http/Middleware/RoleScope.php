<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PR 18 — Role-gated access to manager-oriented operations pages.
 *
 * When a frontline staff user hits a route wrapped by this middleware, they
 * are redirected to the appropriate worker-facing surface (defaults to the
 * canonical frontline home, `/my-day`). Managers / admins / schedulers pass
 * through untouched because the middleware bails out the moment a management
 * capability is detected.
 *
 * This deliberately mirrors the heuristic already used by `DashboardController`
 * and `app-sidebar.tsx` (`shifts.manageAny`, `timesheets.manageAny`,
 * `rostering.viewAny`, `hr.analytics.view`) so staff/manager detection stays
 * consistent across the app and cannot drift.
 */
class RoleScope
{
    public function handle(Request $request, Closure $next, string $target = 'my-day'): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Portal users (client / next_of_kin) never reach the operations
        // module through normal nav, but if they somehow do, push them to
        // their own portal rather than a staff surface.
        if ($user->hasRole('client', 'next_of_kin') || in_array($user->role, ['client', 'next_of_kin'], true)) {
            return redirect()->route('portal.index');
        }

        // Manager / scheduler / HR-admin capability — anyone with these keeps
        // the existing operations surfaces untouched.
        $hasManagerCap =
            $user->canDo('shifts.manageAny') ||
            $user->canDo('timesheets.manageAny') ||
            $user->canDo('timesheets.approve') ||
            $user->canDo('rostering.viewAny') ||
            $user->canDo('hr.analytics.view');

        if ($hasManagerCap) {
            return $next($request);
        }

        // No manager capability: treat as frontline. Only redirect safe GETs
        // to the worker surface; anything else (POST/PUT/PATCH/DELETE/JSON)
        // falls through to the existing `permission:` middleware so it gets
        // the usual 403 rather than a misleading 302 on a mutating request.
        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            return $next($request);
        }

        return redirect()->route($target);
    }
}
