<?php

namespace App\Services\Respite;

use App\Models\BehaviourSupportPlan;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteDailyNote;
use App\Models\RespiteEvidencePack;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\ServiceAgreement;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RespiteStayScope
{
    private const CLIENT_SITE_BYPASS_PERMISSIONS = ['clinical.accessAllSites', 'sites.viewAll'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function applyAccessibleStayScope(Builder $query, Request $request): Builder
    {
        $user = $request->user();
        $siteIds = $this->siteAccess->accessibleSiteIds($user, self::CLIENT_SITE_BYPASS_PERMISSIONS);

        if (! $user || $siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereHas('client', function (Builder $clients) use ($siteIds, $user): void {
                $clients->whereIn('site_id', $siteIds);

                if (! $user->canDo('clients.viewAny')) {
                    if (! $user->canDo('clients.viewAssigned')) {
                        $clients->whereRaw('1 = 0');

                        return;
                    }

                    $clients->whereHas('supportWorkers', fn (Builder $workers) => $workers->whereKey($user->id));
                }
            })
            ->whereHas('booking', function (Builder $bookings) use ($siteIds): void {
                $bookings
                    ->whereColumn('respite_bookings.client_id', 'respite_stays.client_id')
                    ->where(function (Builder $locations) use ($siteIds): void {
                        $locations->whereNull('location_id')->orWhereIn('location_id', $siteIds);
                    });
            });
    }

    /**
     * Canonical booking row scope for task feeds and other non-HTTP consumers.
     * It preserves the same Client assignment and booking-location boundary as
     * applyAccessibleStayScope().
     */
    public function applyAccessibleBookingScope(Builder $query, ?User $user): Builder
    {
        $siteIds = $this->siteAccess->accessibleSiteIds($user, self::CLIENT_SITE_BYPASS_PERMISSIONS);

        if (! $user || $siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereHas('client', function (Builder $clients) use ($siteIds, $user): void {
                $clients->whereIn('site_id', $siteIds);

                if (! $user->canDo('clients.viewAny')) {
                    if (! $user->canDo('clients.viewAssigned')) {
                        $clients->whereRaw('1 = 0');

                        return;
                    }

                    $clients->whereHas('supportWorkers', fn (Builder $workers) => $workers->whereKey($user->id));
                }
            })
            ->where(function (Builder $locations) use ($siteIds): void {
                $locations->whereNull('location_id')->orWhereIn('location_id', $siteIds);
            });
    }

    public function resolveAuthorizedStay(
        Request $request,
        int $stayId,
        bool $lock = false,
        bool $conceal = false,
    ): RespiteStay {
        $stayQuery = RespiteStay::query();
        if ($lock) {
            $stayQuery->lockForUpdate();
        }

        $stay = $stayQuery->findOrFail($stayId);

        $bookingQuery = RespiteBooking::query();
        $clientQuery = Client::query();
        if ($lock) {
            $bookingQuery->lockForUpdate();
            $clientQuery->lockForUpdate();
        }

        $booking = $bookingQuery->find($stay->booking_id);
        $client = $clientQuery->find($stay->client_id);

        abort_unless(
            $booking
                && $client
                && (int) $booking->client_id === (int) $stay->client_id,
            404,
        );

        $user = $request->user();
        abort_unless($user, 403);
        if ($conceal) {
            abort_unless(Gate::forUser($user)->allows('view', $client), 404);
        } else {
            Gate::forUser($user)->authorize('view', $client);
        }

        $siteId = $booking->location_id ?: $client->site_id;
        if ($conceal) {
            $siteIds = $this->siteAccess->accessibleSiteIds($user, self::CLIENT_SITE_BYPASS_PERMISSIONS);
            abort_unless($siteId && in_array((int) $siteId, $siteIds, true), 404);
        } else {
            $this->siteAccess->assertCanAccessSiteId(
                $user,
                $siteId ? (int) $siteId : null,
                self::CLIENT_SITE_BYPASS_PERMISSIONS,
            );
        }

        $stay->setRelation('booking', $booking);
        $stay->setRelation('client', $client);

        return $stay;
    }

    public function resolveAuthorizedBookingRequest(
        Request $request,
        int $requestId,
        bool $lock = false,
    ): RespiteBookingRequest {
        $requestQuery = RespiteBookingRequest::query();
        $clientQuery = Client::query();
        if ($lock) {
            $requestQuery->lockForUpdate();
            $clientQuery->lockForUpdate();
        }

        $bookingRequest = $requestQuery->findOrFail($requestId);
        $client = $clientQuery->find($bookingRequest->client_id);
        abort_unless($client, 404);

        if ($bookingRequest->referral_id) {
            $referralQuery = RespiteReferral::query();
            if ($lock) {
                $referralQuery->lockForUpdate();
            }
            abort_unless($referralQuery
                ->whereKey($bookingRequest->referral_id)
                ->where('client_id', $client->id)
                ->first(['id']), 404);
        }

        if ($bookingRequest->service_agreement_id) {
            $agreementQuery = ServiceAgreement::query();
            if ($lock) {
                $agreementQuery->lockForUpdate();
            }
            abort_unless($agreementQuery
                ->whereKey($bookingRequest->service_agreement_id)
                ->where('client_id', $client->id)
                ->first(['id']), 404);
        }

        $this->assertDirectObjectAccess($request, $client, (int) $client->site_id);
        $bookingRequest->setRelation('client', $client);

        return $bookingRequest;
    }

    public function resolveAuthorizedBooking(
        Request $request,
        int $bookingId,
        bool $lock = false,
    ): RespiteBooking {
        $bookingQuery = RespiteBooking::query();
        $clientQuery = Client::query();
        if ($lock) {
            $bookingQuery->lockForUpdate();
            $clientQuery->lockForUpdate();
        }

        $booking = $bookingQuery->findOrFail($bookingId);
        $client = $clientQuery->find($booking->client_id);
        abort_unless($client, 404);

        if ($booking->booking_request_id) {
            $sourceQuery = RespiteBookingRequest::query();
            abort_unless($sourceQuery
                ->whereKey($booking->booking_request_id)
                ->where('client_id', $client->id)
                ->first(['id']), 404);
        }

        if ($booking->service_agreement_id) {
            $agreementQuery = ServiceAgreement::query();
            abort_unless($agreementQuery
                ->whereKey($booking->service_agreement_id)
                ->where('client_id', $client->id)
                ->first(['id']), 404);
        }

        $this->assertDirectObjectAccess($request, $client, (int) $client->site_id);
        if ($booking->location_id) {
            $this->assertDirectObjectAccess($request, $client, (int) $booking->location_id);
        }

        $booking->setRelation('client', $client);

        return $booking;
    }

    public function assertAuthorizedSiteId(Request $request, int $siteId): void
    {
        $user = $request->user();
        abort_unless($user, 403);

        $siteIds = $this->siteAccess->accessibleSiteIds($user, self::CLIENT_SITE_BYPASS_PERMISSIONS);
        abort_unless($siteId > 0 && in_array($siteId, $siteIds, true), 404);
    }

    public function lockCanonicalStay(int $stayId): RespiteStay
    {
        $stay = RespiteStay::query()->lockForUpdate()->find($stayId);
        if (! $stay) {
            abort(404);
        }

        $booking = RespiteBooking::query()->lockForUpdate()->find($stay->booking_id);
        $client = Client::query()->lockForUpdate()->find($stay->client_id);
        if (! $booking
            || ! $client
            || (int) $booking->client_id !== (int) $stay->client_id) {
            abort(404);
        }

        $stay->setRelation('booking', $booking);
        $stay->setRelation('client', $client);

        return $stay;
    }

    public function siteId(RespiteStay $stay): int
    {
        $stay->loadMissing(['booking', 'client']);
        $siteId = $stay->booking?->location_id ?: $stay->client?->site_id;

        abort_unless($siteId && (int) $siteId > 0, 404);

        return (int) $siteId;
    }

    public function assertSubmittedClient(RespiteStay $stay, mixed $clientId): void
    {
        if ((int) $clientId !== (int) $stay->client_id) {
            $this->fail('client_id', 'The resident must match the selected respite stay.');
        }
    }

    public function assertSubmittedSite(RespiteStay $stay, mixed $siteId): void
    {
        if ((int) $siteId !== $this->siteId($stay)) {
            $this->fail('site_id', 'The site must match the selected respite stay.');
        }
    }

    public function dailyNote(
        RespiteStay $stay,
        int $noteId,
        ?string $field = null,
        bool $lock = false,
    ): RespiteDailyNote {
        $query = RespiteDailyNote::query()
            ->whereKey($noteId)
            ->where('stay_id', $stay->id)
            ->where('client_id', $stay->client_id);

        if ($lock) {
            $query->lockForUpdate();
        }

        $note = $query->first();
        if (! $note) {
            $this->fail($field, 'The daily note must belong to the selected respite stay.');
        }

        if ($note->linked_incident_id) {
            $this->incident($stay, (int) $note->linked_incident_id, $field ?? 'linked_incident_id', $lock);
        }

        return $note;
    }

    public function incident(
        RespiteStay $stay,
        int $incidentId,
        ?string $field = 'linked_incident_id',
        bool $lock = false,
    ): ClientIncident {
        $query = ClientIncident::query()
            ->whereKey($incidentId)
            ->where('client_id', $stay->client_id)
            ->where('site_id', $this->siteId($stay))
            ->where('respite_stay_id', $stay->id);

        if ($lock) {
            $query->lockForUpdate();
        }

        $incident = $query->first();
        if (! $incident) {
            $this->fail($field, 'The incident must belong to the same resident, site and respite stay.');
        }

        return $incident;
    }

    public function currentPlan(
        RespiteStay $stay,
        ?int $planId = null,
        ?string $field = 'behaviour_support_plan_id',
        bool $lock = false,
    ): BehaviourSupportPlan {
        $query = $this->currentPlanQuery($stay);

        if ($planId !== null) {
            $query->whereKey($planId);
        } else {
            $query->orderByRaw('review_date is null')->orderBy('review_date');
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $plan = $query->first();
        if (! $plan) {
            $this->fail($field, 'An active, current behaviour support plan for this resident is required.');
        }

        return $plan;
    }

    public function currentPlanId(RespiteStay $stay, bool $lock = false): ?int
    {
        $query = $this->currentPlanQuery($stay)
            ->orderByRaw('review_date is null')
            ->orderBy('review_date');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->value('id');
    }

    public function boundPlan(
        RespiteStay $stay,
        int $planId,
        ?string $field = 'behaviour_support_plan_id',
        bool $lock = false,
    ): BehaviourSupportPlan {
        $query = BehaviourSupportPlan::query()
            ->whereKey($planId)
            ->where('client_id', $stay->client_id);

        if ($lock) {
            $query->lockForUpdate();
        }

        $plan = $query->first();
        if (! $plan) {
            $this->fail($field, 'The behaviour support plan must belong to this resident.');
        }

        return $plan;
    }

    public function evidencePack(
        RespiteStay $stay,
        int $packId,
        ?string $field = null,
        bool $lock = false,
    ): RespiteEvidencePack {
        $query = RespiteEvidencePack::query()
            ->whereKey($packId)
            ->where('stay_id', $stay->id)
            ->where('booking_id', $stay->booking_id);

        if ($lock) {
            $query->lockForUpdate();
        }

        $pack = $query->first();
        if (! $pack) {
            $this->fail($field, 'The evidence pack must belong to the selected respite stay.');
        }

        return $pack;
    }

    public function assertEvidenceGraph(
        RespiteStay $stay,
        RespiteEvidencePack $pack,
        ?string $field = 'manifest',
        bool $lock = false,
        bool $requireCurrentPlans = false,
    ): void {
        if ((int) $pack->stay_id !== (int) $stay->id || (int) $pack->booking_id !== (int) $stay->booking_id) {
            $this->fail($field, 'The evidence pack is not bound to this respite stay.');
        }

        $this->assertStayRecords($stay, $field, $lock, $requireCurrentPlans);

        foreach ((array) $pack->included_incidents as $incidentId) {
            $this->incident($stay, (int) $incidentId, $field, $lock);
        }
        foreach ((array) $pack->included_daily_notes as $noteId) {
            $this->dailyNote($stay, (int) $noteId, $field, $lock);
        }
    }

    public function assertStayRecords(
        RespiteStay $stay,
        ?string $field = null,
        bool $lock = false,
        bool $requireCurrentPlans = false,
    ): void {
        $siteId = $this->siteId($stay);
        $noteQuery = RespiteDailyNote::query()->where('stay_id', $stay->id);
        $incidentQuery = ClientIncident::query()->where('respite_stay_id', $stay->id);
        $restraintQuery = RestraintEvent::query()->where('stay_id', $stay->id);
        if ($lock) {
            $noteQuery->lockForUpdate();
            $incidentQuery->lockForUpdate();
            $restraintQuery->lockForUpdate();
        }

        $notes = $noteQuery->get();
        foreach ($notes as $note) {
            if ((int) $note->client_id !== (int) $stay->client_id) {
                $this->fail($field, 'A daily note is not bound to this respite stay.');
            }
            if ($note->incident_occurred && ! $note->linked_incident_id) {
                $this->fail($field, 'A daily note incident is not linked to this respite stay.');
            }
            if ($note->linked_incident_id) {
                $this->incident($stay, (int) $note->linked_incident_id, $field, $lock);
            }
        }

        $incidents = $incidentQuery->get();
        foreach ($incidents as $incident) {
            if ((int) $incident->client_id !== (int) $stay->client_id
                || (int) $incident->site_id !== $siteId) {
                $this->fail($field, 'An incident is not bound to this respite stay.');
            }
        }

        $restraints = $restraintQuery->get();
        foreach ($restraints as $restraint) {
            if ((int) $restraint->client_id !== (int) $stay->client_id
                || (int) $restraint->site_id !== $siteId) {
                $this->fail($field, 'A restraint event is not bound to this respite stay.');
            }
            if ($restraint->behaviour_support_plan_id) {
                if ($requireCurrentPlans) {
                    $this->currentPlan($stay, (int) $restraint->behaviour_support_plan_id, $field, $lock);
                } else {
                    $this->boundPlan($stay, (int) $restraint->behaviour_support_plan_id, $field, $lock);
                }
            }
            if ($restraint->related_incident_id) {
                $this->incident($stay, (int) $restraint->related_incident_id, $field, $lock);
            }
        }
    }

    /** @param array<string,mixed>|null $metadata
     * @return array<string,mixed>
     */
    public function normalizeEvidenceMetadata(
        RespiteStay $stay,
        ?array $metadata,
        ?string $field = 'metadata',
        bool $lock = false,
        bool $requireCurrentPlans = true,
    ): array {
        $metadata ??= [];
        $ownership = [
            'stay_id' => (int) $stay->id,
            'client_id' => (int) $stay->client_id,
            'site_id' => $this->siteId($stay),
        ];

        foreach ($ownership as $key => $expected) {
            if (array_key_exists($key, $metadata) && (int) $metadata[$key] !== $expected) {
                $this->fail($field, 'Evidence item ownership must match the respite stay.');
            }
            $metadata[$key] = $expected;
        }

        foreach (['incident_id', 'linked_incident_id', 'related_incident_id'] as $key) {
            if (! empty($metadata[$key])) {
                $this->incident($stay, (int) $metadata[$key], $field, $lock);
            }
        }
        foreach (['daily_note_id', 'note_id'] as $key) {
            if (! empty($metadata[$key])) {
                $this->dailyNote($stay, (int) $metadata[$key], $field, $lock);
            }
        }
        if (! empty($metadata['restraint_event_id'])) {
            $this->restraint($stay, (int) $metadata['restraint_event_id'], $field, $lock);
        }
        if (! empty($metadata['behaviour_support_plan_id'])) {
            if ($requireCurrentPlans) {
                $this->currentPlan($stay, (int) $metadata['behaviour_support_plan_id'], $field, $lock);
            } else {
                $this->boundPlan($stay, (int) $metadata['behaviour_support_plan_id'], $field, $lock);
            }
        }

        return $metadata;
    }

    public function restraint(
        RespiteStay $stay,
        int $restraintId,
        ?string $field = 'restraint_event_id',
        bool $lock = false,
    ): RestraintEvent {
        $query = RestraintEvent::query()
            ->whereKey($restraintId)
            ->where('stay_id', $stay->id)
            ->where('client_id', $stay->client_id)
            ->where('site_id', $this->siteId($stay));

        if ($lock) {
            $query->lockForUpdate();
        }

        $restraint = $query->first();
        if (! $restraint) {
            $this->fail($field, 'The restraint event must belong to this respite stay.');
        }

        return $restraint;
    }

    private function fail(?string $field, string $message): never
    {
        if ($field === null) {
            abort(404);
        }

        throw ValidationException::withMessages([$field => $message]);
    }

    private function currentPlanQuery(RespiteStay $stay): Builder
    {
        return BehaviourSupportPlan::query()
            ->where('client_id', $stay->client_id)
            ->where('status', 'active')
            ->where(function (Builder $plans): void {
                $plans->whereNull('developed_at')->orWhereDate('developed_at', '<=', today());
            })
            ->where(function (Builder $plans): void {
                $plans->whereNull('review_date')->orWhereDate('review_date', '>=', today());
            });
    }

    private function assertDirectObjectAccess(Request $request, Client $client, int $siteId): void
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless(Gate::forUser($user)->allows('view', $client), 404);

        $this->assertAuthorizedSiteId($request, $siteId);
    }
}
