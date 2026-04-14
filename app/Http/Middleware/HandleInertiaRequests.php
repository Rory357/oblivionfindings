<?php

namespace App\Http\Middleware;

use App\Models\Announcement;
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
        // Optimized: Cache permissions during request to reduce DB queries
        $can = null;

        if ($user) {
            $can = $this->getUserPermissions($user);
        }

        // UI terminology (defaults merged with saved overrides)
        $labelDefaults = config('labels');
        $labelOverrides = AppSetting::query()
            ->where('key', 'like', 'labels.%')
            ->get(['key', 'value'])
            ->mapWithKeys(fn ($row) => [str_replace('labels.', '', $row->key) => $row->value])
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
                    ? \App\Models\User::find(app('impersonate')->getImpersonatorId())?->only('id', 'name')
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
                'unreadMessageCount' => $user ? \App\Models\OpsMessage::whereIn('conversation_id',
                    \App\Models\OpsConversationParticipant::where('user_id', $user->id)->pluck('conversation_id')
                )->where('sender_id', '!=', $user->id)->where('is_read', false)->count() : 0,
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
            ],

            // Header inbox (notifications + announcements)
            'inbox' => $user ? [
                'notifications' => [
                    'unread_count' => $user->unreadNotifications()->count(),
                    'items' => $user->notifications()
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
                        ->values(),
                ],
                'announcements' => Announcement::inboxFor($user),
            ] : null,
        ];
    }

    /**
     * Get user permissions with caching to reduce DB queries.
     * Permissions are cached for the duration of the request.
     */
    protected function getUserPermissions($user): array
    {
        return once(function () use ($user) {
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
                    'create' => $user->canDo('clients.create'),
                    'update' => $user->canDo('clients.update'),
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
                ],

                'fleet' => [
                    'viewAny' => $user->canDo('fleet.viewAny'),
                    'driverSessionsManage' => $user->canDo('fleet.driverSessions.manage'),
                    'signalsView' => $user->canDo('fleet.signals.view'),
                ],
                'controlRoom' => [
                    'viewAny' => $user->canDo('controlRoom.viewAny'),
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
                    'observationsRecord' => $user->canDo('clinical.observations.record'),
                    'observationsRecordClinical' => $user->canDo('clinical.observations.recordClinical'),
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
                    'sitesManage' => $user->canDo('settings.sites.manage'),
                    'templatesManage' => $user->canDo('settings.templates.manage'),
                    'rbacManage' => $user->canDo('settings.rbac.manage'),
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
                    'export' => $user->canDo('consents.export'),
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
                        'view' => $user->canDo('hr.time.viewAny'),
                        'viewAny' => $user->canDo('hr.time.viewAny'),
                        'manage' => $user->canDo('hr.time.manage'),
                        'approve' => $user->canDo('hr.time.manage') || $user->canDo('hr.time.approveTeam'),
                        'approveTeam' => $user->canDo('hr.time.approveTeam'),
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
                        'manage' => $user->canDo('governance.actions.manage'),
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
            ];
        });
    }
}
