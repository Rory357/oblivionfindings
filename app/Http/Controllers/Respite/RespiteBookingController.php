<?php

namespace App\Http\Controllers\Respite;

use App\Domain\Finance\Services\AccountsReceivableService;
use App\Events\Respite\RespiteEvent;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteStay;
use App\Models\ServiceAgreement;
use App\Models\Site;
use App\Models\User;
use App\Services\Respite\RespiteCalendarProjector;
use App\Services\Respite\RespiteShiftSync;
use App\Services\Respite\RespiteStateTransitionService;
use App\Services\Respite\RespiteStayScope;
use App\Support\Respite\RespiteFundingSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RespiteBookingController extends Controller
{
    private const FUNDING_STATUSES = ['not_required', 'pending_approval', 'approved', 'declined', 'expired'];

    public function __construct(
        private readonly AccountsReceivableService $accountsReceivable,
        private readonly RespiteStateTransitionService $states,
        private readonly RespiteStayScope $scope,
    ) {}

    public function index(Request $request): Response
    {
        $bookings = $this->scope->applyAccessibleBookingScope(
            RespiteBooking::query(),
            $request->user(),
        )
            ->with(['client', 'coordinator', 'serviceAgreement'])
            ->whereIn('status', ['pending', 'confirmed', 'in_progress', 'completed'])
            ->orderByDesc('start_at')
            ->paginate(20);

        return Inertia::render('respite/bookings/index', [
            'bookings' => $bookings,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('respite/bookings/create', [
            'clients' => $this->scope->applyAccessibleClientScope(Client::query(), $request->user())
                ->select('id', 'first_name', 'last_name')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
            'requests' => $this->scope->applyAccessibleBookingRequestScope(
                RespiteBookingRequest::query(),
                $request->user(),
            )
                ->where('status', 'approved')
                ->with('client')
                ->orderByDesc('requested_start')
                ->get(),
            'pendingRequests' => $this->scope->applyAccessibleBookingRequestScope(
                RespiteBookingRequest::query(),
                $request->user(),
            )
                ->whereIn('status', ['submitted', 'under_review'])
                ->with('client')
                ->orderByDesc('requested_start')
                ->get(),
            'coordinators' => $this->scope->applyAccessibleStaffScope(User::query(), $request->user())
                ->select('id', 'name')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'booking_request_id' => 'nullable|integer|min:1',
            'client_id' => 'required|integer|min:1',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'assigned_coordinator_id' => 'nullable|integer|min:1',
            'location_id' => 'nullable|integer|min:1',
            'funding_source' => ['nullable', 'string', Rule::in(RespiteFundingSource::keys())],
            'funding_reference' => 'nullable|string|max:255',
            'service_agreement_id' => 'nullable|integer|min:1',
            'funding_status' => ['nullable', Rule::in(self::FUNDING_STATUSES)],
            'agreement_status' => 'nullable|in:not_sent,sent,signed,waived',
            'consent_authority' => 'nullable|in:self,activated_epoa_welfare,welfare_guardian,parent_guardian,other',
            'consent_authority_name' => 'nullable|string|max:255',
            'consent_authority_contact' => 'nullable|string|max:255',
            'consent_authority_evidence' => 'nullable|array',
            'code_of_rights_provided' => 'nullable|boolean',
            'consent_to_respite' => 'nullable|boolean',
            'consent_capacity_basis' => 'nullable|in:has_capacity,supported_decision_making,substitute_decision,best_interests,not_recorded',
            'advocate_offered' => 'nullable|boolean',
            'rights_format_provided' => 'nullable|in:written,easy_read,verbal,te_reo,translated,other',
            'cultural_snapshot' => 'nullable|array',
            'cultural_placement_check' => 'nullable|array',
            'setting_restriction' => 'nullable|in:none,locked_unit,enhanced_observation,restricted_leave,other',
            'interpreter_arranged' => 'nullable|boolean',
            'copayment_amount' => 'nullable|numeric|min:0',
            'copayment_basis' => 'nullable|in:none,per_night,fixed',
            'private_pay_portion' => 'nullable|numeric|min:0',
            'copayment_status' => 'nullable|in:not_applicable,quoted,accepted,invoiced,paid,waived',
            'recurrence_rule' => 'nullable|string|max:255',
            'series_id' => 'nullable|string|max:100',
            'funding_approved_ref' => 'nullable|string|max:255',
            'funding_approved_at' => 'nullable|date',
        ]);

        if (array_key_exists('location_id', $validated) && $validated['location_id'] !== null) {
            $this->states->assertAuthorizedSite($request, (int) $validated['location_id']);
        }

        $createBooking = function (Client $client, ?RespiteBookingRequest $sourceRequest = null) use ($request, $validated): RespiteBooking {
            $updates = $validated;

            if ($sourceRequest) {
                if ($sourceRequest->status !== 'approved') {
                    throw ValidationException::withMessages([
                        'booking_request_id' => 'Only an approved booking request can create a respite booking.',
                    ]);
                }
                if ((int) $sourceRequest->client_id !== (int) $client->id
                    || (int) $sourceRequest->client_id !== (int) $updates['client_id']) {
                    throw ValidationException::withMessages([
                        'booking_request_id' => 'The approved request must belong to the selected client.',
                    ]);
                }
                if (RespiteBooking::withTrashed()
                    ->where('booking_request_id', $sourceRequest->id)
                    ->lockForUpdate()
                    ->first(['id']) !== null) {
                    throw ValidationException::withMessages([
                        'booking_request_id' => 'The approved request already has a respite booking.',
                    ]);
                }

                foreach (['funding_source', 'funding_reference', 'service_agreement_id', 'funding_status', 'funding_approved_ref', 'funding_approved_at', 'recurrence_rule', 'series_id'] as $field) {
                    $updates[$field] = $updates[$field] ?? $sourceRequest->{$field};
                }

                $updates['cultural_snapshot'] = $updates['cultural_snapshot'] ?? ($sourceRequest->intake_snapshot['cultural'] ?? null);
                $updates['interpreter_arranged'] = $updates['interpreter_arranged'] ?? (bool) data_get($sourceRequest->intake_snapshot, 'cultural.interpreter_arranged', false);
            }

            $this->assertServiceAgreementForClient($updates['service_agreement_id'] ?? null, $client->id);

            $siteId = (int) (($updates['location_id'] ?? null) ?: $client->site_id);
            $this->scope->resolveAuthorizedSite($request, $siteId, true);
            if (! empty($updates['assigned_coordinator_id'])) {
                $this->scope->resolveAuthorizedStaffAtSite(
                    $request,
                    (int) $updates['assigned_coordinator_id'],
                    $siteId,
                    true,
                );
            }

            $updates['funding_status'] = ($updates['funding_status'] ?? null)
                ?: (! empty($updates['funding_source']) ? 'pending_approval' : 'not_required');
            if ($updates['funding_status'] === 'approved' && empty($updates['funding_approved_at'])) {
                $updates['funding_approved_at'] = now();
            }

            $updates['agreement_status'] = $updates['agreement_status'] ?? $this->agreementStatusFor($updates['service_agreement_id'] ?? null);
            if ($this->containsRightsCapture($updates)) {
                $updates['rights_recorded_by'] = auth()->id();
                $updates['rights_recorded_at'] = now();
            }

            $updates['status'] = 'pending';
            $updates['created_by'] = auth()->id();
            $booking = RespiteBooking::create($updates);

            app(RespiteShiftSync::class)->ensureShiftForBooking($booking, auth()->id());

            return $booking;
        };

        $hasSourceRequest = array_key_exists('booking_request_id', $validated)
            && $validated['booking_request_id'] !== null;
        $booking = $hasSourceRequest
            ? $this->states->transitionRequest(
                $request,
                (int) $validated['booking_request_id'],
                fn (RespiteBookingRequest $sourceRequest): RespiteBooking => $createBooking(
                    $sourceRequest->client,
                    $sourceRequest,
                ),
            )
            : DB::transaction(
                fn (): RespiteBooking => $createBooking(
                    $this->scope->resolveAuthorizedClient($request, (int) $validated['client_id'], true),
                ),
                3,
            );

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
        $booking = $this->scope->resolveAuthorizedBooking(request(), (int) $booking->id);
        $booking->load(['client', 'coordinator', 'request', 'allocations', 'shift', 'serviceAgreement']);

        return Inertia::render('respite/bookings/show', [
            'booking' => $booking,
            'readiness' => $booking->readiness(),
        ]);
    }

    public function update(Request $request, RespiteBooking $booking): RedirectResponse
    {
        $this->states->assertBookingAccessible($request, (int) $booking->id);

        $validated = $request->validate([
            'start_at' => 'sometimes|date',
            'end_at' => 'sometimes|date|after:start_at',
            'status' => ['sometimes', Rule::in(RespiteStateTransitionService::BOOKING_GENERIC_STATUSES)],
            'assigned_coordinator_id' => 'nullable|integer|min:1',
            'location_id' => 'nullable|integer|min:1',
            'cancellation_reason' => 'nullable|string',
            'cancellation_source' => 'nullable|in:provider,family_whanau,client,funder,illness,other',
            'cancellation_notice_hours' => 'nullable|integer|min:0',
            'funding_source' => ['nullable', 'string', Rule::in(RespiteFundingSource::keys())],
            'funding_reference' => 'nullable|string|max:255',
            'service_agreement_id' => 'nullable|integer|min:1',
            'funding_status' => ['nullable', Rule::in(self::FUNDING_STATUSES)],
            'agreement_status' => 'nullable|in:not_sent,sent,signed,waived',
            'consent_authority' => 'nullable|in:self,activated_epoa_welfare,welfare_guardian,parent_guardian,other',
            'consent_authority_name' => 'nullable|string|max:255',
            'consent_authority_contact' => 'nullable|string|max:255',
            'consent_authority_evidence' => 'nullable|array',
            'code_of_rights_provided' => 'nullable|boolean',
            'consent_to_respite' => 'nullable|boolean',
            'consent_capacity_basis' => 'nullable|in:has_capacity,supported_decision_making,substitute_decision,best_interests,not_recorded',
            'advocate_offered' => 'nullable|boolean',
            'rights_format_provided' => 'nullable|in:written,easy_read,verbal,te_reo,translated,other',
            'cultural_snapshot' => 'nullable|array',
            'cultural_placement_check' => 'nullable|array',
            'setting_restriction' => 'nullable|in:none,locked_unit,enhanced_observation,restricted_leave,other',
            'interpreter_arranged' => 'nullable|boolean',
            'copayment_amount' => 'nullable|numeric|min:0',
            'copayment_basis' => 'nullable|in:none,per_night,fixed',
            'private_pay_portion' => 'nullable|numeric|min:0',
            'copayment_status' => 'nullable|in:not_applicable,quoted,accepted,invoiced,paid,waived',
            'recurrence_rule' => 'nullable|string|max:255',
            'series_id' => 'nullable|string|max:100',
            'funding_approved_ref' => 'nullable|string|max:255',
            'funding_approved_at' => 'nullable|date',
        ]);

        if (array_key_exists('location_id', $validated) && $validated['location_id'] !== null) {
            $this->states->assertAuthorizedSite($request, (int) $validated['location_id']);
        }

        $booking = $this->states->transitionBooking(
            $request,
            (int) $booking->id,
            function (RespiteBooking $booking) use ($request, $validated): RespiteBooking {
                $targetStatus = array_key_exists('status', $validated) ? $validated['status'] : null;
                $this->states->assertBookingUpdate($booking->status, $targetStatus);

                $scheduleChanged = (array_key_exists('start_at', $validated)
                        && ! Carbon::parse($validated['start_at'])->equalTo($booking->start_at))
                    || (array_key_exists('end_at', $validated)
                        && ! Carbon::parse($validated['end_at'])->equalTo($booking->end_at))
                    || (array_key_exists('location_id', $validated)
                        && (int) ($validated['location_id'] ?? 0) !== (int) ($booking->location_id ?? 0));
                $endingBooking = in_array($targetStatus, ['cancelled', 'no_show'], true);
                if ($scheduleChanged || $endingBooking) {
                    $this->states->assertBookingHasNoCurrentStay(
                        RespiteStay::withTrashed()
                            ->where('booking_id', $booking->id)
                            ->where('status', '!=', 'discharged')
                            ->lockForUpdate()
                            ->first(['id']) !== null,
                        $scheduleChanged
                            ? 'rescheduled or moved; use the stay lifecycle actions instead'
                            : ($targetStatus === 'cancelled' ? 'cancelled' : 'recorded as a no show'),
                    );
                }

                $updates = $validated;
                if (($updates['funding_status'] ?? null) === 'approved' && empty($updates['funding_approved_at'])) {
                    $updates['funding_approved_at'] = now();
                }
                if ($this->containsRightsCapture($updates)) {
                    $updates['rights_recorded_by'] = auth()->id();
                    $updates['rights_recorded_at'] = now();
                }

                $serviceAgreementId = array_key_exists('service_agreement_id', $updates)
                    ? $updates['service_agreement_id']
                    : $booking->service_agreement_id;
                $this->assertServiceAgreementForClient($serviceAgreementId, $booking->client_id);

                $previousSiteId = (int) ($booking->location_id ?: $booking->client->site_id);
                $siteId = (int) ((array_key_exists('location_id', $updates) ? $updates['location_id'] : $booking->location_id)
                    ?: $booking->client->site_id);
                $this->scope->resolveAuthorizedSite($request, $siteId, true);
                $coordinatorId = array_key_exists('assigned_coordinator_id', $updates)
                    ? $updates['assigned_coordinator_id']
                    : $booking->assigned_coordinator_id;
                if ($coordinatorId !== null) {
                    $this->scope->resolveAuthorizedStaffAtSite(
                        $request,
                        (int) $coordinatorId,
                        $siteId,
                        true,
                    );
                }

                $updates['updated_by'] = auth()->id();
                $booking->update($updates);
                app(RespiteShiftSync::class)->syncBooking($booking->fresh(), $previousSiteId);

                return $booking;
            },
        );

        event(new RespiteEvent('respite.booking.updated', [
            'id' => $booking->id,
            'client_id' => $booking->client_id,
            'status' => $booking->status,
        ]));

        return back()->with('success', 'Booking updated.');
    }

    public function confirm(RespiteBooking $booking): RedirectResponse
    {
        $httpRequest = request();
        $this->states->assertBookingAccessible($httpRequest, (int) $booking->id);

        $validated = $httpRequest->validate([
            'capacity_override_reason' => 'nullable|string|max:500',
            'readiness_override_reason' => 'nullable|string|max:500',
            'service_agreement_id' => 'nullable|integer|min:1',
            'consent_authority' => 'nullable|in:self,activated_epoa_welfare,welfare_guardian,parent_guardian,other',
            'consent_authority_name' => 'nullable|string|max:255',
            'consent_authority_contact' => 'nullable|string|max:255',
            'consent_authority_evidence' => 'nullable|array',
            'code_of_rights_provided' => 'nullable|boolean',
            'consent_to_respite' => 'nullable|boolean',
            'consent_capacity_basis' => 'nullable|in:has_capacity,supported_decision_making,substitute_decision,best_interests,not_recorded',
            'advocate_offered' => 'nullable|boolean',
            'rights_format_provided' => 'nullable|in:written,easy_read,verbal,te_reo,translated,other',
        ]);

        $booking = $this->states->transitionBooking(
            $httpRequest,
            (int) $booking->id,
            function (RespiteBooking $booking) use ($validated): RespiteBooking {
                $this->states->assertBookingConfirmation($booking->status);
                $this->states->assertBookingHasNoCurrentStay(
                    RespiteStay::withTrashed()
                        ->where('booking_id', $booking->id)
                        ->where('status', '!=', 'discharged')
                        ->lockForUpdate()
                        ->first(['id']) !== null,
                    'confirmed',
                );

                $this->captureConfirmReadinessInputs($booking, $validated);
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

                return $booking;
            },
        );

        // Capture-at-source: a confirmed booking with a funder + an agreed daily
        // rate becomes a draft receivable invoice to the funder. Idempotent + non-fatal.
        $this->captureRespiteInvoice($booking);

        event(new RespiteEvent('respite.booking.confirmed', [
            'id' => $booking->id,
            'client_id' => $booking->client_id,
            'status' => $booking->status,
        ]));

        return back()->with('success', 'Booking confirmed.');
    }

    /**
     * Post a confirmed respite booking's care cost to accounts receivable as a
     * DRAFT invoice billed to the funder (nights × the service agreement's daily
     * rate, zero-rated — funded disability support). The finance service is
     * idempotent on the RespiteBooking source, so this can run on every confirm
     * without duplicating. GL-safe (draft) and never blocks the confirmation.
     */
    private function captureRespiteInvoice(RespiteBooking $booking): void
    {
        $booking->loadMissing('serviceAgreement', 'client');
        $rate = (float) ($booking->serviceAgreement->daily_rate ?? 0);

        if ($rate <= 0 || ! $booking->funding_source || ! $booking->start_at || ! $booking->end_at) {
            return;
        }

        $nights = max(1, $booking->start_at->diffInDays($booking->end_at));

        try {
            $orgId = auth()->user()?->organization_id;

            // Best-effort funder attribution: when the booking's funding source
            // matches a configured funding stream, the invoice line carries the
            // stream (and the stream's default revenue account, if set) so the
            // funder income lands on the funding-stream summary — the GL-level
            // drawdown attribution.
            $stream = $this->accountsReceivable->resolveFundingStream($orgId, $booking->funding_source);

            $this->accountsReceivable->captureOperationalInvoice($orgId, [
                'source_type' => RespiteBooking::class,
                'source_id' => $booking->id,
                'funding_body' => $stream?->name ?? (string) $booking->funding_source,
                'client_id' => $booking->client_id,
                'client_name' => $booking->client?->full_name,
                'description' => "Respite care — {$nights} night(s)",
                'quantity' => $nights,
                'unit_price' => $rate,
                'gst_rate' => 0,
                'revenue_account_id' => $stream?->default_revenue_account_id,
                'revenue_account_code' => config('finance.capture.respite_revenue_account', '4000'),
                'funding_stream_id' => $stream?->id,
                'notes' => "Auto-captured from respite booking #{$booking->id}.",
            ]);
        } catch (\Throwable $e) {
            Log::error("Respite funder invoice capture failed for booking #{$booking->id}: {$e->getMessage()}");
        }
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    private function captureConfirmReadinessInputs(RespiteBooking $booking, array $validated): void
    {
        $fields = [
            'service_agreement_id',
            'consent_authority',
            'consent_authority_name',
            'consent_authority_contact',
            'consent_authority_evidence',
            'code_of_rights_provided',
            'consent_to_respite',
            'consent_capacity_basis',
            'advocate_offered',
            'rights_format_provided',
        ];
        $updates = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        if ($updates === []) {
            return;
        }

        $this->assertServiceAgreementForClient($updates['service_agreement_id'] ?? $booking->service_agreement_id, $booking->client_id);

        if (array_key_exists('service_agreement_id', $updates) && ! array_key_exists('agreement_status', $updates)) {
            $updates['agreement_status'] = $this->agreementStatusFor($updates['service_agreement_id']);
        }

        if ($this->containsRightsCapture($updates)) {
            $updates['rights_recorded_by'] = auth()->id();
            $updates['rights_recorded_at'] = now();
        }

        $updates['updated_by'] = auth()->id();
        $booking->update($updates);
        $booking->refresh();
    }

    /**
     * @param  array<string,mixed>  $updates
     */
    private function containsRightsCapture(array $updates): bool
    {
        foreach (['code_of_rights_provided', 'consent_to_respite', 'consent_capacity_basis', 'advocate_offered', 'rights_format_provided'] as $field) {
            if (array_key_exists($field, $updates)) {
                return true;
            }
        }

        return false;
    }

    private function assertCapacityForBooking(RespiteBooking $booking, ?string $overrideReason): void
    {
        $siteId = $booking->location_id ?: $booking->client?->site_id;

        if (! $siteId) {
            return;
        }

        $site = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->find($siteId);
        abort_unless($site, 404);

        $capacity = (int) ($site->respite_capacity ?? 0);

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
                    ->orWhere(function ($fallback) use ($siteId) {
                        $fallback->whereNull('location_id')
                            ->whereHas('client', fn ($clientQuery) => $clientQuery->where('site_id', $siteId));
                    });
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
        if ($serviceAgreementId === null) {
            return;
        }

        $agreement = ServiceAgreement::query()
            ->whereKey($serviceAgreementId)
            ->where('client_id', $clientId)
            ->lockForUpdate()
            ->first();

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
}
