<?php

namespace App\Http\Controllers\Respite;

use App\Events\Respite\RespiteEvent;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\ServiceAgreement;
use App\Models\Site;
use App\Models\User;
use App\Services\Respite\RespiteCalendarProjector;
use App\Services\Respite\RespiteShiftSync;
use App\Support\Respite\RespiteFundingSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RespiteBookingController extends Controller
{
    private const FUNDING_STATUSES = ['not_required', 'pending_approval', 'approved', 'declined', 'expired'];

    private const BOOKING_STATUSES = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show', 'on_hold_pending_funding'];

    public function index(): Response
    {
        $bookings = RespiteBooking::query()
            ->with(['client', 'coordinator', 'serviceAgreement'])
            ->whereIn('status', ['pending', 'confirmed', 'in_progress', 'completed'])
            ->orderByDesc('start_at')
            ->paginate(20);

        return Inertia::render('respite/bookings/index', [
            'bookings' => $bookings,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('respite/bookings/create', [
            'clients' => Client::select('id', 'first_name', 'last_name')->orderBy('last_name')->orderBy('first_name')->get(),
            'requests' => RespiteBookingRequest::where('status', 'approved')->with('client')->orderByDesc('requested_start')->get(),
            'pendingRequests' => RespiteBookingRequest::whereIn('status', ['submitted', 'under_review'])
                ->with('client')
                ->orderByDesc('requested_start')
                ->get(),
            'coordinators' => User::staff()->select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'booking_request_id' => 'nullable|exists:respite_booking_requests,id',
            'client_id' => 'required|exists:clients,id',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'assigned_coordinator_id' => 'nullable|exists:users,id',
            'location_id' => 'nullable|exists:sites,id',
            'funding_source' => ['nullable', 'string', Rule::in(RespiteFundingSource::keys())],
            'funding_reference' => 'nullable|string|max:255',
            'service_agreement_id' => 'nullable|exists:service_agreements,id',
            'funding_status' => ['nullable', Rule::in(self::FUNDING_STATUSES)],
            'agreement_status' => 'nullable|in:not_sent,sent,signed,waived',
            'consent_authority' => 'nullable|in:self,activated_epoa_welfare,welfare_guardian,parent_guardian,other',
            'consent_authority_name' => 'nullable|string|max:255',
            'consent_authority_contact' => 'nullable|string|max:255',
            'consent_authority_evidence' => 'nullable|array',
            'cultural_snapshot' => 'nullable|array',
            'interpreter_arranged' => 'nullable|boolean',
            'copayment_amount' => 'nullable|numeric|min:0',
            'copayment_status' => 'nullable|in:not_applicable,quoted,accepted,invoiced,paid,waived',
            'recurrence_rule' => 'nullable|string|max:255',
            'funding_approved_ref' => 'nullable|string|max:255',
            'funding_approved_at' => 'nullable|date',
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $this->authorize('view', $client);

        if (! empty($validated['booking_request_id'])) {
            $sourceRequest = RespiteBookingRequest::query()->findOrFail($validated['booking_request_id']);

            if ((int) $sourceRequest->client_id !== (int) $client->id) {
                throw ValidationException::withMessages([
                    'booking_request_id' => 'The approved request must belong to the selected client.',
                ]);
            }

            foreach (['funding_source', 'funding_reference', 'service_agreement_id', 'funding_status', 'funding_approved_ref', 'funding_approved_at'] as $field) {
                $validated[$field] = $validated[$field] ?? $sourceRequest->{$field};
            }

            $validated['cultural_snapshot'] = $validated['cultural_snapshot'] ?? ($sourceRequest->intake_snapshot['cultural'] ?? null);
            $validated['interpreter_arranged'] = $validated['interpreter_arranged'] ?? (bool) data_get($sourceRequest->intake_snapshot, 'cultural.interpreter_arranged', false);
        }

        $this->assertServiceAgreementForClient($validated['service_agreement_id'] ?? null, $client->id);

        $validated['funding_status'] = $validated['funding_status']
            ?: (! empty($validated['funding_source']) ? 'pending_approval' : 'not_required');

        if ($validated['funding_status'] === 'approved' && empty($validated['funding_approved_at'])) {
            $validated['funding_approved_at'] = now();
        }

        $validated['agreement_status'] = $validated['agreement_status'] ?? $this->agreementStatusFor($validated['service_agreement_id'] ?? null);

        $validated['status'] = 'pending';
        $validated['created_by'] = auth()->id();

        $booking = DB::transaction(function () use ($validated) {
            $booking = RespiteBooking::create($validated);

            app(RespiteShiftSync::class)->ensureShiftForBooking($booking, auth()->id());

            return $booking;
        });

        event(new RespiteEvent('respite.booking.created', [
            'id' => $booking->id,
            'client_id' => $booking->client_id,
            'status' => $booking->status,
        ]));

        return redirect()
            ->route('respite.bookings.show', $booking)
            ->with('success', 'Respite booking created.');
    }

    public function show(RespiteBooking $booking): Response
    {
        $booking->load(['client', 'coordinator', 'request', 'allocations', 'shift', 'serviceAgreement']);
        $this->authorize('view', $booking->client);

        return Inertia::render('respite/bookings/show', [
            'booking' => $booking,
            'readiness' => $booking->readiness(),
        ]);
    }

    public function update(Request $request, RespiteBooking $booking): RedirectResponse
    {
        $booking->loadMissing('client');
        $this->authorize('view', $booking->client);

        $validated = $request->validate([
            'start_at' => 'sometimes|date',
            'end_at' => 'sometimes|date|after:start_at',
            'status' => ['sometimes', Rule::in(self::BOOKING_STATUSES)],
            'assigned_coordinator_id' => 'nullable|exists:users,id',
            'location_id' => 'nullable|exists:sites,id',
            'cancellation_reason' => 'nullable|string',
            'cancellation_source' => 'nullable|in:provider,family_whanau,client,funder,illness,other',
            'cancellation_notice_hours' => 'nullable|integer|min:0',
            'funding_source' => ['nullable', 'string', Rule::in(RespiteFundingSource::keys())],
            'funding_reference' => 'nullable|string|max:255',
            'service_agreement_id' => 'nullable|exists:service_agreements,id',
            'funding_status' => ['nullable', Rule::in(self::FUNDING_STATUSES)],
            'agreement_status' => 'nullable|in:not_sent,sent,signed,waived',
            'consent_authority' => 'nullable|in:self,activated_epoa_welfare,welfare_guardian,parent_guardian,other',
            'consent_authority_name' => 'nullable|string|max:255',
            'consent_authority_contact' => 'nullable|string|max:255',
            'consent_authority_evidence' => 'nullable|array',
            'cultural_snapshot' => 'nullable|array',
            'interpreter_arranged' => 'nullable|boolean',
            'copayment_amount' => 'nullable|numeric|min:0',
            'copayment_status' => 'nullable|in:not_applicable,quoted,accepted,invoiced,paid,waived',
            'recurrence_rule' => 'nullable|string|max:255',
            'funding_approved_ref' => 'nullable|string|max:255',
            'funding_approved_at' => 'nullable|date',
        ]);

        if (($validated['funding_status'] ?? null) === 'approved' && empty($validated['funding_approved_at'])) {
            $validated['funding_approved_at'] = now();
        }

        $this->assertServiceAgreementForClient($validated['service_agreement_id'] ?? null, $booking->client_id);

        $validated['updated_by'] = auth()->id();
        $booking->update($validated);

        app(RespiteShiftSync::class)->syncBooking($booking->fresh(['shift']));

        event(new RespiteEvent('respite.booking.updated', [
            'id' => $booking->id,
            'client_id' => $booking->client_id,
            'status' => $booking->status,
        ]));

        return back()->with('success', 'Booking updated.');
    }

    public function confirm(RespiteBooking $booking): RedirectResponse
    {
        $booking->loadMissing('client');
        $this->authorize('view', $booking->client);

        $validated = request()->validate([
            'capacity_override_reason' => 'nullable|string|max:500',
            'readiness_override_reason' => 'nullable|string|max:500',
        ]);

        $this->assertCapacityForBooking($booking, $validated['capacity_override_reason'] ?? null);
        $this->assertReadinessForBooking($booking, $validated['readiness_override_reason'] ?? null);

        $approvals = $booking->approvals ?? [];
        if (filled($validated['capacity_override_reason'] ?? null)) {
            $approvals['capacity_override'] = [
                'reason' => $validated['capacity_override_reason'],
                'recorded_by' => auth()->id(),
                'recorded_at' => now()->toIso8601String(),
            ];
        }
        if (filled($validated['readiness_override_reason'] ?? null)) {
            $approvals['readiness_override'] = [
                'reason' => $validated['readiness_override_reason'],
                'recorded_by' => auth()->id(),
                'recorded_at' => now()->toIso8601String(),
            ];
        }

        $booking->update([
            'status' => 'confirmed',
            'approvals' => $approvals ?: $booking->approvals,
            'capacity_override_reason' => $validated['capacity_override_reason'] ?? $booking->capacity_override_reason,
            'readiness_override_reason' => $validated['readiness_override_reason'] ?? $booking->readiness_override_reason,
            'updated_by' => auth()->id(),
        ]);

        app(RespiteCalendarProjector::class)->projectBooking($booking, auth()->id());

        event(new RespiteEvent('respite.booking.confirmed', [
            'id' => $booking->id,
            'client_id' => $booking->client_id,
            'status' => $booking->status,
        ]));

        return back()->with('success', 'Booking confirmed.');
    }

    private function assertCapacityForBooking(RespiteBooking $booking, ?string $overrideReason): void
    {
        $siteId = $booking->location_id ?: $booking->client?->site_id;

        if (! $siteId) {
            return;
        }

        $capacity = (int) (Site::query()->whereKey($siteId)->value('respite_capacity') ?? 0);

        if ($capacity <= 0) {
            return;
        }

        $overlapping = RespiteBooking::query()
            ->whereKeyNot($booking->id)
            ->whereIn('status', ['confirmed', 'in_progress'])
            ->where('start_at', '<', $booking->end_at)
            ->where('end_at', '>', $booking->start_at)
            ->where(function ($query) use ($siteId) {
                $query->where('location_id', $siteId)
                    ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('site_id', $siteId));
            })
            ->count();

        if ($overlapping >= $capacity && blank($overrideReason)) {
            throw ValidationException::withMessages([
                'capacity' => 'This respite home is full for the selected dates. Promote from waitlist or record a capacity override.',
            ]);
        }
    }

    private function assertReadinessForBooking(RespiteBooking $booking, ?string $overrideReason): void
    {
        $booking->loadMissing('serviceAgreement');
        $readiness = $booking->readiness();

        if (($readiness['ready'] ?? false) || filled($overrideReason)) {
            return;
        }

        $next = collect($readiness['segments'])->firstWhere('complete', false);

        throw ValidationException::withMessages([
            'readiness' => $next['message'] ?? 'Complete the pre-stay readiness checks or record an override before confirming.',
        ]);
    }

    private function agreementStatusFor(?int $serviceAgreementId): string
    {
        if (! $serviceAgreementId) {
            return 'not_sent';
        }

        $agreement = ServiceAgreement::query()->find($serviceAgreementId);

        return $agreement && ($agreement->signed_at || $agreement->signed_date) ? 'signed' : 'sent';
    }

    private function assertServiceAgreementForClient(?int $serviceAgreementId, int $clientId): void
    {
        if (! $serviceAgreementId) {
            return;
        }

        $agreement = ServiceAgreement::query()->findOrFail($serviceAgreementId);

        if ((int) $agreement->client_id !== $clientId) {
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
}
