<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
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
                'staff' => [
                    'viewAny' => $user->canDo('staff.viewAny'),
                    'create'  => $user->canDo('staff.create'),
                    'update'  => $user->canDo('staff.update'),
                    'invite'  => $user->canDo('staff.invite'),
                    'assignmentsUpdate' => $user->canDo('staff.assignments.update'),
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
        ];
    }
}
