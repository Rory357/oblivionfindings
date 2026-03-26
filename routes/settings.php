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

    // User Management
    Route::get('settings/users', [\App\Http\Controllers\System\UsersController::class, 'index'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.users.index');
    Route::get('settings/users/{target}', [\App\Http\Controllers\System\UsersController::class, 'show'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.users.show');
    Route::put('settings/users/{target}', [\App\Http\Controllers\System\UsersController::class, 'update'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.users.update');
    Route::post('settings/users/{target}/approve', [\App\Http\Controllers\System\UsersController::class, 'approve'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.users.approve');
    Route::put('settings/users/{target}/roles', function (\Illuminate\Http\Request $request, \App\Models\User $target) {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);
        $data = $request->validate(['role_ids' => 'array', 'role_ids.*' => 'integer|exists:roles,id']);
        $target->roles()->sync($data['role_ids'] ?? []);
        return back()->with('success', 'Roles updated.');
    })->middleware('permission:settings.access.manage')->name('settings.users.roles.sync');
    Route::post('settings/users/{target}/suspend', [\App\Http\Controllers\System\UsersController::class, 'suspend'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.users.suspend');
    Route::delete('settings/users/{target}/sessions/{session}', [\App\Http\Controllers\System\UsersController::class, 'terminateSession'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.users.sessions.destroy');
    Route::delete('settings/users/{target}/sessions', [\App\Http\Controllers\System\UsersController::class, 'terminateAllSessions'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.users.sessions.destroyAll');

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

    // Email & SMS Templates (UI-first, no backend yet)
    Route::get('settings/templates', fn () => Inertia::render('settings/templates'))->name('settings.templates');

    // API & Webhooks (UI-first, no backend yet)
    Route::get('settings/api', fn () => Inertia::render('settings/api'))->name('settings.api');

    // Data & Privacy (UI-first, no backend yet)
    Route::get('settings/data', fn () => Inertia::render('settings/data'))->name('settings.data');

    // Modules & Features (UI-first, no backend yet)
    Route::get('settings/modules', fn () => Inertia::render('settings/modules'))->name('settings.modules');

    // Integrations hub
    Route::get('settings/integrations', [IntegrationHubController::class, 'index'])
        ->middleware('permission:integrations.view')
        ->name('settings.integrations.index');

    // SSO Group Mapping
    Route::get('settings/sso-groups', [\App\Http\Controllers\Settings\SsoGroupController::class, 'index'])->middleware('permission:settings.access.manage')->name('settings.sso-groups');
    Route::post('settings/sso-groups/fetch', [\App\Http\Controllers\Settings\SsoGroupController::class, 'fetchGroups'])->middleware('permission:settings.access.manage');
    Route::post('settings/sso-groups', [\App\Http\Controllers\Settings\SsoGroupController::class, 'store'])->middleware('permission:settings.access.manage');
    Route::put('settings/sso-groups/{mapping}', [\App\Http\Controllers\Settings\SsoGroupController::class, 'update'])->middleware('permission:settings.access.manage');
    Route::delete('settings/sso-groups/{mapping}', [\App\Http\Controllers\Settings\SsoGroupController::class, 'destroy'])->middleware('permission:settings.access.manage');

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
});
