<?php

namespace App\Http\Controllers;

/**
 * NOTE: This controller is 1387 lines and should be refactored into:
 * - ClientOnboardingController (store, create — onboarding/intake methods)
 * - ClientAssignmentController (assignment-related methods)
 * - ClientPortalController (portal-related methods)
 * - ClientMediaController (updatePhoto, destroyPhoto, storeGalleryPhoto, destroyGalleryPhoto)
 * - ClientLocationController (locationHistory)
 * See: Phase 14 refactoring plan
 */

use App\Domain\Clinical\Services\ClinicalHealthSummaryService;
use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Presenters\ClientProfileHealthcareDevicesPresenter;
use App\Domain\SecurityDevices\Services\PersonalTrackingLocationExportService;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Enums\NextOfKinRelationship;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientDailyNoteResource;
use App\Models\AssetGeofence;
use App\Models\AuditLog;
use App\Models\CarePlan;
use App\Models\Client;
use App\Models\ClientAppointment;
use App\Models\ClientBowelEntry;
use App\Models\ClientConsent;
use App\Models\ClientDocument;
use App\Models\ClientExcursionRequest;
use App\Models\ClientFinancialDiscrepancy;
use App\Models\ClientFluidEntry;
use App\Models\ClientFund;
use App\Models\ClientFundTransaction;
use App\Models\ClientIncident;
use App\Models\ClientLeaveRequest;
use App\Models\ClientMealLog;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientNote;
use App\Models\ClientOnboardingWorkflow;
use App\Models\ClientPathPlan;
use App\Models\ClientPersonalAsset;
use App\Models\ClientPhoto;
use App\Models\ClientPurchaseRequest;
use App\Models\ClientRisk;
use App\Models\ClientRoutine;
use App\Models\ClientSeizureEntry;
use App\Models\ClientSleepEntry;
use App\Models\ClientTransportBooking;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\ControlRoomAlert;
use App\Models\FamilyNote;
use App\Models\FamilyVisitRequest;
use App\Models\FirstAidRecord;
use App\Models\FleetIncident;
use App\Models\FleetMedicationTransitLog;
use App\Models\FleetOuting;
use App\Models\FleetOutingResident;
use App\Models\FleetResidentTransport;
use App\Models\FleetTelemetryEvent;
use App\Models\HsRiskAssessment;
use App\Models\LocationHardware;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationReview;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\Role;
use App\Models\SafeWorkProcedure;
use App\Models\ServiceAgreement;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftSeries;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\SiteHouseRoom;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Client\ActionsAggregator;
use App\Services\Client\BehaviourPatternsService;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Clients\ClientFormOptions;
use App\Services\Clients\ClientOnboardingAccess;
use App\Services\Clients\ClientPhotoMediaUrls;
use App\Services\Clients\ClientPhotoStorage;
use App\Services\Clients\ClientPortalMembershipService;
use App\Services\Clients\ClientProfileSectionAccess;
use App\Services\Clients\ClientStaffPreparationProjection;
use App\Services\Clients\ClientWorkerEligibility;
use App\Services\ConsentValidationService;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\HealthSafety\HsModuleSummaryService;
use App\Services\Integration\IntegrationEventHistoryService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationTimelineVisibilityService;
use App\Services\NotificationService;
use App\Services\Queclink\LocateNowService;
use App\Services\Respite\ClientRespiteAllocationSummary;
use App\Services\ShiftCoverageService;
use App\Services\Tracking\GeofenceStatusService;
use App\Services\UserSiteAccessService;
use App\Support\ClientSafetyPayload;
use App\Support\HazardDetailPresenter;
use App\Support\HealthSafety\RiskAssessmentPresenter;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use App\Support\SchemaCache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Client::class);

        $user = $request->user();
        $clientsQuery = Client::query()->withTrashed();
        app(UserSiteAccessService::class)->applyClientScope(
            $clientsQuery,
            $user,
            ['clients.viewAny'],
        );

        $clients = $clientsQuery
            // Include soft-deleted (archived) clients so the redesigned index can
            // surface them under the "Archived" saved view / "Show archived" toggle.
            // They are excluded from the live stats and hidden by default client-side.
            ->when(
                ! $user->canDo('clients.viewAny'),
                fn ($query) => $query->whereHas(
                    'supportWorkers',
                    fn ($workers) => $workers->whereKey($user->id),
                ),
            )
            ->with([
                'site:id,name,is_active',
                'keyWorker:id,name',
                'supportWorkers:id',
                'onboardingOverrides:id,client_id,key,value',
                'medicalProfile:id,client_id,allergies,disabilities',
                'risks:id,client_id,label,severity,active',
            ])
            ->withCount([
                'portalUsers',
                'medications',
                'conditions',
                'emergencyContacts',
                'assessments',
                'documents',
                'supportPlan',
                'respiteBookings',
                'respiteBookingRequests',
                // Daily-style notes recorded in the last 7 days — shown on each card's footer.
                'notes as notes_week_count' => fn ($q) => $q
                    ->forUser($user)
                    ->dailyNotes()
                    ->where('occurred_at', '>=', now()->subDays(7)),
            ])
            ->orderBy('last_name')
            ->get([
                'id',
                'site_id',
                'key_worker_id',
                'nhi_number',
                'first_name',
                'last_name',
                'status',
                'date_of_birth',
                'phone',
                'email',
                'address_line_1',
                'address_line_2',
                'suburb',
                'city',
                'postcode',
                'profile_photo_path',
                'risk_level',
                'safeguarding_flag',
                'deleted_at',
            ]);

        $clients = $clients->map(function (Client $c) use ($user) {
            $sectionAccess = app(ClientProfileSectionAccess::class)->for($user, $c);
            $summary = $this->buildOnboardingSummaryFromCounts($c);
            $hasRespite = ((int) ($c->respite_bookings_count ?? 0) + (int) ($c->respite_booking_requests_count ?? 0)) > 0;

            $address = collect([$c->address_line_1, $c->suburb, $c->city, $c->postcode])
                ->filter(fn ($v) => is_string($v) && trim($v) !== '')
                ->implode(', ');

            $mine = (int) $c->key_worker_id === (int) $user->id
                || $c->supportWorkers->contains('id', $user->id);

            return [
                'id' => $c->id,
                'nhi_number' => $c->nhi_number,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'profile_photo_url' => $c->profile_photo_url,
                'avatar' => $c->avatar,
                'status' => $c->status,
                'age' => $c->date_of_birth ? $c->date_of_birth->age : null,
                'address' => $address !== '' ? $address : null,
                'site' => $c->site ? ['id' => $c->site->id, 'name' => $c->site->name] : null,
                'key_worker' => $c->keyWorker ? [
                    'id' => $c->keyWorker->id,
                    'name' => $c->keyWorker->name,
                    'initials' => $this->nameInitials($c->keyWorker->name),
                ] : null,
                ...($sectionAccess['notes'] ? [
                    'notes_week' => (int) ($c->notes_week_count ?? 0),
                ] : []),
                ...($sectionAccess['onboarding'] ? [
                    'onboarding' => $summary,
                ] : []),
                ...($sectionAccess['respite'] ? [
                    'has_respite' => $hasRespite,
                ] : []),
                'archived' => $c->trashed(),
                'mine' => $mine,
                'safety' => ClientSafetyPayload::summaryForClient(
                    $c,
                    includeMedical: $sectionAccess['medical'],
                    includeRisks: $sectionAccess['risks'],
                ),
            ];
        })->values();

        return inertia('operations/clients/index', [
            'clients' => $clients,
            // Option lists for the in-context "Add client" wizard so it can
            // render without an extra round-trip.
            ...($user->canDo('clients.create')
                ? app(ClientFormOptions::class)->forViewer($user)
                : []),
        ]);
    }

    /** Two-letter initials from a display name (e.g. "Mere Tipene" → "MT"). */
    private function nameInitials(?string $name): ?string
    {
        if (! $name) {
            return null;
        }

        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));

        if (count($parts) === 0) {
            return null;
        }

        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * Archive (soft-delete) a client from the index context menu / bulk bar.
     * Archived clients drop out of the live list but remain visible under the
     * "Show archived" toggle and the "Archived" saved view, and can be restored.
     */
    public function archive(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        if (! $client->trashed()) {
            $client->delete();
            AuditLogger::log('clients.archive', $client, ['client_id' => $client->id]);
        }

        return back()->with('success', 'Client archived.');
    }

    /** Restore a previously archived (soft-deleted) client. */
    public function restore(Request $request, int $client)
    {
        $model = Client::withTrashed()->findOrFail($client);

        $this->authorize('update', $model);

        if ($model->trashed()) {
            $model->restore();
            AuditLogger::log('clients.restore', $model, ['client_id' => $model->id]);
        }

        return back()->with('success', 'Client restored.');
    }

    private function buildOnboardingSummaryFromCounts(Client $client): array
    {
        $overrides = $client->onboardingOverrides
            ->keyBy('key')
            ->map(fn ($o) => (bool) $o->value)
            ->toArray();

        $hasProfile = (bool) ($client->first_name && $client->last_name)
            && (bool) $client->date_of_birth
            && (bool) ($client->phone || $client->email)
            && (bool) ($client->address_line_1 || $client->city || $client->postcode);

        $items = [
            ['key' => 'profile', 'has_data' => $hasProfile, 'override' => (bool) ($overrides['profile'] ?? false)],
            ['key' => 'next_of_kin', 'has_data' => (int) ($client->portal_users_count ?? 0) > 0, 'override' => (bool) ($overrides['next_of_kin'] ?? false)],
            ['key' => 'medications', 'has_data' => (int) ($client->medications_count ?? 0) > 0, 'override' => (bool) ($overrides['medications'] ?? false)],
            ['key' => 'conditions', 'has_data' => (int) ($client->conditions_count ?? 0) > 0, 'override' => (bool) ($overrides['conditions'] ?? false)],
            ['key' => 'emergency_contacts', 'has_data' => (int) ($client->emergency_contacts_count ?? 0) > 0, 'override' => (bool) ($overrides['emergency_contacts'] ?? false)],
            ['key' => 'history', 'has_data' => ((int) ($client->assessments_count ?? 0) > 0) || ((int) ($client->support_plan_count ?? 0) > 0), 'override' => (bool) ($overrides['history'] ?? false)],
            ['key' => 'documents', 'has_data' => (int) ($client->documents_count ?? 0) > 0, 'override' => (bool) ($overrides['documents'] ?? false)],
        ];

        $total = count($items);
        $completed = 0;
        foreach ($items as $i) {
            if (($i['has_data'] ?? false) || ($i['override'] ?? false)) {
                $completed++;
            }
        }

        $percent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        return [
            'completed' => $completed,
            'total' => $total,
            'percent' => $percent,
            'status' => $completed === $total ? 'complete' : 'incomplete',
        ];
    }

    public function show(
        Request $request,
        Client $client,
        ClientProfileHealthcareDevicesPresenter $healthcareDevicesPresenter,
    ) {
        $this->authorize('view', $client);

        // `/clients/{client}` is retained only as a compatibility entry point.
        // Portal identities must never receive the staff profile payload, while
        // staff bookmarks should converge on the canonical operations route.
        if ($request->routeIs('clients.show')) {
            $user = $request->user();
            $routeName = $user?->hasRole('client', 'next_of_kin')
                ? 'portal.clients.show'
                : 'operations.clients.show';
            $location = route($routeName, $client, false);
            if ($request->getQueryString()) {
                $location .= '?'.$request->getQueryString();
            }

            return redirect()->to($location);
        }

        // Canonicalize the retired profile tab before the Inertia page mounts.
        // A client-side replacement is intentionally retained as a fallback,
        // but using it for the initial visit can race a fast browser Back action
        // because Inertia throttles history writes. The server redirect keeps the
        // legacy bookmark compatible while preserving every unrelated query key
        // in one deterministic browser-history entry.
        if ($request->query('tab') === 'support_plan') {
            $query = $request->query();
            $query['tab'] = 'care_plans';
            ksort($query);
            $location = route('operations.clients.show', $client, false);

            if ($query !== []) {
                $location .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }

            return redirect()->to($location);
        }

        AuditLogger::log('clients.view', $client);

        $sectionAccess = app(ClientProfileSectionAccess::class)
            ->for($request->user(), $client);
        $canViewControlledMedication = $sectionAccess['medical']
            && ($request->user()?->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY) ?? false);
        $medicationGovernance = app(MedicationGovernanceScopeService::class);
        $medicationAdministrationCountScope = function ($query) use (
            $sectionAccess,
            $canViewControlledMedication,
            $client,
            $medicationGovernance,
        ): void {
            if (! $sectionAccess['medical']) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->effectiveClinicalEvidence();
            $medicationGovernance->scopeCanonicalClientMedicationRows(
                $query,
                is_numeric($client->site_id) ? [(int) $client->site_id] : [],
                allowNullMedication: false,
            );
            if (! $canViewControlledMedication) {
                $medicationGovernance->scopeWithoutControlledMedicationRows($query);
            }
        };
        $sectionAccess['healthcare_devices'] = $healthcareDevicesPresenter
            ->canView($request->user(), $client);
        $canViewClientLocation = (bool) $sectionAccess['tracking'];
        $canManageClientTrackers = $this->canManageClientTrackers($request->user());
        $canEditClientAssets = (bool) $request->user()?->can('update', $client);
        $canAssignWorkers = (bool) $request->user()?->canDo('clients.assignments.update')
            && $canEditClientAssets;
        $canViewCareContext = $sectionAccess['daily_living']
            || $sectionAccess['health']
            || $sectionAccess['medical'];
        $canManageFamilyNotes = app(
            ClientFamilyCommunicationAccess::class,
        )->canManage($request->user(), $client);
        $canViewFamilyChat = app(
            ClientFamilyCommunicationAccess::class,
        )->canView($request->user(), $client);
        $onboardingAccess = app(ClientOnboardingAccess::class)
            ->forClient($request->user(), $client);
        $profileRelations = [
            'site:id,name',
            'room:id,site_id,name,notes',
            'serviceContext:id,type,name',
            'keyWorker:id,name',
            $canAssignWorkers || $canViewCareContext
                ? 'supportWorkers:id,name,email'
                : 'supportWorkers:id,name',
        ];
        if ($sectionAccess['tracking']) {
            $profileRelations[] = 'houseGeofence:id,name';
        }
        if ($sectionAccess['onboarding']) {
            array_push(
                $profileRelations,
                'onboardingOverrides',
                'onboardingWorkflow.steps',
            );
        }
        if ($sectionAccess['care_plans']) {
            $profileRelations[] = 'supportPlan';
        }
        if ($sectionAccess['assessments']) {
            $profileRelations[] = 'assessments';
        }
        if ($sectionAccess['medical']) {
            array_push(
                $profileRelations,
                'medicalProfile',
                'medications.stock',
                'conditions',
                'emergencyContacts',
            );
        }
        if ($sectionAccess['portal_access']) {
            $profileRelations[] = 'portalUsers:id,name,email';
        }
        if ($sectionAccess['risks']) {
            $profileRelations[] = 'risks';
        }
        $client->load($profileRelations);
        if ($sectionAccess['medical'] && ! $canViewControlledMedication) {
            $client->setRelation(
                'medications',
                $client->medications
                    ->reject(fn (ClientMedication $medication): bool => (bool) $medication->controlled_drug)
                    ->values(),
            );
        }

        // For modal / async detail views, return JSON.
        if ($request->wantsJson() || $request->boolean('modal')) {
            return response()->json([
                'client' => [
                    'id' => $client->id,
                    'first_name' => $client->first_name,
                    'last_name' => $client->last_name,
                    'profile_photo_url' => $client->profile_photo_url,
                    'avatar' => $client->avatar,
                    'status' => $client->status,
                    'site' => $client->site
                        ? [
                            'id' => $client->site->id,
                            'name' => $client->site->name,
                        ]
                        : null,
                    'room' => $this->roomPayload($client->room),
                    'support_workers' => $canAssignWorkers || $canViewCareContext
                        ? $client->supportWorkers->map(fn ($u) => [
                            'id' => $u->id,
                            'name' => $u->name,
                            ...($canAssignWorkers ? ['email' => $u->email] : []),
                        ])->values()
                        : [],
                ],
            ]);
        }

        // Check for expired consents and pass to frontend
        $expiredConsents = $sectionAccess['consents']
            ? ConsentValidationService::getExpiredConsents($client)
            : collect();
        $missingMandatory = $sectionAccess['consents']
            ? ConsentValidationService::getMissingMandatoryConsents($client)
            : collect();

        $nextShift = $sectionAccess['shifts'] ? Shift::query()
            ->where('client_id', $client->id)
            ->where('starts_at', '>=', now())
            ->whereNotIn('status', ['cancelled'])
            ->with(['staff:id,name,email', 'serviceContext:id,name,type'])
            ->withCount([
                'tasks',
                'tasks as incomplete_tasks_count' => fn ($query) => $query->where('is_completed', false),
                'formSubmissions',
                'medicationAdministrations' => $medicationAdministrationCountScope,
                'timesheets',
                'outgoingHandovers',
                'incomingHandovers',
                'residentTransports',
            ])
            ->orderBy('starts_at')
            ->first() : null;

        $lastShift = $sectionAccess['shifts'] ? Shift::query()
            ->where('client_id', $client->id)
            ->where('starts_at', '<', now())
            ->whereNotIn('status', ['cancelled'])
            ->with(['staff:id,name,email', 'serviceContext:id,name,type'])
            ->withCount([
                'tasks',
                'tasks as incomplete_tasks_count' => fn ($query) => $query->where('is_completed', false),
                'formSubmissions',
                'medicationAdministrations' => $medicationAdministrationCountScope,
                'timesheets',
                'outgoingHandovers',
                'incomingHandovers',
                'residentTransports',
            ])
            ->orderByDesc('starts_at')
            ->first() : null;

        $recurringShiftSeries = $sectionAccess['shifts'] ? ShiftSeries::query()
            ->where('client_id', $client->id)
            ->where('status', '!=', 'cancelled')
            ->with([
                'staff:id,name,email',
                'serviceContext:id,name,type',
            ])
            ->withCount([
                'shifts as remaining_occurrences_count' => fn ($query) => $query
                    ->where('ends_at', '>=', now()->startOfDay())
                    ->whereNotIn('status', ['completed', 'cancelled']),
                'shifts as open_occurrences_count' => fn ($query) => $query
                    ->where('ends_at', '>=', now()->startOfDay())
                    ->whereNull('user_id')
                    ->whereNotIn('status', ['completed', 'cancelled']),
                'shifts as active_replacements_count' => fn ($query) => $query
                    ->where('ends_at', '>=', now()->startOfDay())
                    ->whereHas('replacementRequests', fn ($replacementQuery) => $replacementQuery->active()),
            ])
            ->withMin([
                'shifts as next_starts_at' => fn ($query) => $query
                    ->where('ends_at', '>=', now()->startOfDay())
                    ->whereNotIn('status', ['completed', 'cancelled']),
            ], 'starts_at')
            ->orderByDesc('updated_at')
            ->limit(3)
            ->get() : collect();

        $documents = $sectionAccess['documents']
            ? ClientDocument::query()
                ->where('client_id', $client->id)
                ->orderByDesc('created_at')
                ->get(['id', 'title', 'category', 'folder', 'version', 'effective_date', 'expiry_date', 'portal_visible', 'notes', 'original_name', 'mime_type', 'size_bytes', 'created_at'])
            : collect();

        $eventsBase = $sectionAccess['timeline']
            ? TimelineEvent::query()->where('client_id', $client->id)
            : null;
        if ($eventsBase) {
            app(MedicationTimelineVisibilityService::class)
                ->applyVisibleScope($eventsBase, $request->user());
        }
        $eventsTotal = $eventsBase ? (clone $eventsBase)->count() : 0;
        $events = $eventsBase
            ? (clone $eventsBase)
                ->orderByDesc('occurred_at')
                ->limit(80)
                ->with([
                    'actor:id,name',
                    'site:id,name',
                    'comments' => fn ($q) => $q->whereNull('parent_id')->with(['user:id,name,role', 'replies' => fn ($r) => $r->with('user:id,name,role')->orderBy('created_at'), 'replies.likes', 'likes'])->orderBy('created_at'),
                    'reactions',
                ])
                ->get()
            : collect();

        $handoverBase = $sectionAccess['timeline']
            ? TimelineEvent::query()
                ->where('client_id', $client->id)
                ->where('type', 'handover')
                ->where('is_pinned', true)
            : null;
        if ($handoverBase) {
            app(MedicationTimelineVisibilityService::class)
                ->applyVisibleScope($handoverBase, $request->user());
        }
        $handoverTotal = $handoverBase ? (clone $handoverBase)->count() : 0;
        $handover = $handoverBase
            ? (clone $handoverBase)
                ->orderByDesc('occurred_at')
                ->limit(5)
                ->with(['actor:id,name'])
                ->get()
            : collect();

        $siteCoverageSummary = null;
        if ($client->site_id && $sectionAccess['shifts']) {
            $siteCoverageSummary = collect(app(ShiftCoverageService::class)->buildSiteSummaries(
                now()->copy()->startOfDay(),
                now()->copy()->addDays(14)->endOfDay(),
                $client->site_id,
            ))->first();
        }

        $dailyNotes = $sectionAccess['notes'] ? ClientNote::query()
            ->where('client_id', $client->id)
            ->forUser($request->user())
            ->dailyNotes()
            ->with(['author:id,name', 'reviewer:id,name', 'shift:id,starts_at,ends_at'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get() : collect();

        $communicationNotes = $sectionAccess['notes'] ? ClientNote::query()
            ->where('client_id', $client->id)
            ->forUser($request->user())
            ->communication()
            ->with(['author:id,name', 'reviewer:id,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get() : collect();

        $dailyNotesBase = $sectionAccess['notes']
            ? ClientNote::query()
                ->where('client_id', $client->id)
                ->forUser($request->user())
            : null;

        $actionsReviewCoverage = $sectionAccess['actions_reviews']
            ? app(ActionsAggregator::class)->forClientWithCoverage($client, $request->user())
            : ['items' => [], 'has_more' => false];
        $actionsReviews = $actionsReviewCoverage['items'];

        // Site / environmental hazards at the client's current home (read-only
        // context — managed from the Hazards register). Open + mitigated only.
        $siteId = $client->site_id;
        $homeHazards = ($siteId && $sectionAccess['first_aid'])
            ? SiteHazard::query()
                ->where('site_id', $siteId)
                ->whereIn('status', ['open', 'in_progress', 'mitigated'])
                ->with('assignedTo:id,name')
                ->orderByDesc('created_at')
                ->limit(25)
                ->get()
                ->map(fn (SiteHazard $h) => [
                    'id' => $h->id,
                    'reference_number' => $h->reference_number,
                    'hazard_label' => HazardDetailPresenter::hazardLabel($h),
                    'description' => $h->description,
                    'risk_rating' => $h->risk_rating,
                    'severity' => $h->severity,
                    'status' => $h->status,
                    'due_date' => $h->due_date?->toDateString(),
                    'overdue' => $h->isOverdue(),
                    'site_id' => $h->site_id,
                ])->values()
            : collect();

        $homeHazardDetail = null;
        if ($request->filled('hazard') && $siteId && $sectionAccess['first_aid']) {
            $hz = SiteHazard::query()
                ->where('site_id', $siteId)
                ->with(['site:id,name,type', 'reportedBy:id,name', 'assignedTo:id,name', 'statusChangedBy:id,name', 'closedBy:id,name', 'actions.assignedTo:id,name', 'actions.completedBy:id,name'])
                ->find($request->query('hazard'));
            if ($hz) {
                $homeHazardDetail = HazardDetailPresenter::make($hz, ['manage' => false, 'assign' => false, 'close' => false]);
            }
        }

        // Safe Work Procedures governing care at this client's home (site-scoped +
        // org-wide, approved), read-only — deep-links to the procedures register.
        $homeProcedures = ($siteId && $request->user()?->canDo('procedures.view'))
            ? SafeWorkProcedure::query()->applicableToSite($siteId)
                ->orderBy('title')
                ->limit(15)
                ->get(['id', 'reference_number', 'title', 'category', 'status', 'review_date'])
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'reference_number' => $p->reference_number,
                    'title' => $p->title,
                    'category' => $p->category,
                    'status' => $p->status,
                    'review_date' => $p->review_date?->toDateString(),
                ])->values()
            : collect();

        $workingCarePlan = $sectionAccess['care_plans']
            ? $this->carePlanWorkingVersion($client)
            : null;
        $carePlanTotal = $sectionAccess['care_plans']
            ? CarePlan::where('client_id', $client->id)->count()
            : 0;
        $carePlanRecentNotesTotal = $sectionAccess['care_plans']
            ? ClientNote::where('client_id', $client->id)
                ->where('type', 'progress_note')
                ->count()
            : 0;
        $staffPreparation = $sectionAccess['onboarding']
            && ($request->user()?->canDo('hr.onboarding.view') ?? false)
            ? app(ClientStaffPreparationProjection::class)
                ->forClient($client)
            : null;
        $canCreateClientNote = $request->user()?->can('create', ClientNote::class) ?? false;
        $canRecordMedicationAdministration = $sectionAccess['medical']
            && ($request->user()?->canDo('medications.administer.record') ?? false);
        $canRecordControlledMedication = $canRecordMedicationAdministration
            && ($request->user()?->canDo('medications.controlled.record') ?? false);
        $pendingMedicationAlerts = $medicationGovernance->scopeCanonicalClientMedicationRows(
            MedicationDashboardAlert::query(),
            $siteId ? [(int) $siteId] : [],
        )
            ->where('client_id', $client->id)
            ->where('status', 'active');
        if (! ($request->user()?->canDo('medications.controlled.view') ?? false)) {
            $pendingMedicationAlerts->whereNotIn('alert_type', [
                'controlled_discrepancy',
                'controlled_overdue_check',
                'controlled_loss',
            ]);
            $medicationGovernance->scopeWithoutControlledMedicationRows($pendingMedicationAlerts);
        }
        $pendingMedicationAlertsCount = $pendingMedicationAlerts->count();
        $activeMedicationsCount = 0;
        $lastMedicationAdministration = null;
        if ($sectionAccess['medical']) {
            $activeMedications = ClientMedication::query()
                ->where('client_id', $client->id)
                ->active()
                ->whereNull('ceased_at')
                ->when(! $canViewControlledMedication, fn ($query) => $query
                    ->where('controlled_drug', false));
            $activeMedicationsCount = $activeMedications->count();

            $administrations = ClientMedicationAdministration::query()
                ->effectiveClinicalEvidence()
                ->where('client_id', $client->id)
                ->whereNotNull('administered_at');
            $medicationGovernance->scopeCanonicalClientMedicationRows(
                $administrations,
                $siteId ? [(int) $siteId] : [],
                allowNullMedication: false,
            );
            if (! $canViewControlledMedication) {
                $medicationGovernance->scopeWithoutControlledMedicationRows($administrations);
            }
            $lastMedicationAdministration = $administrations->max('administered_at');
        }
        $dailyNotesTotal = $dailyNotesBase
            ? (clone $dailyNotesBase)->dailyNotes()->count()
            : 0;
        $communicationNotesTotal = $dailyNotesBase
            ? (clone $dailyNotesBase)->communication()->count()
            : 0;

        $profileProps = [
            'profile_section_access' => $sectionAccess,
            'homeHazards' => $homeHazards,
            'homeHazardDetail' => $homeHazardDetail,
            'homeProcedures' => $homeProcedures,
            'homeName' => $client->site?->name,
            'homeSiteId' => $siteId,
            'client' => [
                'id' => $client->id,
                'nhi_number' => $client->nhi_number,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'profile_photo_url' => $client->profile_photo_url,
                'avatar' => $client->avatar,
                'preferred_name' => $client->preferred_name,
                'date_of_birth' => optional($client->date_of_birth)->toDateString(),
                'gender' => $client->gender,
                'status' => $client->status,
                'phone' => $client->phone,
                'email' => $client->email,
                'address_line_1' => $client->address_line_1,
                'address_line_2' => $client->address_line_2,
                'suburb' => $client->suburb,
                'city' => $client->city,
                'postcode' => $client->postcode,
                ...($sectionAccess['finance'] ? [
                    'funding_type' => $client->funding_type,
                    'funding_notes' => $client->funding_notes,
                ] : []),
                'site' => $client->site ? ['id' => $client->site->id, 'name' => $client->site->name] : null,
                'room' => $this->roomPayload($client->room),
                'service_context' => $client->serviceContext ? [
                    'id' => $client->serviceContext->id,
                    'type' => $client->serviceContext->type?->value,
                    'name' => $client->serviceContext->name,
                ] : null,
                'support_workers' => $canAssignWorkers || $canViewCareContext
                    ? $client->supportWorkers->map(fn ($u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                        ...($canAssignWorkers ? ['email' => $u->email] : []),
                    ])->values()
                    : [],
                // Identity & Culture
                'ethnicity' => $client->ethnicity,
                'preferred_pronouns' => $client->preferred_pronouns,
                'religion' => $client->religion,
                'languages' => $client->languages,
                'education_level' => $client->education_level,
                'employment_status' => $client->employment_status,
                // Interests & Strengths
                'interests_hobbies' => $client->interests_hobbies,
                'strengths_abilities' => $client->strengths_abilities,
                'life_story' => $client->life_story,
                ...($sectionAccess['transport'] || $sectionAccess['daily_living'] ? [
                    'transport_needs' => $client->transport_needs,
                    'transport_notes' => $client->transport_notes,
                ] : []),
                ...($canViewCareContext ? [
                    // Support needs captured at intake.
                    'mobility_needs' => $client->mobility_needs,
                    'sensory_needs' => $client->sensory_needs,
                    'cognitive_needs' => $client->cognitive_needs,
                    'sleep_preferences' => $client->sleep_preferences,
                    'sleep_target_hours' => $client->sleep_target_hours ? (float) $client->sleep_target_hours : null,
                    'fluid_intake_min_ml' => $client->fluid_intake_min_ml,
                    'fluid_intake_max_ml' => $client->fluid_intake_max_ml,
                    'seizure_duration_escalation_seconds' => $client->seizure_duration_escalation_seconds,
                    'service_start_date' => optional($client->service_start_date)->toDateString(),
                ] : []),
                ...($canViewCareContext || $sectionAccess['meals'] ? [
                    'dietary_requirements' => $client->dietary_requirements,
                ] : []),
                ...($sectionAccess['risks'] ? [
                    'risk_level' => $client->risk_level,
                    'safeguarding_flag' => (bool) $client->safeguarding_flag,
                ] : []),
                ...($canAssignWorkers || $canViewCareContext ? [
                    'key_worker' => $client->keyWorker ? [
                        'id' => $client->keyWorker->id,
                        'name' => $client->keyWorker->name,
                    ] : null,
                ] : []),
                ...($sectionAccess['tracking'] ? [
                    'house_geofence' => $client->houseGeofence ? [
                        'id' => $client->houseGeofence->id,
                        'name' => $client->houseGeofence->name,
                    ] : null,
                ] : []),
            ],
            'medical' => $sectionAccess['medical'] ? [
                'profile' => $client->medicalProfile,
                'medications' => $client->medications->map(fn (ClientMedication $medication) => $this->medicationPayload($medication))->values(),
                'conditions' => $client->conditions,
                'emergency_contacts' => $client->emergencyContacts,
            ] : null,
            'next_of_kins' => $sectionAccess['portal_access'] ? $client->nextOfKins()
                ->with('user:id,name,email')
                ->get()
                ->map(function ($k) {
                    $relEnum = NextOfKinRelationship::tryFromLegacy($k->relationship);

                    return [
                        'id' => $k->id,
                        'name' => $k->user?->name,
                        'email' => $k->user?->email,
                        'relationship' => $k->relationship,
                        'relationship_label' => $relEnum?->label() ?? $k->relationship,
                        'relationship_category' => $relEnum?->category() ?? 'other',
                        'phone' => $k->phone,
                        'alternate_phone' => $k->alternate_phone,
                        'address' => $k->address,
                        'is_primary' => (bool) $k->is_primary_contact,
                        'is_emergency_contact' => (bool) $k->is_emergency_contact,
                        'can_view_medical' => (bool) $k->can_view_medical,
                        'can_view_medications' => (bool) $k->can_view_medications,
                        'can_view_incidents' => (bool) $k->can_view_incidents,
                        'can_receive_updates' => (bool) $k->can_receive_updates,
                        'has_portal_access' => $k->hasPortalAccess(),
                        'notes' => $k->notes,
                    ];
                })
                ->values() : null,
            'audit_history' => $sectionAccess['audit']
                ? AuditLog::query()
                    ->where('client_id', $client->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(200)
                    ->get()
                    ->map(fn ($log) => [
                        'id' => $log->id,
                        'action' => $log->action,
                        'auditable_type' => $log->auditable_type,
                        'auditable_id' => $log->auditable_id,
                        'meta' => $log->meta,
                        'actor' => $log->user
                            ? ['id' => $log->user->id, 'name' => $log->user->name]
                            : null,
                        'ip_address' => $log->ip_address,
                        'created_at' => optional($log->created_at)->toISOString(),
                    ])
                : [],
            'behaviour_patterns' => $sectionAccess['behaviour']
                ? app(BehaviourPatternsService::class)->forClient($client, $request->user())
                : null,
            'path_plan' => $sectionAccess['care_plans']
                ? ClientPathPlan::query()
                    ->where('client_id', $client->id)
                    ->first()?->only([
                        'id',
                        'dream',
                        'north_star',
                        'strengths',
                        'action_steps',
                        'trusted_people',
                        'independence_goals',
                        'community',
                        'meaningful_outcomes',
                        'plan_date',
                        'next_review_at',
                    ])
                : null,
            'leave_excursions' => $sectionAccess['daily_living'] ? [
                'leave' => ClientLeaveRequest::query()
                    ->where('client_id', $client->id)
                    ->with(['requester:id,name', 'approver:id,name'])
                    ->orderByDesc('starts_on')
                    ->limit(50)
                    ->get()
                    ->map(fn ($l) => [
                        'id' => $l->id,
                        'starts_on' => $l->starts_on?->toDateString(),
                        'ends_on' => $l->ends_on?->toDateString(),
                        'destination' => $l->destination,
                        'support_required' => $l->support_required,
                        'risks_and_mitigations' => $l->risks_and_mitigations,
                        'emergency_contact' => $l->emergency_contact,
                        'status' => $l->status,
                        'requester' => $l->requester?->name,
                        'approver' => $l->approver?->name,
                        'approved_at' => $l->approved_at?->toISOString(),
                        'approval_notes' => $l->approval_notes,
                    ])
                    ->values(),
                'excursions' => ClientExcursionRequest::query()
                    ->where('client_id', $client->id)
                    ->with(['requester:id,name', 'approver:id,name'])
                    ->orderByDesc('starts_at')
                    ->limit(50)
                    ->get()
                    ->map(fn ($e) => [
                        'id' => $e->id,
                        'starts_at' => $e->starts_at?->toISOString(),
                        'ends_at' => $e->ends_at?->toISOString(),
                        'destination' => $e->destination,
                        'activity_description' => $e->activity_description,
                        'transport_method' => $e->transport_method,
                        'risk_assessment' => $e->risk_assessment,
                        'outcome_notes' => $e->outcome_notes,
                        'status' => $e->status,
                        'requester' => $e->requester?->name,
                        'approver' => $e->approver?->name,
                        'approved_at' => $e->approved_at?->toISOString(),
                        'approval_notes' => $e->approval_notes,
                    ])
                    ->values(),
            ] : null,
            'client_finance' => $sectionAccess['finance'] ? [
                'funds' => ClientFund::query()
                    ->where('client_id', $client->id)
                    ->orderBy('fund_name')
                    ->get()
                    ->map(fn ($fund) => [
                        'id' => $fund->id,
                        'name' => $fund->fund_name,
                        'type' => $fund->fund_type,
                        'balance' => (float) $fund->balance,
                        'available_balance' => (float) $fund->available_balance,
                        'reconciliation_status' => $fund->reconciliation_status,
                        'low_balance_threshold' => $fund->low_balance_threshold
                            ? (float) $fund->low_balance_threshold
                            : null,
                        'is_active' => (bool) $fund->is_active,
                        'notes' => $fund->notes,
                    ])
                    ->values(),
                'recent_transactions' => ClientFundTransaction::query()
                    ->whereNotNull('balance_effect_applied_at')
                    ->whereIn(
                        'client_fund_id',
                        ClientFund::query()
                            ->where('client_id', $client->id)
                            ->pluck('id'),
                    )
                    ->with('recorder:id,name')
                    ->orderByDesc('transaction_date')
                    ->limit(25)
                    ->get()
                    ->map(fn ($tx) => [
                        'id' => $tx->id,
                        'type' => $tx->transaction_type,
                        'amount' => (float) $tx->amount,
                        'running_balance' => $tx->running_balance
                            ? (float) $tx->running_balance
                            : null,
                        'description' => $tx->description,
                        'category' => $tx->category,
                        'transaction_date' => $tx->transaction_date?->toDateString(),
                        'recorder' => $tx->recorder?->name,
                        'reference' => $tx->reference,
                    ])
                    ->values(),
                'purchase_requests' => ClientPurchaseRequest::query()
                    ->where('client_id', $client->id)
                    ->orderByDesc('requested_at')
                    ->orderByDesc('created_at')
                    ->limit(25)
                    ->get()
                    ->map(fn ($req) => [
                        'id' => $req->id,
                        'description' => $req->description,
                        'amount' => (float) $req->amount,
                        'status' => $req->status,
                        'requested_at' => ($req->requested_at ?? $req->created_at)?->toISOString(),
                    ])
                    ->values(),
                'discrepancies' => ClientFinancialDiscrepancy::query()
                    ->where('client_id', $client->id)
                    ->orderByDesc('raised_at')
                    ->orderByDesc('created_at')
                    ->limit(25)
                    ->get()
                    ->map(fn ($d) => [
                        'id' => $d->id,
                        'description' => $d->description,
                        'amount' => (float) $d->amount,
                        'status' => $d->status,
                        'raised_at' => ($d->raised_at ?? $d->created_at)?->toISOString(),
                    ])
                    ->values(),
            ] : null,
            'support_plan' => $sectionAccess['care_plans']
                ? $client->supportPlan
                : null,
            'assessments' => $sectionAccess['assessments']
                ? $client->assessments
                    ->sortByDesc(fn ($a) => $a->assessed_at ?? $a->created_at)
                    ->values()
                : null,
            'documents' => $documents,
            'portal_users' => $sectionAccess['portal_access'] ? $client->portalUsers->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'relation' => $u->pivot?->relation,
            ])->values() : null,
            'events' => $events->map(fn ($e) => [
                'id' => $e->id,
                'source_id' => $e->source_id,
                'source_type' => $e->source_type,
                'type' => $e->type,
                'occurred_at' => optional($e->occurred_at)->toISOString(),
                'subject' => $e->subject,
                'body' => $e->body,
                'meta' => $e->meta ?? [],
                'visibility' => $e->visibility,
                'is_pinned' => (bool) $e->is_pinned,
                'shift_id' => $e->shift_id,
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
                'site' => $e->site ? ['id' => $e->site->id, 'name' => $e->site->name] : null,
                'comments' => $e->comments->map(fn ($c) => [
                    'id' => $c->id,
                    'body' => $c->body,
                    'user_id' => $c->user_id,
                    'user_name' => $c->user?->name,
                    'is_staff' => ! in_array($c->user?->role, ['client', 'next_of_kin'], true),
                    'likes_count' => $c->likes->count(),
                    'liked_by_user_ids' => $c->likes->pluck('user_id')->all(),
                    'created_at' => $c->created_at?->toISOString(),
                    'replies' => $c->replies->map(fn ($r) => [
                        'id' => $r->id,
                        'body' => $r->body,
                        'user_id' => $r->user_id,
                        'user_name' => $r->user?->name,
                        'is_staff' => ! in_array($r->user?->role, ['client', 'next_of_kin'], true),
                        'likes_count' => $r->likes->count(),
                        'liked_by_user_ids' => $r->likes->pluck('user_id')->all(),
                        'created_at' => $r->created_at?->toISOString(),
                    ]),
                ]),
                'reactions' => $e->reactions
                    ->groupBy('emoji')
                    ->map(fn ($group, $emoji) => [
                        'emoji' => $emoji,
                        'count' => $group->count(),
                        'user_ids' => $group->pluck('user_id')->all(),
                    ])
                    ->values()
                    ->all(),
            ])->values(),
            'handover' => $handover->map(fn ($e) => [
                'id' => $e->id,
                'source_id' => $e->source_id,
                'source_type' => $e->source_type,
                'type' => $e->type,
                'occurred_at' => optional($e->occurred_at)->toISOString(),
                'subject' => $e->subject,
                'body' => $e->body,
                'is_pinned' => (bool) $e->is_pinned,
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
            ])->values(),
            'timeline_summary' => $sectionAccess['timeline'] ? [
                'total' => $eventsTotal,
                'loaded' => $events->count(),
                'has_more' => $eventsTotal > $events->count(),
                'pinned_handover_total' => $handoverTotal,
                'pinned_handover_loaded' => $handover->count(),
                'pinned_handover_has_more' => $handoverTotal > $handover->count(),
            ] : null,
            'shifts_summary' => $sectionAccess['shifts'] ? [
                'next' => $this->serializeShiftSummary($nextShift),
                'last' => $this->serializeShiftSummary($lastShift),
                'recurring' => $recurringShiftSeries->map(fn (ShiftSeries $series) => [
                    'id' => $series->id,
                    'status' => $series->status,
                    'shift_type' => $series->shift_type ?? 'standard',
                    'weekdays' => $series->by_weekday ?? [],
                    'starts_time' => $series->starts_time,
                    'ends_time' => $series->ends_time,
                    'next_starts_at' => $series->next_starts_at ? Carbon::parse($series->next_starts_at)->toIso8601String() : null,
                    'location' => $series->location,
                    'is_sleepover' => (bool) $series->is_sleepover,
                    'is_on_call' => (bool) $series->is_on_call,
                    'service_context' => $series->serviceContext ? [
                        'id' => $series->serviceContext->id,
                        'name' => $series->serviceContext->name,
                        'type' => $series->serviceContext->type?->value,
                    ] : null,
                    'staff' => $series->staff ? [
                        'id' => $series->staff->id,
                        'name' => $series->staff->name,
                        'email' => $series->staff->email,
                    ] : null,
                    'remaining_occurrences_count' => (int) ($series->remaining_occurrences_count ?? 0),
                    'open_occurrences_count' => (int) ($series->open_occurrences_count ?? 0),
                    'active_replacements_count' => (int) ($series->active_replacements_count ?? 0),
                ])->values(),
            ] : null,
            'site_coverage' => $siteCoverageSummary ? [
                'site_id' => (int) $siteCoverageSummary['site_id'],
                'site_name' => $siteCoverageSummary['site_name'],
                'total_windows' => (int) $siteCoverageSummary['total_windows'],
                'under_covered_windows' => (int) $siteCoverageSummary['under_covered_windows'],
                'exact_windows' => (int) $siteCoverageSummary['exact_windows'],
                'overstaffed_windows' => (int) $siteCoverageSummary['overstaffed_windows'],
                'largest_missing_staff' => (int) $siteCoverageSummary['largest_missing_staff'],
                'alerts' => collect($siteCoverageSummary['alerts'] ?? [])->take(4)->map(fn (array $alert) => [
                    'rule_name' => $alert['rule_name'],
                    'window_label' => $alert['window_label'],
                    'required_staff' => (int) $alert['required_staff'],
                    'assigned_staff' => (int) $alert['assigned_staff'],
                    'missing_staff' => (int) $alert['missing_staff'],
                    'coverage_state' => $alert['coverage_state'],
                    'starts_at' => $alert['starts_at'] ?? null,
                    'ends_at' => $alert['ends_at'] ?? null,
                ])->values(),
            ] : null,
            'onboarding' => $sectionAccess['onboarding'] ? [
                'checklist' => $this->buildOnboardingChecklist($client),
                'workflow' => $client->onboardingWorkflow ? [
                    'id' => $client->onboardingWorkflow->id,
                    'status' => $client->onboardingWorkflow->status,
                    'started_at' => $client->onboardingWorkflow->started_at?->toISOString(),
                    'completed_at' => $client->onboardingWorkflow->completed_at?->toISOString(),
                    'assigned_to' => $client->onboardingWorkflow->assignee ? [
                        'id' => $client->onboardingWorkflow->assignee->id,
                        'name' => $client->onboardingWorkflow->assignee->name,
                    ] : null,
                    'notes' => $client->onboardingWorkflow->notes,
                    'steps' => $client->onboardingWorkflow->steps->sortBy('step_order')->map(fn ($s) => [
                        'id' => $s->id,
                        'step_name' => $s->step_name,
                        'step_order' => $s->step_order,
                        'is_required' => $s->is_required,
                        'status' => $s->status,
                        'completed_at' => $s->completed_at?->toISOString(),
                        'completed_by' => $s->completer ? ['id' => $s->completer->id, 'name' => $s->completer->name] : null,
                        'notes' => $s->notes,
                        'due_date' => $s->due_date?->toDateString(),
                    ])->values()->toArray(),
                ] : null,
            ] : null,
            'staff_preparation' => $staffPreparation,
            'client_daily_notes' => ClientDailyNoteResource::collection($dailyNotes)->resolve($request),
            'communication_notes' => ClientDailyNoteResource::collection($communicationNotes)->resolve($request),
            'daily_notes_summary' => $dailyNotesBase ? [
                'total' => $dailyNotesTotal,
                'loaded' => $dailyNotes->count(),
                'has_more' => $dailyNotesTotal > $dailyNotes->count(),
                'flagged_open' => (clone $dailyNotesBase)->dailyNotes()->reviewQueue()->count(),
                'drafts' => (clone $dailyNotesBase)->dailyNotes()->where('is_draft', true)->count(),
                'communication' => $communicationNotesTotal,
                'communication_loaded' => $communicationNotes->count(),
                'communication_has_more' => $communicationNotesTotal > $communicationNotes->count(),
                'open_follow_ups' => (clone $dailyNotesBase)
                    ->whereNotNull('follow_up_action')
                    ->whereNull('follow_up_completed_at')
                    ->count(),
            ] : null,
            'health_monitoring' => $sectionAccess['health'] ? [
                'bowel' => ClientBowelEntry::query()
                    ->where('client_id', $client->id)
                    ->with('recorder:id,name')
                    ->orderByDesc('occurred_at')
                    ->limit(60)
                    ->get(),
                'fluid' => ClientFluidEntry::query()
                    ->where('client_id', $client->id)
                    ->with('recorder:id,name')
                    ->orderByDesc('occurred_at')
                    ->limit(90)
                    ->get(),
                'seizure' => ClientSeizureEntry::query()
                    ->where('client_id', $client->id)
                    ->with('recorder:id,name')
                    ->orderByDesc('occurred_at')
                    ->limit(60)
                    ->get(),
                'sleep' => ClientSleepEntry::query()
                    ->where('client_id', $client->id)
                    ->with('recorder:id,name')
                    ->orderByDesc('slept_at')
                    ->limit(60)
                    ->get(),
                'sleep_summary' => $this->buildSleepSummary($client),
            ] : null,
            'meal_logs' => $sectionAccess['meals']
                ? $this->buildMealLogData($client)
                : null,
            'client_routines' => $sectionAccess['daily_living']
                ? ClientRoutine::query()
                    ->where('client_id', $client->id)
                    ->with('updater:id,name')
                    ->orderBy('display_order')
                    ->get()
                : null,
            'actions_reviews' => $sectionAccess['actions_reviews']
                ? $actionsReviews
                : null,
            'actions_reviews_summary' => $sectionAccess['actions_reviews'] ? [
                'open' => count($actionsReviews),
                'loaded' => count($actionsReviews),
                'has_more' => (bool) $actionsReviewCoverage['has_more'],
                'critical' => collect($actionsReviews)->where('severity', 'critical')->count(),
                'warning' => collect($actionsReviews)->where('severity', 'warning')->count(),
            ] : null,

            // Service agreements
            'client_agreements' => $sectionAccess['agreements']
                ? ServiceAgreement::where('client_id', $client->id)
                    ->orderByDesc('created_at')
                    ->get()
                : null,

            // Risks (active first, inactive shown below for management)
            'client_risks' => $sectionAccess['risks']
                ? ClientRisk::where('client_id', $client->id)
                    ->orderByDesc('active')
                    ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
                    ->orderBy('label')
                    ->limit(50)
                    ->get()
                : null,

            // Formal H&S risk assessments (polymorphic; distinct from the ClientRisk
            // care-risk list above — shown as a separate section in the same tab).
            'hs_risk_assessments' => $sectionAccess['first_aid']
                ? HsRiskAssessment::forAssessable(Client::class, $client->id)
                    ->with(['assessedBy:id,name', 'assessable', 'hsEvent:id,reference_number'])
                    ->withCount('attachments')
                    ->orderByDesc('created_at')
                    ->limit(100)
                    ->get()
                    ->map(fn (HsRiskAssessment $ra) => RiskAssessmentPresenter::row($ra))
                    ->values()
                : [],
            'ra_pickers' => $sectionAccess['first_aid_manage']
                ? RiskAssessmentPresenter::pickers()
                : ['sites' => [], 'clients' => [], 'events' => []],

            // Recent incidents (last 5)
            'client_incidents' => $sectionAccess['incidents']
                ? ClientIncident::where('client_id', $client->id)
                    ->with(['reporter:id,name'])
                    ->orderByDesc('occurred_at')
                    ->limit(5)
                    ->get()
                : null,

            // First-aid treatments recorded for this client (read-only panel; gated on the
            // first_aid_records.client_id FK added by the gold-standard rebuild).
            'first_aid_records' => $sectionAccess['first_aid']
                && SchemaCache::hasTable('first_aid_records')
                ? FirstAidRecord::where('client_id', $client->id)
                    ->with(['site:id,name', 'firstAider:id,name'])
                    ->orderByDesc('treatment_date')
                    ->limit(10)
                    ->get()
                : [],

            'care_plans_summary' => $sectionAccess['care_plans'] ? [
                // The version staff should edit: an in-progress review takes
                // precedence over the published plan it was cloned from.
                'working_plan' => $workingCarePlan,
                // The "current" plan: the active plan if one exists, otherwise the
                // latest draft (so a freshly-created draft is visible + editable in-tab).
                'active_plan' => CarePlan::where('client_id', $client->id)
                    ->whereIn('status', ['active', 'draft'])
                    ->withCount(['goals', 'goals as goals_completed' => fn ($q) => $q->where('status', 'completed')])
                    ->with([
                        'creator:id,name',
                        'reviewer:id,name',
                        'signOffs' => fn ($q) => $q->latest('agreed_on'),
                        'signOffs.recorder:id,name',
                        'goals' => function ($q) {
                            $q->select('id', 'care_plan_id', 'title', 'status', 'progress_percentage', 'priority', 'category', 'target_date', 'description')
                                ->withCount([
                                    'steps',
                                    'steps as steps_done_count' => fn ($s) => $s->where('is_complete', true),
                                    'progressNotes as open_hurdles_count' => fn ($n) => $n->where('category', 'goal_hurdle')->where('is_flagged', true),
                                ])
                                ->orderByDesc('progress_percentage');
                        }])
                    ->orderByRaw("FIELD(status, 'active', 'draft')")
                    ->orderByDesc('version')
                    ->first(),
                'total_plans' => $carePlanTotal,
                'review_due' => CarePlan::where('client_id', $client->id)
                    ->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('next_review_at')->orWhere('next_review_at', '<=', now());
                    })->exists(),
                'recent_notes' => ClientNote::where('client_id', $client->id)
                    ->where('type', 'progress_note')
                    ->with(['author:id,name', 'carePlanGoal:id,title'])
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get()
                    ->map(fn (ClientNote $note) => [
                        'id' => $note->id,
                        'content' => $note->body,
                        'created_at' => optional($note->occurred_at ?? $note->created_at)->toISOString(),
                        'author' => $note->author ? [
                            'id' => $note->author->id,
                            'name' => $note->author->name,
                        ] : null,
                        'goal' => $note->carePlanGoal ? [
                            'id' => $note->carePlanGoal->id,
                            'title' => $note->carePlanGoal->title,
                        ] : null,
                        'is_flagged' => (bool) $note->is_flagged,
                    ]),
                'recent_notes_total' => $carePlanRecentNotesTotal,
                'recent_notes_loaded' => min(5, $carePlanRecentNotesTotal),
                'recent_notes_has_more' => $carePlanRecentNotesTotal > 5,
                // Full version history for the client (active + in-review + archived).
                'versions' => CarePlan::where('client_id', $client->id)
                    ->select('id', 'title', 'status', 'plan_type', 'version', 'parent_id', 'reviewed_at', 'reviewed_by', 'next_review_at', 'starts_at', 'created_at')
                    ->with('reviewer:id,name')
                    ->orderByDesc('version')
                    ->orderByDesc('created_at')
                    ->limit(30)
                    ->get(),
                'versions_total' => $carePlanTotal,
                'versions_loaded' => min(30, $carePlanTotal),
                'versions_has_more' => $carePlanTotal > 30,
                // In-progress review version (status=review), surfaced so the tab can
                // edit + complete it without leaving the profile.
                'review_plan' => CarePlan::where('client_id', $client->id)
                    ->where('status', 'review')
                    ->with('creator:id,name')
                    ->withCount(['goals'])
                    ->orderByDesc('version')
                    ->first(),
            ] : null,
            'respite' => $sectionAccess['respite'] ? [
                'bookings' => RespiteBooking::query()
                    ->where('client_id', $client->id)
                    ->orderByDesc('start_at')
                    ->limit(10)
                    ->with(['coordinator', 'shift'])
                    ->get()
                    ->map(fn ($b) => [
                        'id' => $b->id,
                        'start_at' => optional($b->start_at)->toISOString(),
                        'end_at' => optional($b->end_at)->toISOString(),
                        'status' => $b->status,
                        'shift_id' => $b->shift?->id,
                        'coordinator' => $b->coordinator ? ['id' => $b->coordinator->id, 'name' => $b->coordinator->name] : null,
                    ])->values(),
                'requests' => RespiteBookingRequest::query()
                    ->where('client_id', $client->id)
                    ->orderByDesc('requested_start')
                    ->limit(10)
                    ->get()
                    ->map(fn ($r) => [
                        'id' => $r->id,
                        'requested_start' => optional($r->requested_start)->toISOString(),
                        'requested_end' => optional($r->requested_end)->toISOString(),
                        'status' => $r->status,
                    ])->values(),
                'allocation' => app(ClientRespiteAllocationSummary::class)->forClient($client),
            ] : null,
            'consents' => $sectionAccess['consents']
                ? ClientConsent::where('client_id', $client->id)
                    ->where('site_id', $client->site_id)
                    ->with('consentType:id,name,category')
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn ($c) => [
                        'id' => $c->id,
                        'consent_type' => $c->consentType?->name ?? 'Unknown',
                        'consent_type_category' => $c->consentType?->category,
                        'status' => $c->status,
                        'decision_state' => $c->decision_state,
                        'governance_review_reason' => $c->governance_review_reason,
                        'is_consumable' => $c->isValid(),
                        'given_at' => $c->given_at?->toISOString(),
                        'given_method' => $c->given_method,
                        'expires_at' => $c->expires_at?->toISOString(),
                        'is_expired' => $c->isExpired(),
                        'is_expiring_soon' => $c->isExpiringSoon(),
                        'withdrawn_at' => $c->withdrawn_at?->toISOString(),
                        'withdrawal_reason' => $c->withdrawal_reason,
                        'conditions' => $c->conditions,
                        'special_conditions' => $c->special_conditions,
                        'capacity_assessed' => $c->capacity_assessed,
                        'capacity_outcome' => $c->capacity_outcome,
                        'best_interests_decision' => $c->best_interests_decision,
                    ])
                : null,
            'consent_type_options' => $sectionAccess['consents']
                ? ConsentType::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])
                    ->values()
                : [],
            'consent_request_list' => $sectionAccess['consents']
                ? ConsentRequest::where('client_id', $client->id)
                    ->where('site_id', $client->site_id)
                    ->with(['consentType:id,name', 'recipient:id,name'])
                    ->orderByDesc('created_at')
                    ->limit(50)
                    ->get()
                    ->map(fn ($r) => [
                        'id' => $r->id,
                        'consent_type' => $r->consentType?->name ?? 'Consent',
                        'recipient' => $r->recipient?->name,
                        'recipient_relationship' => $r->recipient_relationship,
                        'status' => $r->status,
                        'created_at' => $r->created_at?->toISOString(),
                        'expires_at' => $r->expires_at?->toISOString(),
                    ])->values()
                : [],
            'health_summary' => $sectionAccess['health']
                ? $this->buildHealthSummary($client)
                : null,
            'healthcare_devices' => Inertia::optional(fn () => $healthcareDevicesPresenter->present(
                $request->user(),
                $client,
                (bool) $sectionAccess['health'],
            )),
            'can' => [
                'edit' => $request->user()?->canDo('clients.update') ?? false,
                'update_client' => $canEditClientAssets,
                'assign_workers' => ($request->user()?->canDo('clients.assignments.update') ?? false)
                    && ($request->user()?->can('update', $client) ?? false),
                'view_family_chat' => $canViewFamilyChat,
                'send_family_chat' => $canManageFamilyNotes,
                'record_medication_administration' => $canRecordMedicationAdministration,
                'record_controlled_medication' => $canRecordControlledMedication,
                'update_risk_level' => $canEditClientAssets,
                'navigate_daily_notes' => (bool) $sectionAccess['notes'],
                'navigate_care_plans' => (bool) $sectionAccess['care_plans'],
                'navigate_risks' => (bool) $sectionAccess['risks'],
                'navigate_medical' => (bool) $sectionAccess['medical'],
                'view_healthcare_devices' => (bool) $sectionAccess['healthcare_devices'],
                'navigate_calendar' => (bool) $sectionAccess['calendar'],
                'navigate_workers' => (bool) ($sectionAccess['daily_living'] || $canAssignWorkers),
                'navigate_family_portal' => (bool) $sectionAccess['portal_access'],
                'navigate_site' => $request->user()?->canDo('sites.viewAny') ?? false,
                'create_note' => $request->user()?->canDo('timeline.create') ?? false,
                'create_daily_note' => $canCreateClientNote,
                'create_quick_note' => $canCreateClientNote,
                'create_communication_note' => $canCreateClientNote,
                'pin_handover' => $request->user()?->canDo('timeline.pin') ?? false,
                'manage_onboarding' => $onboardingAccess['manage_checklist'],
                'manage_onboarding_checklist' => $onboardingAccess['manage_checklist'],
                'create_onboarding_workflow' => $onboardingAccess['create_workflow'],
                'manage_family_notes' => $canManageFamilyNotes,
                'create_shift' => $request->user()?->canDo('shifts.create') ?? false,
                'manage_onboarding_workflow' => $onboardingAccess['manage_workflow'],
                'view_hr_onboarding' => $request->user()?->canDo('hr.onboarding.view') ?? false,
                'record_observation' => $request->user()?->canDo('clinical.observations.record') ?? false,
                'record_clinical_observation' => $request->user()?->canDo('clinical.observations.recordClinical') ?? false,
                'record_event' => $request->user()?->canDo('clinical.events.record') ?? false,
                'manage_risks' => ($request->user()?->canDo('risks.create') ?? false)
                    || ($request->user()?->canDo('risks.update') ?? false),
                'create_risks' => $request->user()?->canDo('risks.create') ?? false,
                'update_risks' => $request->user()?->canDo('risks.update') ?? false,
                'delete_risks' => $request->user()?->canDo('risks.delete') ?? false,
                'view_hs_risk_assessments' => $request->user()?->canDo('hazards.view') ?? false,
                'manage_hs_risk_assessments' => $request->user()?->canDo('hazards.manage') ?? false,
                'care_plans_view' => $request->user()?->canDo('care_plans.viewAny') ?? false,
                'care_plans_create' => $request->user()?->canDo('care_plans.create') ?? false,
                'care_plans_update' => $request->user()?->canDo('care_plans.update') ?? false,
                'care_plans_delete' => $request->user()?->canDo('care_plans.delete') ?? false,
                'manage_care_plan_goals' => $workingCarePlan
                    ? ($request->user()?->can('update', $workingCarePlan) ?? false)
                    : false,
                'edit_path_plan' => $request->user()?->can('update', $client) ?? false,
            ],
            'assignable_workers' => $this->buildAssignableWorkers($client, $request->user()),
            'pending_visit_count' => $sectionAccess['portal_access']
                ? FamilyVisitRequest::where('client_id', $client->id)->where('status', 'pending')->count()
                : null,
            'pending_consent_requests_count' => $sectionAccess['consents']
                ? $this->buildPendingConsentRequestCount($client)
                : null,
            'family_notes_open_count' => $sectionAccess['family_notes']
                ? FamilyNote::where('client_id', $client->id)->whereIn('status', ['open', 'in_progress'])->count()
                : null,
            'family_notes' => $sectionAccess['family_notes']
                ? FamilyNote::where('client_id', $client->id)
                    ->with([
                        'creator:id,name',
                        'completer:id,name',
                        'staffResponder:id,name',
                        'shift:id,starts_at,ends_at,shift_type,location,service_context_id,user_id',
                        'shift.serviceContext:id,name',
                        'shift.staff:id,name',
                    ])
                    ->orderByRaw("FIELD(status, 'open', 'in_progress', 'completed', 'cancelled')")
                    ->orderByDesc('created_at')
                    ->limit(50)
                    ->get()
                    ->map(fn ($n) => [
                        'id' => $n->id,
                        'title' => $n->title,
                        'description' => $n->description,
                        'note_type' => $n->note_type,
                        'priority' => $n->priority,
                        'status' => $n->status,
                        'due_date' => $n->due_date?->toDateString(),
                        'due_time' => $n->due_time,
                        'completed_at' => $n->completed_at?->toISOString(),
                        'completed_by_name' => $n->completer?->name,
                        'staff_response' => $n->staff_response,
                        'staff_responded_by_name' => $n->staffResponder?->name,
                        'staff_responded_at' => $n->staff_responded_at?->toISOString(),
                        'assigned_shift_date' => $n->shift?->starts_at?->format('j M'),
                        'assigned_to_shift_id' => $n->assigned_to_shift_id,
                        'assigned_shift' => $n->shift ? [
                            'id' => $n->shift->id,
                            'starts_at' => $n->shift->starts_at?->toISOString(),
                            'ends_at' => $n->shift->ends_at?->toISOString(),
                            'shift_type' => $n->shift->shift_type ?? 'standard',
                            'location' => $n->shift->location,
                            'service_context' => $n->shift->serviceContext?->name,
                            'staff_name' => $n->shift->staff?->name,
                        ] : null,
                        'creator_name' => $n->creator?->name,
                        'created_by' => $n->created_by,
                        'created_at' => $n->created_at?->toISOString(),
                        'is_overdue' => $n->due_date && $n->due_date->isPast() && in_array($n->status, ['open', 'in_progress']),
                    ])
                : null,
            'photos' => $sectionAccess['photos']
                ? ClientPhoto::where('client_id', $client->id)
                    ->with('uploadedBy:id,name')
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(function (ClientPhoto $photo): array {
                        return [
                            'id' => $photo->id,
                            ...app(ClientPhotoMediaUrls::class)->staff($photo),
                            'caption' => $photo->caption,
                            'tags' => $photo->tags,
                            'visibility' => $photo->visibility,
                            'status' => $photo->status,
                            'original_name' => $photo->original_name,
                            'uploaded_by' => $photo->uploadedBy?->name,
                            'created_at' => $photo->created_at?->toISOString(),
                        ];
                    })->values()
                : null,
            'personal_assets' => $sectionAccess['personal_assets']
                ? $this->buildPersonalAssetsData(
                    $client,
                    $canViewClientLocation,
                    $canManageClientTrackers,
                )
                : null,
            ...($sectionAccess['personal_assets'] && $canEditClientAssets ? [
                'asset_locations' => $this->buildAssetLocations($client),
            ] : []),
            ...($sectionAccess['tracking'] && $canEditClientAssets && $canManageClientTrackers ? [
                'available_trackers' => $this->buildAvailableTrackers($client, $request->user()),
            ] : []),
            'emar_summary' => $sectionAccess['medical'] ? [
                'active_medications_count' => $activeMedicationsCount,
                'last_administration' => $lastMedicationAdministration,
                'pending_alerts_count' => $pendingMedicationAlertsCount,
                'next_review_date' => MedicationReview::where('client_id', $client->id)
                    ->where('status', '!=', 'completed')
                    ->whereNotNull('scheduled_date')
                    ->where('scheduled_date', '>=', now()->toDateString())
                    ->orderBy('scheduled_date')
                    ->value('scheduled_date'),
            ] : null,
            ...($sectionAccess['tracking'] && $canViewClientLocation ? [
                'location' => $this->buildLocationData(
                    $client,
                    $canManageClientTrackers,
                    $request->user(),
                ),
            ] : []),
            'calendar_events' => $sectionAccess['calendar']
                ? $this->buildCalendarEvents(
                    $client,
                    includeShifts: $sectionAccess['shifts'],
                    includeFamilyVisits: $sectionAccess['portal_access'],
                    includeMedicationData: $sectionAccess['medical'],
                    includeControlledMedicationData: $canViewControlledMedication,
                )
                : null,
            'expiredConsents' => $expiredConsents->map(fn ($c) => [
                'id' => $c->id,
                'type' => $c->consentType?->name,
                'expired_at' => $c->expires_at?->toIso8601String(),
            ]),
            'missingMandatoryConsents' => $missingMandatory->pluck('name')->values(),
            'transport' => $sectionAccess['transport']
                ? Inertia::optional(fn () => $this->buildTransportData($client))
                : null,
            'hs_summary' => Inertia::optional(fn () => app(HsModuleSummaryService::class)->forClient($client->id)),
            'safety' => ClientSafetyPayload::forClient(
                $client,
                includeMedical: $sectionAccess['medical'],
                includeRisks: $sectionAccess['risks'],
            ),
            // Read-only Privacy panel — the client's Privacy Act 2020 access/
            // correction requests (gated on the privacy view permission).
            'data_subject_requests' => $sectionAccess['privacy']
                ? $client->dataSubjectRequests()->with('assignedTo:id,name')->latest('received_at')->get()->map(fn ($r) => [
                    'id' => $r->id,
                    'reference' => $r->reference_number,
                    'request_type' => $r->request_type,
                    'status' => $r->status,
                    'received_at' => optional($r->received_at)->toDateString(),
                    'due_date' => optional($r->extended_due_date ?: $r->due_date)->toDateString(),
                    'is_overdue' => $r->isOverdue(),
                    'assigned_to' => $r->assignedTo?->name,
                ])->all()
                : [],
        ];

        foreach ([
            'events' => 'timeline',
            'handover' => 'timeline',
            'timeline_summary' => 'timeline',
            'client_daily_notes' => 'notes',
            'communication_notes' => 'notes',
            'daily_notes_summary' => 'notes',
            'support_plan' => 'care_plans',
            'care_plans_summary' => 'care_plans',
            'path_plan' => 'care_plans',
            'assessments' => 'assessments',
            'behaviour_patterns' => 'behaviour',
            'calendar_events' => 'calendar',
            'shifts_summary' => 'shifts',
            'site_coverage' => 'shifts',
            'medical' => 'medical',
            'health_monitoring' => 'health',
            'health_summary' => 'health',
            'healthcare_devices' => 'healthcare_devices',
            'emar_summary' => 'medical',
            'client_finance' => 'finance',
            'consents' => 'consents',
            'consent_type_options' => 'consents',
            'consent_request_list' => 'consents',
            'expiredConsents' => 'consents',
            'missingMandatoryConsents' => 'consents',
            'pending_consent_requests_count' => 'consents',
            'client_risks' => 'risks',
            'hs_risk_assessments' => 'first_aid',
            'ra_pickers' => 'first_aid_manage',
            'client_incidents' => 'incidents',
            'first_aid_records' => 'first_aid',
            'documents' => 'documents',
            'portal_users' => 'portal_access',
            'next_of_kins' => 'portal_access',
            'audit_history' => 'audit',
            'data_subject_requests' => 'privacy',
            'respite' => 'respite',
            'onboarding' => 'onboarding',
            'leave_excursions' => 'daily_living',
            'meal_logs' => 'meals',
            'client_routines' => 'daily_living',
            'actions_reviews' => 'actions_reviews',
            'actions_reviews_summary' => 'actions_reviews',
            'client_agreements' => 'agreements',
            'pending_visit_count' => 'portal_access',
            'family_notes_open_count' => 'family_notes',
            'family_notes' => 'family_notes',
            'photos' => 'photos',
            'personal_assets' => 'personal_assets',
            'asset_locations' => 'personal_assets',
            'available_trackers' => 'tracking',
            'location' => 'tracking',
            'transport' => 'transport',
            'homeHazards' => 'first_aid',
            'homeHazardDetail' => 'first_aid',
            'hs_summary' => 'first_aid',
        ] as $prop => $section) {
            if (! $sectionAccess[$section]) {
                unset($profileProps[$prop]);
            }
        }

        $response = inertia('operations/clients/show', $profileProps);

        return $sectionAccess['tracking'] && $canViewClientLocation
            ? $response->toResponse($request)->withHeaders($this->privateLocationHeaders())
            : $response;
    }

    private function carePlanWorkingVersion(Client $client): ?CarePlan
    {
        return CarePlan::query()
            ->where('client_id', $client->id)
            ->whereIn('status', ['review', 'active', 'draft'])
            ->withCount([
                'goals',
                'goals as goals_completed' => fn ($query) => $query->where('status', 'completed'),
            ])
            ->with([
                'creator:id,name',
                'reviewer:id,name',
                'signOffs' => fn ($query) => $query->latest('agreed_on'),
                'signOffs.recorder:id,name',
                'goals' => function ($query) {
                    $query->select('id', 'care_plan_id', 'title', 'status', 'progress_percentage', 'priority', 'category', 'target_date', 'description')
                        ->withCount([
                            'steps',
                            'steps as steps_done_count' => fn ($steps) => $steps->where('is_complete', true),
                            'progressNotes as open_hurdles_count' => fn ($notes) => $notes
                                ->where('category', 'goal_hurdle')
                                ->where('is_flagged', true),
                        ])
                        ->orderByDesc('progress_percentage');
                },
            ])
            ->orderByRaw("FIELD(status, 'review', 'active', 'draft')")
            ->orderByDesc('version')
            ->first();
    }

    private function roomPayload(?SiteHouseRoom $room): ?array
    {
        if (! $room) {
            return null;
        }

        return [
            'id' => $room->id,
            'site_id' => $room->site_id,
            'name' => $room->name,
            'notes' => $room->notes,
        ];
    }

    private function medicationPayload(ClientMedication $medication): array
    {
        $payload = $medication->toArray();
        $stock = $medication->stock;

        $payload['stock'] = $stock ? [
            'on_hand' => $stock->on_hand,
            'unit' => $stock->unit,
            'reorder_threshold' => $stock->reorder_level,
            'is_low' => $stock->isLowStock(),
            'last_counted_at' => $stock->last_counted_at?->toISOString(),
            'expiry_date' => $stock->expiry_date?->toDateString(),
        ] : null;

        return $payload;
    }

    private function buildMealLogData(Client $client): array
    {
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        $todayStart = now($timezone)->startOfDay()->utc();
        $todayEnd = now($timezone)->endOfDay()->utc();
        $weekStart = now($timezone)->subDays(6)->startOfDay()->utc();

        $today = ClientMealLog::query()
            ->where('client_id', $client->id)
            ->whereBetween('occurred_at', [$todayStart, $todayEnd])
            ->with('recorder:id,name')
            ->orderByDesc('occurred_at')
            ->get();

        $history = ClientMealLog::query()
            ->where('client_id', $client->id)
            ->where('occurred_at', '>=', $weekStart)
            ->with('recorder:id,name')
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get();

        return [
            'today' => $today,
            'last_7_days' => $history,
            'summary' => [
                'eaten_today' => $today->where('status', 'eaten')->count(),
                'expected_today' => 3,
                'partial_today' => $today->where('status', 'partial')->count(),
                'refused_today' => $today->whereIn('status', ['refused', 'declined'])->count(),
            ],
        ];
    }

    private function buildSleepSummary(Client $client): array
    {
        $entries = ClientSleepEntry::query()
            ->where('client_id', $client->id)
            ->orderByDesc('slept_at')
            ->limit(7)
            ->get(['hours_slept']);

        $target = $client->sleep_target_hours ? (float) $client->sleep_target_hours : 7.0;
        $average = $entries->count() > 0
            ? round((float) $entries->avg(fn (ClientSleepEntry $entry) => (float) $entry->hours_slept), 1)
            : null;

        return [
            'average_7_nights' => $average,
            'target_hours' => $target,
            'below_target' => $average !== null ? $average < $target : null,
        ];
    }

    private function buildAssignableWorkers(Client $client, ?User $user): array
    {
        if (
            ! ($user?->canDo('clients.assignments.update') ?? false)
            || ! ($user?->can('update', $client) ?? false)
        ) {
            return [];
        }

        $assignedIds = $client->supportWorkers->pluck('id')->map(fn ($id) => (int) $id)->all();

        return app(ClientWorkerEligibility::class)->query($client)
            ->orderByRaw(
                count($assignedIds) > 0
                    ? 'CASE WHEN id IN ('.implode(',', array_map('intval', $assignedIds)).') THEN 0 ELSE 1 END'
                    : '1'
            )
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $worker) => [
                'id' => $worker->id,
                'name' => $worker->name,
                'email' => $worker->email,
                'assigned' => in_array((int) $worker->id, $assignedIds, true),
                'key_worker' => (int) $client->key_worker_id === (int) $worker->id,
            ])
            ->values()
            ->all();
    }

    private function syncClientRoomAssignment(Client $client, ?int $previousRoomId = null): void
    {
        if ($previousRoomId && (int) $previousRoomId !== (int) $client->room_id) {
            SiteHouseRoom::query()
                ->whereKey($previousRoomId)
                ->where('assigned_client_id', $client->id)
                ->update([
                    'assigned_client_id' => null,
                    'assigned_from' => null,
                    'assigned_until' => null,
                ]);
        }

        if (! $client->room_id) {
            SiteHouseRoom::query()
                ->where('assigned_client_id', $client->id)
                ->update([
                    'assigned_client_id' => null,
                    'assigned_from' => null,
                    'assigned_until' => null,
                ]);

            return;
        }

        SiteHouseRoom::query()
            ->where('assigned_client_id', $client->id)
            ->whereKeyNot($client->room_id)
            ->update([
                'assigned_client_id' => null,
                'assigned_from' => null,
                'assigned_until' => null,
            ]);

        SiteHouseRoom::query()
            ->whereKey($client->room_id)
            ->update([
                'assigned_client_id' => $client->id,
                'assigned_from' => now()->toDateString(),
                'assigned_until' => null,
            ]);
    }

    private function serializeShiftSummary(?Shift $shift): ?array
    {
        if (! $shift) {
            return null;
        }

        return [
            'id' => $shift->id,
            'starts_at' => optional($shift->starts_at)->toISOString(),
            'ends_at' => optional($shift->ends_at)->toISOString(),
            'actual_starts_at' => optional($shift->actual_starts_at)->toISOString(),
            'actual_ends_at' => optional($shift->actual_ends_at)->toISOString(),
            'status' => $shift->status,
            'shift_type' => $shift->shift_type ?? 'standard',
            'is_sleepover' => (bool) $shift->is_sleepover,
            'is_on_call' => (bool) $shift->is_on_call,
            'expected_break_minutes' => $shift->expected_break_minutes,
            'location' => $shift->location,
            'service_context' => $shift->serviceContext ? [
                'id' => $shift->serviceContext->id,
                'name' => $shift->serviceContext->name,
                'type' => $shift->serviceContext->type?->value,
            ] : null,
            'staff' => $shift->staff ? [
                'id' => $shift->staff->id,
                'name' => $shift->staff->name,
                'email' => $shift->staff->email,
            ] : null,
            'task_count' => (int) ($shift->tasks_count ?? 0),
            'incomplete_task_count' => (int) ($shift->incomplete_tasks_count ?? 0),
            'form_submission_count' => (int) ($shift->form_submissions_count ?? 0),
            'medication_administration_count' => (int) ($shift->medication_administrations_count ?? 0),
            'timesheet_count' => (int) ($shift->timesheets_count ?? 0),
            'handover_count' => (int) (($shift->outgoing_handovers_count ?? 0) + ($shift->incoming_handovers_count ?? 0)),
            'transport_count' => (int) ($shift->resident_transports_count ?? 0),
        ];
    }

    private function buildCalendarEvents(
        Client $client,
        bool $includeShifts,
        bool $includeFamilyVisits,
        bool $includeMedicationData,
        bool $includeControlledMedicationData,
    ): array {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth()->addDays(7);
        $events = collect();

        if ($includeShifts) {
            $shifts = Shift::where('client_id', $client->id)
                ->whereBetween('starts_at', [$start, $end])
                ->with('staff:id,name')
                ->get();
            foreach ($shifts as $s) {
                $events->push([
                    'id' => 'shift-'.$s->id,
                    'title' => ($s->staff?->name ?? 'Staff TBC').' — Shift',
                    'start' => $s->starts_at?->toIso8601String(),
                    'end' => $s->ends_at?->toIso8601String(),
                    'backgroundColor' => $s->status === 'completed' ? '#10b981' : ($s->status === 'cancelled' ? '#94a3b8' : '#3b82f6'),
                    'borderColor' => 'transparent',
                    'extendedProps' => ['type' => 'shift', 'status' => $s->status, 'staff_name' => $s->staff?->name, 'notes' => $s->notes, 'location' => $s->location],
                ]);
            }
        }

        if ($includeFamilyVisits) {
            $visits = FamilyVisitRequest::where('client_id', $client->id)
                ->where('status', 'approved')
                ->whereBetween('requested_date', [$start->toDateString(), $end->toDateString()])
                ->with('user:id,name')
                ->get();
            foreach ($visits as $v) {
                $vStart = $v->requested_date->copy();
                if ($v->preferred_time_start) {
                    [$h, $m] = explode(':', $v->preferred_time_start);
                    $vStart->setTime((int) $h, (int) $m);
                }
                $vEnd = $v->requested_date->copy();
                if ($v->preferred_time_end) {
                    [$h, $m] = explode(':', $v->preferred_time_end);
                    $vEnd->setTime((int) $h, (int) $m);
                } else {
                    $vEnd = $vStart->copy()->addHour();
                }
                $events->push([
                    'id' => 'visit-'.$v->id,
                    'title' => 'Family Visit — '.($v->user?->name ?? 'Family'),
                    'start' => $vStart->toIso8601String(),
                    'end' => $vEnd->toIso8601String(),
                    'backgroundColor' => '#22c55e',
                    'borderColor' => 'transparent',
                    'extendedProps' => ['type' => 'family_visit', 'requester' => $v->user?->name, 'notes' => $v->notes],
                ]);
            }
        }

        // Appointments
        $appointments = ClientAppointment::where('client_id', $client->id)
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<=', $end)
            ->where('status', '!=', 'cancelled')
            ->get();
        $typeColors = ['gp_visit' => '#f59e0b', 'specialist' => '#8b5cf6', 'therapy' => '#ec4899', 'activity' => '#06b6d4', 'reminder' => '#6366f1', 'other' => '#64748b'];
        foreach ($appointments as $a) {
            $events->push([
                'id' => 'appt-'.$a->id,
                'title' => $a->title,
                'start' => $a->starts_at->toIso8601String(),
                'end' => $a->ends_at?->toIso8601String(),
                'backgroundColor' => $typeColors[$a->appointment_type] ?? '#64748b',
                'borderColor' => 'transparent',
                'extendedProps' => ['type' => 'appointment', 'appointment_type' => $a->appointment_type, 'status' => $a->status, 'location' => $a->location, 'provider_name' => $a->provider_name, 'description' => $a->description],
            ]);
        }

        if ($includeMedicationData) {
            $medicationGovernance = app(MedicationGovernanceScopeService::class);
            // Medication administrations
            $medAdmins = ClientMedicationAdministration::query()
                ->effectiveClinicalEvidence()
                ->where('client_id', $client->id)
                ->whereBetween('scheduled_for', [$start, $end])
                ->with('medication:id,client_id,name,dosage,route,controlled_drug');
            $medicationGovernance->scopeCanonicalClientMedicationRows(
                $medAdmins,
                is_numeric($client->site_id) ? [(int) $client->site_id] : [],
                allowNullMedication: false,
            );
            if (! $includeControlledMedicationData) {
                $medicationGovernance->scopeWithoutControlledMedicationRows($medAdmins);
            }
            $medAdmins = $medAdmins->get();
            foreach ($medAdmins as $ma) {
                $medName = $ma->medication?->name ?? 'Medication';
                $statusColor = match ($ma->status) {
                    'given' => '#10b981', 'refused' => '#f97316', 'withheld' => '#eab308', 'missed' => '#ef4444', default => '#ec4899'
                };
                $statusLabel = match ($ma->status) {
                    'given' => 'Given', 'refused' => 'Refused', 'withheld' => 'Withheld', 'missed' => 'Missed', default => 'Scheduled'
                };
                $events->push([
                    'id' => 'med-'.$ma->id,
                    'title' => $medName.' — '.$statusLabel,
                    'start' => $ma->scheduled_for?->toIso8601String() ?? $ma->administered_at?->toIso8601String(),
                    'backgroundColor' => $statusColor,
                    'borderColor' => 'transparent',
                    'extendedProps' => ['type' => 'medication', 'status' => $ma->status, 'medication_name' => $medName, 'dosage' => $ma->medication?->dosage],
                ]);
            }

            // Scheduled medication doses — only show for today ± 3 days to avoid clutter
            $medStart = now()->subDays(3)->startOfDay();
            $medEnd = now()->addDays(3)->endOfDay();
            $activeMeds = ClientMedication::where('client_id', $client->id)
                ->active()
                ->whereNull('ceased_at')
                ->where('is_prn', false)
                ->when(! $includeControlledMedicationData, fn ($query) => $query
                    ->where('controlled_drug', false))
                ->get();
            foreach ($activeMeds as $med) {
                $times = $this->parseFrequencyTimes($med->frequency);
                if (empty($times)) {
                    continue;
                }
                $current = $medStart->copy();
                while ($current->lte($medEnd)) {
                    foreach ($times as $time) {
                        $scheduledAt = $current->copy()->setTimeFromTimeString($time);
                        $alreadyRecorded = $medAdmins->contains(fn ($ma) => $ma->client_medication_id === $med->id && $ma->scheduled_for && $ma->scheduled_for->format('Y-m-d H:i') === $scheduledAt->format('Y-m-d H:i'));
                        if (! $alreadyRecorded && $scheduledAt->gte($start) && $scheduledAt->lte($end)) {
                            $isPast = $scheduledAt->lt(now());
                            $events->push([
                                'id' => 'medsched-'.$med->id.'-'.$scheduledAt->format('YmdHi'),
                                'title' => $med->name.($isPast ? ' — Overdue' : ' — Due'),
                                'start' => $scheduledAt->toIso8601String(),
                                'backgroundColor' => $isPast ? '#ef4444' : '#ec4899',
                                'borderColor' => 'transparent',
                                'extendedProps' => ['type' => 'medication', 'status' => $isPast ? 'overdue' : 'scheduled', 'medication_name' => $med->name, 'dosage' => $med->dosage],
                            ]);
                        }
                    }
                    $current->addDay();
                }
            }

        }

        return $events->values()->toArray();
    }

    private function parseFrequencyTimes(?string $frequency): array
    {
        if (! $frequency) {
            return [];
        }
        $freq = strtolower(trim($frequency));
        if (preg_match_all('/(\d{1,2}):(\d{2})/', $freq, $matches, PREG_SET_ORDER)) {
            return array_map(fn ($m) => sprintf('%02d:%02d', $m[1], $m[2]), $matches);
        }

        return match (true) {
            str_contains($freq, 'twice daily'), str_contains($freq, 'bd'), str_contains($freq, 'bid') => ['08:00', '20:00'],
            str_contains($freq, 'three times'), str_contains($freq, 'tds'), str_contains($freq, 'tid') => ['08:00', '14:00', '20:00'],
            str_contains($freq, 'four times'), str_contains($freq, 'qds'), str_contains($freq, 'qid') => ['08:00', '12:00', '16:00', '20:00'],
            str_contains($freq, 'lunch') => ['12:00'],
            str_contains($freq, 'evening'), str_contains($freq, 'night'), str_contains($freq, 'nocte') => ['20:00'],
            str_contains($freq, 'morning'), str_contains($freq, 'mane') => ['08:00'],
            str_contains($freq, 'once daily'), str_contains($freq, 'daily'), str_contains($freq, 'od') => ['08:00'],
            default => [],
        };
    }

    private function buildHealthSummary(Client $client): array
    {
        $emptySummary = [
            'latest_observations' => [],
            'recent_events' => [
                'count' => 0,
                'high_severity_count' => 0,
                'items' => [],
            ],
            'protocol_compliance' => [
                'rate' => 0,
                'due_count' => 0,
                'overdue_count' => 0,
            ],
        ];

        foreach ([
            'clinical_observations',
            'clinical_events',
            'clinical_protocols',
            'clinical_protocol_schedules',
        ] as $table) {
            if (! SchemaCache::hasTable($table)) {
                return $emptySummary;
            }
        }

        try {
            return app(ClinicalHealthSummaryService::class)->getSummary($client);
        } catch (QueryException $exception) {
            report($exception);

            return $emptySummary;
        }
    }

    private function buildPendingConsentRequestCount(Client $client): int
    {
        if (! SchemaCache::hasTable('consent_requests')) {
            return 0;
        }

        try {
            return ConsentRequest::forClient($client->id)->pending()->count();
        } catch (QueryException $exception) {
            report($exception);

            return 0;
        }
    }

    private function buildPersonalAssetsData(
        Client $client,
        bool $canViewLocation,
        bool $canManageTrackers,
    ) {
        return ClientPersonalAsset::query()
            ->where('client_id', $client->id)
            ->with([
                'recordedBy:id,name',
                'site:id,name',
                'room:id,site_id,name',
                'trackerDevice.assignments' => fn ($query) => $query
                    ->active()
                    ->where('assignable_type', 'client')
                    ->where('assignable_id', $client->id)
                    ->with('consent.consentType'),
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ClientPersonalAsset $asset) use ($canViewLocation, $canManageTrackers): array {
                $site = $asset->site;
                $room = $site && $asset->room && (int) $asset->room->site_id === (int) $site->id
                    ? $asset->room
                    : null;
                $tracker = $asset->trackerDevice;
                $trackerAssignment = $tracker?->assignments->first();
                if (! $trackerAssignment
                    || ! app(PersonalTrackingPrivacyService::class)
                        ->assignmentAuthorisesClient($trackerAssignment, (int) $asset->client_id)) {
                    $tracker = null;
                }
                $trackerMeta = $tracker?->meta ?? [];

                return [
                    'id' => $asset->id,
                    'name' => $asset->name,
                    'category' => $asset->category,
                    'description' => $asset->description,
                    'serial_number' => $asset->serial_number,
                    'estimated_value' => $asset->estimated_value,
                    'condition' => $asset->condition,
                    'location' => $asset->location,
                    'site_id' => $site?->id,
                    'site_name' => $site?->name,
                    'room_id' => $room?->id,
                    'room_name' => $room?->name,
                    'tracker_device_id' => $canManageTrackers ? $tracker?->id : null,
                    'tracker' => $canViewLocation && $tracker ? [
                        'id' => $tracker->id,
                        'name' => $tracker->name,
                        'status' => $tracker->getRawOriginal('status'),
                        'last_seen_at' => $tracker->last_seen_at?->toISOString(),
                        'battery' => $tracker->battery_level ?? $trackerMeta['battery'] ?? null,
                        'lat' => $tracker->latitude ?? $trackerMeta['lat'] ?? null,
                        'lng' => $tracker->longitude ?? $trackerMeta['lng'] ?? null,
                        'speed' => $trackerMeta['speed'] ?? null,
                    ] : null,
                    'photo_url' => $asset->photo_url,
                    'acquired_at' => $asset->acquired_at?->toDateString(),
                    'notes' => $asset->notes,
                    'status' => $asset->status,
                    'ownership' => $asset->ownership,
                    'funding_source' => $asset->funding_source,
                    'return_required' => $asset->return_required,
                    'return_by' => $asset->return_by?->toDateString(),
                    'last_serviced_at' => $asset->last_serviced_at?->toDateString(),
                    'next_service_due' => $asset->next_service_due?->toDateString(),
                    'service_provider' => $asset->service_provider,
                    'warranty_expires_at' => $asset->warranty_expires_at?->toDateString(),
                    'insurance_reference' => $asset->insurance_reference,
                    'disposed_at' => $asset->disposed_at?->toDateString(),
                    'disposal_reason' => $asset->disposal_reason,
                    'portal_visible' => $asset->portal_visible,
                    'is_service_overdue' => $asset->isServiceOverdue(),
                    'is_warranty_expired' => $asset->isWarrantyExpired(),
                    'is_warranty_expiring_soon' => $asset->isWarrantyExpiringSoon(),
                    'is_return_overdue' => $asset->isReturnOverdue(),
                    'recorded_by' => $asset->recordedBy?->name,
                    'created_at' => $asset->created_at?->toISOString(),
                ];
            })
            ->values();
    }

    private function buildAssetLocations(Client $client)
    {
        if (! is_numeric($client->site_id)) {
            return collect();
        }

        return Site::query()
            ->whereKey((int) $client->site_id)
            ->where('is_active', true)
            ->with(['houseRooms' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->select(['id', 'site_id', 'name'])])
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'rooms' => $site->houseRooms->map(fn (SiteHouseRoom $room) => [
                    'id' => $room->id,
                    'name' => $room->name,
                ])->values(),
            ]);
    }

    private function buildAvailableTrackers(Client $client, User $viewer)
    {
        if (! SchemaCache::hasTable('devices') || ! SchemaCache::hasTable('location_hardware')) {
            return collect();
        }

        try {
            $access = app(SecurityDevicesAccessService::class);
            if (! $access->canViewUnassigned($viewer) || ! is_numeric($client->site_id)) {
                return collect();
            }

            $trackers = $access->unassignedTrackingDevicesForClient($viewer, $client)
                ->orderBy('name')
                ->get([
                    'id',
                    'legacy_location_hardware_id',
                    'name',
                    'status',
                    'last_seen_at',
                    'serial_number',
                    'battery_level',
                    'meta',
                ]);
            $currentTrackerIds = ClientPersonalAsset::query()
                ->where('client_id', $client->id)
                ->whereNotIn('status', ['disposed', 'returned'])
                ->whereNotNull('tracker_device_id')
                ->pluck('tracker_device_id');
            if ($currentTrackerIds->isNotEmpty()) {
                $currentTrackers = $access->visibleDevices($viewer)
                    ->whereKey($currentTrackerIds)
                    ->where('domain', 'tracking')
                    ->whereHas('assignments', fn ($assignment) => $assignment
                        ->active()
                        ->where('assignable_type', 'client')
                        ->where('assignable_id', $client->id))
                    ->with(['assignments' => fn ($assignment) => $assignment
                        ->active()
                        ->where('assignable_type', 'client')
                        ->where('assignable_id', $client->id)
                        ->with('consent.consentType')])
                    ->get([
                        'id',
                        'legacy_location_hardware_id',
                        'name',
                        'status',
                        'last_seen_at',
                        'serial_number',
                        'battery_level',
                        'meta',
                    ])
                    ->filter(fn (Device $tracker): bool => $tracker->assignments->contains(
                        fn ($assignment): bool => app(PersonalTrackingPrivacyService::class)
                            ->assignmentAuthorisesClient($assignment, $client),
                    ));
                $trackers = $trackers->concat($currentTrackers)->unique('id')->values();
            }

            $hardwareById = LocationHardware::query()
                ->whereIn('id', $trackers->pluck('legacy_location_hardware_id'))
                ->where('site_id', $client->site_id)
                ->get(['id', 'site_id'])
                ->keyBy('id');

            return $trackers->map(fn (Device $tracker) => [
                'id' => $tracker->id,
                'name' => $tracker->name,
                'status' => $tracker->getRawOriginal('status'),
                'serial' => $tracker->serial_number,
                'site_id' => $hardwareById->get($tracker->legacy_location_hardware_id)?->site_id,
                'last_seen_at' => $tracker->last_seen_at?->toISOString(),
                'battery' => $tracker->battery_level ?? $tracker->meta['battery'] ?? null,
            ])->values();
        } catch (QueryException $exception) {
            report($exception);

            return collect();
        }
    }

    private function buildOnboardingChecklist(Client $client): array
    {
        $overrides = $client->onboardingOverrides
            ->keyBy('key')
            ->map(fn ($o) => (bool) $o->value)
            ->toArray();

        $hasProfile = (bool) ($client->first_name && $client->last_name)
            && (bool) $client->date_of_birth
            && (bool) ($client->phone || $client->email)
            && (bool) ($client->address_line_1 || $client->city || $client->postcode);

        $items = [
            [
                'key' => 'profile',
                'label' => 'Profile details',
                'has_data' => $hasProfile,
                'override' => (bool) ($overrides['profile'] ?? false),
            ],
            [
                'key' => 'next_of_kin',
                'label' => 'Next of kin / portal contacts',
                'has_data' => $client->portalUsers->count() > 0,
                'override' => (bool) ($overrides['next_of_kin'] ?? false),
            ],
            [
                'key' => 'medications',
                'label' => 'Medications',
                'has_data' => $client->medications->count() > 0,
                'override' => (bool) ($overrides['medications'] ?? false),
            ],
            [
                'key' => 'conditions',
                'label' => 'Medical conditions',
                'has_data' => $client->conditions->count() > 0,
                'override' => (bool) ($overrides['conditions'] ?? false),
            ],
            [
                'key' => 'emergency_contacts',
                'label' => 'Emergency contacts',
                'has_data' => $client->emergencyContacts->count() > 0,
                'override' => (bool) ($overrides['emergency_contacts'] ?? false),
            ],
            [
                'key' => 'history',
                'label' => 'History (assessments or support plan)',
                'has_data' => ($client->assessments->count() > 0) || (bool) $client->supportPlan,
                'override' => (bool) ($overrides['history'] ?? false),
            ],
            [
                'key' => 'documents',
                'label' => 'Documents',
                'has_data' => $client->documents()->exists(),
                'override' => (bool) ($overrides['documents'] ?? false),
            ],
            [
                'key' => 'personal_assets',
                'label' => 'Personal belongings registered',
                'has_data' => $client->personalAssets()->exists(),
                'override' => (bool) ($overrides['personal_assets'] ?? false),
            ],
        ];

        $items = array_map(function ($i) {
            $i['complete'] = (bool) ($i['has_data'] || $i['override']);

            return $i;
        }, $items);

        $total = count($items);
        $completed = count(array_filter($items, fn ($i) => $i['complete']));
        $percent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        return [
            'items' => $items,
            'completed' => $completed,
            'total' => $total,
            'percent' => $percent,
            'status' => $completed === $total ? 'complete' : 'incomplete',
        ];
    }

    // public function show(Client $client)
    // {
    //     $this->authorize('view', $client);

    //     return inertia('operations/clients/show', [
    //         'client' => $client->load('supportWorkers'),
    //     ]);
    // }

    public function create(Request $request)
    {
        $this->authorize('create', Client::class);

        return inertia(
            'operations/clients/create',
            app(ClientFormOptions::class)->forViewer($request->user()),
        );
    }

    public function store(
        StoreClientRequest $request,
        ClientPortalMembershipService $portalMembership,
    ) {
        $this->authorize('create', Client::class);

        try {
            $data = $request->validated();

            // If not specified, apply the active application default only when
            // it is available to the selected Site.
            if (empty($data['service_context_id'])) {
                $siteId = filled($data['site_id'] ?? null) ? (int) $data['site_id'] : null;
                $data['service_context_id'] = ServiceContext::defaultIdForSite($siteId);
            }

            // Pull the related-record payloads out of the flat client attributes.
            $medical = $data['medical'] ?? [];
            $conditions = $data['conditions'] ?? [];
            $emergencyContacts = $data['emergency_contacts'] ?? [];
            $createPortalUser = ! empty($data['create_client_portal_user']);

            $clientFields = collect($data)->except([
                'create_client_portal_user',
                'profile_photo',
                'medical',
                'conditions',
                'emergency_contacts',
            ])->all();

            // New clients default to onboarding so an onboarding workflow can be
            // initialised; only an explicit "inactive" is preserved as-is.
            if (! isset($clientFields['status']) || $clientFields['status'] === 'active') {
                $clientFields['status'] = 'onboarding';
            }

            // Optional profile photo — square-crop + store, same as updatePhoto().
            if ($request->hasFile('profile_photo')) {
                $clientFields['profile_photo_path'] = $this->storeAvatar(
                    $request->file('profile_photo'),
                    'profile-photos/clients',
                );
            }

            $auth = $request->user();

            $client = DB::transaction(function () use ($clientFields, $medical, $conditions, $emergencyContacts, $createPortalUser, $data, $auth, $portalMembership) {
                if (! empty($clientFields['room_id'])) {
                    $roomIsStillAvailable = SiteHouseRoom::query()
                        ->whereKey((int) $clientFields['room_id'])
                        ->where('site_id', $clientFields['site_id'] ?? null)
                        ->where('is_active', true)
                        ->where('is_assignable', true)
                        ->whereNull('assigned_client_id')
                        ->lockForUpdate()
                        ->exists();

                    if (! $roomIsStillAvailable) {
                        throw ValidationException::withMessages([
                            'room_id' => 'This room is no longer available.',
                        ]);
                    }
                }

                $client = Client::create($clientFields);
                $this->syncClientRoomAssignment($client);

                ClientOnboardingWorkflow::createForClient($client, $auth->id);

                // Medical profile (hasOne) — only persist when something was captured.
                $medicalFilled = collect($medical)->contains(
                    fn ($v) => is_array($v) ? count($v) > 0 : (filled($v) && $v !== false && $v !== '0')
                );
                if ($medicalFilled) {
                    $client->medicalProfile()->create([
                        'gp_name' => $medical['gp_name'] ?? null,
                        'gp_practice' => $medical['gp_practice'] ?? null,
                        'gp_phone' => $medical['gp_phone'] ?? null,
                        'hospital_preference' => $medical['hospital_preference'] ?? null,
                        'blood_type' => $medical['blood_type'] ?? null,
                        'organ_donor' => (bool) ($medical['organ_donor'] ?? false),
                        'allergies' => $medical['allergies'] ?? [],
                        'disabilities' => $medical['disabilities'] ?? [],
                        'medical_history' => $medical['medical_history'] ?? null,
                        'mental_health_history' => $medical['mental_health_history'] ?? null,
                        'surgical_history' => $medical['surgical_history'] ?? null,
                        'immunisation_notes' => $medical['immunisation_notes'] ?? null,
                        'notes' => $medical['notes'] ?? null,
                    ]);
                }

                // Diagnosed conditions (hasMany) — skip blank rows.
                foreach ($conditions as $condition) {
                    if (blank($condition['label'] ?? null)) {
                        continue;
                    }
                    $client->conditions()->create([
                        'label' => $condition['label'],
                        'severity' => $condition['severity'] ?? 'Mild',
                        'notes' => $condition['notes'] ?? null,
                    ]);
                }

                // Emergency contacts (hasMany) — skip rows with neither name nor phone.
                $order = 0;
                foreach ($emergencyContacts as $contact) {
                    $hasName = filled($contact['name'] ?? null);
                    $hasPhone = filled($contact['phone'] ?? null);
                    if (! $hasName && ! $hasPhone) {
                        continue;
                    }
                    $order++;
                    $client->emergencyContacts()->create([
                        'name' => $contact['name'] ?? '',
                        'relationship' => $contact['relationship'] ?? null,
                        'phone' => $contact['phone'] ?? null,
                        'alternate_phone' => $contact['alternate_phone'] ?? null,
                        'email' => $contact['email'] ?? null,
                        'address' => $contact['address'] ?? null,
                        'notes' => $contact['notes'] ?? null,
                        'contact_order' => $order,
                        'is_primary_contact' => $order === 1,
                        'preferred_method' => $contact['preferred_method'] ?? null,
                        'availability' => $contact['availability'] ?? null,
                        'can_view_medical' => (bool) ($contact['can_view_medical'] ?? false),
                        'can_view_medications' => (bool) ($contact['can_view_medications'] ?? false),
                        'can_view_incidents' => (bool) ($contact['can_view_incidents'] ?? false),
                        'can_receive_updates' => (bool) ($contact['can_receive_updates'] ?? true),
                        // Keep the legacy single health-info flag in step with the
                        // granular medical-info consent.
                        'authorised_health_info' => (bool) ($contact['can_view_medical'] ?? false),
                    ]);
                }

                if ($createPortalUser) {
                    $clientEmail = trim((string) ($data['email'] ?? $client->email ?? ''));
                    if ($clientEmail !== '') {
                        $name = trim($client->first_name.' '.$client->last_name);
                        $clientUser = $this->findOrCreatePortalUser($auth, $clientEmail, $name, 'client');
                        $portalMembership->link($client, $clientUser, 'client');
                        $this->sendPasswordSetupEmail($clientEmail);
                    }
                }

                return $client;
            });

            app(NotificationService::class)->notifyCrud($request->user(), 'created', 'client', $client, $client, [
                'title' => "Client created: {$client->first_name} {$client->last_name}",
                'url' => url("/clients/{$client->id}"),
            ]);

            // The Add Client wizard posts with _modal so it can show its own
            // in-dialog success pane; flash the new id so "Go to profile" works.
            if ($request->boolean('_modal')) {
                return back()
                    ->with('success', 'Client created successfully.')
                    ->with('created_client_id', $client->id);
            }

            return redirect()
                ->route('clients.index')
                ->with('success', 'Client created successfully.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withInput()
                ->with('error', 'Failed to create client: '.$e->getMessage());
        }
    }

    private function findOrCreatePortalUser(
        User $actor,
        string $email,
        string $name,
        string $roleName,
    ): User {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Portal identity publication must share the client transaction.');
        }

        $email = strtolower(trim($email));
        app(EmployeeIntakeService::class)
            ->acquireIntakeLock('email:'.$email);
        $userId = User::query()->where('email', $email)->value('id');
        $roleId = (int) Role::query()->where('name', $roleName)->value('id');
        abort_unless($roleId > 0, 404);
        $lockedUsers = app(AuthorizationEvidenceLockService::class)->lockForUsers(
            [(int) $actor->id, $userId],
            ['clients.create'],
            [$roleId],
        );
        $lockedRole = Role::query()->whereKey($roleId)->lockForUpdate()->first();
        abort_unless(
            $lockedRole instanceof Role && (string) $lockedRole->name === $roleName,
            409,
            'The requested portal role changed. Please retry.',
        );
        /** @var User|null $lockedActor */
        $lockedActor = $lockedUsers->get((int) $actor->id);
        abort_unless($lockedActor?->canDo('clients.create'), 403);
        /** @var User|null $user */
        $user = $userId ? $lockedUsers->get((int) $userId) : null;
        if ($user && strtolower(trim((string) $user->email)) !== $email) {
            throw ValidationException::withMessages([
                'email' => 'The matching account changed while this request was waiting. Please retry.',
            ]);
        }

        if (! $user) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                // Random placeholder; user sets their own password from reset email.
                'password' => Str::password(32),
                'role' => $roleName,
                'approved_at' => now(),
            ]);
        } else {
            if (! $user->approved_at) {
                $user->forceFill(['approved_at' => now()])->save();
            }
            if (empty($user->role)) {
                $user->forceFill(['role' => $roleName])->save();
            }
        }

        $user->roles()->syncWithoutDetaching([$roleId]);

        return $user;
    }

    private function sendPasswordSetupEmail(string $email): void
    {
        Password::sendResetLink(['email' => $email]);
    }

    public function edit(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $client->loadMissing([
            'medicalProfile',
            'conditions',
            'emergencyContacts',
        ]);

        $currentSiteId = is_numeric($client->site_id) ? (int) $client->site_id : null;
        $availableSiteIds = app(UserSiteAccessService::class)->accessibleSiteIds(
            $request->user(),
            ['clients.update'],
        );
        if ($currentSiteId !== null) {
            $availableSiteIds[] = $currentSiteId;
            $availableSiteIds = array_values(array_unique($availableSiteIds));
        }

        $sitesQuery = Site::query()
            ->whereIn('id', $availableSiteIds)
            ->orderBy('name');
        // Keep inactive site visible if client currently assigned to it
        $sitesQuery->where(function ($query) use ($client) {
            $query->where('is_active', true);
            if ($client->site_id) {
                $query->orWhere('id', $client->site_id);
            }
        });

        $sites = $sitesQuery
            ->with(['houseRooms' => fn ($query) => $query
                ->where('is_active', true)
                ->where('is_assignable', true)
                ->orderBy('sort_order')
                ->orderBy('name')])
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'is_active' => (bool) $site->is_active,
                'rooms' => $site->houseRooms->map(fn (SiteHouseRoom $room) => [
                    'id' => $room->id,
                    'name' => $room->name,
                    'notes' => $room->notes,
                ])->values(),
            ]);

        $serviceContextsQuery = ServiceContext::query()
            ->availableToSite($currentSiteId)
            ->orderBy('name');
        $serviceContextsQuery->where(function ($query) use ($client) {
            $query->where('is_active', true);
            if ($client->service_context_id) {
                $query->orWhere('id', $client->service_context_id);
            }
        });
        $serviceContexts = $serviceContextsQuery->get(['id', 'site_id', 'type', 'name', 'is_active']);

        $geofences = AssetGeofence::query()
            ->eligibleForClientSite(is_numeric($client->site_id) ? (int) $client->site_id : null)
            ->orderBy('name')
            ->get(['id', 'site_id', 'name']);

        $keyWorkers = app(ClientWorkerEligibility::class)
            ->query($client)
            ->orderBy('name')
            ->get(['id', 'name']);

        $payload = [
            // Keep the compact legacy key during the compatibility window, but
            // hydrate the canonical Add Client completion wizard from one full
            // round-trippable shape.
            'client' => $client->only([
                'id', 'site_id', 'room_id', 'service_context_id', 'nhi_number', 'first_name', 'last_name', 'preferred_name', 'date_of_birth', 'gender', 'status',
                'phone', 'email', 'address_line_1', 'address_line_2', 'suburb', 'city', 'postcode',
                'profile_photo_path', 'funding_type', 'funding_notes',
            ]),
            'initialValues' => $this->clientWizardInitialValues(
                $client,
                $geofences->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            ),
            'sites' => $sites,
            'serviceContexts' => $serviceContexts,
            'keyWorkers' => $keyWorkers,
            'geofences' => $geofences,
            'defaultServiceContextId' => ServiceContext::query()
                ->availableToSite($currentSiteId)
                ->whereKey(ServiceContext::defaultId())
                ->value('id'),
        ];

        // The edit form is rendered inline as a modal on the index page —
        // no standalone Inertia page exists. Return JSON when the modal
        // requests it; otherwise send users back to the client detail view.
        if ($request->wantsJson() || $request->boolean('modal')) {
            return response()->json($payload);
        }

        return redirect()->route('operations.clients.show', $client);
    }

    /** @return array<string, mixed> */
    /** @param list<int>|null $eligibleGeofenceIds */
    private function clientWizardInitialValues(Client $client, ?array $eligibleGeofenceIds = null): array
    {
        $stringId = fn (mixed $value): string => filled($value)
            ? (string) $value
            : '';
        $medical = $client->medicalProfile;

        return [
            '_modal' => true,
            'site_id' => $stringId($client->site_id),
            'room_id' => $stringId($client->room_id),
            'service_context_id' => $stringId($client->service_context_id),
            'status' => $client->status ?? 'onboarding',
            'first_name' => $client->first_name ?? '',
            'last_name' => $client->last_name ?? '',
            'preferred_name' => $client->preferred_name ?? '',
            'date_of_birth' => $client->date_of_birth?->toDateString() ?? '',
            'gender' => $client->gender ?? '',
            'preferred_pronouns' => $client->preferred_pronouns ?? '',
            'nhi_number' => $client->nhi_number ?? '',
            'phone' => $client->phone ?? '',
            'email' => $client->email ?? '',
            'address_line_1' => $client->address_line_1 ?? '',
            'address_line_2' => $client->address_line_2 ?? '',
            'suburb' => $client->suburb ?? '',
            'city' => $client->city ?? '',
            'postcode' => $client->postcode ?? '',
            'create_client_portal_user' => false,
            'ethnicity' => $client->ethnicity ?? '',
            'languages' => $client->languages ?? [],
            'religion' => $client->religion ?? '',
            'mobility_needs' => $client->mobility_needs ?? '',
            'sensory_needs' => $client->sensory_needs ?? '',
            'cognitive_needs' => $client->cognitive_needs ?? '',
            'dietary_requirements' => $client->dietary_requirements ?? '',
            'sleep_preferences' => $client->sleep_preferences ?? '',
            'transport_needs' => $client->transport_needs ?? [],
            'transport_notes' => $client->transport_notes ?? '',
            'fluid_intake_min_ml' => $stringId($client->fluid_intake_min_ml),
            'fluid_intake_max_ml' => $stringId($client->fluid_intake_max_ml),
            'seizure_duration_escalation_seconds' => $stringId($client->seizure_duration_escalation_seconds),
            'interests_hobbies' => $client->interests_hobbies ?? '',
            'strengths_abilities' => $client->strengths_abilities ?? '',
            'life_story' => $client->life_story ?? '',
            'education_level' => $client->education_level ?? '',
            'employment_status' => $client->employment_status ?? '',
            'medical' => [
                'gp_name' => $medical?->gp_name ?? '',
                'gp_practice' => $medical?->gp_practice ?? '',
                'gp_phone' => $medical?->gp_phone ?? '',
                'hospital_preference' => $medical?->hospital_preference ?? '',
                'blood_type' => $medical?->blood_type ?? '',
                'organ_donor' => (bool) ($medical?->organ_donor ?? false),
                'allergies' => $medical?->allergies ?? [],
                'disabilities' => $medical?->disabilities ?? [],
                'medical_history' => $medical?->medical_history ?? '',
                'mental_health_history' => $medical?->mental_health_history ?? '',
                'surgical_history' => $medical?->surgical_history ?? '',
                'immunisation_notes' => $medical?->immunisation_notes ?? '',
                'notes' => $medical?->notes ?? '',
            ],
            'conditions' => $client->conditions->map(fn ($condition) => [
                'label' => $condition->label ?? '',
                'severity' => $condition->severity ?? 'Mild',
                'notes' => $condition->notes ?? '',
            ])->values(),
            'service_start_date' => $client->service_start_date?->toDateString() ?? '',
            'key_worker_id' => $stringId($client->key_worker_id),
            'risk_level' => $client->risk_level ?? 'low',
            'safeguarding_flag' => (bool) $client->safeguarding_flag,
            'house_geofence_id' => $client->house_geofence_id !== null
                && ($eligibleGeofenceIds === null || in_array((int) $client->house_geofence_id, $eligibleGeofenceIds, true))
                    ? $stringId($client->house_geofence_id)
                    : '',
            'funding_type' => $client->funding_type ?? '',
            'funding_notes' => $client->funding_notes ?? '',
            'emergency_contacts' => $client->emergencyContacts->map(fn ($contact) => [
                'name' => $contact->name ?? '',
                'relationship' => $contact->relationship ?? '',
                'phone' => $contact->phone ?? '',
                'alternate_phone' => $contact->alternate_phone ?? '',
                'email' => $contact->email ?? '',
                'address' => $contact->address ?? '',
                'preferred_method' => $contact->preferred_method ?? '',
                'availability' => $contact->availability ?? '',
                'notes' => $contact->notes ?? '',
                'can_view_medical' => (bool) $contact->can_view_medical,
                'can_view_medications' => (bool) $contact->can_view_medications,
                'can_view_incidents' => (bool) $contact->can_view_incidents,
                'can_receive_updates' => (bool) $contact->can_receive_updates,
            ])->values(),
        ];
    }

    public function update(UpdateClientRequest $request, Client $client)
    {
        $this->authorize('update', $client);

        try {
            $data = $request->validated();
            $previousRoomId = $client->room_id ? (int) $client->room_id : null;
            $syncMedical = array_key_exists('medical', $data);
            $syncConditions = array_key_exists('conditions', $data);
            $syncEmergencyContacts = array_key_exists('emergency_contacts', $data);

            $medical = $data['medical'] ?? [];
            $conditions = $data['conditions'] ?? [];
            $emergencyContacts = $data['emergency_contacts'] ?? [];

            $clientFields = collect($data)->except([
                'create_client_portal_user',
                'profile_photo',
                'medical',
                'conditions',
                'emergency_contacts',
            ])->all();

            if ($request->hasFile('profile_photo')) {
                if ($client->profile_photo_path) {
                    Storage::disk('public')->delete($client->profile_photo_path);
                }

                $clientFields['profile_photo_path'] = $this->storeAvatar(
                    $request->file('profile_photo'),
                    'profile-photos/clients',
                );
            }

            DB::transaction(function () use (
                $client,
                $clientFields,
                $medical,
                $conditions,
                $emergencyContacts,
                $syncMedical,
                $syncConditions,
                $syncEmergencyContacts,
                $previousRoomId
            ) {
                $client->update($clientFields);
                $this->syncClientRoomAssignment($client->refresh(), $previousRoomId);

                if ($syncMedical) {
                    $this->syncClientMedicalProfile($client, $medical);
                }

                if ($syncConditions) {
                    $this->syncClientConditions($client, $conditions);
                }

                if ($syncEmergencyContacts) {
                    $this->syncClientEmergencyContacts($client, $emergencyContacts);
                }
            });

            app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'client', $client, $client, [
                'title' => "Client updated: {$client->first_name} {$client->last_name}",
                'url' => url("/clients/{$client->id}"),
            ]);

            // Modal submissions want to stay on the current page so the
            // dialog can close and the caller can reload fresh data.
            if ($request->boolean('_modal')) {
                return back()->with('success', 'Client updated successfully.');
            }

            return redirect()
                ->route('clients.index')
                ->with('success', 'Client updated successfully.');
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withInput()
                ->with('error', 'Failed to update client: '.$e->getMessage());
        }
    }

    private function syncClientMedicalProfile(Client $client, array $medical): void
    {
        $medicalFilled = collect($medical)->contains(
            fn ($v) => is_array($v) ? count($v) > 0 : (filled($v) && $v !== false && $v !== '0')
        );

        if (! $medicalFilled) {
            $client->medicalProfile()->delete();

            return;
        }

        $client->medicalProfile()->updateOrCreate([], [
            'gp_name' => $medical['gp_name'] ?? null,
            'gp_practice' => $medical['gp_practice'] ?? null,
            'gp_phone' => $medical['gp_phone'] ?? null,
            'hospital_preference' => $medical['hospital_preference'] ?? null,
            'blood_type' => $medical['blood_type'] ?? null,
            'organ_donor' => (bool) ($medical['organ_donor'] ?? false),
            'allergies' => $medical['allergies'] ?? [],
            'disabilities' => $medical['disabilities'] ?? [],
            'medical_history' => $medical['medical_history'] ?? null,
            'mental_health_history' => $medical['mental_health_history'] ?? null,
            'surgical_history' => $medical['surgical_history'] ?? null,
            'immunisation_notes' => $medical['immunisation_notes'] ?? null,
            'notes' => $medical['notes'] ?? null,
        ]);
    }

    private function syncClientConditions(Client $client, array $conditions): void
    {
        $client->conditions()->delete();

        foreach ($conditions as $condition) {
            if (blank($condition['label'] ?? null)) {
                continue;
            }

            $client->conditions()->create([
                'label' => $condition['label'],
                'severity' => $condition['severity'] ?? 'Mild',
                'notes' => $condition['notes'] ?? null,
            ]);
        }
    }

    private function syncClientEmergencyContacts(Client $client, array $emergencyContacts): void
    {
        $client->emergencyContacts()->delete();

        $order = 0;
        foreach ($emergencyContacts as $contact) {
            $hasName = filled($contact['name'] ?? null);
            $hasPhone = filled($contact['phone'] ?? null);
            if (! $hasName && ! $hasPhone) {
                continue;
            }

            $order++;
            $client->emergencyContacts()->create([
                'name' => $contact['name'] ?? '',
                'relationship' => $contact['relationship'] ?? null,
                'phone' => $contact['phone'] ?? null,
                'alternate_phone' => $contact['alternate_phone'] ?? null,
                'email' => $contact['email'] ?? null,
                'address' => $contact['address'] ?? null,
                'notes' => $contact['notes'] ?? null,
                'contact_order' => $order,
                'is_primary_contact' => $order === 1,
                'preferred_method' => $contact['preferred_method'] ?? null,
                'availability' => $contact['availability'] ?? null,
                'can_view_medical' => (bool) ($contact['can_view_medical'] ?? false),
                'can_view_medications' => (bool) ($contact['can_view_medications'] ?? false),
                'can_view_incidents' => (bool) ($contact['can_view_incidents'] ?? false),
                'can_receive_updates' => (bool) ($contact['can_receive_updates'] ?? true),
                'authorised_health_info' => (bool) ($contact['can_view_medical'] ?? false),
            ]);
        }
    }

    /**
     * Quick-update a single field on a client (e.g. risk_level, safeguarding_flag).
     */
    public function quickUpdate(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'risk_level' => ['nullable', 'in:low,medium,high,critical'],
            'safeguarding_flag' => ['nullable', 'boolean'],
            'key_worker_id' => [
                'bail',
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($client): void {
                    if (! app(ClientWorkerEligibility::class)->contains($client, (int) $value)) {
                        $fail('Choose a current key worker assigned to the selected Site.');
                    }
                },
            ],
        ]);

        $client->update(collect($data)
            ->filter(fn ($_value, string $key) => $request->has($key))
            ->all());

        return redirect()->back()->with('success', 'Updated.');
    }

    public function updatePhoto(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $request->validate([
            'photo' => ['required', 'image', 'max:5120'], // 5MB
        ]);

        $path = $this->storeAvatar($request->file('photo'), 'profile-photos/clients');

        if ($client->profile_photo_path) {
            Storage::disk('public')->delete($client->profile_photo_path);
        }

        $client->forceFill(['profile_photo_path' => $path])->save();

        return back()->with('success', 'Client photo updated.');
    }

    /**
     * Remove the client's profile photo.
     */
    public function destroyPhoto(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        if ($client->profile_photo_path) {
            Storage::disk('public')->delete($client->profile_photo_path);
        }

        $client->forceFill(['profile_photo_path' => null])->save();

        return back()->with('success', 'Client photo removed.');
    }

    /**
     * Upload a gallery photo for a client.
     */
    public function storeGalleryPhoto(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $request->validate([
            'photo' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp'],
            'caption' => ['nullable', 'string', 'max:500'],
            'tags' => ['nullable', 'array'],
            'visibility' => ['nullable', 'in:staff_only,family,all_portal_users'],
        ]);

        $file = $request->file('photo');
        $stored = app(ClientPhotoStorage::class)->store($file, $client);

        ClientPhoto::create([
            'client_id' => $client->id,
            'uploaded_by_user_id' => $request->user()->id,
            ...$stored,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'caption' => $request->input('caption'),
            'tags' => $request->input('tags'),
            'visibility' => $request->input('visibility', 'family'),
            'status' => 'approved',
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
        ]);

        AuditLogger::log('client.photo.upload', $client);

        return back()->with('success', 'Photo uploaded.');
    }

    /**
     * Delete a gallery photo.
     */
    public function destroyGalleryPhoto(Request $request, Client $client, ClientPhoto $photo)
    {
        $this->authorize('update', $client);
        abort_unless($photo->client_id === $client->id, 404);

        app(ClientPhotoStorage::class)->delete($photo);
        $photo->delete();

        AuditLogger::log('client.photo.delete', $client);

        return back()->with('success', 'Photo deleted.');
    }

    /**
     * Store a square-cropped avatar (center crop) and resize to 512x512.
     */
    private function storeAvatar(UploadedFile $file, string $dir): string
    {
        try {
            $data = file_get_contents($file->getRealPath());
            $src = @imagecreatefromstring($data);
            if (! $src) {
                throw new \RuntimeException('Unable to read image');
            }

            $w = imagesx($src);
            $h = imagesy($src);
            $size = min($w, $h);
            $x = (int) floor(($w - $size) / 2);
            $y = (int) floor(($h - $size) / 2);

            $crop = imagecrop($src, ['x' => $x, 'y' => $y, 'width' => $size, 'height' => $size]);
            if (! $crop) {
                $crop = $src;
            }

            $dst = imagecreatetruecolor(512, 512);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);
            imagecopyresampled($dst, $crop, 0, 0, 0, 0, 512, 512, imagesx($crop), imagesy($crop));

            ob_start();
            imagejpeg($dst, null, 85);
            $jpg = ob_get_clean();

            imagedestroy($dst);
            if ($crop !== $src) {
                imagedestroy($crop);
            }
            imagedestroy($src);

            $filename = trim($dir, '/').'/'.Str::uuid()->toString().'.jpg';
            Storage::disk('public')->put($filename, $jpg);

            return $filename;
        } catch (\Throwable $e) {
            return $file->storePublicly($dir, 'public');
        }
    }

    /**
     * Build transport data for the client transport tab.
     */
    private function buildTransportData(Client $client): array
    {
        $hasTransports = SchemaCache::hasTable('fleet_resident_transports');
        $hasOutings = SchemaCache::hasTable('fleet_outings');
        $hasMedLogs = SchemaCache::hasTable('fleet_medication_transit_logs');
        $hasIncidents = SchemaCache::hasTable('fleet_incidents');

        // Stats (30-day window)
        $thirtyDaysAgo = now()->subDays(30);

        $transportCount30d = $hasTransports
            ? FleetResidentTransport::where('resident_id', $client->id)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->count()
            : 0;

        $outingCount30d = $hasOutings
            ? FleetOutingResident::where('client_id', $client->id)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->count()
            : 0;

        $incidentCount30d = $hasIncidents && $hasTransports
            ? FleetIncident::query()
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->whereHas('booking', function ($q) use ($client) {
                    $q->whereHas('transports', fn ($tq) => $tq->where('resident_id', $client->id));
                })
                ->count()
            : 0;

        // Upcoming outings
        $upcomingOutings = $hasOutings
            ? FleetOuting::query()
                ->whereHas('residents', fn ($q) => $q->where('client_id', $client->id))
                ->whereIn('status', ['planned', 'active'])
                ->with(['asset:id,name', 'driver:id,name'])
                ->withCount('residents')
                ->orderBy('planned_departure')
                ->limit(5)
                ->get()
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'title' => $o->title,
                    'destination' => $o->destination,
                    'status' => $o->status,
                    'planned_departure' => optional($o->planned_departure)->toISOString(),
                    'planned_return' => optional($o->planned_return)->toISOString(),
                    'vehicle' => $o->asset ? ['id' => $o->asset->id, 'name' => $o->asset->name] : null,
                    'driver' => $o->driver ? ['id' => $o->driver->id, 'name' => $o->driver->name] : null,
                    'residents_count' => $o->residents_count,
                ])
                ->values()
            : collect();

        // Transport history (paginated)
        $transports = $hasTransports
            ? FleetResidentTransport::query()
                ->where('resident_id', $client->id)
                ->with(['asset:id,name,asset_tag', 'driver:id,name', 'shift:id,starts_at,ends_at,shift_type'])
                ->latest('departed_at')
                ->limit(20)
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'transport_type' => $t->transport_type,
                    'pickup_location' => $t->pickup_location,
                    'dropoff_location' => $t->dropoff_location,
                    'departed_at' => optional($t->departed_at)->toISOString(),
                    'arrived_at' => optional($t->arrived_at)->toISOString(),
                    'duration_minutes' => $t->duration_minutes,
                    'status' => $t->status,
                    'vehicle' => $t->asset ? ['id' => $t->asset->id, 'name' => $t->asset->name] : null,
                    'driver' => $t->driver ? ['id' => $t->driver->id, 'name' => $t->driver->name] : null,
                    'shift' => $t->shift ? [
                        'id' => $t->shift->id,
                        'starts_at' => optional($t->shift->starts_at)->toISOString(),
                        'shift_type' => $t->shift->shift_type ?? 'standard',
                    ] : null,
                ])
                ->values()
            : collect();

        // Medication transit logs
        $medicationLogs = $hasMedLogs
            ? FleetMedicationTransitLog::query()
                ->where('client_id', $client->id)
                ->with(['packedBy:id,name', 'administeredBy:id,name', 'witnessedBy:id,name'])
                ->latest('packed_at')
                ->limit(20)
                ->get()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'medication_name' => $m->medication_name,
                    'is_controlled_drug' => $m->is_controlled_drug,
                    'packed_at' => optional($m->packed_at)->toISOString(),
                    'packed_by' => $m->packedBy ? $m->packedBy->name : null,
                    'administered_at' => optional($m->administered_at)->toISOString(),
                    'administered_by' => $m->administeredBy ? $m->administeredBy->name : null,
                    'witnessed_by' => $m->witnessedBy ? $m->witnessedBy->name : null,
                    'returned_to_house_at' => optional($m->returned_to_house_at)->toISOString(),
                    'status' => $m->status,
                ])
                ->values()
            : collect();

        // Client-scoped transport bookings (Book transport workflow)
        $bookings = SchemaCache::hasTable('client_transport_bookings')
            ? ClientTransportBooking::query()
                ->where('client_id', $client->id)
                ->whereIn('status', ['requested', 'confirmed'])
                ->with('driver:id,name')
                ->orderBy('scheduled_at')
                ->limit(20)
                ->get()
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'purpose' => $b->purpose,
                    'destination' => $b->destination,
                    'scheduled_at' => optional($b->scheduled_at)->toISOString(),
                    'vehicle' => $b->vehicle,
                    'driver' => $b->driver ? ['id' => $b->driver->id, 'name' => $b->driver->name] : null,
                    'escort_required' => (bool) $b->escort_required,
                    'return_trip' => (bool) $b->return_trip,
                    'status' => $b->status,
                    'notes' => $b->notes,
                ])
                ->values()
            : collect();

        return [
            'stats' => [
                'transports_30d' => $transportCount30d,
                'outings_30d' => $outingCount30d,
                'incidents_30d' => $incidentCount30d,
            ],
            'upcoming_outings' => $upcomingOutings,
            'transport_history' => $transports,
            'medication_logs' => $medicationLogs,
            'bookings' => $bookings,
        ];
    }

    private function canViewClientLocation(?User $user, Client $client): bool
    {
        return $user !== null
            && (bool) app(ClientProfileSectionAccess::class)->for($user, $client)['tracking'];
    }

    private function canManageClientTrackers(?User $user): bool
    {
        return (bool) ($user?->canDo('fleet.manage') || $user?->canDo('assets.trackers.manage'));
    }

    private function buildLocationData(Client $client, bool $canManageTrackers, User $viewer): array
    {
        $assignment = app(PersonalTrackingPrivacyService::class)
            ->authorisedClientAssignment($client);
        $trackingConsent = $assignment?->consent;
        $privacyStatusUrl = route(
            'operations.clients.location.privacy-status',
            ['client' => $client->id],
            false,
        );

        if (! $trackingConsent) {
            return [
                'trackingRestricted' => true,
                'canManage' => false,
                'tracker' => null,
                'currentLocation' => null,
                'trackingConsent' => null,
                'geofences' => [],
                'geofenceStatus' => GeofenceStatusService::STATUS_UNKNOWN,
                'privacyStatusUrl' => $privacyStatusUrl,
                'exportUrl' => null,
                'canExport' => false,
                'retentionDays' => null,
            ];
        }

        if (! SchemaCache::hasTable('devices')) {
            return [
                'trackingRestricted' => false,
                'canManage' => $canManageTrackers,
                'tracker' => null,
                'currentLocation' => null,
                'trackingConsent' => null,
                'geofences' => [],
                'privacyStatusUrl' => $privacyStatusUrl,
                'exportUrl' => null,
                'canExport' => false,
                'retentionDays' => (int) $assignment->retention_days,
            ];
        }

        $device = $assignment->device;

        $trackerInfo = null;
        $currentLocation = null;

        if ($device) {
            $canonicalDeviceAvailable = $viewer->canDo('securityDevices.viewAny')
                && $viewer->canDo('securityDevices.devices.view')
                && app(SecurityDevicesAccessService::class)
                    ->visibleDevices($viewer)
                    ->whereKey($device->id)
                    ->exists();
            $canonicalDetailUrl = $canonicalDeviceAvailable
                ? "/security-devices/devices/{$device->id}"
                : null;
            $meta = $device->meta ?? [];
            $lat = $device->latitude ?? $meta['lat'] ?? $meta['latitude'] ?? null;
            $lng = $device->longitude ?? $meta['lng'] ?? $meta['longitude'] ?? null;
            $address = $lat !== null && $lng !== null ? $this->latestAddressForDevice($device) : null;
            $coordinates = $this->formatCoordinates($lat, $lng);
            $rawBattery = $device->battery_level ?? $meta['battery'] ?? $meta['battery_level'] ?? null;
            $battery = $rawBattery === null ? null : (int) $rawBattery;
            $batteryThreshold = (int) ($meta['battery_low_threshold'] ?? 20);
            $batteryStatus = $meta['battery_status'] ?? (
                $battery === null ? 'unknown' : ((float) $battery <= $batteryThreshold ? 'low' : 'normal')
            );

            $trackerInfo = [
                'id' => $device->id,
                'device_uid' => $device->device_uid,
                'name' => $device->name,
                'serial' => $device->serial_number,
                'mac' => $device->mac_address,
                'imei' => $device->imei,
                'model' => $device->model,
                'manufacturer' => $device->manufacturer,
                'firmware_version' => $device->firmware_version,
                'hardware_version' => $meta['hardware_version'] ?? null,
                'ble_firmware' => $meta['ble_firmware'] ?? null,
                'ble_mac' => $meta['ble_mac'] ?? null,
                'sim_iccid' => $meta['iccid'] ?? null,
                'imsi' => $meta['imsi'] ?? null,
                'network_type' => $meta['network_type'] ?? null,
                'rsrp' => $meta['rsrp'] ?? null,
                'band' => $meta['band'] ?? null,
                'mcc' => $meta['mcc'] ?? null,
                'mnc' => $meta['mnc'] ?? null,
                'cell_id' => $meta['cell_id'] ?? null,
                'lac' => $meta['lac'] ?? null,
                'satellites' => $meta['satellites'] ?? null,
                'last_frame_at' => $meta['last_frame_at'] ?? null,
                'last_location_at' => $meta['last_location_at'] ?? null,
                'config_snapshot' => $meta['config_snapshot'] ?? null,
                'provider' => $device->provider,
                'status' => $device->status?->value ?? 'unknown',
                'health_status' => $device->health_status?->value ?? 'unknown',
                'last_seen_at' => $device->last_seen_at?->toISOString(),
                'battery' => $battery,
                'battery_status' => $batteryStatus,
                'battery_voltage_mv' => $meta['battery_voltage_mv'] ?? null,
                'battery_low_threshold' => $batteryThreshold,
                'battery_updated_at' => optional($device->battery_updated_at)->toISOString(),
                'charging_status' => $meta['charging_status'] ?? null,
                'external_power' => $this->isTruthy($meta['external_power'] ?? false),
                'last_power_event' => $meta['power_event'] ?? null,
                'last_safety_event' => $meta['last_safety_event'] ?? null,
                'last_safety_event_at' => $meta['last_safety_event_at'] ?? null,
                'panic_active' => (bool) ($meta['panic_active'] ?? false),
                ...($canManageTrackers ? [
                    'locate_now_url' => route('operations.clients.location.locate-now', ['client' => $client->id], false),
                    'acknowledge_panic_url' => route('operations.clients.location.acknowledge-panic', ['client' => $client->id], false),
                ] : []),
                'fleet_dashboard_url' => '/fleet-assets/resident-tracking?focus='.$client->id,
                'history_url' => "/fleet-assets/resident-tracking/history/{$client->id}",
                'tracking_workspace_url' => $canonicalDeviceAvailable
                    ? '/security-devices/tracking?tab=personal-safety'
                    : null,
                'tracking_workspace_access' => [
                    'state' => $canonicalDeviceAvailable ? 'available' : 'restricted',
                    'label' => $canonicalDeviceAvailable
                        ? 'Open Tracking workspace'
                        : 'Tracking workspace access required',
                ],
                'last_command_status' => QueclinkPendingCommand::query()
                    ->where('command_word', 'GTRTO')
                    ->whereHas('device', fn ($query) => $query->where('device_id', $device->id))
                    ->latest()
                    ->value('status'),
                'detail_url' => $canonicalDetailUrl,
                'detail_access' => [
                    'state' => $canonicalDeviceAvailable ? 'available' : 'restricted',
                    'label' => $canonicalDeviceAvailable
                        ? 'Open Device Profile'
                        : 'Device Profile access required',
                ],
            ];

            if ($lat !== null && $lng !== null) {
                $currentLocation = [
                    'lat' => (float) $lat,
                    'lng' => (float) $lng,
                    'address' => $address,
                    'coordinates' => $coordinates,
                    'display_location' => $address ?: $coordinates,
                    'speed' => $meta['speed'] ?? null,
                    'heading' => $meta['heading'] ?? null,
                    'accuracy' => $meta['accuracy'] ?? null,
                    'altitude' => $meta['altitude'] ?? null,
                ];
            }
        }

        // Only the resident's explicitly linked or same-site house geofence.
        // A client without a site must never inherit the first global fence.
        $geofences = [];
        $houseGeofence = null;
        try {
            if (SchemaCache::hasTable('asset_geofences') && is_numeric($client->site_id)) {
                if ($client->house_geofence_id) {
                    $houseGeofence = AssetGeofence::query()
                        ->whereKey($client->house_geofence_id)
                        ->where('site_id', (int) $client->site_id)
                        ->where('is_active', true)
                        ->where(function ($q) {
                            $q->where('scope', 'house')->orWhere('scope', 'resident');
                        })
                        ->first();
                }

                if (! $houseGeofence && $client->site_id) {
                    $houseGeofence = AssetGeofence::query()
                        ->where('site_id', $client->site_id)
                        ->where('is_active', true)
                        ->where(function ($q) {
                            $q->where('scope', 'house')->orWhere('scope', 'resident');
                        })
                        ->orderByRaw("CASE scope WHEN 'house' THEN 0 WHEN 'resident' THEN 1 ELSE 2 END")
                        ->first();
                }
            }

            if ($houseGeofence) {
                $geofences = [$this->serialiseGeofence($houseGeofence)];
            }
        } catch (\Throwable $e) {
            $geofences = [];
        }

        $geofenceStatus = $currentLocation
            ? app(GeofenceStatusService::class)->evaluate(
                $currentLocation['lat'],
                $currentLocation['lng'],
                $houseGeofence,
            )
            : GeofenceStatusService::STATUS_UNKNOWN;

        return [
            'trackingRestricted' => false,
            'canManage' => $canManageTrackers,
            'tracker' => $trackerInfo,
            'currentLocation' => $currentLocation,
            'trackingConsent' => [
                'status' => $trackingConsent->status,
                'given_at' => optional($trackingConsent->given_at)->toISOString(),
                'expires_at' => optional($trackingConsent->expires_at)->toISOString(),
            ],
            'geofences' => $geofences,
            'geofenceStatus' => $geofenceStatus,
            'privacyStatusUrl' => $privacyStatusUrl,
            'exportUrl' => route(
                'operations.clients.location.export',
                ['client' => $client->id],
                false,
            ),
            'canExport' => (bool) auth()->user()?->canDo('assets.telemetry.export'),
            'retentionDays' => (int) $assignment->retention_days,
        ];
    }

    private function serialiseGeofence($gf): ?array
    {
        if (! $gf) {
            return null;
        }

        $shape = $gf->shape ?? [];
        $result = [
            'id' => (string) $gf->id,
            'name' => $gf->name,
            'type' => $gf->type ?? 'circle',
            'scope' => $gf->scope,
            'color' => $shape['color'] ?? '#8b5cf6',
        ];

        if ($gf->type === 'circle') {
            $result['center'] = [
                'lat' => $shape['lat'] ?? $shape['latitude'] ?? 0,
                'lng' => $shape['lng'] ?? $shape['lon'] ?? $shape['longitude'] ?? 0,
            ];
            $result['radius_m'] = $shape['radius_m'] ?? $shape['radius'] ?? 100;
        } elseif ($gf->type === 'polygon') {
            $points = $shape['coordinates'] ?? $shape['points'] ?? [];
            $result['coordinates'] = collect($points)->map(fn ($p) => [
                'lat' => $p['lat'] ?? $p['latitude'] ?? 0,
                'lng' => $p['lng'] ?? $p['lon'] ?? $p['longitude'] ?? 0,
            ])->toArray();
        }

        return $result;
    }

    /**
     * Return location history for a client's personal tracker (JSON).
     * Reads device identity from the canonical registry and prefers
     * integration_events.canonical_device_id for history. A narrow legacy
     * fallback remains for unmapped historical rows during the transition.
     */
    public function locationHistory(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($this->canViewClientLocation($request->user(), $client), 403);
        $assignment = app(PersonalTrackingPrivacyService::class)
            ->authorisedClientAssignment($client);
        abort_unless($assignment, 403);

        $locations = app(IntegrationEventHistoryService::class)
            ->forDevice(
                $assignment->device,
                $request->only(['date_from', 'date_to']),
                false,
                $assignment->retention_days,
            );

        return response()->json(['locations' => $locations])
            ->withHeaders($this->privateLocationHeaders());
    }

    public function locationPrivacyStatus(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($this->canViewClientLocation($request->user(), $client), 403);
        $assignment = app(PersonalTrackingPrivacyService::class)
            ->authorisedClientAssignment($client);

        return response()->json([
            'active' => $assignment !== null,
            'checked_at' => now()->toISOString(),
            'retention_days' => $assignment?->retention_days,
            'export_allowed' => $assignment !== null
                && $request->user()->canDo('assets.telemetry.export'),
        ])->withHeaders($this->privateLocationHeaders());
    }

    public function exportLocationHistory(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($this->canViewClientLocation($request->user(), $client), 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'date_from' => ['required', 'date', 'before_or_equal:today'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from', 'before_or_equal:today'],
            'event_types' => ['sometimes', 'array', 'max:20'],
            'event_types.*' => ['string', 'max:100'],
        ]);

        return app(PersonalTrackingLocationExportService::class)
            ->export($client, $request->user(), $data);
    }

    private function privateLocationHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Vary' => 'Cookie',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    public function locateNow(Request $request, Client $client, LocateNowService $locateNow)
    {
        $this->authorize('view', $client);
        abort_unless($this->canManageClientTrackers($request->user()), 403);
        $assignment = app(PersonalTrackingPrivacyService::class)
            ->authorisedClientAssignment($client);
        abort_unless($assignment, 403);
        $device = $assignment->device;

        if (! $device) {
            throw ValidationException::withMessages([
                'tracker' => 'This client does not have a paired Queclink tracker.',
            ]);
        }

        $managementUrl = $locateNow->managementUrlForDevice($device);

        return redirect()->to($managementUrl)->with(
            'success',
            'Review the governed location refresh, confirm your identity, and record the operational reason before dispatch.',
        );
    }

    public function acknowledgePanic(
        Request $request,
        Client $client,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $user = $request->user();
        abort_unless($user, 403);
        $this->authorize('view', $client);
        abort_unless($this->canManageClientTrackers($user), 403);
        $assignment = app(PersonalTrackingPrivacyService::class)
            ->authorisedClientAssignment($client);
        abort_unless($assignment, 403);
        $device = $assignment->device;

        if ($device) {
            $meta = $device->meta ?? [];
            $meta['panic_active'] = false;
            $meta['panic_acknowledged_at'] = now()->toISOString();
            $meta['panic_acknowledged_by'] = $user->id;
            $device->forceFill(['meta' => $meta])->save();
        }

        if (SchemaCache::hasTable('control_room_alerts')) {
            $alerts = ControlRoomAlert::query()
                ->where('client_id', $client->id)
                ->whereIn('source', ['tracker', 'resident_tracker'])
                ->where('status', ControlRoomAlert::STATUS_OPEN)
                ->get();

            foreach ($alerts as $alert) {
                try {
                    $lifecycle->acknowledge($alert, $user);
                } catch (InvalidArgumentException) {
                    // A concurrent operator may have already moved this alert.
                    // Leave its newer lifecycle state intact.
                }
            }
        }

        return back()->with('success', 'Panic acknowledged.');
    }

    private function isTruthy(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'true'
            || $value === 'yes';
    }

    private function latestAddressForDevice(Device $device): ?string
    {
        return FleetTelemetryEvent::query()
            ->where('device_id', $device->id)
            ->where('consent_blocked', false)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNotNull('address')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('address');
    }

    private function formatCoordinates(mixed $lat, mixed $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return sprintf('%.6f, %.6f', (float) $lat, (float) $lng);
    }
}
