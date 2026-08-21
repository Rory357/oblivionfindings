<?php

namespace App\Http\Middleware;

use App\Domain\Clinical\Services\ClinicalSiteAccessService;
use App\Domain\Finance\Services\FinanceHubCountsService;
use App\Domain\It\ItModuleNavigation;
use App\Models\Announcement;
use App\Models\AppSetting;
use App\Models\ClientMedication;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\Site;
use App\Models\User;
use App\Services\Assurance\NzsAssuranceResolver;
use App\Services\MarScheduleService;
use App\Services\Operations\OpsMessageVisibilityService;
use App\Services\Tasks\TaskAggregator;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
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

            // Overdue-meds badge for the sidebar "Meds today" item. Only
            // computed for users who actually see that item (frontline
            // workers — managers get the eMAR sub-panel instead), and cached
            // briefly because the dose-slot maths isn't free.
            $showsWorkerMedsItem = ! (
                ($can['medications']['ordersManage'] ?? false)
                || ($can['medications']['auditView'] ?? false)
                || ($can['medications']['reportsExport'] ?? false)
                || ($can['reports']['viewAny'] ?? false)
            ) && (
                ($can['medications']['administerRecord'] ?? false)
                || ($can['medications']['view'] ?? false)
                || ($can['clients']['update'] ?? false)
            );

            if ($showsWorkerMedsItem && isset($can['medications'])) {
                $can['medications']['overdueTodayCount'] = $this->medsOverdueTodayCount($user);
            }

            // Company-wide /tasks entry: visible when the user can see at
            // least one module feed; badge = my open + overdue items. One
            // cache entry per user — the aggregator fans out across ~17
            // modules, and users with no feeds never pay for the badge.
            $can['tasks'] = $this->taskNavigation($user);
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

            // Per-user calendar subscribe (.ics) URL, shared so any calendar surface
            // — including the Site profile Calendar tab embed — can offer Subscribe
            // without each controller re-deriving it.
            'calendarFeedUrl' => $user && $user->calendar_feed_token
                ? url('/calendar/feed/'.$user->calendar_feed_token.'.ics')
                : null,

            // Count badges for the finance hub tab strips (the number beside each
            // tab). Lazy Closure gated to finance routes → non-finance pages and
            // Inertia partial-reloads never invoke it; every *TabsFooter reads its
            // own hub's slice from `financeHubCounts[hub]`.
            'financeHubCounts' => $user && str_starts_with((string) $request->route()?->getName(), 'finance.')
                ? fn () => app(FinanceHubCountsService::class)->forApplication()
                : null,

            'itNavigation' => $user && str_starts_with((string) $request->route()?->getName(), 'it.')
                ? fn () => ItModuleNavigation::forUser($user)
                : null,

            // Resolver-backed Site assurance for the existing H&S, clinical and
            // compliance heroes. Certification and first-aider cover remain separate
            // signals; inaccessible explicit Site ids always collapse to unknown.
            'nzsAssurance' => $user && $this->isNzsAssuranceSurface($request)
                ? fn () => $this->nzsAssuranceForRequest($request, $user)
                : null,

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,

                    'profile_photo_url' => $user->profile_photo_url,

                    'avatar' => $user->avatar,

                    // Keep during migration (existing UI uses it)
                    'role' => $user->role ?? null,

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
                    ? app(OpsMessageVisibilityService::class)->unreadCount($user)
                    : 0,
            ],

            'labels' => $labels,
            'locale' => $locale,
            'availableLocales' => $availableLocales,
            'translations' => [
                'app' => trans('app'),
                'rostering' => trans('rostering'),
            ],
            'webpush_public_key' => config('services.webpush.public_key'),

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
                // Raise-a-ticket success pane reads the new ticket's
                // reference from here once props refresh.
                'it_ticket' => session('it_ticket'),
                // /my-day's "Today's timesheet" find-or-create flow uses this
                // to tell the front-end which draft to open in the review
                // popup once props refresh.
                'open_timesheet_id' => session('open_timesheet_id'),
                // The Add Client wizard reads this after a successful create so
                // its success pane can link straight to the new profile.
                'created_client_id' => session('created_client_id'),
                // The Add Site modal reads this after a successful create so its
                // success pane can link straight to the new site profile.
                'created_site_id' => session('created_site_id'),
                // The Safeguarding raise wizard reads this so its success pane can
                // open the newly-raised concern.
                'created_concern_id' => session('created_concern_id'),
                // The Fleet incident report wizard reads these so its success pane
                // can open the newly-reported incident and show its ticket number.
                'created_fleet_incident_id' => session('created_fleet_incident_id'),
                'created_fleet_incident_reference' => session('created_fleet_incident_reference'),
                // The First Aid report wizard reads this so its success pane can
                // open the newly-recorded treatment on the register.
                'created_first_aid_id' => session('created_first_aid_id'),
                // The Worker Participation "schedule meeting" wizard reads this
                // after creating a NEW committee so it can chain the meeting POST
                // to /committees/{id}/meetings on the freshly created committee.
                'created_committee_id' => session('created_committee_id'),
                // The Safe Work Procedure wizard reads this after a successful
                // create so its success pane can open the new procedure.
                'created_procedure_id' => session('created_procedure_id'),
                // The Risk Assessment wizard reads this after a successful create/
                // supersede so it can upload staged evidence to the new draft and
                // its success pane can open it.
                'created_risk_assessment_id' => session('created_risk_assessment_id'),
                // The Injury record wizard reads this so its success pane can open
                // the newly-recorded injury straight on its RTW section.
                'created_injury_id' => session('created_injury_id'),
                // A disciplinary dismissal outcome flashes this next-step payload
                // ({label, url, employee_name}) so the case page can offer an
                // explicit "Start offboarding" CTA (never auto-created).
                'offboarding_cta' => session('offboarding_cta'),
                // The incident report wizard reads this so its success pane can
                // open the newly-created incident over the register.
                'created_incident_id' => session('created_incident_id'),
                // Canonical incident reporting returns official references and
                // the truthful H&S handover state; it never derives a reference
                // from a raw database id.
                'incident_report_result' => session('incident_report_result'),
                // The New-alert wizard reads this so its success pane can open
                // the freshly-raised alert's workspace.
                'created_alert_id' => session('created_alert_id'),
                // Sensor triage confirm flashes the incident it created.
                'confirmed_incident_id' => session('confirmed_incident_id'),
                // The quick-flag dialog reads these so its success pane can show
                // the CR-/INC- chips and open either record.
                'flagged_incident_id' => session('flagged_incident_id'),
                'flagged_alert_id' => session('flagged_alert_id'),
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

    private function isNzsAssuranceSurface(Request $request): bool
    {
        $name = (string) $request->route()?->getName();
        $path = trim($request->path(), '/');

        return $path === 'health-safety'
            || str_starts_with($path, 'health-safety/')
            || $path === 'health-clinical'
            || str_starts_with($path, 'health-clinical/')
            || $path === 'compliance'
            || in_array($name, [
                'compliance.hazards',
                'sites.hazards.index',
                'sites.compliance.dashboard',
            ], true);
    }

    /** @return array{certification_status:string,first_aid_coverage_status:string} */
    private function nzsAssuranceForRequest(Request $request, User $user): array
    {
        $path = trim($request->path(), '/');
        $name = (string) $request->route()?->getName();
        if ($path === 'health-clinical' || str_starts_with($path, 'health-clinical/')) {
            $siteIds = app(ClinicalSiteAccessService::class)->allowedSiteIds($user);
        } elseif ($name === 'sites.compliance.dashboard') {
            $siteIds = app(UserSiteAccessService::class)->accessibleSiteIds($user, ['sites.viewAll']);
        } elseif ($path === 'compliance') {
            $siteIds = app(UserSiteAccessService::class)->accessibleSiteIds(
                $user,
                ['healthSafety.viewAllSites', 'reports.viewAny'],
            );
        } else {
            $siteIds = app(UserSiteAccessService::class)->accessibleHealthSafetySiteIds($user);
        }

        $routeSite = $request->route('site');
        $explicitSiteId = $routeSite instanceof Site
            ? (int) $routeSite->id
            : (is_numeric($routeSite) ? (int) $routeSite : null);
        if ($explicitSiteId === null && $request->filled('site_id')) {
            $explicitSiteId = $request->integer('site_id');
        }

        if ($explicitSiteId !== null) {
            $siteIds = in_array($explicitSiteId, $siteIds, true) ? [$explicitSiteId] : [];
        }

        return app(NzsAssuranceResolver::class)->resolveSites($siteIds);
    }

    /**
     * Keep task-provider faults out of the global Inertia failure path. This
     * projection is deliberately request-time: Site reassignment, revocation
     * and permission changes must immediately remove foreign derived counts.
     *
     * @return array{view: bool, badge: int, badgeDegraded: bool}
     */
    private function taskNavigation(User $user): array
    {
        $this->preparePermissionLookup($user);
        $projection = app(TaskAggregator::class)->navigationBadgeFor($user);

        return [
            'view' => $projection['view'],
            'badge' => $projection['view'] ? $projection['badge'] : 0,
            'badgeDegraded' => $projection['degraded'],
        ];
    }

    /**
     * Permission map bust — bump when permission shape/keys change so
     * stale caches from previous deploys are ignored.
     */
    protected const PERMISSIONS_CACHE_VERSION = 'v6';

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
            fn () => $this->buildUserPermissions($this->preparePermissionLookup($user)),
        ));
    }

    protected function preparePermissionLookup(User $user): User
    {
        return $user->loadMissing([
            'permissionOverrides:id,key',
            'roles.permissions:id,key',
        ]);
    }

    protected function buildUserPermissions($user): array
    {
        return [
            'sites' => [
                'viewAny' => $user->canDo('sites.viewAny'),
                'viewAll' => $user->canDo('sites.viewAll'),
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
                'monitoringManage' => $user->canDo('securityDevices.monitoring.manage'),
                'reportsView' => $user->canDo('securityDevices.reports.view'),
                'commandsAdmin' => $user->canDo('securityDevices.commands.admin'),
            ],

            'calendar' => [
                'viewAny' => $user->canDo('calendar.viewAny'),
                'view' => $user->canDo('calendar.view'),
                'create' => $user->canDo('calendar.create'),
                'manage' => $user->canDo('calendar.manage'),
                'approve' => $user->canDo('calendar.approve'),
                'manageRecurring' => $user->canDo('calendar.manage_recurring'),
            ],

            'clinical' => [
                'dashboard' => $user->canDo('clinical.dashboard'),
                'observationsViewAny' => $user->canDo('clinical.observations.viewAny'),
                'observationsViewAssigned' => $user->canDo('clinical.observations.viewAssigned'),
                'observationsRecord' => $user->canDo('clinical.observations.record'),
                'observationsRecordClinical' => $user->canDo('clinical.observations.recordClinical'),
                'eventsViewAny' => $user->canDo('clinical.events.viewAny'),
                'eventsViewAssigned' => $user->canDo('clinical.events.viewAssigned'),
                'eventsRecord' => $user->canDo('clinical.events.record'),
                'eventsReview' => $user->canDo('clinical.events.review'),
                'eventsEscalate' => $user->canDo('clinical.events.escalate'),
                'behaviourViewAny' => $user->canDo('clinical.behaviour.viewAny'),
                'monitoringViewAny' => $user->canDo('clinical.monitoring.viewAny'),
                'protocolsViewAny' => $user->canDo('clinical.protocols.viewAny'),
                'protocolsManage' => $user->canDo('clinical.protocols.manage'),
                'assessmentsViewAny' => $user->canDo('clinical.assessments.viewAny'),
                'assessmentsRecord' => $user->canDo('clinical.assessments.record'),
            ],

            'hazards' => [
                'view' => $user->canDo('hazards.view'),
                'create' => $user->canDo('hazards.create'),
                'assign' => $user->canDo('hazards.assign'),
                'close' => $user->canDo('hazards.close'),
                'manage' => $user->canDo('hazards.manage'),
                'manageTypes' => $user->canDo('hazards.manage_types'),
            ],

            'restraints' => [
                'view' => $user->canDo('restraints.view'),
                'create' => $user->canDo('restraints.create'),
                'manage' => $user->canDo('restraints.manage'),
                'review' => $user->canDo('restraints.review'),
            ],

            'procedures' => [
                'view' => $user->canDo('procedures.view'),
                'create' => $user->canDo('procedures.create'),
                'manage' => $user->canDo('procedures.manage'),
                'approve' => $user->canDo('procedures.approve'),
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
                'manageSecrets' => $user->canDo('integrations.manage_secrets'),
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
                'tasksView' => $user->canDo('respite.tasks.view'),
                'tasksManage' => $user->canDo('respite.tasks.manage'),
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
                'payslips' => [
                    'view' => $user->canDo('hr.payslips.view'),
                    'generate' => $user->canDo('hr.payslips.generate'),
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
                'recognition' => [
                    'view' => $user->canDo('hr.recognition.view'),
                    'give' => $user->canDo('hr.recognition.give'),
                ],
                'announcements' => [
                    'view' => $user->canDo('hr.announcements.view'),
                    'manage' => $user->canDo('hr.announcements.manage'),
                ],
                'exit-interviews' => [
                    'view' => $user->canDo('hr.exit-interviews.view'),
                    'manage' => $user->canDo('hr.exit-interviews.manage'),
                ],
                'approvals' => [
                    'view' => $user->canDo('hr.approvals.view'),
                    'manage' => $user->canDo('hr.approvals.manage'),
                ],
                'settings' => [
                    'manage' => $user->canDo('hr.settings.manage'),
                ],
            ],

            'it' => [
                'view' => $user->canDo('it.view'),
                'manage' => $user->canDo('it.manage'),
                'request' => $user->canDo('it.request'),
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
                'claimsRetryPosting' => $user->canDo('funding.claims.retryPosting'),
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
                'approve' => $user->canDo('client_funds.approve'),
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

    protected function jobBoardOpenCount(User $user): int
    {
        if (! Schema::hasTable('shift_open_positions')) {
            return 0;
        }

        try {
            return ShiftOpenPosition::query()
                ->tap(fn ($query) => app(UserSiteAccessService::class)
                    ->applyShiftOpenPositionScope($query, $user, ['reports.viewAny']))
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

    /**
     * Today's overdue (scheduled-before-now, unrecorded) doses for the
     * worker's shift clients — the sidebar "Meds today" badge. Same overdue
     * semantics as the /meds/today board hero. Cached for 60s per user/day;
     * WorkerMedsController busts the cache when a dose is recorded.
     */
    public static function medsOverdueBadgeCacheKey(int $userId, string $localDate): string
    {
        return 'meds:overdue-badge:'.$userId.':'.$localDate;
    }

    protected function medsOverdueTodayCount($user): int
    {
        if (! Schema::hasTable('client_medications')) {
            return 0;
        }

        try {
            $schedule = app(MarScheduleService::class);
            $now = Carbon::now($schedule->workerTimezone());

            return (int) Cache::remember(
                self::medsOverdueBadgeCacheKey((int) $user->id, $now->toDateString()),
                now()->addSeconds(60),
                function () use ($user, $schedule, $now): int {
                    $dayStartUtc = $now->copy()->startOfDay()->utc();
                    $dayEndUtc = $now->copy()->endOfDay()->utc();

                    $clientIds = Shift::query()
                        ->where('user_id', $user->id)
                        // Include a live overnight shift that began before
                        // midnight but still overlaps the worker's local day.
                        // Filtering only by starts_at made the critical badge
                        // disappear at midnight while the same due row
                        // remained visible on the medication board.
                        ->where('starts_at', '<=', $dayEndUtc)
                        ->where(function ($query) use ($dayStartUtc) {
                            $query->whereNull('ends_at')
                                ->orWhere('ends_at', '>=', $dayStartUtc);
                        })
                        ->pluck('client_id')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    if (empty($clientIds)) {
                        return 0;
                    }

                    $medications = ClientMedication::whereIn('client_id', $clientIds)
                        ->active()
                        ->where('is_prn', false)
                        ->where(function ($query) {
                            $query->whereNotNull('dose_times')
                                ->orWhereNotNull('frequency');
                        })
                        ->get();

                    if ($medications->isEmpty()) {
                        return 0;
                    }

                    $dayStart = $now->copy()->startOfDay();
                    $administrations = $schedule->administrationsForWindow($clientIds, $dayStart, $now);

                    $count = 0;
                    foreach ($medications as $medication) {
                        foreach ($schedule->scheduledTimesForDate($medication, $dayStart) as $scheduled) {
                            if ($scheduled->gte($now)) {
                                continue;
                            }

                            $administration = $administrations->get($schedule->slotKey(
                                (int) $medication->client_id,
                                (int) $medication->id,
                                $scheduled,
                            ));

                            if ($administration && in_array($administration->status, ['given', 'refused', 'withheld', 'missed'], true)) {
                                continue;
                            }

                            $count++;
                        }
                    }

                    return $count;
                },
            );
        } catch (\Throwable) {
            return 0;
        }
    }
}
