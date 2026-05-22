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
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
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
use App\Models\ClientFluidEntry;
use App\Models\ClientFund;
use App\Models\ClientFundTransaction;
use App\Models\ClientIncident;
use App\Models\ClientLeaveRequest;
use App\Models\ClientLedgerEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientNote;
use App\Models\ClientOnboardingWorkflow;
use App\Models\ClientPathPlan;
use App\Models\ClientPersonalAsset;
use App\Models\ClientPhoto;
use App\Models\ClientRisk;
use App\Models\ClientRoutine;
use App\Models\ClientSeizureEntry;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\ControlRoomAlert;
use App\Models\FamilyNote;
use App\Models\FamilyVisitRequest;
use App\Models\FleetIncident;
use App\Models\FleetMedicationTransitLog;
use App\Models\FleetOuting;
use App\Models\FleetOutingResident;
use App\Models\FleetResidentTransport;
use App\Models\FleetTelemetryEvent;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationReview;
use App\Models\ProgressNote;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\Role;
use App\Models\ServiceAgreement;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftSeries;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Client\ActionsAggregator;
use App\Services\Client\BehaviourPatternsService;
use App\Services\ConsentValidationService;
use App\Services\HealthSafety\HsModuleSummaryService;
use App\Services\Integration\IntegrationEventHistoryService;
use App\Services\NotificationService;
use App\Services\Queclink\LocateNowService;
use App\Services\ShiftCoverageService;
use App\Services\Tracking\GeofenceStatusService;
use App\Support\ClientSafetyPayload;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Client::class);

        $user = auth()->user();

        $clients = Client::query()
            ->when(
                $user->hasRole('support_worker') && ! $user->hasRole('admin', 'manager', 'coordinator'),
                fn ($q) => $q->whereHas('supportWorkers', fn ($q) => $q->whereKey($user->id))
            )
            ->with([
                'site:id,name,is_active',
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
            ])
            ->orderBy('last_name')
            ->get([
                'id',
                'site_id',
                'nhi_number',
                'first_name',
                'last_name',
                'status',
                'date_of_birth',
                'phone',
                'email',
                'address_line_1',
                'city',
                'postcode',
                'profile_photo_path',
                'risk_level',
                'safeguarding_flag',
            ]);

        $clients = $clients->map(function (Client $c) {
            $summary = $this->buildOnboardingSummaryFromCounts($c);
            $hasRespite = ((int) ($c->respite_bookings_count ?? 0) + (int) ($c->respite_booking_requests_count ?? 0)) > 0;

            return [
                'id' => $c->id,
                'nhi_number' => $c->nhi_number,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'profile_photo_url' => $c->profile_photo_url,
                'avatar' => $c->avatar,
                'status' => $c->status,
                'site' => $c->site ? ['id' => $c->site->id, 'name' => $c->site->name] : null,
                'onboarding' => $summary,
                'has_respite' => $hasRespite,
                'safety' => ClientSafetyPayload::summaryForClient($c),
            ];
        })->values();

        return inertia('operations/clients/index', [
            'clients' => $clients,
        ]);
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

    public function show(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        AuditLogger::log('clients.view', $client);

        $client->load([
            'site:id,name',
            'serviceContext:id,type,name',
            'supportWorkers:id,name,email',
            'medicalProfile',
            'medications',
            'conditions',
            'emergencyContacts',
            'portalUsers:id,name,email',
            'supportPlan',
            'assessments',
            'onboardingOverrides',
            'onboardingWorkflow.steps',
            'risks',
        ]);

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
                    'support_workers' => $client->supportWorkers->map(fn ($u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                    ])->values(),
                ],
            ]);
        }

        // Check for expired consents and pass to frontend
        $expiredConsents = ConsentValidationService::getExpiredConsents($client);
        $missingMandatory = ConsentValidationService::getMissingMandatoryConsents($client);

        $nextShift = Shift::query()
            ->where('client_id', $client->id)
            ->where('starts_at', '>=', now())
            ->whereNotIn('status', ['cancelled'])
            ->with(['staff:id,name,email', 'serviceContext:id,name,type'])
            ->withCount([
                'tasks',
                'tasks as incomplete_tasks_count' => fn ($query) => $query->where('is_completed', false),
                'formSubmissions',
                'medicationAdministrations',
                'timesheets',
                'outgoingHandovers',
                'incomingHandovers',
                'residentTransports',
            ])
            ->orderBy('starts_at')
            ->first();

        $lastShift = Shift::query()
            ->where('client_id', $client->id)
            ->where('starts_at', '<', now())
            ->whereNotIn('status', ['cancelled'])
            ->with(['staff:id,name,email', 'serviceContext:id,name,type'])
            ->withCount([
                'tasks',
                'tasks as incomplete_tasks_count' => fn ($query) => $query->where('is_completed', false),
                'formSubmissions',
                'medicationAdministrations',
                'timesheets',
                'outgoingHandovers',
                'incomingHandovers',
                'residentTransports',
            ])
            ->orderByDesc('starts_at')
            ->first();

        $recurringShiftSeries = ShiftSeries::query()
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
            ->get();

        $documents = ClientDocument::query()
            ->where('client_id', $client->id)
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'category', 'folder', 'version', 'effective_date', 'expiry_date', 'portal_visible', 'notes', 'original_name', 'mime_type', 'size_bytes', 'created_at']);

        $events = TimelineEvent::query()
            ->where('client_id', $client->id)
            ->orderByDesc('occurred_at')
            ->limit(80)
            ->with([
                'actor:id,name',
                'site:id,name',
                'comments' => fn ($q) => $q->whereNull('parent_id')->with(['user:id,name,role', 'replies' => fn ($r) => $r->with('user:id,name,role')->orderBy('created_at'), 'replies.likes', 'likes'])->orderBy('created_at'),
                'reactions',
            ])
            ->get();

        $handover = TimelineEvent::query()
            ->where('client_id', $client->id)
            ->where('type', 'handover')
            ->where('is_pinned', true)
            ->orderByDesc('occurred_at')
            ->limit(5)
            ->with(['actor:id,name'])
            ->get();

        $siteCoverageSummary = null;
        if ($client->site_id) {
            $siteCoverageSummary = collect(app(ShiftCoverageService::class)->buildSiteSummaries(
                now()->copy()->startOfDay(),
                now()->copy()->addDays(14)->endOfDay(),
                $client->site_id,
            ))->first();
        }

        $dailyNotes = ClientNote::query()
            ->where('client_id', $client->id)
            ->forUser($request->user())
            ->dailyNotes()
            ->with(['author:id,name', 'reviewer:id,name', 'shift:id,starts_at,ends_at'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $communicationNotes = ClientNote::query()
            ->where('client_id', $client->id)
            ->forUser($request->user())
            ->communication()
            ->with(['author:id,name', 'reviewer:id,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $dailyNotesBase = ClientNote::query()
            ->where('client_id', $client->id)
            ->forUser($request->user());

        $actionsReviews = app(ActionsAggregator::class)->forClient($client, $request->user());

        return inertia('operations/clients/show', [
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
                'funding_type' => $client->funding_type,
                'funding_notes' => $client->funding_notes,
                'site' => $client->site ? ['id' => $client->site->id, 'name' => $client->site->name] : null,
                'service_context' => $client->serviceContext ? [
                    'id' => $client->serviceContext->id,
                    'type' => $client->serviceContext->type?->value,
                    'name' => $client->serviceContext->name,
                ] : null,
                'support_workers' => $client->supportWorkers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values(),
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
                // Transport
                'transport_needs' => $client->transport_needs,
                'transport_notes' => $client->transport_notes,
            ],
            'medical' => [
                'profile' => $client->medicalProfile,
                'medications' => $client->medications,
                'conditions' => $client->conditions,
                'emergency_contacts' => $client->emergencyContacts,
            ],
            'next_of_kins' => $client->nextOfKins()
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
                ->values(),
            'audit_history' => $request->user()?->canDo('audit.viewClient')
                || $request->user()?->canDo('clients.update')
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
            'behaviour_patterns' => app(BehaviourPatternsService::class)
                ->forClient($client, $request->user()),
            'path_plan' => ClientPathPlan::query()
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
                ]),
            'leave_excursions' => [
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
            ],
            'client_finance' => [
                'funds' => ClientFund::query()
                    ->where('client_id', $client->id)
                    ->orderBy('fund_name')
                    ->get()
                    ->map(fn ($fund) => [
                        'id' => $fund->id,
                        'name' => $fund->fund_name,
                        'type' => $fund->fund_type,
                        'balance' => (float) $fund->balance,
                        'low_balance_threshold' => $fund->low_balance_threshold
                            ? (float) $fund->low_balance_threshold
                            : null,
                        'is_active' => (bool) $fund->is_active,
                        'notes' => $fund->notes,
                    ])
                    ->values(),
                'recent_transactions' => ClientFundTransaction::query()
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
                'ledger_entries' => ClientLedgerEntry::query()
                    ->where('client_id', $client->id)
                    ->orderByDesc('entry_date')
                    ->limit(25)
                    ->get()
                    ->map(fn ($entry) => [
                        'id' => $entry->id,
                        'type' => $entry->type,
                        'category' => $entry->category,
                        'direction' => $entry->direction,
                        'amount' => (float) $entry->amount,
                        'description' => $entry->description,
                        'entry_date' => $entry->entry_date?->toDateString(),
                        'approved_at' => $entry->approved_at?->toISOString(),
                    ])
                    ->values(),
                // Purchase requests + financial discrepancies are tracked as
                // Phase 2 follow-ups. For now surface empty arrays so the tab
                // can render the sections with a "no items yet" state.
                'purchase_requests' => [],
                'discrepancies' => [],
            ],
            'support_plan' => $client->supportPlan,
            'assessments' => $client->assessments
                ->sortByDesc(fn ($a) => $a->assessed_at ?? $a->created_at)
                ->values(),
            'documents' => $documents,
            'portal_users' => $client->portalUsers->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'relation' => $u->pivot?->relation,
            ])->values(),
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
            'shifts_summary' => [
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
            ],
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
            'onboarding' => [
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
            ],
            // Progress notes for client (last 20)
            'client_progress_notes' => ProgressNote::where('client_id', $client->id)
                ->with(['author:id,name', 'goal:id,title'])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(),
            'client_daily_notes' => ClientDailyNoteResource::collection($dailyNotes)->resolve($request),
            'communication_notes' => ClientDailyNoteResource::collection($communicationNotes)->resolve($request),
            'daily_notes_summary' => [
                'total' => (clone $dailyNotesBase)->dailyNotes()->count(),
                'flagged_open' => (clone $dailyNotesBase)->dailyNotes()->reviewQueue()->count(),
                'drafts' => (clone $dailyNotesBase)->dailyNotes()->where('is_draft', true)->count(),
                'communication' => (clone $dailyNotesBase)->communication()->count(),
                'open_follow_ups' => (clone $dailyNotesBase)
                    ->whereNotNull('follow_up_action')
                    ->whereNull('follow_up_completed_at')
                    ->count(),
            ],
            'health_monitoring' => [
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
            ],
            'client_routines' => ClientRoutine::query()
                ->where('client_id', $client->id)
                ->with('updater:id,name')
                ->orderBy('display_order')
                ->get(),
            'actions_reviews' => $actionsReviews,
            'actions_reviews_summary' => [
                'open' => count($actionsReviews),
                'critical' => collect($actionsReviews)->where('severity', 'critical')->count(),
                'warning' => collect($actionsReviews)->where('severity', 'warning')->count(),
            ],

            // Service agreements
            'client_agreements' => ServiceAgreement::where('client_id', $client->id)
                ->orderByDesc('created_at')
                ->get(),

            // Risks (active first, inactive shown below for management)
            'client_risks' => ClientRisk::where('client_id', $client->id)
                ->orderByDesc('active')
                ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
                ->orderBy('label')
                ->limit(50)
                ->get(),

            // Recent incidents (last 5)
            'client_incidents' => ClientIncident::where('client_id', $client->id)
                ->with(['reporter:id,name'])
                ->orderByDesc('occurred_at')
                ->limit(5)
                ->get(),

            'care_plans_summary' => [
                'active_plan' => CarePlan::where('client_id', $client->id)
                    ->where('status', 'active')
                    ->withCount(['goals', 'goals as goals_completed' => fn ($q) => $q->where('status', 'completed')])
                    ->with('goals:id,care_plan_id,title,status,progress_percentage,priority')
                    ->first(),
                'total_plans' => CarePlan::where('client_id', $client->id)->count(),
                'review_due' => CarePlan::where('client_id', $client->id)
                    ->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('next_review_at')->orWhere('next_review_at', '<=', now());
                    })->exists(),
                'recent_notes' => ProgressNote::where('client_id', $client->id)
                    ->with(['author:id,name', 'goal:id,title'])
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get(),
            ],
            'respite' => [
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
            ],
            'consents' => ClientConsent::where('client_id', $client->id)
                ->with('consentType:id,name,category')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'consent_type' => $c->consentType?->name ?? 'Unknown',
                    'consent_type_category' => $c->consentType?->category,
                    'status' => $c->status,
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
                ]),
            'health_summary' => $this->buildHealthSummary($client),
            'can' => [
                'edit' => $request->user()?->canDo('clients.update') ?? false,
                'assign_workers' => $request->user()?->canDo('clients.assignments.update') ?? false,
                'create_note' => $request->user()?->canDo('timeline.create') ?? false,
                'pin_handover' => $request->user()?->canDo('timeline.pin') ?? false,
                'manage_onboarding' => $request->user()?->canDo('clients.onboarding.manage') ?? false,
                'create_shift' => $request->user()?->canDo('shifts.create') ?? false,
                'manage_onboarding_workflow' => $request->user()?->canDo('onboarding.edit') ?? false,
                'record_observation' => $request->user()?->canDo('clinical.observations.record') ?? false,
                'record_clinical_observation' => $request->user()?->canDo('clinical.observations.recordClinical') ?? false,
                'record_event' => $request->user()?->canDo('clinical.events.record') ?? false,
                'manage_risks' => ($request->user()?->canDo('risks.create') ?? false)
                    || ($request->user()?->canDo('risks.update') ?? false),
                'create_risks' => $request->user()?->canDo('risks.create') ?? false,
                'update_risks' => $request->user()?->canDo('risks.update') ?? false,
                'delete_risks' => $request->user()?->canDo('risks.delete') ?? false,
            ],
            'pending_visit_count' => FamilyVisitRequest::where('client_id', $client->id)->where('status', 'pending')->count(),
            'pending_consent_requests_count' => $this->buildPendingConsentRequestCount($client),
            'family_notes_open_count' => FamilyNote::where('client_id', $client->id)->whereIn('status', ['open', 'in_progress'])->count(),
            'family_notes' => FamilyNote::where('client_id', $client->id)
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
                ]),
            'photos' => ClientPhoto::where('client_id', $client->id)
                ->with('uploadedBy:id,name')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'url' => $p->url,
                    'thumbnail_url' => $p->thumbnail_url,
                    'caption' => $p->caption,
                    'tags' => $p->tags,
                    'visibility' => $p->visibility,
                    'status' => $p->status,
                    'original_name' => $p->original_name,
                    'uploaded_by' => $p->uploadedBy?->name,
                    'created_at' => $p->created_at?->toISOString(),
                ])->values(),
            'personal_assets' => ClientPersonalAsset::where('client_id', $client->id)
                ->with(['recordedBy:id,name', 'site:id,name', 'room:id,site_id,name', 'tracker'])
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'category' => $a->category,
                    'description' => $a->description,
                    'serial_number' => $a->serial_number,
                    'estimated_value' => $a->estimated_value,
                    'condition' => $a->condition,
                    'location' => $a->location,
                    'site_id' => $a->site_id,
                    'site_name' => $a->site?->name,
                    'room_id' => $a->room_id,
                    'room_name' => $a->room?->name,
                    'tracker_hardware_id' => $a->tracker_hardware_id,
                    'tracker' => $a->tracker ? [
                        'id' => $a->tracker->id,
                        'name' => $a->tracker->name,
                        'status' => $a->tracker->status,
                        'last_seen_at' => $a->tracker->last_seen_at?->toISOString(),
                        'battery' => $a->tracker->meta['battery'] ?? null,
                        'lat' => $a->tracker->meta['lat'] ?? null,
                        'lng' => $a->tracker->meta['lng'] ?? null,
                        'speed' => $a->tracker->meta['speed'] ?? null,
                    ] : null,
                    'photo_url' => $a->photo_url,
                    'acquired_at' => $a->acquired_at?->toDateString(),
                    'notes' => $a->notes,
                    'status' => $a->status,
                    'ownership' => $a->ownership,
                    'funding_source' => $a->funding_source,
                    'return_required' => $a->return_required,
                    'return_by' => $a->return_by?->toDateString(),
                    'last_serviced_at' => $a->last_serviced_at?->toDateString(),
                    'next_service_due' => $a->next_service_due?->toDateString(),
                    'service_provider' => $a->service_provider,
                    'warranty_expires_at' => $a->warranty_expires_at?->toDateString(),
                    'insurance_reference' => $a->insurance_reference,
                    'disposed_at' => $a->disposed_at?->toDateString(),
                    'disposal_reason' => $a->disposal_reason,
                    'portal_visible' => $a->portal_visible,
                    'is_service_overdue' => $a->isServiceOverdue(),
                    'is_warranty_expired' => $a->isWarrantyExpired(),
                    'is_warranty_expiring_soon' => $a->isWarrantyExpiringSoon(),
                    'is_return_overdue' => $a->isReturnOverdue(),
                    'recorded_by' => $a->recordedBy?->name,
                    'created_at' => $a->created_at?->toISOString(),
                ])->values(),
            'asset_locations' => Site::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'type' => $s->type,
                    'rooms' => $s->houseRooms()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name'])->map(fn ($r) => [
                        'id' => $r->id,
                        'name' => $r->name,
                    ]),
                ]),
            'available_trackers' => $this->buildAvailableTrackers(),
            'emar_summary' => [
                'active_medications_count' => ClientMedication::where('client_id', $client->id)
                    ->where('active', true)
                    ->whereNull('ceased_at')
                    ->count(),
                'last_administration' => ClientMedicationAdministration::where('client_id', $client->id)
                    ->whereNotNull('administered_at')
                    ->max('administered_at'),
                'pending_alerts_count' => MedicationDashboardAlert::where('client_id', $client->id)
                    ->where('status', 'active')
                    ->count(),
                'next_review_date' => MedicationReview::where('client_id', $client->id)
                    ->where('status', '!=', 'completed')
                    ->whereNotNull('scheduled_date')
                    ->where('scheduled_date', '>=', now()->toDateString())
                    ->orderBy('scheduled_date')
                    ->value('scheduled_date'),
            ],
            'location' => $this->buildLocationData($client),
            'calendar_events' => $this->buildCalendarEvents($client),
            'expiredConsents' => $expiredConsents->map(fn ($c) => [
                'id' => $c->id,
                'type' => $c->consentType?->name,
                'expired_at' => $c->expires_at?->toIso8601String(),
            ]),
            'missingMandatoryConsents' => $missingMandatory->pluck('name')->values(),
            'transport' => Inertia::optional(fn () => $this->buildTransportData($client)),
            'hs_summary' => Inertia::optional(fn () => app(HsModuleSummaryService::class)->forClient($client->id)),
            'safety' => ClientSafetyPayload::forClient($client),
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

    private function buildCalendarEvents(Client $client): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth()->addDays(7);
        $events = collect();

        // Shifts
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

        // Family visits
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

        // Medication administrations
        $medAdmins = ClientMedicationAdministration::where('client_id', $client->id)
            ->whereBetween('scheduled_for', [$start, $end])
            ->with('medication:id,name,dosage,route')
            ->get();
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
        $activeMeds = ClientMedication::where('client_id', $client->id)->where('active', true)->whereNull('ceased_at')->where('is_prn', false)->get();
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
            if (! Schema::hasTable($table)) {
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
        if (! Schema::hasTable('consent_requests')) {
            return 0;
        }

        try {
            return ConsentRequest::forClient($client->id)->pending()->count();
        } catch (QueryException $exception) {
            report($exception);

            return 0;
        }
    }

    private function buildAvailableTrackers()
    {
        if (! Schema::hasTable('devices')) {
            return collect();
        }

        try {
            return Device::query()
                ->where('domain', 'tracking')
                ->whereNotIn('status', ['decommissioned', 'lost'])
                ->whereDoesntHave('assignments', fn ($query) => $query->active())
                ->orderBy('name')
                ->get(['id', 'device_uid', 'name', 'status', 'health_status', 'last_seen_at', 'serial_number', 'battery_level'])
                ->map(fn ($tracker) => [
                    'id' => $tracker->id,
                    'name' => $tracker->name,
                    'status' => $tracker->status,
                    'serial' => $tracker->serial,
                    'site_id' => $tracker->site_id,
                    'last_seen_at' => $tracker->last_seen_at?->toISOString(),
                    'battery' => $tracker->meta['battery'] ?? null,
                ]);
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

    public function create()
    {
        $this->authorize('create', Client::class);

        $sites = Site::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $serviceContexts = ServiceContext::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'type', 'name']);

        return inertia('operations/clients/create', [
            'sites' => $sites,
            'serviceContexts' => $serviceContexts,
            'defaultServiceContextId' => ServiceContext::defaultId(),
        ]);
    }

    public function store(StoreClientRequest $request)
    {
        $this->authorize('create', Client::class);

        try {
            $data = $request->validated();

            // If not specified, apply organisation default service context (if configured).
            if (empty($data['service_context_id'])) {
                $data['service_context_id'] = ServiceContext::defaultId();
            }

            $clientFields = collect($data)->except([
                'create_client_portal_user',
            ])->all();

            // Default to onboarding status for new clients
            if (! isset($clientFields['status']) || $clientFields['status'] === 'active') {
                $clientFields['status'] = 'onboarding';
            }

            $auth = $request->user();

            $client = DB::transaction(function () use ($clientFields, $data, $auth) {
                $client = Client::create($clientFields);

                ClientOnboardingWorkflow::createForClient($client, $auth->id);

                if (! empty($data['create_client_portal_user'])) {
                    $clientEmail = trim((string) ($data['email'] ?? $client->email ?? ''));
                    if ($clientEmail !== '') {
                        $name = trim($client->first_name.' '.$client->last_name);
                        $clientUser = $this->findOrCreatePortalUser($clientEmail, $name, 'client');
                        $client->portalUsers()->syncWithoutDetaching([
                            $clientUser->id => ['relation' => 'client'],
                        ]);
                        $this->sendPasswordSetupEmail($clientEmail);
                    }
                }

                return $client;
            });

            app(NotificationService::class)->notifyCrud($request->user(), 'created', 'client', $client, $client, [
                'title' => "Client created: {$client->first_name} {$client->last_name}",
                'url' => url("/clients/{$client->id}"),
            ]);

            return redirect()
                ->route('clients.index')
                ->with('success', 'Client created successfully.');
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withInput()
                ->with('error', 'Failed to create client: '.$e->getMessage());
        }
    }

    private function findOrCreatePortalUser(string $email, string $name, string $roleName): User
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
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

        $role = Role::where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    private function sendPasswordSetupEmail(string $email): void
    {
        Password::sendResetLink(['email' => $email]);
    }

    public function edit(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $sitesQuery = Site::query()->orderBy('name');
        // Keep inactive site visible if client currently assigned to it
        $sitesQuery->where('is_active', true);
        if ($client->site_id) {
            $sitesQuery->orWhere('id', $client->site_id);
        }

        $sites = $sitesQuery->get(['id', 'name', 'is_active']);

        $serviceContextsQuery = ServiceContext::query()->orderBy('name');
        $serviceContextsQuery->where('is_active', true);
        if ($client->service_context_id) {
            $serviceContextsQuery->orWhere('id', $client->service_context_id);
        }
        $serviceContexts = $serviceContextsQuery->get(['id', 'type', 'name', 'is_active']);

        $payload = [
            'client' => $client->only([
                'id', 'site_id', 'service_context_id', 'nhi_number', 'first_name', 'last_name', 'preferred_name', 'date_of_birth', 'gender', 'status',
                'phone', 'email', 'address_line_1', 'address_line_2', 'suburb', 'city', 'postcode',
                'profile_photo_path', 'funding_type', 'funding_notes',
            ]),
            'sites' => $sites,
            'serviceContexts' => $serviceContexts,
            'defaultServiceContextId' => ServiceContext::defaultId(),
        ];

        // The edit form is rendered inline as a modal on the index page —
        // no standalone Inertia page exists. Return JSON when the modal
        // requests it; otherwise send users back to the client detail view.
        if ($request->wantsJson() || $request->boolean('modal')) {
            return response()->json($payload);
        }

        return redirect()->route('operations.clients.show', $client);
    }

    public function update(UpdateClientRequest $request, Client $client)
    {
        $this->authorize('update', $client);

        try {
            $client->update($request->validated());

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

    /**
     * Quick-update a single field on a client (e.g. risk_level, safeguarding_flag).
     */
    public function quickUpdate(Request $request, Client $client)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clients.update'), 403);

        $data = $request->validate([
            'risk_level' => ['nullable', 'in:low,medium,high,critical'],
            'safeguarding_flag' => ['nullable', 'boolean'],
            'key_worker_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $client->update(array_filter($data, fn ($v) => $v !== null));

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
            'photo' => 'required|image|max:10240',
            'caption' => 'nullable|string|max:500',
            'tags' => 'nullable|array',
            'visibility' => 'nullable|in:staff_only,family,all_portal_users',
        ]);

        $file = $request->file('photo');
        $dir = "client-photos/{$client->id}";
        $path = $file->store($dir, 'public');

        // Generate thumbnail
        $thumbPath = null;
        try {
            $data = file_get_contents($file->getRealPath());
            $src = @imagecreatefromstring($data);
            if ($src) {
                $w = imagesx($src);
                $h = imagesy($src);
                $max = 400;
                if ($w > $max || $h > $max) {
                    $ratio = min($max / $w, $max / $h);
                    $nw = (int) round($w * $ratio);
                    $nh = (int) round($h * $ratio);
                    $thumb = imagecreatetruecolor($nw, $nh);
                    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                } else {
                    $thumb = $src;
                }
                $thumbDir = "client-photos/{$client->id}/thumbs";
                $thumbName = pathinfo($path, PATHINFO_FILENAME).'.jpg';
                $thumbPath = "{$thumbDir}/{$thumbName}";
                $absPath = \Storage::disk('public')->path($thumbPath);
                if (! is_dir(dirname($absPath))) {
                    mkdir(dirname($absPath), 0755, true);
                }
                imagejpeg($thumb, $absPath, 85);
                if ($thumb !== $src) {
                    imagedestroy($thumb);
                }
                imagedestroy($src);
            }
        } catch (\Throwable $e) {
            // Thumbnail generation failed, continue without it
        }

        ClientPhoto::create([
            'client_id' => $client->id,
            'uploaded_by_user_id' => $request->user()->id,
            'storage_path' => $path,
            'thumbnail_path' => $thumbPath,
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

        \Storage::disk('public')->delete($photo->storage_path);
        if ($photo->thumbnail_path) {
            \Storage::disk('public')->delete($photo->thumbnail_path);
        }
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
        $hasTransports = Schema::hasTable('fleet_resident_transports');
        $hasOutings = Schema::hasTable('fleet_outings');
        $hasMedLogs = Schema::hasTable('fleet_medication_transit_logs');
        $hasIncidents = Schema::hasTable('fleet_incidents');

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

        return [
            'stats' => [
                'transports_30d' => $transportCount30d,
                'outings_30d' => $outingCount30d,
                'incidents_30d' => $incidentCount30d,
            ],
            'upcoming_outings' => $upcomingOutings,
            'transport_history' => $transports,
            'medication_logs' => $medicationLogs,
        ];
    }

    /**
     * Build location/tracker data for the client profile.
     * Reads from canonical Security & Devices registry.
     */
    private function buildLocationData(Client $client): array
    {
        $tenantId = $client->tenant_id ?? 1;

        if (! Schema::hasTable('devices')) {
            return [
                'tracker' => null,
                'currentLocation' => null,
                'trackingConsent' => null,
                'geofences' => [],
            ];
        }

        // Find the active tracking device assigned to this client.
        try {
            $device = app(DeviceRegistryService::class)
                ->forClient($tenantId, $client->id)
                ->where('domain', 'tracking')
                ->first();
        } catch (QueryException $exception) {
            report($exception);

            return [
                'tracker' => null,
                'currentLocation' => null,
                'trackingConsent' => null,
                'geofences' => [],
            ];
        }

        $trackerInfo = null;
        $currentLocation = null;

        if ($device) {
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
                'locate_now_url' => route('operations.clients.location.locate-now', ['client' => $client->id], false),
                'acknowledge_panic_url' => route('operations.clients.location.acknowledge-panic', ['client' => $client->id], false),
                'fleet_dashboard_url' => '/fleet-assets/resident-tracking?focus='.$client->id,
                'history_url' => "/fleet-assets/resident-tracking/history/{$client->id}",
                'last_command_status' => QueclinkPendingCommand::query()
                    ->where('command_word', 'GTRTO')
                    ->whereHas('device', fn ($query) => $query->where('device_id', $device->id))
                    ->latest()
                    ->value('status'),
                'detail_url' => "/security-devices/devices/{$device->id}",
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

        // Tracking consent
        $trackingConsentType = ConsentType::query()
            ->where('name', 'Asset Location Tracking (Safety)')
            ->first();

        $trackingConsent = null;
        if ($trackingConsentType) {
            $consent = ClientConsent::query()
                ->where('client_id', $client->id)
                ->where('consent_type_id', $trackingConsentType->id)
                ->active()
                ->orderByDesc('given_at')
                ->first();

            if ($consent) {
                $trackingConsent = [
                    'status' => $consent->status,
                    'given_at' => optional($consent->given_at)->toISOString(),
                    'expires_at' => optional($consent->expires_at)->toISOString(),
                ];
            }
        }

        // Only the resident's specific house geofence (with legacy fallback).
        $geofences = [];
        $houseGeofence = null;
        try {
            if (Schema::hasTable('asset_geofences')) {
                $houseGeofence = $client->houseGeofence
                    ?: AssetGeofence::query()
                        ->where('is_active', true)
                        ->when($client->site_id, fn ($q) => $q->where('site_id', $client->site_id))
                        ->where(function ($q) {
                            $q->where('scope', 'house')->orWhere('scope', 'resident');
                        })
                        ->orderByRaw("CASE scope WHEN 'house' THEN 0 WHEN 'resident' THEN 1 ELSE 2 END")
                        ->first();
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
            'tracker' => $trackerInfo,
            'currentLocation' => $currentLocation,
            'trackingConsent' => $trackingConsent,
            'geofences' => $geofences,
            'geofenceStatus' => $geofenceStatus,
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

        $tenantId = $client->tenant_id ?? 1;

        // Canonical device lookup.
        $device = app(DeviceRegistryService::class)
            ->forClient($tenantId, $client->id)
            ->where('domain', 'tracking')
            ->first();

        $locations = app(IntegrationEventHistoryService::class)
            ->forDevice($device, $request->only(['date_from', 'date_to']));

        return response()->json(['locations' => $locations]);
    }

    public function locateNow(Request $request, Client $client, LocateNowService $locateNow)
    {
        $this->authorize('view', $client);

        $tenantId = $client->tenant_id ?? 1;
        $device = app(DeviceRegistryService::class)
            ->forClient($tenantId, $client->id)
            ->where('domain', 'tracking')
            ->first();

        if (! $device) {
            throw ValidationException::withMessages([
                'tracker' => 'This client does not have a paired Queclink tracker.',
            ]);
        }

        $locateNow->queueForDevice($device, $request->user());

        return back()->with('success', 'Locate Now queued. The tracker will report on its next connection.');
    }

    public function acknowledgePanic(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $tenantId = $client->tenant_id ?? 1;
        $device = app(DeviceRegistryService::class)
            ->forClient($tenantId, $client->id)
            ->where('domain', 'tracking')
            ->first();

        if ($device) {
            $meta = $device->meta ?? [];
            $meta['panic_active'] = false;
            $meta['panic_acknowledged_at'] = now()->toISOString();
            $meta['panic_acknowledged_by'] = $request->user()?->id;
            $device->forceFill(['meta' => $meta])->save();
        }

        if (Schema::hasTable('control_room_alerts')) {
            ControlRoomAlert::query()
                ->where('client_id', $client->id)
                ->whereIn('source', ['tracker', 'resident_tracker'])
                ->whereIn('status', ['open', 'triaging'])
                ->update([
                    'status' => 'ack',
                    'acknowledged_at' => now(),
                    'acknowledged_by_user_id' => $request->user()?->id,
                ]);
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
