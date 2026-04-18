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
use App\Http\Controllers\Settings\NotificationTemplateController;
use App\Http\Controllers\Settings\SsoGroupController;
use App\Http\Controllers\System\UsersController;
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

    // Email & SMS Templates
    Route::get('settings/templates', [NotificationTemplateController::class, 'index'])
        ->middleware('permission:settings.templates.manage')
        ->name('settings.templates');
    Route::put('settings/templates/{template}', [NotificationTemplateController::class, 'update'])
        ->middleware('permission:settings.templates.manage')
        ->name('settings.templates.update');
    Route::post('settings/templates/{template}/preview', [NotificationTemplateController::class, 'preview'])
        ->middleware('permission:settings.templates.manage')
        ->name('settings.templates.preview');
    Route::post('settings/templates/{template}/send-test', [NotificationTemplateController::class, 'sendTest'])
        ->middleware('permission:settings.templates.manage')
        ->name('settings.templates.send-test');
    Route::post('settings/templates/{template}/reset', [NotificationTemplateController::class, 'reset'])
        ->middleware('permission:settings.templates.manage')
        ->name('settings.templates.reset');

    // Email Settings
    Route::get('settings/email', fn () => Inertia::render('settings/email-settings'))->name('settings.email');

    // Security Settings
    Route::get('settings/security', fn () => Inertia::render('settings/security', [
        'settings' => \App\Models\AppSetting::query()
            ->whereIn('key', [
                'password_min_length',
                'password_require_uppercase',
                'password_require_numbers',
                'password_require_symbols',
                'password_expiry_days',
                'session_timeout_minutes',
                'max_login_attempts',
                'lockout_duration_minutes',
                'force_2fa',
            ])
            ->pluck('value', 'key'),
        'twoFaStats' => [
            'enabled' => \App\Models\User::query()->whereNotNull('two_factor_confirmed_at')->count(),
            'total' => \App\Models\User::query()->count(),
        ],
    ]))
        ->middleware('permission:settings.access.manage')
        ->name('settings.security');
    Route::put('settings/security', function (\Illuminate\Http\Request $request) {
        $request->validate([
            'password_min_length' => ['required', 'integer', 'min:6', 'max:128'],
            'password_require_uppercase' => ['required', 'boolean'],
            'password_require_numbers' => ['required', 'boolean'],
            'password_require_symbols' => ['required', 'boolean'],
            'password_expiry_days' => ['required', 'integer', 'min:0'],
            'session_timeout_minutes' => ['required', 'integer', 'min:1'],
            'max_login_attempts' => ['required', 'integer', 'min:1'],
            'lockout_duration_minutes' => ['required', 'integer', 'min:1'],
            'force_2fa' => ['required', 'boolean'],
        ]);

        $settings = [
            'password_min_length' => (int) $request->input('password_min_length'),
            'password_require_uppercase' => $request->boolean('password_require_uppercase'),
            'password_require_numbers' => $request->boolean('password_require_numbers'),
            'password_require_symbols' => $request->boolean('password_require_symbols'),
            'password_expiry_days' => (int) $request->input('password_expiry_days'),
            'session_timeout_minutes' => (int) $request->input('session_timeout_minutes'),
            'max_login_attempts' => (int) $request->input('max_login_attempts'),
            'lockout_duration_minutes' => (int) $request->input('lockout_duration_minutes'),
            'force_2fa' => $request->boolean('force_2fa'),
        ];

        collect($settings)->each(
            fn ($value, $key) => \App\Models\AppSetting::updateOrCreate(['key' => $key], ['value' => $value])
        );

        \App\Services\AuditLogger::log('settings.security.updated', null, [
            'changes' => $settings,
            'changed_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Security settings updated.');
    })
        ->middleware('permission:settings.access.manage')
        ->name('settings.security.update');

    // API & Webhooks (UI-first, no backend yet)
    Route::get('settings/api', fn () => Inertia::render('settings/api'))->name('settings.api');

    // Data & Privacy (UI-first, no backend yet)
    Route::get('settings/data', fn () => Inertia::render('settings/data'))->name('settings.data');

    // Modules & Features (UI-first, no backend yet)
    Route::get('settings/modules', fn () => Inertia::render('settings/modules'))->name('settings.modules');

    // User Management
    Route::get('settings/users', [UsersController::class, 'index'])->name('settings.users.index');
    Route::get('settings/users/{target}', [UsersController::class, 'show'])->name('settings.users.show');
    Route::put('settings/users/{target}', [UsersController::class, 'update'])->name('settings.users.update');
    Route::post('settings/users/{target}/approve', [UsersController::class, 'approve'])->name('settings.users.approve');
    Route::post('settings/users/{target}/suspend', [UsersController::class, 'suspend'])->name('settings.users.suspend');
    Route::delete('settings/users/{target}/sessions/{session}', [UsersController::class, 'terminateSession'])->name('settings.users.terminate-session');
    Route::delete('settings/users/{target}/sessions', [UsersController::class, 'terminateAllSessions'])->name('settings.users.terminate-all-sessions');

    // SSO Configuration
    Route::get('settings/sso', fn () => Inertia::render('settings/sso-config', [
        'mappings' => \App\Models\SsoGroupMapping::with('role:id,name,label')->orderBy('provider')->get(),
        'roles' => \App\Models\Role::select('id', 'name', 'label')->orderBy('label')->get(),
        'stats' => [
            'total' => \App\Models\SsoGroupMapping::count(),
            'microsoft' => \App\Models\SsoGroupMapping::where('provider', 'microsoft')->count(),
            'google' => \App\Models\SsoGroupMapping::where('provider', 'google')->count(),
        ],
    ]))
        ->middleware('permission:settings.access.manage')
        ->name('settings.sso');
    Route::get('settings/sso-groups', [SsoGroupController::class, 'index'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.sso_groups.index');
    Route::post('settings/sso-groups', [SsoGroupController::class, 'store'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.sso_groups.store');
    Route::put('settings/sso-groups/{mapping}', [SsoGroupController::class, 'update'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.sso_groups.update');
    Route::delete('settings/sso-groups/{mapping}', [SsoGroupController::class, 'destroy'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.sso_groups.destroy');
    Route::post('settings/sso-groups/fetch', [SsoGroupController::class, 'fetchGroups'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.sso_groups.fetch');

    // Audit Logs
    Route::get('settings/audit-logs', function () {
        $query = \App\Models\AuditLog::query()
            ->with('user:id,name,email')
            ->orderByDesc('created_at');
        if (request('q')) {
            $query->where(function ($q) {
                $q->where('action', 'like', '%' . request('q') . '%')
                  ->orWhere('auditable_type', 'like', '%' . request('q') . '%');
            });
        }
        if (request('user_id')) { $query->where('user_id', request('user_id')); }
        if (request('type')) { $query->where('event', request('type')); }
        return Inertia::render('settings/audit-logs', [
            'events' => $query->paginate(50),
            'users' => \App\Models\User::select('id', 'name')->orderBy('name')->get(),
            'filters' => request()->only('q', 'user_id', 'type', 'from', 'to'),
        ]);
    })
        ->middleware('permission:audit.viewAny|settings.access.manage')
        ->name('settings.audit_logs');

    // Integrations hub
    Route::get('settings/integrations', [IntegrationHubController::class, 'index'])
        ->middleware('permission:integrations.view')
        ->name('settings.integrations.index');

    // UniFi integration configuration now lives in Security & Devices.
    // Keep a permanent redirect so bookmarks and older links still land
    // on the right place.
    Route::redirect('settings/integrations/unifi', '/security-devices/integrations/unifi', 301)
        ->name('settings.integrations.unifi');
});
