<?php

use App\Http\Controllers\Settings\AccessController;
use App\Http\Controllers\Settings\ApiSettingsController;
use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\AuditLogSettingsController;
use App\Http\Controllers\Settings\BrandingController;
use App\Http\Controllers\Settings\CalendarSyncOAuthController;
use App\Http\Controllers\Settings\CalendarSyncSettingsController;
use App\Http\Controllers\Settings\DataSettingsController;
use App\Http\Controllers\Settings\EmailSettingsController;
use App\Http\Controllers\Settings\ItMailboxOAuthController;
use App\Http\Controllers\Settings\ItMailboxSettingsController;
use App\Http\Controllers\Settings\ModuleSettingsController;
use App\Http\Controllers\Settings\NotificationEscalationsController;
use App\Http\Controllers\Settings\NotificationPreferencesController;
use App\Http\Controllers\Settings\NotificationTemplateController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\PushSubscriptionController;
use App\Http\Controllers\Settings\RolesController;
use App\Http\Controllers\Settings\SecurityPolicyController;
use App\Http\Controllers\Settings\ServiceContextController;
use App\Http\Controllers\Settings\SsoConfigController;
use App\Http\Controllers\Settings\SsoGroupController;
use App\Http\Controllers\Settings\TerminologyController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Http\Controllers\Settings\UiPreferenceController;
use App\Http\Controllers\Settings\UserManagementRedirectController;
use App\Http\Controllers\System\UsersController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::put('settings/ui-preferences/{key}', [UiPreferenceController::class, 'update'])
        ->name('settings.ui-preferences.update');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('settings/profile/landing', [ProfileController::class, 'updateLanding'])->name('profile.landing.update');
    Route::post('settings/profile/photo', [ProfileController::class, 'updatePhoto'])->name('profile.photo.update');
    Route::delete('settings/profile/photo', [ProfileController::class, 'destroyPhoto'])->name('profile.photo.destroy');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', [AppearanceController::class, 'edit'])
        ->name('appearance.edit');
    Route::put('settings/appearance', [AppearanceController::class, 'update'])
        ->name('appearance.update');
    Route::post('settings/appearance/reset', [AppearanceController::class, 'reset'])
        ->name('appearance.reset');

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
    Route::put('settings/notifications/delivery', [NotificationPreferencesController::class, 'updateDelivery'])
        ->name('settings.notifications.delivery.update');
    Route::post('settings/notifications/push-subscriptions', [PushSubscriptionController::class, 'store'])
        ->name('settings.notifications.push-subscriptions.store');
    Route::delete('settings/notifications/push-subscriptions', [PushSubscriptionController::class, 'destroy'])
        ->name('settings.notifications.push-subscriptions.destroy');

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

    // Legacy notification settings URLs kept for older links and browser tests.
    Route::redirect('settings/notification-escalations', '/settings/notifications/escalations', 301)
        ->name('settings.notification_escalations.legacy');
    Route::redirect('settings/notification-roles', '/settings/notifications/roles', 301)
        ->name('settings.notification_roles.legacy');

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
    Route::get('settings/email', [EmailSettingsController::class, 'index'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.email');
    Route::put('settings/email', [EmailSettingsController::class, 'update'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.email.update');
    Route::post('settings/email/test', [EmailSettingsController::class, 'test'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.email.test');

    // Security Settings
    Route::get('settings/security', [SecurityPolicyController::class, 'edit'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.security');
    Route::put('settings/security', [SecurityPolicyController::class, 'update'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.security.update');

    // API & Webhooks
    Route::get('settings/api', [ApiSettingsController::class, 'index'])
        ->middleware('permission:integrations.view')
        ->name('settings.api');
    Route::post('settings/api/keys', [ApiSettingsController::class, 'storeKey'])
        ->middleware('permission:integrations.manage_tenant_secrets')
        ->name('settings.api.keys.store');
    Route::post('settings/api/keys/{keyId}/revoke', [ApiSettingsController::class, 'revokeKey'])
        ->middleware('permission:integrations.manage_tenant_secrets')
        ->name('settings.api.keys.revoke');
    Route::post('settings/api/webhooks', [ApiSettingsController::class, 'storeWebhook'])
        ->middleware('permission:integrations.manage_tenant_secrets')
        ->name('settings.api.webhooks.store');
    Route::post('settings/api/webhooks/{webhookId}/test', [ApiSettingsController::class, 'testWebhook'])
        ->middleware('permission:integrations.manage_tenant_secrets')
        ->name('settings.api.webhooks.test');
    Route::delete('settings/api/webhooks/{webhookId}', [ApiSettingsController::class, 'destroyWebhook'])
        ->middleware('permission:integrations.manage_tenant_secrets')
        ->name('settings.api.webhooks.destroy');

    // Data & Privacy
    Route::get('settings/data', [DataSettingsController::class, 'index'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.data');
    Route::put('settings/data/retention', [DataSettingsController::class, 'updateRetention'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.data.retention.update');
    Route::put('settings/data/privacy', [DataSettingsController::class, 'updatePrivacy'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.data.privacy.update');
    Route::put('settings/data/compliance', [DataSettingsController::class, 'updateCompliance'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.data.compliance.update');
    Route::post('settings/data/requests', [DataSettingsController::class, 'storeRequest'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.data.requests.store');
    Route::post('settings/data/breaches', [DataSettingsController::class, 'storeBreach'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.data.breaches.store');
    Route::post('settings/data/processors', [DataSettingsController::class, 'storeProcessor'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.data.processors.store');
    Route::put('settings/data/processors/{processorId}', [DataSettingsController::class, 'updateProcessor'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.data.processors.update');
    Route::delete('settings/data/processors/{processorId}', [DataSettingsController::class, 'destroyProcessor'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.data.processors.destroy');

    // Modules & Features
    Route::get('settings/modules', [ModuleSettingsController::class, 'index'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.modules');
    Route::put('settings/modules', [ModuleSettingsController::class, 'update'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.modules.update');

    // User Management
    Route::get('settings/users', [UserManagementRedirectController::class, 'index'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.users.index');
    Route::get('settings/users/{target}', [UserManagementRedirectController::class, 'show'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.users.show');
    Route::put('settings/users/{target}', [UsersController::class, 'update'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.users.update');
    Route::post('settings/users/{target}/approve', [UsersController::class, 'approve'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.users.approve');
    Route::post('settings/users/{target}/suspend', [UsersController::class, 'suspend'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.users.suspend');
    Route::delete('settings/users/{target}/sessions/{session}', [UsersController::class, 'terminateSession'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.users.terminate-session');
    Route::delete('settings/users/{target}/sessions', [UsersController::class, 'terminateAllSessions'])
        ->middleware('permission:settings.access.manage')
        ->name('settings.users.terminate-all-sessions');

    // SSO Configuration
    Route::get('settings/sso', [SsoConfigController::class, 'index'])
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
    Route::get('settings/audit-logs', [AuditLogSettingsController::class, 'index'])
        ->middleware('permission:audit.viewAny|settings.access.manage')
        ->name('settings.audit_logs');
    Route::get('settings/audit-logs/export', [AuditLogSettingsController::class, 'export'])
        ->middleware('permission:audit.viewAny|settings.access.manage')
        ->name('settings.audit_logs.export');

    // Calendar sync (admin): connect Google Workspace / Microsoft 365 and map each
    // house to a resource calendar. Gated on the existing integrations-manage permission.
    Route::middleware('permission:integrations.manage_tenant_secrets')->group(function () {
        Route::get('settings/calendar-sync', [CalendarSyncSettingsController::class, 'index'])
            ->name('settings.calendar-sync');
        Route::put('settings/calendar-sync/mapping', [CalendarSyncSettingsController::class, 'updateMapping'])
            ->name('settings.calendar-sync.mapping');
        Route::put('settings/calendar-sync/settings', [CalendarSyncSettingsController::class, 'updateGlobal'])
            ->name('settings.calendar-sync.settings');
        Route::get('settings/calendar-sync/resources/{provider}', [CalendarSyncSettingsController::class, 'resources'])
            ->name('settings.calendar-sync.resources');
        Route::post('settings/calendar-sync/sync-now', [CalendarSyncSettingsController::class, 'syncNow'])
            ->name('settings.calendar-sync.sync-now');
        Route::post('settings/calendar-sync/mapping/{mapping}/reset-feed', [CalendarSyncSettingsController::class, 'resetFeed'])
            ->name('settings.calendar-sync.reset-feed');

        // Admin OAuth connect/callback/disconnect for the org calendar connection.
        Route::get('settings/calendar-sync/connect/{provider}', [CalendarSyncOAuthController::class, 'redirect'])
            ->name('settings.calendar-sync.connect');
        Route::get('settings/calendar-sync/callback/{provider}', [CalendarSyncOAuthController::class, 'callback'])
            ->name('settings.calendar-sync.callback');
        Route::delete('settings/calendar-sync/connect/{provider}', [CalendarSyncOAuthController::class, 'disconnect'])
            ->name('settings.calendar-sync.disconnect');
    });

    // IT support mailbox (email-to-ticket): connect the Exchange/Gmail account
    // the hourly PollItMailboxJob reads. Same admin gate as calendar-sync;
    // the OAuth flow mirrors it (E6).
    Route::middleware('permission:integrations.manage_tenant_secrets')->group(function () {
        Route::get('settings/it-mailbox', [ItMailboxSettingsController::class, 'index'])
            ->name('settings.it-mailbox');
        Route::put('settings/it-mailbox/mailbox/{provider}', [ItMailboxSettingsController::class, 'updateMailbox'])
            ->name('settings.it-mailbox.mailbox');
        Route::post('settings/it-mailbox/poll-now', [ItMailboxSettingsController::class, 'pollNow'])
            ->name('settings.it-mailbox.poll-now');
        Route::get('settings/it-mailbox/connect/{provider}', [ItMailboxOAuthController::class, 'redirect'])
            ->name('settings.it-mailbox.connect');
        Route::get('settings/it-mailbox/callback/{provider}', [ItMailboxOAuthController::class, 'callback'])
            ->name('settings.it-mailbox.callback');
        Route::delete('settings/it-mailbox/connect/{provider}', [ItMailboxOAuthController::class, 'disconnect'])
            ->name('settings.it-mailbox.disconnect');
    });

    // Hardware integrations now live in Security & Devices. Microsoft and Google stay in Auth/SSO.
    Route::redirect('settings/integrations', '/security-devices/integrations', 301)
        ->middleware('permission:integrations.view')
        ->name('settings.integrations.index');

    // UniFi integration configuration now lives in Security & Devices.
    // Keep a permanent redirect so bookmarks and older links still land
    // on the right place.
    Route::redirect('settings/integrations/unifi', '/security-devices/integrations/unifi', 301)
        ->name('settings.integrations.unifi');
});
