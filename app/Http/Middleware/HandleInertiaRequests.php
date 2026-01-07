<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $user = $request->user();

        // Build permissions for frontend (RBAC)
        // Keep this list small and expand as you add modules.
        $can = null;

        if ($user) {
            $can = [
                'staff' => [
                    'viewAny' => $user->canDo('staff.viewAny'),
                    'create'  => $user->canDo('staff.create'),
                    'update'  => $user->canDo('staff.update'),
                    'invite'  => $user->canDo('staff.invite'),
                ],
                'clients' => [
                    'viewAny' => $user->canDo('clients.viewAny'),
                    'create'  => $user->canDo('clients.create'),
                    'update'  => $user->canDo('clients.update'),
                    'assignmentsUpdate' => $user->canDo('clients.assignments.update'),
                ],
                'shifts' => [
                    'viewAny' => $user->canDo('shifts.viewAny'),
                ],
            ];
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,

                    // Keep during migration (existing UI uses it)
                    'role' => $user->role ?? null,

                    'organization_id' => $user->organization_id ?? null,
                ] : null,

                // NEW: capability map for the UI
                'can' => $can,
            ],

            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
