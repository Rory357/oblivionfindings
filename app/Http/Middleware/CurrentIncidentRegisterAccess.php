<?php

namespace App\Http\Middleware;

use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentRegisterReadService;
use App\Support\Database\LockConflictRetry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Same register read grant, evaluated from current evidence instead of cached request relations. */
final class CurrentIncidentRegisterAccess
{
    public function __construct(private readonly AuthorizationEvidenceLockService $authorization) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user(), 403);
        // A save holding the viewer's evidence rows makes this no-wait read fail at once; try again a moment later.
        LockConflictRetry::read(fn () => DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($request): void {
            $reads->assertActive();
            $current = $this->authorization->lockForUserWithoutWaiting($request->user(), IncidentRegisterReadService::PERMISSIONS);
            abort_unless($current->canDo('incidents.viewAny') || $current->canDo('incidents.viewAssigned'), 403);
        })));
        // The source service independently repeats the full current decision;
        // no user model or read handle leaves this route guard.
        return $next($request);
    }
}
