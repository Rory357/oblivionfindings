<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Http\Controllers\Settings\TerminologyController;
use App\Http\Controllers\Settings\AccessController;
use App\Http\Controllers\Settings\RolesController;
use App\Http\Controllers\Settings\BrandingController;
use App\Http\Controllers\Settings\ServiceContextController;
use App\Http\Controllers\Settings\NotificationPreferencesController;
use App\Http\Controllers\Settings\NotificationEscalationsController;
use App\Http\Controllers\Settings\IntegrationHubController;
use App\Http\Controllers\Settings\UnifiSettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('settings/profile/photo', [ProfileController::class, 'updatePhoto'])->name('profile.photo.update');
    Route::delete('settings/profile/photo', [ProfileController::class, 'destroyPhoto'])->name('profile.photo.destroy');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');

    // Admin access controls (roles & per-user overrides)
    Route::get('settings/access', [AccessController::class, 'index'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.access');
    Route::put('settings/access/{target}', [AccessController::class, 'update'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.access.update');

    Route::post('settings/access/{target}/approve', [AccessController::class, 'approve'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.access.approve');

    // Board Member Management (integrated with access control)
    Route::post('settings/board-members', [AccessController::class, 'storeBoardMember'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.board-members.store');
    Route::delete('settings/board-members/{boardMember}', [AccessController::class, 'destroyBoardMember'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.board-members.destroy');

    // Roles (create/edit roles + attach permissions)
    Route::get('settings/roles', [RolesController::class, 'index'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.roles.index');
    Route::get('settings/roles/create', [RolesController::class, 'create'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.roles.create');
    Route::post('settings/roles', [RolesController::class, 'store'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.roles.store');
    Route::get('settings/roles/{role}/edit', [RolesController::class, 'edit'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.roles.edit');
    Route::put('settings/roles/{role}', [RolesController::class, 'update'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.roles.update');

    // Organisation terminology (Clients → Patients, etc.)
    Route::get('settings/terminology', [TerminologyController::class, 'edit'])
        ->middleware('permission:settings.terminology.manage')
        ->name('settings.terminology');
    Route::put('settings/terminology', [TerminologyController::class, 'update'])
        ->middleware('permission:settings.terminology.manage')
        ->name('settings.terminology.update');

    // Organisation branding (colors/logo)
    Route::get('settings/branding', [BrandingController::class, 'edit'])
        ->middleware('permission:settings.branding.manage')
        ->name('settings.branding');
    Route::post('settings/branding', [BrandingController::class, 'update'])
        ->middleware('permission:settings.branding.manage')
        ->name('settings.branding.update');

    // Service contexts (Residential / Home Support / Respite)
    Route::get('settings/service-contexts', [ServiceContextController::class, 'index'])
        ->middleware('permission:settings.service_contexts.manage')
        ->name('settings.service_contexts');
    Route::post('settings/service-contexts', [ServiceContextController::class, 'store'])
        ->middleware('permission:settings.service_contexts.manage')
        ->name('settings.service_contexts.store');


    Route::post('settings/service-contexts/default', [ServiceContextController::class, 'setDefault'])
        ->middleware('permission:settings.service_contexts.manage')
        ->name('settings.service_contexts.default');

    Route::put('settings/service-contexts/{serviceContext}', [ServiceContextController::class, 'update'])
        ->middleware('permission:settings.service_contexts.manage')
        ->name('settings.service_contexts.update');

    // Notification preferences
    Route::get('settings/notifications', [NotificationPreferencesController::class, 'index'])
        ->name('settings.notifications');
    Route::put('settings/notifications', [NotificationPreferencesController::class, 'update'])
        ->name('settings.notifications.update');

    // Role defaults (admin)
    Route::get('settings/notifications/roles', [NotificationPreferencesController::class, 'roles'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.notifications.roles');
    Route::put('settings/notifications/roles', [NotificationPreferencesController::class, 'updateRoles'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.notifications.roles.update');

    // Notification escalation rules (admin)
    Route::get('settings/notifications/escalations', [NotificationEscalationsController::class, 'index'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.notifications.escalations');
    Route::put('settings/notifications/escalations', [NotificationEscalationsController::class, 'update'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.notifications.escalations.update');

    // Integrations hub
    Route::get('settings/integrations', [IntegrationHubController::class, 'index'])
        ->middleware('permission:integrations.view')
        ->name('settings.integrations.index');

    // UniFi integration settings
    Route::prefix('settings/integrations/unifi')->middleware('permission:integrations.manage_tenant_secrets')->group(function () {
        Route::get('/', [UnifiSettingsController::class, 'index'])->name('settings.integrations.unifi');
        Route::post('/key', [UnifiSettingsController::class, 'saveKey']);
        Route::post('/test', [UnifiSettingsController::class, 'testKey']);
        Route::post('/rotate', [UnifiSettingsController::class, 'rotateKey']);
        Route::post('/sync-sites', [UnifiSettingsController::class, 'syncSites']);
        Route::post('/map-site', [UnifiSettingsController::class, 'mapSite']);
        Route::delete('/map-site/{siteConfig}', [UnifiSettingsController::class, 'removeSiteMapping']);
        Route::post('/sync-devices', [UnifiSettingsController::class, 'syncDevices']);
        Route::put('/hardware/{hardware}/room', [UnifiSettingsController::class, 'assignHardwareRoom']);
        Route::put('/defaults', [UnifiSettingsController::class, 'updateDefaults']);
    });

    // User Management (moved from System)
    Route::middleware('permission:settings.access.manage')->group(function () {
        Route::get('/settings/users', [\App\Http\Controllers\System\UsersController::class, 'index'])->name('settings.users.index');
        Route::get('/settings/users/create', [\App\Http\Controllers\System\UsersController::class, 'create'])->name('settings.users.create');
        Route::post('/settings/users', [\App\Http\Controllers\System\UsersController::class, 'store'])->name('settings.users.store');
        Route::get('/settings/users/{target}', [\App\Http\Controllers\System\UsersController::class, 'show'])->name('settings.users.show');
        Route::put('/settings/users/{target}', [\App\Http\Controllers\System\UsersController::class, 'update'])->name('settings.users.update');
        Route::delete('/settings/users/{target}', [\App\Http\Controllers\System\UsersController::class, 'destroy'])->name('settings.users.destroy');
        Route::post('/settings/users/{target}/approve', [\App\Http\Controllers\System\UsersController::class, 'approve'])->name('settings.users.approve');
        Route::post('/settings/users/{target}/suspend', [\App\Http\Controllers\System\UsersController::class, 'suspend'])->name('settings.users.suspend');
    });

    // Security Settings
    Route::middleware('permission:settings.access.manage')->group(function () {
        Route::get('/settings/security', function () {
            $keys = [
                'security.password_min_length',
                'security.password_require_uppercase',
                'security.password_require_numbers',
                'security.password_require_symbols',
                'security.password_expiry_days',
                'security.session_timeout_minutes',
                'security.max_login_attempts',
                'security.lockout_duration_minutes',
                'security.force_2fa',
            ];
            $settings = \App\Models\AppSetting::whereIn('key', $keys)
                ->pluck('value', 'key')
                ->mapWithKeys(fn ($value, $key) => [str_replace('security.', '', $key) => $value]);

            $totalUsers = \App\Models\User::count();
            $twoFaEnabled = \App\Models\User::whereNotNull('two_factor_confirmed_at')->count();

            return inertia('settings/security', [
                'settings' => $settings,
                'twoFaStats' => [
                    'enabled' => $twoFaEnabled,
                    'total' => $totalUsers,
                ],
            ]);
        })->name('settings.security');

        Route::put('/settings/security', function (\Illuminate\Http\Request $request) {
            $fields = [
                'password_min_length', 'password_require_uppercase', 'password_require_numbers',
                'password_require_symbols', 'password_expiry_days', 'session_timeout_minutes',
                'max_login_attempts', 'lockout_duration_minutes', 'force_2fa',
            ];
            collect($request->only($fields))->each(fn ($value, $key) =>
                \App\Models\AppSetting::updateOrCreate(
                    ['key' => "security.{$key}"],
                    ['value' => $value]
                )
            );

            return back()->with('success', 'Security settings updated.');
        })->name('settings.security.update');
    });

    // Audit Logs
    Route::get('/settings/audit-logs', function (\Illuminate\Http\Request $request) {
        $search = $request->query('search', '');
        $userFilter = $request->query('user', 'all');
        $moduleFilter = $request->query('module', 'all');
        $actionFilter = $request->query('action', 'all');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $query = \App\Models\AuditLog::query()
            ->with('user:id,name,email')
            ->latest();

        if ($search) {
            $query->where('action', 'like', "%{$search}%");
        }
        if ($userFilter !== 'all' && is_numeric($userFilter)) {
            $query->where('user_id', (int) $userFilter);
        }
        if ($moduleFilter !== 'all') {
            $query->where('auditable_type', 'like', "%{$moduleFilter}%");
        }
        if ($actionFilter !== 'all') {
            $query->where('action', $actionFilter);
        }
        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $events = $query->paginate(30)->through(fn ($log) => [
            'id' => $log->id,
            'description' => $log->action,
            'event' => strtolower(explode('.', $log->action)[0] ?? $log->action),
            'module' => $log->auditable_type ? strtolower(class_basename($log->auditable_type)) : null,
            'subject_type' => $log->auditable_type ? class_basename($log->auditable_type) : null,
            'subject_id' => $log->auditable_id,
            'properties' => $log->meta ?? [],
            'causer' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'email' => $log->user->email,
            ] : null,
            'created_at' => $log->created_at?->toISOString(),
        ]);

        $users = \App\Models\User::orderBy('name')->get(['id', 'name']);

        $today = now()->startOfDay();
        $weekStart = now()->startOfWeek();
        $monthStart = now()->startOfMonth();

        return inertia('settings/audit-logs', [
            'events' => $events,
            'users' => $users,
            'filters' => [
                'search' => $search,
                'user' => $userFilter,
                'module' => $moduleFilter,
                'action' => $actionFilter,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'stats' => [
                'today' => \App\Models\AuditLog::where('created_at', '>=', $today)->count(),
                'this_week' => \App\Models\AuditLog::where('created_at', '>=', $weekStart)->count(),
                'this_month' => \App\Models\AuditLog::where('created_at', '>=', $monthStart)->count(),
            ],
        ]);
    })->middleware('permission:audit.viewAny')->name('settings.audit');
});
