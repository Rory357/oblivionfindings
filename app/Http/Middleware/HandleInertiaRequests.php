<?php

namespace App\Http\Middleware;

use App\Models\Announcement;
use App\Models\AppSetting;
use App\Models\OpsMessage;
use App\Models\ShiftOpenPosition;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
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
        $availableLocales = (array) config('locales.available', [
            'en' => ['label' => 'English (NZ)', 'native' => 'English (NZ)'],
        ]);
        $locale = (string) ($user?->locale ?? config('app.locale', 'en'));

        if (! array_key_exists($locale, $availableLocales)) {
            $locale = (string) config('app.fallback_locale', 'en');
        }

        app()->setLocale($locale);

        // Build permissions for frontend (RBAC)
        // Optimized: Cache permissions during request to reduce DB queries
        $can = null;

        if ($user) {
            $can = $this->getUserPermissions($user);
            if (isset($can['job_board'])) {
                $can['job_board']['open_count'] = $this->jobBoardOpenCount($user);
            }
        }

        // Pull all app-settings we need for chrome (labels / theme / branding)
        // in one query and key by setting name. Avoids 4+ round-trips per page.
        $settings = Schema::hasTable('app_settings')
            ? AppSetting::query()
                ->where(function ($q) {
                    $q->where('key', 'like', 'labels.%')
                        ->orWhereIn('key', [
                            'theme.light',
                            'theme.dark',
                            'branding.name',
                            'branding.logo_path',
                        ]);
                })
                ->get(['key', 'value'])
                ->keyBy('key')
            : collect();

        $labelOverrides = $settings
            ->filter(fn ($row, $key) => str_starts_with($key, 'labels.'))
            ->mapWithKeys(fn ($row, $key) => [substr($key, strlen('labels.')) => $row->value])
            ->toArray();

        $labels = array_merge(config('labels'), $labelOverrides);

        $themeLight = $settings->get('theme.light')?->value ?? [];
        $themeDark = $settings->get('theme.dark')?->value ?? [];
        $brandingName = $settings->get('branding.name')?->value;
        $logoPath = $settings->get('branding.logo_path')?->value;
        $logoUrl = $logoPath ? Storage::disk('public')->url($logoPath) : null;

        $hasOpsMessagingTables = Schema::hasTable('ops_messages')
            && Schema::hasTable('ops_conversation_participants');
        $hasNotificationsTable = Schema::hasTable('notifications');
        $hasAnnouncementsTables = Schema::hasTable('announcements')
            && Schema::hasTable('announcement_user_reads');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,

                    'profile_photo_url' => $user->profile_photo_url,

                    'avatar' => $user->avatar,

                    // Keep during migration (existing UI uses it)
                    'role' => $user->role ?? null,

                    'organization_id' => $user->organization_id ?? null,
                ] : null,

                // NEW: capability map for the UI
                'can' => $can,

                'impersonating' => $user ? app('impersonate')->isImpersonating() : false,
                'impersonator' => $user && app('impersonate')->isImpersonating()
                    ? User::find(app('impersonate')->getImpersonatorId())?->only('id', 'name')
                    : null,

                // Portal client data for sidebar navigation
                'portalClients' => $user && $user->hasRole('client', 'next_of_kin')
                    ? $user->portalClients()->get(['clients.id', 'clients.first_name', 'clients.last_name', 'clients.profile_photo_path'])->map(fn ($c) => [
                        'id' => $c->id,
                        'name' => trim($c->first_name.' '.$c->last_name),
                        'avatar' => $c->profile_photo_url,
                        'relation' => $c->pivot->relation ?? null,
                    ])->values()->all()
                    : null,
                'unreadMessageCount' => $user && $hasOpsMessagingTables
                    ? OpsMessage::query()
                        ->whereExists(fn ($q) => $q->from('ops_conversation_participants')
                            ->whereColumn('ops_conversation_participants.conversation_id', 'ops_messages.conversation_id')
                            ->where('ops_conversation_participants.user_id', $user->id))
                        ->where('sender_id', '!=', $user->id)
                        ->where('is_read', false)
                        ->count()
                    : 0,
            ],

            'labels' => $labels,
            'locale' => $locale,
            'availableLocales' => $availableLocales,
            'translations' => [
                'app' => trans('app'),
                'rostering' => trans('rostering'),
            ],

            // NEW: organisation theme tokens and branding assets
            'theme' => [
                'light' => is_array($themeLight) ? $themeLight : [],
                'dark' => is_array($themeDark) ? $themeDark : [],
            ],
            'branding' => [
                'name' => is_string($brandingName) && trim($brandingName) !== '' ? $brandingName : config('app.name'),
                'logoUrl' => $logoUrl,
            ],
            // Per-user appearance preferences so the React app can hydrate
            // from server state instead of only trusting localStorage. Null
            // values mean "fall back to brand/org defaults". The `appearance`
            // cookie is a fallback for users who toggled the theme but whose
            // DB column is still null (e.g. first login on a new device) —
            // without it the first paint reverts to system/dark.
            'appearance' => $user ? [
                'theme' => $user->theme
                    ?? (in_array($request->cookie('appearance'), ['light', 'dark', 'system'], true)
                        ? $request->cookie('appearance')
                        : 'system'),
                'accent_colour' => $user->accent_colour,
                'font_size' => (int) ($user->font_size ?? 14),
                'sidebar_density' => $user->sidebar_density ?? 'comfortable',
                'reduce_motion' => (bool) ($user->reduce_motion ?? false),
                'first_day_of_week' => $user->first_day_of_week ?? 'monday',
                'date_format' => $user->date_format ?? 'DD/MM/YYYY',
                'time_format' => $user->time_format ?? '24',
            ] : null,
            'fleet' => [
                'maps' => [
                    'apiKey' => config('fleet.maps.api_key'),
                ],
            ],

            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',

            // Flash messages for global toasts
            'flash' => [
                // Many controllers use 'status' (starter-kit convention). Treat it as success.
                'success' => session('success') ?? session('status'),
                'error' => session('error'),
                'warning' => session('warning'),
                'info' => session('info'),
                'clock_out_blockers' => session('clock_out_blockers'),
                'rostering_report_link' => session('rostering_report_link'),
                // /my-day's "Today's timesheet" find-or-create flow uses this
                // to tell the front-end which draft to open in the review
                // popup once props refresh.
                'open_timesheet_id' => session('open_timesheet_id'),
            ],

            // Header inbox (notifications + announcements). Deferred so the
            // initial page render doesn't block on these queries — Inertia
            // fetches them in a follow-up request after mount.
            'inbox' => Inertia::defer(fn () => $user ? [
                'notifications' => [
                    'unread_count' => $hasNotificationsTable ? $user->unreadNotifications()->count() : 0,
                    'items' => $hasNotificationsTable
                        ? $user->notifications()
                            ->latest()
                            ->limit(8)
                            ->get(['id', 'type', 'data', 'read_at', 'acknowledged_at', 'escalation_count', 'created_at'])
                            ->map(fn ($n) => [
                                'id' => $n->id,
                                'type' => $n->type,
                                'data' => $n->data,
                                'read_at' => optional($n->read_at)->toISOString(),
                                'acknowledged_at' => optional($n->acknowledged_at)->toISOString(),
                                'escalation_count' => (int) ($n->escalation_count ?? 0),
                                'created_at' => optional($n->created_at)->toISOString(),
                            ])
                            ->values()
                        : collect(),
                ],
                'announcements' => $hasAnnouncementsTables
                    ? Announcement::inboxFor($user)
                    : ['unread_count' => 0, 'items' => []],
            ] : null),
        ];
    }

    /**
     * Permission map bust — bump when permission shape/keys change so
     * stale caches from previous deploys are ignored.
     */
    protected const PERMISSIONS_CACHE_VERSION = 'v2';

    /**
     * Get user permissions, deduped per-request via `once()` and cached
     * across requests via the cache store. Short TTL keeps role changes
     * visible without needing explicit invalidation.
     */
    protected function getUserPermissions($user): array
    {
        return once(fn () => Cache::remember(
            sprintf('user:%d:capabilities:%s', $user->id, self::PERMISSIONS_CACHE_VERSION),
            300,
            fn () => $this->buildUserPermissions($user),
        ));
    }

    protected function buildUserPermissions($user): array
    {
        return [
            'sites' => [
                'viewAny' => $user->canDo('sites.viewAny'),
                'create' => $user->canDo('sites.create'),
                'update' => $user->canDo('sites.update'),
                'archive' => $user->canDo('sites.archive'),
                'types' => [
                    'headOfficeView' => $user->canDo('sites.type.head_office.view'),
                    'houseView' => $user->canDo('sites.type.house.view'),
                    'facilityView' => $user->canDo('sites.type.facility.view'),
                ],
            ],

            'staff' => [
                'viewAny' => $user->canDo('staff.viewAny'),
                'create' => $user->canDo('staff.create'),
                'update' => $user->canDo('staff.update'),
                'invite' => $user->canDo('staff.invite'),
                'assignmentsUpdate' => $user->canDo('staff.assignments.update'),
                'credentialsViewAny' => $user->canDo('staff.credentials.viewAny'),
                'credentialsUpdateAny' => $user->canDo('staff.credentials.updateAny'),
                'credentialsUpdateSelf' => $user->canDo('staff.credentials.updateSelf'),
                'availabilityUpdateAny' => $user->canDo('staff.availability.updateAny'),
                'availabilityUpdateSelf' => $user->canDo('staff.availability.updateSelf'),
            ],
            'clients' => [
                'viewAny' => $user->canDo('clients.viewAny'),
                'viewAssigned' => $user->canDo('clients.viewAssigned'),
                'create' => $user->canDo('clients.create'),
                'update' => $user->canDo('clients.update'),
                // Archiving a client is gated by the same capability as editing one.
                'archive' => $user->canDo('clients.update'),
                'assignmentsUpdate' => $user->canDo('clients.assignments.update'),
            ],
            'shifts' => [
                'viewAny' => $user->canDo('shifts.viewAny'),
                'viewAssigned' => $user->canDo('shifts.viewAssigned'),
                'create' => $user->canDo('shifts.create'),
                'update' => $user->canDo('shifts.update'),
                'manageAny' => $user->canDo('shifts.manageAny'),
                'tasksUpdateSelf' => $user->canDo('shifts.tasks.updateSelf'),
            ],

            'timesheets' => [
                'viewAny' => $user->canDo('timesheets.viewAny'),
                'viewAssigned' => $user->canDo('timesheets.viewAssigned'),
                'create' => $user->canDo('timesheets.create'),
                'update' => $user->canDo('timesheets.update'),
                'submit' => $user->canDo('timesheets.submit'),
                'approve' => $user->canDo('timesheets.approve'),
                'manageAny' => $user->canDo('timesheets.manageAny'),
            ],

            'reports' => [
                'viewAny' => $user->canDo('reports.viewAny'),
            ],

            'assets' => [
                'viewAny' => $user->canDo('assets.viewAny'),
                'viewAssigned' => $user->canDo('assets.viewAssigned'),
                'create' => $user->canDo('assets.create'),
                'update' => $user->canDo('assets.update'),
                'delete' => $user->canDo('assets.delete'),
                'inspectionsRecord' => $user->canDo('assets.inspections.record'),
                'maintenanceRecord' => $user->canDo('assets.maintenance.record'),
                'documentsManage' => $user->canDo('assets.documents.manage'),
                'qrDownload' => $user->canDo('assets.qr.download'),
                'ownershipManage' => $user->canDo('assets.ownership.manage'),
                'assignmentsManage' => $user->canDo('assets.assignments.manage'),
                'trackersManage' => $user->canDo('assets.trackers.manage'),
                'telemetryIngest' => $user->canDo('assets.telemetry.ingest'),
                'telemetryView' => $user->canDo('assets.telemetry.view'),
                'alertsView' => $user->canDo('assets.alerts.view'),
                'scanRecord' => $user->canDo('assets.scan.record'),
                'geofencesManage' => $user->canDo('assets.geofences.manage'),
            ],

            'medications' => [
                'view' => $user->canDo('medications.view'),
                'ordersManage' => $user->canDo('medications.orders.manage'),
                'administerRecord' => $user->canDo('medications.administer.record'),
                'administerCorrect' => $user->canDo('medications.administer.correct'),
                'auditView' => $user->canDo('medications.audit.view'),
                'reportsExport' => $user->canDo('medications.reports.export'),
                'controlledView' => $user->canDo('medications.controlled.view'),
                'controlledRecord' => $user->canDo('medications.controlled.record'),
                'controlledWitness' => $user->canDo('medications.controlled.witness'),
                'controlledOverride' => $user->canDo('medications.controlled.override'),
                'breakGlass' => $user->canDo('medications.breakglass'),
            ],

            'rostering' => [
                'viewAny' => $user->canDo('rostering.viewAny'),
                'autoSchedule' => $user->canDo('rostering.autoSchedule'),
                'publish' => $user->canDo('rostering.publish'),
            ],

            'fleet' => [
                'viewAny' => $user->canDo('fleet.viewAny'),
                'driverSessionsManage' => $user->canDo('fleet.driverSessions.manage'),
                'signalsView' => $user->canDo('fleet.signals.view'),
            ],
            'controlRoom' => [
                'viewAny' => $user->canDo('controlRoom.viewAny'),
                'alertsView' => $user->canDo('controlRoom.alerts.view'),
                'alertsManage' => $user->canDo('controlRoom.alerts.manage'),
                'alertsAssign' => $user->canDo('controlRoom.alerts.assign'),
                'alertsEscalate' => $user->canDo('controlRoom.alerts.escalate'),
                'alertsCreate' => $user->canDo('controlRoom.alerts.create'),
                'reportsView' => $user->canDo('controlRoom.reports.view'),
            ],

            'securityDevices' => [
                'viewAny' => $user->canDo('securityDevices.viewAny'),
                'devicesView' => $user->canDo('securityDevices.devices.view'),
                'devicesCreate' => $user->canDo('securityDevices.devices.create'),
                'devicesUpdate' => $user->canDo('securityDevices.devices.update'),
                'devicesDelete' => $user->canDo('securityDevices.devices.delete'),
                'devicesAssign' => $user->canDo('securityDevices.devices.assign'),
                'groupsManage' => $user->canDo('securityDevices.groups.manage'),
                'eventsView' => $user->canDo('securityDevices.events.view'),
                'maintenanceView' => $user->canDo('securityDevices.maintenance.view'),
                'maintenanceManage' => $user->canDo('securityDevices.maintenance.manage'),
                'integrationsView' => $user->canDo('securityDevices.integrations.view'),
                'integrationsManage' => $user->canDo('securityDevices.integrations.manage'),
                'reportsView' => $user->canDo('securityDevices.reports.view'),
            ],

            'calendar' => [
                'viewAny' => $user->canDo('calendar.viewAny'),
                'view' => $user->canDo('calendar.view'),
                'create' => $user->canDo('calendar.create'),
                'approve' => $user->canDo('calendar.approve'),
                'manageRecurring' => $user->canDo('calendar.manage_recurring'),
            ],

            'clinical' => [
                'dashboard' => $user->canDo('clinical.dashboard'),
                'observationsView' => $user->canDo('clinical.observations.view'),
                'observationsViewAssigned' => $user->canDo('clinical.observations.viewAssigned'),
                'observationsRecord' => $user->canDo('clinical.observations.record'),
                'observationsRecordClinical' => $user->canDo('clinical.observations.recordClinical'),
                'eventsView' => $user->canDo('clinical.events.view'),
                'eventsViewAssigned' => $user->canDo('clinical.events.viewAssigned'),
                'eventsRecord' => $user->canDo('clinical.events.record'),
            ],

            'hazards' => [
                'view' => $user->canDo('hazards.view'),
                'create' => $user->canDo('hazards.create'),
                'assign' => $user->canDo('hazards.assign'),
                'close' => $user->canDo('hazards.close'),
                'manageTypes' => $user->canDo('hazards.manage_types'),
            ],

            'checklists' => [
                'view' => $user->canDo('checklists.view'),
                'run' => $user->canDo('checklists.run'),
                'schedule' => $user->canDo('checklists.schedule'),
                'manageTemplates' => $user->canDo('checklists.manage_templates'),
            ],

            'vendors' => [
                'view' => $user->canDo('vendors.view'),
                'manage' => $user->canDo('vendors.manage'),
            ],

            'credentials' => [
                'view' => $user->canDo('credentials.view'),
                'reveal' => $user->canDo('credentials.reveal'),
                'manage' => $user->canDo('credentials.manage'),
            ],

            'sitesReports' => [
                'view' => $user->canDo('reports.sites.view'),
                'export' => $user->canDo('reports.sites.export'),
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

            'compliance' => [
                'view' => $user->canDo('compliance.view'),
            ],

            'incidents' => [
                'viewAny' => $user->canDo('incidents.viewAny'),
                'viewAssigned' => $user->canDo('incidents.viewAssigned'),
                'create' => $user->canDo('incidents.create'),
                'update' => $user->canDo('incidents.update'),
                'submit' => $user->canDo('incidents.submit'),
                'approve' => $user->canDo('incidents.approve'),
                'export' => $user->canDo('incidents.export'),
                'templatesManage' => $user->canDo('incidents.templates.manage'),
                'followupsManage' => $user->canDo('incidents.followups.manage'),
                'followupsComplete' => $user->canDo('incidents.followups.complete'),
                'portalManage' => $user->canDo('incidents.portal.manage'),
                'portalView' => $user->canDo('incidents.view.portal'),
            ],

            'risks' => [
                'viewAny' => $user->canDo('risks.viewAny'),
                'viewAssigned' => $user->canDo('risks.viewAssigned'),
                'create' => $user->canDo('risks.create'),
                'update' => $user->canDo('risks.update'),
                'delete' => $user->canDo('risks.delete'),
            ],

            'integrations' => [
                'view' => $user->canDo('integrations.view'),
                'manageTenantSecrets' => $user->canDo('integrations.manage_tenant_secrets'),
                'manageSiteSecrets' => $user->canDo('integrations.manage_site_secrets'),
            ],

            'siteHardware' => [
                'view' => $user->canDo('siteHardware.view'),
                'manage' => $user->canDo('siteHardware.manage'),
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
                'manageServiceContexts' => $user->canDo('settings.service_contexts.manage'),
                'templatesManage' => $user->canDo('settings.templates.manage'),
                'impersonate' => $user->canDo('settings.access.impersonate'),
            ],

            'safeguarding' => [
                'viewAny' => $user->canDo('safeguarding.viewAny'),
                'create' => $user->canDo('safeguarding.create'),
                'update' => $user->canDo('safeguarding.update'),
                'investigate' => $user->canDo('safeguarding.investigate'),
                'reportExternal' => $user->canDo('safeguarding.report.external'),
                'viewSensitive' => $user->canDo('safeguarding.viewSensitive'),
            ],

            'privacy' => [
                'viewRequests' => $user->canDo('privacy.viewRequests'),
                'processRequests' => $user->canDo('privacy.processRequests'),
                'manageRetention' => $user->canDo('privacy.manageRetention'),
                'manageLegalHolds' => $user->canDo('privacy.manageLegalHolds'),
                'reportBreaches' => $user->canDo('privacy.reportBreaches'),
                'conductDPIA' => $user->canDo('privacy.conductDPIA'),
            ],

            'respite' => [
                'viewAny' => $user->canDo('respite.viewAny'),
                'create' => $user->canDo('respite.create'),
                'update' => $user->canDo('respite.update'),
                'bookingsManage' => $user->canDo('respite.bookings.manage'),
                'staysManage' => $user->canDo('respite.stays.manage'),
                'resourcesManage' => $user->canDo('respite.resources.manage'),
                'proceduresManage' => $user->canDo('respite.procedures.manage'),
                'calendarView' => $user->canDo('respite.calendar.view'),
                'evidenceView' => $user->canDo('respite.evidence.view'),
            ],

            'consents' => [
                'viewAny' => $user->canDo('consents.viewAny'),
                'manage' => $user->canDo('consents.manage'),
                'record' => $user->canDo('consents.record'),
                'withdraw' => $user->canDo('consents.withdraw'),
                'request' => $user->canDo('consents.request'),
            ],

            'hr' => [
                'recruitment' => [
                    'view' => $user->canDo('hr.recruitment.view'),
                    'manage' => $user->canDo('hr.recruitment.manage'),
                ],
                'employees' => [
                    'viewAny' => $user->canDo('hr.employees.viewAny'),
                    'viewOwn' => $user->canDo('hr.employees.viewOwn'),
                    'manage' => $user->canDo('hr.employees.manage'),
                    'viewFinancial' => $user->canDo('hr.employees.viewFinancial'),
                    'viewRestricted' => $user->canDo('hr.employees.viewRestricted'),
                ],
                'compliance' => [
                    'view' => $user->canDo('hr.compliance.view'),
                    'manage' => $user->canDo('hr.compliance.manage'),
                ],
                'training' => [
                    'view' => $user->canDo('hr.training.view'),
                    'manage' => $user->canDo('hr.training.manage'),
                ],
                'vetting' => [
                    'view' => $user->canDo('hr.vetting.view'),
                    'manage' => $user->canDo('hr.vetting.manage'),
                ],
                'leave' => [
                    'viewAny' => $user->canDo('hr.leave.viewAny'),
                    'viewOwn' => $user->canDo('hr.leave.viewOwn'),
                    'approve' => $user->canDo('hr.leave.approve'),
                    'manage' => $user->canDo('hr.leave.manage'),
                ],
                'performance' => [
                    'view' => $user->canDo('hr.performance.view'),
                    'manage' => $user->canDo('hr.performance.manage'),
                ],
                'cases' => [
                    'view' => $user->canDo('hr.cases.view'),
                    'manage' => $user->canDo('hr.cases.manage'),
                ],
                'policies' => [
                    'view' => $user->canDo('hr.policies.view'),
                    'manage' => $user->canDo('hr.policies.manage'),
                    'attest' => $user->canDo('hr.policies.attest'),
                ],
                'documents' => [
                    'view' => $user->canDo('hr.documents.view'),
                    'manage' => $user->canDo('hr.documents.manage'),
                ],
                'payroll' => [
                    'view' => $user->canDo('hr.payroll.view'),
                    'export' => $user->canDo('hr.payroll.export'),
                ],
                'reports' => [
                    'view' => $user->canDo('hr.reports.view'),
                    'export' => $user->canDo('hr.reports.export'),
                ],
                'driver' => [
                    'view' => $user->canDo('hr.driver.view'),
                    'manage' => $user->canDo('hr.driver.manage'),
                ],
                'wellbeing' => [
                    'view' => $user->canDo('hr.wellbeing.view'),
                ],
                'onboarding' => [
                    'view' => $user->canDo('hr.onboarding.view'),
                    'manage' => $user->canDo('hr.onboarding.manage'),
                ],
                'positions' => [
                    'view' => $user->canDo('hr.positions.view'),
                    'manage' => $user->canDo('hr.positions.manage'),
                ],
                'orgchart' => [
                    'view' => $user->canDo('hr.orgchart.view'),
                    'manage' => $user->canDo('hr.orgchart.manage'),
                ],
                'time' => [
                    'view' => $user->canDo('timesheets.viewAny'),
                    'viewAny' => $user->canDo('timesheets.viewAny'),
                    'manage' => $user->canDo('timesheets.manageAny'),
                    'approve' => $user->canDo('timesheets.manageAny') || $user->canDo('timesheets.approve'),
                    'approveTeam' => $user->canDo('timesheets.approve'),
                ],
                'compensation' => [
                    'view' => $user->canDo('hr.compensation.view'),
                    'manage' => $user->canDo('hr.compensation.manage'),
                ],
                'benefits' => [
                    'view' => $user->canDo('hr.benefits.view'),
                    'manage' => $user->canDo('hr.benefits.manage'),
                ],
                'goals' => [
                    'view' => $user->canDo('hr.goals.view'),
                    'manage' => $user->canDo('hr.goals.manage'),
                ],
                'assets' => [
                    'view' => $user->canDo('hr.assets.view'),
                    'manage' => $user->canDo('hr.assets.manage'),
                ],
                'calendar' => [
                    'view' => $user->canDo('hr.calendar.view'),
                    'manage' => $user->canDo('hr.calendar.manage'),
                ],
                'analytics' => [
                    'view' => $user->canDo('hr.analytics.view'),
                ],
                'surveys' => [
                    'view' => $user->canDo('hr.surveys.view'),
                    'manage' => $user->canDo('hr.surveys.manage'),
                ],
                'expenses' => [
                    'view' => $user->canDo('hr.expenses.view'),
                    'manage' => $user->canDo('hr.expenses.manage'),
                    'approve' => $user->canDo('hr.expenses.approve'),
                ],
                'skills' => [
                    'view' => $user->canDo('hr.skills.view'),
                    'manage' => $user->canDo('hr.skills.manage'),
                ],
                'announcements' => [
                    'view' => $user->canDo('hr.announcements.view'),
                    'manage' => $user->canDo('hr.announcements.manage'),
                ],
                'approvals' => [
                    'view' => $user->canDo('hr.approvals.view'),
                    'manage' => $user->canDo('hr.approvals.manage'),
                ],
                'settings' => [
                    'manage' => $user->canDo('hr.settings.manage'),
                ],
            ],

            'governance' => [
                'view' => $user->canDo('governance.view'),
                'meetings' => [
                    'view' => $user->canDo('governance.meetings.view'),
                    'manage' => $user->canDo('governance.meetings.manage'),
                ],
                'resolutions' => [
                    'view' => $user->canDo('governance.resolutions.view'),
                    'vote' => $user->canDo('governance.resolutions.vote'),
                    'manage' => $user->canDo('governance.resolutions.manage'),
                ],
                'risks' => [
                    'view' => $user->canDo('governance.risks.view'),
                    'manage' => $user->canDo('governance.risks.manage'),
                ],
                'compliance' => [
                    'view' => $user->canDo('governance.compliance.view'),
                    'manage' => $user->canDo('governance.compliance.manage'),
                ],
                'performance' => [
                    'view' => $user->canDo('governance.performance.view'),
                    'manage' => $user->canDo('governance.performance.manage'),
                ],
                'strategy' => [
                    'view' => $user->canDo('governance.strategy.view'),
                    'manage' => $user->canDo('governance.strategy.manage'),
                ],
                'budgets' => [
                    'view' => $user->canDo('governance.budgets.view'),
                    'create' => $user->canDo('governance.budgets.create'),
                    'submit' => $user->canDo('governance.budgets.submit'),
                    'approve' => $user->canDo('governance.budgets.approve'),
                ],
                'packs' => [
                    'view' => $user->canDo('governance.packs.view'),
                    'manage' => $user->canDo('governance.packs.manage'),
                ],
                'actions' => [
                    'view' => $user->canDo('governance.actions.view'),
                    'manage' => $user->canDo('governance.actions.manage'),
                ],
                'policies' => [
                    'view' => $user->canDo('governance.policies.view'),
                    'manage' => $user->canDo('governance.policies.manage'),
                ],
                'documents' => [
                    'view' => $user->canDo('governance.documents.view'),
                    'manage' => $user->canDo('governance.documents.manage'),
                ],
                'ceo-reports' => [
                    'view' => $user->canDo('governance.ceo-reports.view'),
                    'manage' => $user->canDo('governance.ceo-reports.manage'),
                ],
                'interests' => [
                    'view' => $user->canDo('governance.interests.view'),
                    'manage' => $user->canDo('governance.interests.manage'),
                ],
                'evaluations' => [
                    'view' => $user->canDo('governance.evaluations.view'),
                    'manage' => $user->canDo('governance.evaluations.manage'),
                ],
                'clinical' => [
                    'view' => $user->canDo('governance.clinical.view'),
                    'manage' => $user->canDo('governance.clinical.manage'),
                ],
                'te-tiriti' => [
                    'view' => $user->canDo('governance.te-tiriti.view'),
                    'manage' => $user->canDo('governance.te-tiriti.manage'),
                ],
                'evidence' => [
                    'view' => $user->canDo('governance.evidence.view'),
                    'manage' => $user->canDo('governance.evidence.manage'),
                ],
                'audit' => [
                    'view' => $user->canDo('governance.audit.view'),
                ],
                'spend' => [
                    'view' => $user->canDo('governance.spend.view'),
                    'request' => $user->canDo('governance.spend.request'),
                    'approve' => $user->canDo('governance.spend.approve'),
                ],
                'settings' => [
                    'view' => $user->canDo('governance.settings.view'),
                    'manage' => $user->canDo('governance.settings.manage'),
                ],
            ],

            'finance' => [
                'dashboard' => $user->canDo('finance.dashboard'),
                'ledger' => [
                    'view' => $user->canDo('finance.ledger.view'),
                    'manage' => $user->canDo('finance.ledger.manage'),
                ],
                'ap' => [
                    'view' => $user->canDo('finance.ap.view'),
                    'manage' => $user->canDo('finance.ap.manage'),
                ],
                'ar' => [
                    'view' => $user->canDo('finance.ar.view'),
                    'manage' => $user->canDo('finance.ar.manage'),
                ],
                'bank' => [
                    'view' => $user->canDo('finance.bank.view'),
                    'manage' => $user->canDo('finance.bank.manage'),
                ],
                'tax' => [
                    'view' => $user->canDo('finance.tax.view'),
                    'manage' => $user->canDo('finance.tax.manage'),
                ],
                'assets' => [
                    'view' => $user->canDo('finance.assets.view'),
                    'manage' => $user->canDo('finance.assets.manage'),
                ],
                'pettyCash' => [
                    'view' => $user->canDo('finance.petty_cash.view'),
                    'manage' => $user->canDo('finance.petty_cash.manage'),
                ],
                'reports' => [
                    'view' => $user->canDo('finance.reports.view'),
                ],
                'admin' => $user->canDo('finance.admin'),
            ],

            'roadmap' => [
                'view' => $user->canDo('roadmap.view'),
                'manage' => $user->canDo('roadmap.manage'),
                'approve' => $user->canDo('roadmap.approve'),
                'budgetManage' => $user->canDo('roadmap.budget.manage'),
                'decisionsView' => $user->canDo('roadmap.decisions.view'),
                'decisionsManage' => $user->canDo('roadmap.decisions.manage'),
                'reportsExport' => $user->canDo('roadmap.reports.export'),
            ],

            // Operations domain capabilities referenced by the sidebar
            // and guarded by route middleware across the Operations module.
            'operations' => [
                'dashboard' => $user->canDo('operations.dashboard.view'),
                'reports' => [
                    'view' => $user->canDo('operations.reports.view'),
                ],
            ],
            'care_plans' => [
                'viewAny' => $user->canDo('care_plans.viewAny'),
                'create' => $user->canDo('care_plans.create'),
                'update' => $user->canDo('care_plans.update'),
                'delete' => $user->canDo('care_plans.delete'),
                'goalsManage' => $user->canDo('care_plans.goals.manage'),
            ],
            'progress_notes' => [
                'viewAny' => $user->canDo('progress_notes.viewAny'),
                'create' => $user->canDo('progress_notes.create'),
                'update' => $user->canDo('progress_notes.update'),
                'delete' => $user->canDo('progress_notes.delete'),
                'review' => $user->canDo('progress_notes.review'),
            ],
            'service_agreements' => [
                'viewAny' => $user->canDo('service_agreements.viewAny'),
                'create' => $user->canDo('service_agreements.create'),
                'update' => $user->canDo('service_agreements.update'),
                'delete' => $user->canDo('service_agreements.delete'),
            ],
            'billing' => [
                'viewAny' => $user->canDo('billing.viewAny'),
                'create' => $user->canDo('billing.create'),
                'approve' => $user->canDo('billing.approve'),
            ],
            'invoices' => [
                'viewAny' => $user->canDo('invoices.viewAny'),
                'create' => $user->canDo('invoices.create'),
                'send' => $user->canDo('invoices.send'),
                'void' => $user->canDo('invoices.void'),
            ],
            'funding' => [
                'viewAny' => $user->canDo('funding.viewAny'),
                'claimsCreate' => $user->canDo('funding.claims.create'),
                'claimsSubmit' => $user->canDo('funding.claims.submit'),
            ],
            'messages' => [
                'viewAny' => $user->canDo('messages.viewAny'),
                'send' => $user->canDo('messages.send'),
            ],
            'handovers' => [
                'viewAny' => $user->canDo('handovers.viewAny'),
                'create' => $user->canDo('handovers.create'),
            ],
            'quotes' => [
                'viewAny' => $user->canDo('quotes.viewAny'),
                'create' => $user->canDo('quotes.create'),
                'update' => $user->canDo('quotes.update'),
            ],
            'mileage' => [
                'viewAny' => $user->canDo('mileage.viewAny'),
                'viewOwn' => $user->canDo('mileage.viewOwn'),
                'create' => $user->canDo('mileage.create'),
                'approve' => $user->canDo('mileage.approve'),
            ],
            'custom_forms' => [
                'viewAny' => $user->canDo('custom_forms.viewAny'),
                'create' => $user->canDo('custom_forms.create'),
                'update' => $user->canDo('custom_forms.update'),
                'submit' => $user->canDo('custom_forms.submit'),
            ],
            'evv' => [
                'viewAny' => $user->canDo('evv.viewAny'),
                'record' => $user->canDo('evv.record'),
                'verify' => $user->canDo('evv.verify'),
            ],
            'care_note_templates' => [
                'viewAny' => $user->canDo('care_note_templates.viewAny'),
            ],
            'note_templates' => [
                'viewAny' => $user->canDo('note_templates.viewAny'),
                'manage' => $user->canDo('note_templates.manage'),
            ],
            'client_funds' => [
                'manage' => $user->canDo('client_funds.manage'),
            ],
            'price_books' => [
                'viewAny' => $user->canDo('price_books.viewAny'),
                'create' => $user->canDo('price_books.create'),
                'update' => $user->canDo('price_books.update'),
            ],
            'recurring_charges' => [
                'viewAny' => $user->canDo('recurring_charges.viewAny'),
                'manage' => $user->canDo('recurring_charges.manage'),
            ],
            'family_portal' => [
                'viewAny' => $user->canDo('family_portal.viewAny'),
                'manage' => $user->canDo('family_portal.manage'),
            ],
            'onboarding' => [
                'viewAny' => $user->canDo('onboarding.viewAny'),
                'view' => $user->canDo('onboarding.view'),
                'create' => $user->canDo('onboarding.create'),
                'edit' => $user->canDo('onboarding.edit'),
            ],
            'job_board' => [
                'viewAny' => $user->canDo('job_board.viewAny'),
                'create' => $user->canDo('job_board.create'),
                'claim' => $user->canDo('job_board.claim'),
                'approve' => $user->canDo('job_board.approve'),
            ],
            'qualifications' => [
                'viewAny' => $user->canDo('qualifications.viewAny'),
                'create' => $user->canDo('qualifications.create'),
                'edit' => $user->canDo('qualifications.edit'),
                'delete' => $user->canDo('qualifications.delete'),
            ],
            'geofences' => [
                'viewAny' => $user->canDo('geofences.viewAny'),
                'create' => $user->canDo('geofences.create'),
                'edit' => $user->canDo('geofences.edit'),
                'delete' => $user->canDo('geofences.delete'),
            ],
            'roster_templates' => [
                'viewAny' => $user->canDo('roster_templates.viewAny'),
                'create' => $user->canDo('roster_templates.create'),
                'update' => $user->canDo('roster_templates.update'),
            ],
        ];
    }

    protected function jobBoardOpenCount($user): int
    {
        if (! Schema::hasTable('shift_open_positions')) {
            return 0;
        }

        try {
            return ShiftOpenPosition::query()
                ->when($user->organization_id, fn ($query) => $query->where('organization_id', $user->organization_id))
                ->where('status', 'open')
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
