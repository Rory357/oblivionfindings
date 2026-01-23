<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use App\Models\Announcement;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
                'sites' => [
                    'viewAny' => $user->canDo('sites.viewAny'),
                    'create' => $user->canDo('sites.create'),
                    'update' => $user->canDo('sites.update'),
                ],

                'staff' => [
                    'viewAny' => $user->canDo('staff.viewAny'),
                    'create'  => $user->canDo('staff.create'),
                    'update'  => $user->canDo('staff.update'),
                    'invite'  => $user->canDo('staff.invite'),
                    'assignmentsUpdate' => $user->canDo('staff.assignments.update'),
                    'credentialsViewAny' => $user->canDo('staff.credentials.viewAny'),
                    'credentialsUpdateAny' => $user->canDo('staff.credentials.updateAny'),
                    'credentialsUpdateSelf' => $user->canDo('staff.credentials.updateSelf'),
                    'availabilityUpdateAny' => $user->canDo('staff.availability.updateAny'),
                    'availabilityUpdateSelf' => $user->canDo('staff.availability.updateSelf'),
                ],
                'clients' => [
                    'viewAny' => $user->canDo('clients.viewAny'),
                    'create'  => $user->canDo('clients.create'),
                    'update'  => $user->canDo('clients.update'),
                    'assignmentsUpdate' => $user->canDo('clients.assignments.update'),
                ],
                'shifts' => [
                    'viewAny' => $user->canDo('shifts.viewAny'),
                    'create' => $user->canDo('shifts.create'),
                    'update' => $user->canDo('shifts.update'),
                    'manageAny' => $user->canDo('shifts.manageAny'),
                    'tasksUpdateSelf' => $user->canDo('shifts.tasks.updateSelf'),
                ],

                'timesheets' => [
                    'viewAny' => $user->canDo('timesheets.viewAny'),
                    'create' => $user->canDo('timesheets.create'),
                    'update' => $user->canDo('timesheets.update'),
                    'approve' => $user->canDo('timesheets.approve'),
                    'manageAny' => $user->canDo('timesheets.manageAny'),
                ],

                'reports' => [
                    'viewAny' => $user->canDo('reports.viewAny'),
                ],

                'rostering' => [
                    'viewAny' => $user->canDo('rostering.viewAny'),
                ],

                'fleet' => [
                    'viewAny' => $user->canDo('fleet.viewAny'),
                ],

                'calendar' => [
                    'viewAny' => $user->canDo('calendar.viewAny'),
                ],

                'timeline' => [
                    'viewAny' => $user->canDo('timeline.viewAny'),
                    'create' => $user->canDo('timeline.create'),
                    'pin' => $user->canDo('timeline.pin'),
                ],

                'summaries' => [
                    'viewAny' => $user->canDo('summaries.viewAny'),
                    'generate' => $user->canDo('summaries.generate'),
                ],

                'audit' => [
                    'viewAny' => $user->canDo('audit.viewAny'),
                ],

                'incidents' => [
                    'viewAny' => $user->canDo('incidents.viewAny'),
                    'viewAssigned' => $user->canDo('incidents.viewAssigned'),
                    'create' => $user->canDo('incidents.create'),
                    'update' => $user->canDo('incidents.update'),
                    'approve' => $user->canDo('incidents.approve'),
                ],

                'risks' => [
                    'viewAny' => $user->canDo('risks.viewAny'),
                    'viewAssigned' => $user->canDo('risks.viewAssigned'),
                    'create' => $user->canDo('risks.create'),
                    'update' => $user->canDo('risks.update'),
                    'delete' => $user->canDo('risks.delete'),
                ],

                'unifi' => [
                    'manage' => $user->canDo('unifi.manage'),
                ],

                'rag' => [
                    'askAny' => $user->canDo('rag.ask.any'),
                    'askAssigned' => $user->canDo('rag.ask.assigned'),
                    'askSelf' => $user->canDo('rag.ask.self'),
                ],

                'settings' => [
                    'manageAccess' => $user->canDo('settings.access.manage'),
                    'manageTerminology' => $user->canDo('settings.terminology.manage'),
                    'manageBranding' => $user->canDo('settings.branding.manage'),
                ],
            ];
        }

        // UI terminology (defaults merged with saved overrides)
        $labelDefaults = config('labels');
        $labelOverrides = AppSetting::query()
            ->where('key', 'like', 'labels.%')
            ->get(['key', 'value'])
            ->mapWithKeys(fn($row) => [str_replace('labels.', '', $row->key) => $row->value])
            ->toArray();

        $labels = array_merge($labelDefaults, $labelOverrides);

        // Organisation theme + branding
        $themeLight = AppSetting::query()->where('key', 'theme.light')->value('value') ?? [];
        $themeDark = AppSetting::query()->where('key', 'theme.dark')->value('value') ?? [];

        $brandingName = AppSetting::query()->where('key', 'branding.name')->value('value');
        $logoPath = AppSetting::query()->where('key', 'branding.logo_path')->value('value');
        $logoUrl = $logoPath ? Storage::disk('public')->url($logoPath) : null;

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

            'labels' => $labels,

            // NEW: organisation theme tokens and branding assets
            'theme' => [
                'light' => is_array($themeLight) ? $themeLight : [],
                'dark' => is_array($themeDark) ? $themeDark : [],
            ],
            'branding' => [
                'name' => is_string($brandingName) && trim($brandingName) !== '' ? $brandingName : config('app.name'),
                'logoUrl' => $logoUrl,
            ],

            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',

            // Flash messages for global toasts
            'flash' => [
                // Many controllers use 'status' (starter-kit convention). Treat it as success.
                'success' => session('success') ?? session('status'),
                'error' => session('error'),
                'warning' => session('warning'),
                'info' => session('info'),
            ],

            // Header inbox (notifications + announcements)
            'inbox' => $user ? [
                'notifications' => [
                    'unread_count' => $user->unreadNotifications()->count(),
                    'items' => $user->notifications()
                        ->latest()
                        ->limit(8)
                        ->get(['id', 'type', 'data', 'read_at', 'created_at'])
                        ->map(fn($n) => [
                            'id' => $n->id,
                            'type' => $n->type,
                            'data' => $n->data,
                            'read_at' => optional($n->read_at)->toISOString(),
                            'created_at' => optional($n->created_at)->toISOString(),
                        ])
                        ->values(),
                ],
                'announcements' => Announcement::inboxFor($user),
            ] : null,
        ];
    }
}
