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

    public function index(Request $request): Response
    {
        $query = RespiteBookingRequest::query()
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
                'submitted' => RespiteBookingRequest::where('status', 'submitted')->count(),
                'approved' => RespiteBookingRequest::where('status', 'approved')->count(),
                'rejected' => RespiteBookingRequest::where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('respite/requests/create', [
            'clients' => Client::select('id', 'first_name', 'last_name')->orderBy('last_name')->orderBy('first_name')->get(),
            'serviceContexts' => ServiceContext::select('id', 'name')->orderBy('name')->get(),
            'serviceAgreements' => ServiceAgreement::query()
                ->active()
                ->orderBy('title')
                ->get(['id', 'client_id', 'title', 'reference_number', 'ends_at', 'total_budget', 'budget_used', 'total_hours', 'hours_used']),
            'fundingSources' => RespiteFundingSource::options(),
            'defaultClientId' => $request->query('client_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'service_context_id' => 'nullable|exists:service_contexts,id',
            'requested_start' => 'required|date',
            'requested_end' => 'required|date|after:requested_start',
            'requirements' => 'nullable|array',
            'intake_snapshot' => 'nullable|array',
            'preference_notes' => 'nullable|string',
            'referral_id' => 'nullable|exists:respite_referrals,id',
            'funding_source' => ['nullable', 'string', Rule::in(RespiteFundingSource::keys())],
            'funding_reference' => 'nullable|string|max:255',
            'service_agreement_id' => 'nullable|exists:service_agreements,id',
            'funding_status' => ['nullable', Rule::in(self::FUNDING_STATUSES)],
            'funding_approved_ref' => 'nullable|string|max:255',
            'funding_approved_at' => 'nullable|date',
            'waitlist_position' => 'nullable|integer|min:1',
            'priority' => 'nullable|in:routine,priority,crisis',
            'expected_availability_date' => 'nullable|date',
            'is_emergency' => 'nullable|boolean',
            'fast_tracked' => 'nullable|boolean',
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $this->authorize('view', $client);

        $validated = $this->normaliseFunding($validated);

        $validated['status'] = 'submitted';
        $validated['created_by'] = auth()->id();

        $requestModel = RespiteBookingRequest::create($validated);

        event(new RespiteEvent('respite.booking_request.submitted', [
            'id' => $requestModel->id,
            'client_id' => $requestModel->client_id,
            'status' => $requestModel->status,
        ]));

        return redirect()
            ->route('respite.requests.show', $requestModel)
            ->with('success', 'Respite booking request submitted.');
    }

    public function show(RespiteBookingRequest $request): Response
    {
        $request->load(['client', 'serviceContext', 'approvedBy', 'serviceAgreement']);
        $this->authorize('view', $request->client);

        $booking = RespiteBooking::where('booking_request_id', $request->id)
            ->with('serviceAgreement')
            ->first();

        return Inertia::render('respite/requests/show', [
            'request' => $request,
            'booking' => $booking,
        ]);
    }

    public function update(Request $httpRequest, RespiteBookingRequest $request): RedirectResponse
    {
        $request->loadMissing('client');
        $this->authorize('view', $request->client);

        $validated = $httpRequest->validate([
            'requested_start' => 'sometimes|date',
            'requested_end' => 'sometimes|date|after:requested_start',
            'requirements' => 'nullable|array',
            'intake_snapshot' => 'nullable|array',
            'preference_notes' => 'nullable|string',
            'funding_source' => ['nullable', 'string', Rule::in(RespiteFundingSource::keys())],
            'funding_reference' => 'nullable|string|max:255',
            'service_agreement_id' => 'nullable|exists:service_agreements,id',
            'funding_status' => ['nullable', Rule::in(self::FUNDING_STATUSES)],
            'funding_approved_ref' => 'nullable|string|max:255',
            'funding_approved_at' => 'nullable|date',
            'status' => 'sometimes|in:draft,submitted,under_review,approved,rejected,waitlisted',
            'waitlist_position' => 'nullable|integer|min:1',
            'priority' => 'nullable|in:routine,priority,crisis',
            'expected_availability_date' => 'nullable|date',
            'is_emergency' => 'nullable|boolean',
            'fast_tracked' => 'nullable|boolean',
            'decision_notes' => 'nullable|string',
        ]);

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
        ]);
        $validated = array_merge($validated, array_intersect_key($funding, array_flip([
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
        ])));

        $validated['updated_by'] = auth()->id();
        $request->update($validated);

        event(new RespiteEvent('respite.booking_request.updated', [
            'id' => $request->id,
            'client_id' => $request->client_id,
            'status' => $request->status,
        ]));

        return back()->with('success', 'Booking request updated.');
    }

    public function approve(Request $httpRequest, RespiteBookingRequest $request): RedirectResponse
    {
        $validated = $httpRequest->validate([
            'funding_override_reason' => 'nullable|string|max:500',
        ]);

        $request->loadMissing('client', 'serviceAgreement');
        $this->authorize('view', $request->client);

        $booking = DB::transaction(function () use ($request, $validated) {
            $fundingStatus = $this->effectiveFundingStatus($request->funding_status, $request->funding_source);
            $approvedAt = $request->funding_approved_at ?: ($fundingStatus === 'approved' ? now() : null);

            $request->update([
                'status' => 'approved',
                'funding_status' => $fundingStatus,
                'funding_approved_at' => $approvedAt,
                'approved_by_user_id' => auth()->id(),
                'approved_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            $booking = RespiteBooking::firstOrNew(['booking_request_id' => $request->id]);
            $approvals = $booking->approvals ?? [];

            if ($fundingStatus === 'pending_approval' && filled($validated['funding_override_reason'] ?? null)) {
                $approvals['funding_override'] = [
                    'reason' => $validated['funding_override_reason'],
                    'recorded_by' => auth()->id(),
                    'recorded_at' => now()->toIso8601String(),
                ];
            }

            $booking->fill([
                'client_id' => $request->client_id,
                'start_at' => $request->requested_start,
                'end_at' => $request->requested_end,
                'funding_source' => $request->funding_source,
                'funding_reference' => $request->funding_reference,
                'service_agreement_id' => $request->service_agreement_id,
                'funding_status' => $fundingStatus,
                'agreement_status' => $this->agreementStatusFor($request->serviceAgreement),
                'funding_approved_ref' => $request->funding_approved_ref,
                'funding_approved_at' => $approvedAt,
                'approvals' => $approvals ?: $booking->approvals,
                'cultural_snapshot' => $request->intake_snapshot['cultural'] ?? null,
                'interpreter_arranged' => (bool) data_get($request->intake_snapshot, 'cultural.interpreter_arranged', false),
                'updated_by' => auth()->id(),
            ]);

            if (! $booking->exists) {
                $booking->fill([
                    'client_id' => $request->client_id,
                    'start_at' => $request->requested_start,
                    'end_at' => $request->requested_end,
                    'status' => 'pending',
                    'created_by' => auth()->id(),
                ]);
            }

            $booking->save();

            $serviceContextId = $request->service_context_id
                ?: Client::query()->whereKey($request->client_id)->value('service_context_id');

            if (empty($serviceContextId)) {
                $serviceContextId = ServiceContext::defaultId();
            }

            app(RespiteShiftSync::class)->ensureShiftForBooking($booking, auth()->id(), $serviceContextId);

            return $booking;
        });

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
        $validated = $httpRequest->validate([
            'location_id' => 'nullable|exists:sites,id',
            'capacity_override_reason' => 'nullable|string|max:500',
        ]);

        $request->loadMissing('client', 'serviceAgreement', 'referral');
        $this->authorize('view', $request->client);

        if ($request->status !== 'waitlisted') {
            throw ValidationException::withMessages([
                'status' => 'Only waitlisted requests can be promoted.',
            ]);
        }

        $booking = DB::transaction(function () use ($request, $validated) {
            $fundingStatus = $this->effectiveFundingStatus($request->funding_status, $request->funding_source);

            $request->update([
                'status' => 'approved',
                'approved_by_user_id' => auth()->id(),
                'approved_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            $booking = RespiteBooking::firstOrNew(['booking_request_id' => $request->id]);
            $approvals = $booking->approvals ?? [];
            $approvals['waitlist_promotion'] = [
                'position' => $request->waitlist_position,
                'priority' => $request->priority,
                'promoted_by' => auth()->id(),
                'promoted_at' => now()->toIso8601String(),
            ];

            if (filled($validated['capacity_override_reason'] ?? null)) {
                $approvals['capacity_override'] = [
                    'reason' => $validated['capacity_override_reason'],
                    'recorded_by' => auth()->id(),
                    'recorded_at' => now()->toIso8601String(),
                ];
            }

            $booking->fill([
                'client_id' => $request->client_id,
                'start_at' => $request->requested_start,
                'end_at' => $request->requested_end,
                'status' => $booking->exists ? $booking->status : 'pending',
                'location_id' => $validated['location_id'] ?? $booking->location_id,
                'funding_source' => $request->funding_source,
                'funding_reference' => $request->funding_reference,
                'service_agreement_id' => $request->service_agreement_id,
                'funding_status' => $fundingStatus,
                'agreement_status' => $this->agreementStatusFor($request->serviceAgreement),
                'approvals' => $approvals,
                'capacity_override_reason' => $validated['capacity_override_reason'] ?? null,
                'cultural_snapshot' => $request->intake_snapshot['cultural'] ?? null,
                'interpreter_arranged' => (bool) data_get($request->intake_snapshot, 'cultural.interpreter_arranged', false),
                'created_by' => $booking->exists ? $booking->created_by : auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            $booking->save();

            $serviceContextId = $request->service_context_id
                ?: Client::query()->whereKey($request->client_id)->value('service_context_id')
                ?: ServiceContext::defaultId();

            app(RespiteShiftSync::class)->ensureShiftForBooking($booking, auth()->id(), $serviceContextId);

            return $booking;
        });

        event(new RespiteEvent('respite.booking_request.promoted', [
            'id' => $request->id,
            'client_id' => $request->client_id,
            'booking_id' => $booking->id,
        ]));

        return back()->with('success', 'Waitlisted request promoted.');
    }

    private function normaliseFunding(array $data): array
    {
        $referral = isset($data['referral_id'])
            ? RespiteReferral::query()->find($data['referral_id'])
            : null;

        if ($referral && isset($data['client_id']) && (int) $referral->client_id !== (int) $data['client_id']) {
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

        if (! empty($data['service_agreement_id'])) {
            $agreement = ServiceAgreement::query()->findOrFail($data['service_agreement_id']);

            if (isset($data['client_id']) && (int) $agreement->client_id !== (int) $data['client_id']) {
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
