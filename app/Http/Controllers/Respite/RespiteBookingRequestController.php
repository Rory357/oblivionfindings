<?php

namespace App\Http\Controllers\Respite;

use App\Events\Respite\RespiteEvent;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteReferral;
use App\Models\ServiceAgreement;
use App\Models\ServiceContext;
use App\Services\Respite\RespiteShiftSync;
use App\Services\Respite\RespiteStateTransitionService;
use App\Services\Respite\RespiteStayScope;
use App\Support\Respite\RespiteFundingSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RespiteBookingRequestController extends Controller
{
    private const FUNDING_STATUSES = ['not_required', 'pending_approval', 'approved', 'declined', 'expired'];

    public function __construct(
        private readonly RespiteStateTransitionService $states,
        private readonly RespiteStayScope $scope,
    ) {}

    public function index(Request $request): Response
    {
        $accessibleRequests = fn () => $this->scope->applyAccessibleBookingRequestScope(
            RespiteBookingRequest::query(),
            $request->user(),
        );
        $query = $accessibleRequests()
            ->with(['client', 'serviceContext', 'approvedBy', 'serviceAgreement'])
            ->where('status', '!=', 'approved');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('q')) {
            $query->where(function ($q) use ($request) {
                $q->where('funding_reference', 'like', "%{$request->q}%")
                    ->orWhere('preference_notes', 'like', "%{$request->q}%");
            });
        }

        $query->orderByDesc('requested_start');
        $requests = $query->paginate(20)->withQueryString();

        return Inertia::render('respite/requests/index', [
            'requests' => $requests,
            'filters' => $request->only(['status', 'q']),
            'stats' => [
                'submitted' => $accessibleRequests()->where('status', 'submitted')->count(),
                'approved' => $accessibleRequests()->where('status', 'approved')->count(),
                'rejected' => $accessibleRequests()->where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $clients = $this->scope->applyAccessibleClientScope(Client::query(), $request->user())
            ->select('id', 'first_name', 'last_name')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
        $defaultClientId = $request->integer('client_id');

        return Inertia::render('respite/requests/create', [
            'clients' => $clients,
            'serviceContexts' => $this->scope->applyAccessibleServiceContextScope(
                ServiceContext::query(),
                $request->user(),
            )
                ->select('id', 'name')
                ->orderBy('name')
                ->get(),
            'serviceAgreements' => ServiceAgreement::query()
                ->whereHas(
                    'client',
                    fn ($clients) => $this->scope->applyAccessibleClientScope($clients, $request->user()),
                )
                ->active()
                ->orderBy('title')
                ->get(['id', 'client_id', 'title', 'reference_number', 'ends_at', 'total_budget', 'budget_used', 'total_hours', 'hours_used']),
            'fundingSources' => RespiteFundingSource::options(),
            'defaultClientId' => $defaultClientId > 0 && $clients->contains('id', $defaultClientId)
                ? $defaultClientId
                : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|integer|min:1',
            'service_context_id' => 'nullable|integer|min:1',
            'requested_start' => 'required|date',
            'requested_end' => 'required|date|after:requested_start',
            'requirements' => 'nullable|array',
            'intake_snapshot' => 'nullable|array',
            'preference_notes' => 'nullable|string',
            'referral_id' => 'nullable|integer|min:1',
            'funding_source' => ['nullable', 'string', Rule::in(RespiteFundingSource::keys())],
            'funding_reference' => 'nullable|string|max:255',
            'service_agreement_id' => 'nullable|integer|min:1',
            'funding_status' => ['nullable', Rule::in(self::FUNDING_STATUSES)],
            'funding_approved_ref' => 'nullable|string|max:255',
            'funding_approved_at' => 'nullable|date',
            'waitlist_position' => 'nullable|integer|min:1',
            'priority' => 'nullable|in:routine,priority,crisis',
            'expected_availability_date' => 'nullable|date',
            'is_emergency' => 'nullable|boolean',
            'fast_tracked' => 'nullable|boolean',
            'series_id' => 'nullable|string|max:100',
            'recurrence_rule' => 'nullable|string|max:255',
            'allocated_days' => 'nullable|integer|min:1',
        ]);

        [$requestModel, $referral] = DB::transaction(function () use ($request, $validated): array {
            $client = $this->scope->resolveAuthorizedClient($request, (int) $validated['client_id'], true);
            $this->scope->resolveAuthorizedSite($request, (int) $client->site_id, true);
            if (! empty($validated['service_context_id'])) {
                $this->scope->resolveAuthorizedServiceContextForClient(
                    $request,
                    (int) $validated['service_context_id'],
                    $client,
                    true,
                );
            }
            $referral = null;

            if (! empty($validated['referral_id'])) {
                $referral = RespiteReferral::query()
                    ->whereKey($validated['referral_id'])
                    ->where('client_id', $client->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $alreadyLinked = $referral->linked_booking_request_id !== null
                    || RespiteBookingRequest::withTrashed()
                        ->where('referral_id', $referral->id)
                        ->lockForUpdate()
                        ->exists();
                if ($referral->status === 'declined' || $alreadyLinked) {
                    throw ValidationException::withMessages([
                        'referral_id' => $referral->status === 'declined'
                            ? 'A declined referral cannot create a booking request.'
                            : 'This referral already has a booking request.',
                    ]);
                }
            }

            $updates = $this->normaliseFunding($validated, true, $referral);
            $updates['status'] = 'submitted';
            $updates['created_by'] = auth()->id();

            $requestModel = RespiteBookingRequest::create($updates);
            if ($referral) {
                $referral->update([
                    'linked_booking_request_id' => $requestModel->id,
                    'status' => 'accepted',
                    'updated_by' => auth()->id(),
                ]);
            }

            return [$requestModel, $referral];
        }, 3);

        if ($referral) {
            event(new RespiteEvent('respite.referral.updated', [
                'id' => $referral->id,
                'client_id' => $referral->client_id,
                'status' => $referral->status,
            ]));
        }

        event(new RespiteEvent('respite.booking_request.submitted', [
            'id' => $requestModel->id,
            'client_id' => $requestModel->client_id,
            'status' => $requestModel->status,
        ]));

        // The workspace pop-up posts with _modal so it stays on the workspace
        // (the lists refresh in place); the legacy full-page create still lands
        // on the request detail.
        if ($request->boolean('_modal')) {
            return back()->with('success', 'Respite booking request submitted.');
        }

        return redirect()
            ->route('respite.requests.show', $requestModel)
            ->with('success', 'Respite booking request submitted.');
    }

    public function show(RespiteBookingRequest $request): Response
    {
        $httpRequest = request();
        $request = $this->scope->resolveAuthorizedBookingRequest($httpRequest, (int) $request->id);
        $request->load(['client', 'serviceContext', 'approvedBy', 'serviceAgreement']);

        $booking = $this->scope->applyAccessibleBookingScope(
            RespiteBooking::query(),
            $httpRequest->user(),
        )
            ->where('booking_request_id', $request->id)
            ->with('serviceAgreement')
            ->first();

        return Inertia::render('respite/requests/show', [
            'request' => $request,
            'booking' => $booking,
        ]);
    }

    public function update(Request $httpRequest, RespiteBookingRequest $request): RedirectResponse
    {
        $this->states->assertRequestAccessible($httpRequest, (int) $request->id);

        $validated = $httpRequest->validate([
            'requested_start' => 'sometimes|date',
            'requested_end' => 'sometimes|date|after:requested_start',
            'requirements' => 'nullable|array',
            'intake_snapshot' => 'nullable|array',
            'preference_notes' => 'nullable|string',
            'funding_source' => ['nullable', 'string', Rule::in(RespiteFundingSource::keys())],
            'funding_reference' => 'nullable|string|max:255',
            'service_agreement_id' => 'nullable|integer|min:1',
            'funding_status' => ['nullable', Rule::in(self::FUNDING_STATUSES)],
            'funding_approved_ref' => 'nullable|string|max:255',
            'funding_approved_at' => 'nullable|date',
            'status' => ['sometimes', Rule::in(RespiteStateTransitionService::REQUEST_GENERIC_STATUSES)],
            'waitlist_position' => 'nullable|integer|min:1',
            'priority' => 'nullable|in:routine,priority,crisis',
            'expected_availability_date' => 'nullable|date',
            'is_emergency' => 'nullable|boolean',
            'fast_tracked' => 'nullable|boolean',
            'series_id' => 'nullable|string|max:100',
            'recurrence_rule' => 'nullable|string|max:255',
            'allocated_days' => 'nullable|integer|min:1',
            'decision_notes' => 'nullable|string',
        ]);

        $request = $this->states->transitionRequest(
            $httpRequest,
            (int) $request->id,
            function (RespiteBookingRequest $request) use ($validated): RespiteBookingRequest {
                $this->states->assertRequestUpdate(
                    $request->status,
                    array_key_exists('status', $validated) ? $validated['status'] : null,
                );

                if (array_key_exists('status', $validated) && $validated['status'] !== $request->status) {
                    $this->states->assertRequestHasNoBooking(
                        RespiteBooking::withTrashed()
                            ->where('booking_request_id', $request->id)
                            ->lockForUpdate()
                            ->first(['id']) !== null,
                    );
                }

                $funding = $this->normaliseFunding([
                    'client_id' => $request->client_id,
                    'referral_id' => $request->referral_id,
                    'funding_source' => $validated['funding_source'] ?? $request->funding_source,
                    'funding_reference' => $validated['funding_reference'] ?? $request->funding_reference,
                    'service_agreement_id' => $validated['service_agreement_id'] ?? $request->service_agreement_id,
                    'funding_status' => $validated['funding_status'] ?? $request->funding_status,
                    'funding_approved_ref' => $validated['funding_approved_ref'] ?? $request->funding_approved_ref,
                    'funding_approved_at' => $validated['funding_approved_at'] ?? $request->funding_approved_at,
                    'intake_snapshot' => $validated['intake_snapshot'] ?? $request->intake_snapshot,
                    'priority' => $validated['priority'] ?? $request->priority,
                    'is_emergency' => $validated['is_emergency'] ?? $request->is_emergency,
                    'fast_tracked' => $validated['fast_tracked'] ?? $request->fast_tracked,
                    'series_id' => $validated['series_id'] ?? $request->series_id,
                    'recurrence_rule' => $validated['recurrence_rule'] ?? $request->recurrence_rule,
                    'allocated_days' => $validated['allocated_days'] ?? $request->allocated_days,
                ], true);
                $updates = array_merge($validated, array_intersect_key($funding, array_flip([
                    'funding_source',
                    'funding_reference',
                    'service_agreement_id',
                    'funding_status',
                    'funding_approved_ref',
                    'funding_approved_at',
                    'intake_snapshot',
                    'priority',
                    'is_emergency',
                    'fast_tracked',
                    'series_id',
                    'recurrence_rule',
                    'allocated_days',
                ])));

                $updates['updated_by'] = auth()->id();
                $request->update($updates);

                return $request;
            },
        );

        event(new RespiteEvent('respite.booking_request.updated', [
            'id' => $request->id,
            'client_id' => $request->client_id,
            'status' => $request->status,
        ]));

        return back()->with('success', 'Booking request updated.');
    }

    public function approve(Request $httpRequest, RespiteBookingRequest $request): RedirectResponse
    {
        $this->states->assertRequestAccessible($httpRequest, (int) $request->id);

        $validated = $httpRequest->validate([
            'funding_override_reason' => 'nullable|string|max:500',
        ]);

        [$request, $booking] = $this->states->transitionRequest(
            $httpRequest,
            (int) $request->id,
            function (RespiteBookingRequest $request) use ($httpRequest, $validated): array {
                $this->states->assertRequestApproval($request->status);
                $this->scope->resolveAuthorizedSite(
                    $httpRequest,
                    (int) $request->client->site_id,
                    true,
                );
                if ($request->service_context_id) {
                    $this->scope->resolveAuthorizedServiceContextForClient(
                        $httpRequest,
                        (int) $request->service_context_id,
                        $request->client,
                        true,
                    );
                }
                $this->states->assertRequestHasNoBooking(
                    RespiteBooking::withTrashed()
                        ->where('booking_request_id', $request->id)
                        ->lockForUpdate()
                        ->first(['id']) !== null,
                );
                $funding = $this->normaliseFunding([
                    'client_id' => $request->client_id,
                    'referral_id' => $request->referral_id,
                    'funding_source' => $request->funding_source,
                    'funding_reference' => $request->funding_reference,
                    'service_agreement_id' => $request->service_agreement_id,
                    'funding_status' => $request->funding_status,
                    'funding_approved_ref' => $request->funding_approved_ref,
                    'funding_approved_at' => $request->funding_approved_at,
                ], true);
                $request->unsetRelation('serviceAgreement');
                $request->load('serviceAgreement');

                $fundingStatus = $funding['funding_status'];
                $approvedAt = $funding['funding_approved_at'] ?? null;

                $request->update([
                    'status' => 'approved',
                    'funding_status' => $fundingStatus,
                    'funding_approved_at' => $approvedAt,
                    'approved_by_user_id' => auth()->id(),
                    'approved_at' => now(),
                    'updated_by' => auth()->id(),
                ]);

                $approvals = [];
                if ($fundingStatus === 'pending_approval' && filled($validated['funding_override_reason'] ?? null)) {
                    $approvals['funding_override'] = [
                        'reason' => $validated['funding_override_reason'],
                        'recorded_by' => auth()->id(),
                        'recorded_at' => now()->toIso8601String(),
                    ];
                }

                $booking = RespiteBooking::create([
                    'booking_request_id' => $request->id,
                    'client_id' => $request->client_id,
                    'start_at' => $request->requested_start,
                    'end_at' => $request->requested_end,
                    'status' => 'pending',
                    'funding_source' => $request->funding_source,
                    'funding_reference' => $request->funding_reference,
                    'service_agreement_id' => $request->service_agreement_id,
                    'funding_status' => $fundingStatus,
                    'agreement_status' => $this->agreementStatusFor($request->serviceAgreement),
                    'funding_approved_ref' => $request->funding_approved_ref,
                    'funding_approved_at' => $approvedAt,
                    'approvals' => $approvals ?: null,
                    'series_id' => $request->series_id,
                    'recurrence_rule' => $request->recurrence_rule,
                    'cultural_snapshot' => $request->intake_snapshot['cultural'] ?? null,
                    'interpreter_arranged' => (bool) data_get($request->intake_snapshot, 'cultural.interpreter_arranged', false),
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ]);

                app(RespiteShiftSync::class)->ensureShiftForBooking($booking, auth()->id());

                return [$request, $booking];
            },
        );

        event(new RespiteEvent('respite.booking_request.approved', [
            'id' => $request->id,
            'client_id' => $request->client_id,
            'status' => $request->status,
        ]));

        event(new RespiteEvent('respite.booking.created', [
            'id' => $booking->id,
            'client_id' => $booking->client_id,
            'status' => $booking->status,
        ]));

        $redirect = back()->with('success', 'Booking request approved.');

        if ($request->funding_status === 'pending_approval') {
            $redirect->with('warning', 'Funding is still pending. The booking readiness gate will stay open until approval is recorded.');
        }

        return $redirect;
    }

    public function promote(Request $httpRequest, RespiteBookingRequest $request): RedirectResponse
    {
        $this->states->assertRequestAccessible($httpRequest, (int) $request->id);

        $validated = $httpRequest->validate([
            'location_id' => 'nullable|integer|min:1',
            'capacity_override_reason' => 'nullable|string|max:500',
        ]);

        if (array_key_exists('location_id', $validated) && $validated['location_id'] !== null) {
            $this->states->assertAuthorizedSite($httpRequest, (int) $validated['location_id']);
        }

        [$request, $booking] = $this->states->transitionRequest(
            $httpRequest,
            (int) $request->id,
            function (RespiteBookingRequest $request) use ($httpRequest, $validated): array {
                $this->states->assertRequestPromotion($request->status);
                $siteId = (int) (($validated['location_id'] ?? null) ?: $request->client->site_id);
                $this->scope->resolveAuthorizedSite($httpRequest, $siteId, true);
                if ($request->service_context_id) {
                    $this->scope->resolveAuthorizedServiceContextForClient(
                        $httpRequest,
                        (int) $request->service_context_id,
                        $request->client,
                        true,
                    );
                }
                $this->states->assertRequestHasNoBooking(
                    RespiteBooking::withTrashed()
                        ->where('booking_request_id', $request->id)
                        ->lockForUpdate()
                        ->first(['id']) !== null,
                );
                $funding = $this->normaliseFunding([
                    'client_id' => $request->client_id,
                    'referral_id' => $request->referral_id,
                    'funding_source' => $request->funding_source,
                    'funding_reference' => $request->funding_reference,
                    'service_agreement_id' => $request->service_agreement_id,
                    'funding_status' => $request->funding_status,
                    'funding_approved_ref' => $request->funding_approved_ref,
                    'funding_approved_at' => $request->funding_approved_at,
                ], true);
                $request->unsetRelation('serviceAgreement');
                $request->load('serviceAgreement');

                $fundingStatus = $funding['funding_status'];
                $approvedAt = $funding['funding_approved_at'] ?? null;
                $request->update([
                    'status' => 'approved',
                    'funding_status' => $fundingStatus,
                    'funding_approved_at' => $approvedAt,
                    'approved_by_user_id' => auth()->id(),
                    'approved_at' => now(),
                    'updated_by' => auth()->id(),
                ]);

                $approvals = [
                    'waitlist_promotion' => [
                        'position' => $request->waitlist_position,
                        'priority' => $request->priority,
                        'promoted_by' => auth()->id(),
                        'promoted_at' => now()->toIso8601String(),
                    ],
                ];

                if (filled($validated['capacity_override_reason'] ?? null)) {
                    $approvals['capacity_override'] = [
                        'reason' => $validated['capacity_override_reason'],
                        'recorded_by' => auth()->id(),
                        'recorded_at' => now()->toIso8601String(),
                    ];
                }

                $booking = RespiteBooking::create([
                    'booking_request_id' => $request->id,
                    'client_id' => $request->client_id,
                    'start_at' => $request->requested_start,
                    'end_at' => $request->requested_end,
                    'status' => 'pending',
                    'location_id' => $validated['location_id'] ?? null,
                    'funding_source' => $request->funding_source,
                    'funding_reference' => $request->funding_reference,
                    'service_agreement_id' => $request->service_agreement_id,
                    'funding_status' => $fundingStatus,
                    'funding_approved_ref' => $request->funding_approved_ref,
                    'funding_approved_at' => $approvedAt,
                    'agreement_status' => $this->agreementStatusFor($request->serviceAgreement),
                    'approvals' => $approvals,
                    'capacity_override_reason' => $validated['capacity_override_reason'] ?? null,
                    'series_id' => $request->series_id,
                    'recurrence_rule' => $request->recurrence_rule,
                    'cultural_snapshot' => $request->intake_snapshot['cultural'] ?? null,
                    'interpreter_arranged' => (bool) data_get($request->intake_snapshot, 'cultural.interpreter_arranged', false),
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ]);

                app(RespiteShiftSync::class)->ensureShiftForBooking($booking, auth()->id());

                return [$request, $booking];
            },
        );

        event(new RespiteEvent('respite.booking_request.promoted', [
            'id' => $request->id,
            'client_id' => $request->client_id,
            'booking_id' => $booking->id,
        ]));

        return back()->with('success', 'Waitlisted request promoted.');
    }

    private function normaliseFunding(
        array $data,
        bool $lock = false,
        ?RespiteReferral $resolvedReferral = null,
    ): array {
        $referral = $resolvedReferral;
        if (! $referral && ! empty($data['referral_id'])) {
            $referralQuery = RespiteReferral::query()
                ->whereKey($data['referral_id'])
                ->when(
                    isset($data['client_id']),
                    fn ($query) => $query->where('client_id', $data['client_id']),
                );
            if ($lock) {
                $referralQuery->lockForUpdate();
            }
            $referral = $referralQuery->first();
        }

        if (! empty($data['referral_id'])
            && (! $referral
                || (isset($data['client_id']) && (int) $referral->client_id !== (int) $data['client_id']))) {
            throw ValidationException::withMessages([
                'referral_id' => 'The referral must belong to the selected client.',
            ]);
        }

        if ($referral) {
            $data['funding_source'] = $data['funding_source'] ?? $referral->funding_source;
            $data['funding_reference'] = $data['funding_reference'] ?? $referral->funding_reference;
            $isEmergency = (bool) ($data['is_emergency'] ?? ($referral->urgency === 'crisis' || $referral->carer_breakdown_flag));
            $data['priority'] = $data['priority'] ?? ($referral->urgency === 'crisis' ? 'crisis' : 'routine');
            $data['is_emergency'] = $isEmergency;
            $data['fast_tracked'] = (bool) ($data['fast_tracked'] ?? $isEmergency);
            $data['intake_snapshot'] = array_replace_recursive($data['intake_snapshot'] ?? [], [
                'referral' => [
                    'id' => $referral->id,
                    'third_party_source_type' => $referral->third_party_source_type,
                    'third_party_source_name' => $referral->third_party_source_name,
                    'third_party_collection_consent' => $referral->third_party_collection_consent,
                ],
                'cultural' => [
                    'is_maori' => $referral->is_maori,
                    'ethnicity' => $referral->ethnicity,
                    'iwi' => $referral->iwi,
                    'hapu' => $referral->hapu,
                    'marae' => $referral->marae,
                    'interpreter_required' => $referral->interpreter_required,
                    'interpreter_language' => $referral->interpreter_language,
                    'interpreter_arranged' => $referral->interpreter_arranged,
                    'cultural_considerations' => $referral->cultural_considerations,
                    'cultural_dietary_needs' => $referral->cultural_dietary_needs,
                ],
                'carer' => [
                    'primary_carer_name' => $referral->primary_carer_name,
                    'primary_carer_relationship' => $referral->primary_carer_relationship,
                    'primary_carer_contact' => $referral->primary_carer_contact,
                    'carer_strain_level' => $referral->carer_strain_level,
                    'carer_breakdown_flag' => $referral->carer_breakdown_flag,
                    'booker_type' => $referral->booker_type,
                ],
            ]);
        }

        if (array_key_exists('service_agreement_id', $data) && $data['service_agreement_id'] !== null) {
            $agreementQuery = ServiceAgreement::query()
                ->whereKey($data['service_agreement_id'])
                ->when(
                    isset($data['client_id']),
                    fn ($query) => $query->where('client_id', $data['client_id']),
                );
            if ($lock) {
                $agreementQuery->lockForUpdate();
            }
            $agreement = $agreementQuery->first();

            if (! $agreement) {
                throw ValidationException::withMessages([
                    'service_agreement_id' => 'The service agreement must belong to the selected client.',
                ]);
            }

            if ($agreement->status !== 'active') {
                throw ValidationException::withMessages([
                    'service_agreement_id' => 'The service agreement must be active.',
                ]);
            }
        }

        $data['funding_status'] = $this->effectiveFundingStatus($data['funding_status'] ?? null, $data['funding_source'] ?? null);

        if ($data['funding_status'] === 'approved' && empty($data['funding_approved_at'])) {
            $data['funding_approved_at'] = now();
        }

        return $data;
    }

    private function agreementStatusFor(?ServiceAgreement $agreement): string
    {
        if (! $agreement) {
            return 'not_sent';
        }

        return $agreement->signed_at || $agreement->signed_date ? 'signed' : 'sent';
    }

    private function effectiveFundingStatus(?string $status, ?string $fundingSource): string
    {
        if ($status) {
            return $status;
        }

        return $fundingSource ? 'pending_approval' : 'not_required';
    }
}
