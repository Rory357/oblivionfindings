<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\ControlRoom\AlertWorkspaceService;
use App\Services\ControlRoom\ControlRoomDeskService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ControlRoomDashboardController extends Controller
{
    public function __invoke(Request $request, ControlRoomDeskService $desk): Response
    {
        $user = $request->user();
        abort_unless($user, 403);
        $desk->prepareViewerAccess($user);
        abort_unless($user->canDo('controlRoom.viewAny'), 403);

        $filters = $desk->filters($user, $request->query());
        $live = fn (): array => $desk->live($user, $request->query());

        // A polling partial reload is not a meaningful user navigation and
        // must not fill the audit log every 30 seconds.
        if (! $request->headers->has('X-Inertia-Partial-Data')) {
            AuditLogger::log('controlRoom.dashboard.view', null, [
                'filters' => $filters,
            ]);
        }

        return Inertia::render('control-room/index', [
            'detail' => fn () => $request->filled('alert')
                ? app(AlertWorkspaceService::class)->build($user, (int) $request->input('alert'))
                : null,

            // Live Desk contract. Each closure shares one memoized snapshot,
            // including during Inertia partial reloads.
            'hero' => fn () => $live()['hero'],
            'worklist' => fn () => $live()['worklist'],
            'queues' => fn () => $live()['queues'],
            'handover' => fn () => $live()['handover'],
            'activity' => fn () => $live()['activity'],
            'freshness' => fn () => $live()['freshness'],
            'filters' => $filters,
            'sites' => fn () => $desk->sites($user),
            'staff' => fn () => $desk->staff($user),
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
                'assign' => $user->canDo('controlRoom.alerts.assign'),
                'escalate' => $user->canDo('controlRoom.alerts.escalate'),
                'create' => $user->canDo('controlRoom.alerts.create'),
                'viewReports' => $user->canDo('controlRoom.reports.view'),
            ],

            // Historical reporting is intentionally absent from the initial
            // response. The desktop opens it explicitly when it is useful.
            'analytics' => Inertia::optional(fn () => $user->canDo('controlRoom.reports.view')
                ? $desk->analytics($user, $request->query())
                : null),

            // Short-term compatibility for existing deep links and tests. The
            // values are aliases of the same live snapshot, never a second
            // query path or a competing workflow.
            'alerts' => fn () => $live()['worklist'],
            'stats' => fn () => $live()['stats'],
            'recent_activity' => fn () => $live()['activity'],
        ]);
    }
}
