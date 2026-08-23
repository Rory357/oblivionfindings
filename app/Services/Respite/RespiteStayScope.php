<?php

namespace App\Services\Respite;

use App\Models\BehaviourSupportPlan;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteDailyNote;
use App\Models\RespiteEvidencePack;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RespiteStayScope
{
    private const CLIENT_SITE_BYPASS_PERMISSIONS = [
        'clinical.accessAllSites',
        'sites.viewAll',
    ];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function applyAccessibleStayScope(Builder $query, Request $request): Builder
    {
        return $this->applyStayScope(
            $query,
            $request->user(),
            self::CLIENT_SITE_BYPASS_PERMISSIONS,
            true,
        );
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

    public function applyAccessibleBookingRequestScope(Builder $query, ?User $user): Builder
    {
        return $query->whereHas(
            'client',
            fn (Builder $clients) => $this->applyAccessibleClientScope($clients, $user),
        );
    }

    public function applyAccessibleClientScope(Builder $query, ?User $user): Builder
    {
        $siteIds = $this->siteAccess->accessibleSiteIds($user, self::CLIENT_SITE_BYPASS_PERMISSIONS);
        if (! $user || $siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn('site_id', $siteIds);
        if ($user->canDo('clients.viewAny')) {
            return $query;
        }
        if (! $user->canDo('clients.viewAssigned')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'supportWorkers',
            fn (Builder $workers) => $workers->whereKey($user->id),
        );
    }

    public function applyAccessibleStaffScope(Builder $query, ?User $user): Builder
    {
        return $this->siteAccess->applyStaffScope(
            $query,
            $user,
            self::CLIENT_SITE_BYPASS_PERMISSIONS,
        );
    }

    public function applyAccessibleServiceContextScope(Builder $query, ?User $user): Builder
    {
        $siteIds = $this->siteAccess->accessibleSiteIds($user, self::CLIENT_SITE_BYPASS_PERMISSIONS);
        if (! $user || $siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('is_active', true)
            ->availableToSites($siteIds);
    }

    public function resolveAuthorizedClient(
        Request $request,
        int $clientId,
        bool $lock = false,
    ): Client {
        $user = $request->user();
        abort_unless($user, 404);

        $query = $this->applyAccessibleClientScope(Client::query(), $user);
        if ($lock) {
            $query->lockForUpdate();
        }

        $client = $query->findOrFail($clientId);
        abort_unless(Gate::forUser($user)->allows('view', $client), 404);
        $this->assertCanonicalSitesAvailable($client, null, $lock);

        return $client;
    }

    public function resolveAuthorizedBookingRequest(
        Request $request,
        int $requestId,
        bool $lock = false,
    ): RespiteBookingRequest {
        $user = $request->user();
        abort_unless($user, 404);

        $candidate = $this->applyAccessibleBookingRequestScope(
            RespiteBookingRequest::query(),
            $user,
        )->findOrFail($requestId);

        $clientQuery = Client::query();
        if ($lock) {
            $clientQuery->lockForUpdate();
        }
        $client = $clientQuery->find($candidate->client_id);
        abort_unless($client && Gate::forUser($user)->allows('view', $client), 404);
        $this->assertCanonicalSitesAvailable($client, null, $lock);

        $requestQuery = $this->applyAccessibleBookingRequestScope(
            RespiteBookingRequest::query(),
            $user,
        )
            ->whereKey($candidate->id)
            ->where('client_id', $client->id);
        if ($lock) {
            $requestQuery->lockForUpdate();
        }

        $bookingRequest = $requestQuery->firstOrFail();
        $bookingRequest->setRelation('client', $client);

        return $bookingRequest;
    }

    public function resolveAuthorizedBooking(
        Request $request,
        int $bookingId,
        bool $lock = false,
    ): RespiteBooking {
        $user = $request->user();
        abort_unless($user, 404);

        $candidate = $this->applyAccessibleBookingScope(
            RespiteBooking::query(),
            $user,
        )->findOrFail($bookingId);

        $clientQuery = Client::query();
        if ($lock) {
            $clientQuery->lockForUpdate();
        }
        $client = $clientQuery->find($candidate->client_id);
        abort_unless($client && Gate::forUser($user)->allows('view', $client), 404);
        $this->assertCanonicalSitesAvailable($client, $candidate->location_id, $lock);

        $sourceRequest = null;
        if ($candidate->booking_request_id) {
            $sourceRequestQuery = RespiteBookingRequest::withTrashed()
                ->whereKey($candidate->booking_request_id)
                ->where('client_id', $client->id);
            if ($lock) {
                $sourceRequestQuery->lockForUpdate();
            }
            $sourceRequest = $sourceRequestQuery->first();
            abort_unless($sourceRequest, 404);
        }

        $bookingQuery = $this->applyAccessibleBookingScope(
            RespiteBooking::query(),
            $user,
        )
            ->whereKey($candidate->id)
            ->where('client_id', $client->id);
        if ($lock) {
            $bookingQuery->lockForUpdate();
        }

        $booking = $bookingQuery->firstOrFail();
        $booking->setRelation('client', $client);
        if ($sourceRequest) {
            $booking->setRelation('request', $sourceRequest);
        }

        return $booking;
    }

    public function assertAuthorizedSiteId(Request $request, int $siteId): void
    {
        $this->resolveAuthorizedSite($request, $siteId);
    }

    public function resolveAuthorizedSite(
        Request $request,
        int $siteId,
        bool $lock = false,
    ): Site {
        $user = $request->user();
        abort_unless($user, 404);

        $siteIds = $this->siteAccess->accessibleSiteIds($user, self::CLIENT_SITE_BYPASS_PERMISSIONS);
        abort_unless($siteId > 0 && in_array($siteId, $siteIds, true), 404);

        $query = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereKey($siteId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function resolveAuthorizedStaffAtSite(
        Request $request,
        int $staffId,
        int $siteId,
        bool $lock = false,
    ): User {
        $user = $request->user();
        abort_unless($user, 404);
        $this->assertAuthorizedSiteId($request, $siteId);

        $query = $this->applyAccessibleStaffScope(User::query(), $user)
            ->whereKey($staffId)
            ->whereHas('hrEmployeeProfile', function (Builder $profiles) use ($siteId): void {
                $profiles->where(function (Builder $sites) use ($siteId): void {
                    $sites->where('primary_site_id', $siteId)
                        ->orWhereJsonContains('secondary_site_ids', $siteId);
                });
            });
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function resolveAuthorizedServiceContextForClient(
        Request $request,
        int $serviceContextId,
        Client $client,
        bool $lock = false,
    ): ServiceContext {
        $user = $request->user();
        abort_unless($user && $client->site_id, 404);
        $this->assertAuthorizedSiteId($request, (int) $client->site_id);

        $query = $this->applyAccessibleServiceContextScope(ServiceContext::query(), $user)
            ->availableToSite((int) $client->site_id)
            ->whereKey($serviceContextId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function resolveAuthorizedStay(
        Request $request,
        int $stayId,
        bool $lock = false,
    ): RespiteStay {
        return $this->resolveAuthorizedStayWithScope(
            $request,
            $stayId,
            $lock,
            self::CLIENT_SITE_BYPASS_PERMISSIONS,
            true,
        );
    }

    public function resolveAuthorizedHealthSafetyStay(
        Request $request,
        int $stayId,
        bool $lock = false,
    ): RespiteStay {
        return $this->resolveAuthorizedStayWithScope(
            $request,
            $stayId,
            $lock,
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
            false,
        );
    }

    /**
     * Resolve the authoritative stay graph before a read or mutation.
     *
     * @param  array<int, string>  $siteBypassPermissions
     */
    private function resolveAuthorizedStayWithScope(
        Request $request,
        int $stayId,
        bool $lock,
        array $siteBypassPermissions,
        bool $requireClientPermission,
    ): RespiteStay {
        $user = $request->user();
        abort_unless($user, 404);

        $candidate = $this->applyStayScope(
            RespiteStay::query(),
            $user,
            $siteBypassPermissions,
            $requireClientPermission,
        )->findOrFail($stayId);

        $clientQuery = Client::query();
        if ($lock) {
            $clientQuery->lockForUpdate();
        }
        $client = $clientQuery->find($candidate->client_id);

        $bookingCandidate = RespiteBooking::query()
            ->whereKey($candidate->booking_id)
            ->where('client_id', $candidate->client_id)
            ->first();
        $this->assertCanonicalSitesAvailable($client, $bookingCandidate?->location_id, $lock);
        $sourceRequest = null;
        if ($bookingCandidate?->booking_request_id) {
            $sourceRequestQuery = RespiteBookingRequest::withTrashed()
                ->whereKey($bookingCandidate->booking_request_id)
                ->where('client_id', $candidate->client_id);
            if ($lock) {
                $sourceRequestQuery->lockForUpdate();
            }
            $sourceRequest = $sourceRequestQuery->first();
        }

        $bookingQuery = RespiteBooking::query()
            ->whereKey($candidate->booking_id)
            ->where('client_id', $candidate->client_id);
        if ($lock) {
            $bookingQuery->lockForUpdate();
        }
        $booking = $bookingQuery->first();

        $stayQuery = $this->applyStayScope(
            RespiteStay::query(),
            $user,
            $siteBypassPermissions,
            $requireClientPermission,
        )
            ->whereKey($candidate->id)
            ->where('booking_id', $candidate->booking_id)
            ->where('client_id', $candidate->client_id);
        if ($lock) {
            $stayQuery->lockForUpdate();
        }
        $stay = $stayQuery->firstOrFail();

        abort_unless(
            $booking
                && $client
                && (int) $booking->client_id === (int) $stay->client_id,
            404,
        );
        abort_unless(
            ! $booking->booking_request_id || $sourceRequest,
            404,
        );

        $siteId = $booking->location_id ?: $client->site_id;
        $allowedSiteIds = $this->siteAccess->accessibleSiteIds($user, $siteBypassPermissions);
        abort_unless(
            $siteId
                && $client->site_id
                && in_array((int) $siteId, $allowedSiteIds, true)
                && in_array((int) $client->site_id, $allowedSiteIds, true),
            404,
        );
        if ($requireClientPermission) {
            abort_unless(Gate::forUser($user)->allows('view', $client), 404);
        }

        $stay->setRelation('booking', $booking);
        $stay->setRelation('client', $client);
        if ($sourceRequest) {
            $booking->setRelation('request', $sourceRequest);
        }

        return $stay;
    }

    private function assertCanonicalSitesAvailable(
        ?Client $client,
        mixed $locationId,
        bool $lock,
    ): void {
        abort_unless($client?->site_id, 404);

        $siteIds = collect([$client->site_id, $locationId])
            ->filter(fn ($siteId) => (int) $siteId > 0)
            ->map(fn ($siteId) => (int) $siteId)
            ->unique()
            ->sort()
            ->values();
        $query = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereIn('id', $siteIds)
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        abort_unless($query->get(['id'])->count() === $siteIds->count(), 404);
    }

    public function siteId(RespiteStay $stay): int
    {
        $stay->loadMissing(['booking', 'client']);
        $siteId = $stay->booking?->location_id ?: $stay->client?->site_id;

        abort_unless($siteId && (int) $siteId > 0, 404);

        return (int) $siteId;
    }

    public function assertSubmittedClient(
        RespiteStay $stay,
        mixed $clientId,
        ?string $field = 'client_id',
    ): void {
        if ((int) $clientId !== (int) $stay->client_id) {
            $this->fail($field, 'The resident must match the selected respite stay.');
        }
    }

    public function assertSubmittedSite(
        RespiteStay $stay,
        mixed $siteId,
        ?string $field = 'site_id',
    ): void {
        if ((int) $siteId !== $this->siteId($stay)) {
            $this->fail($field, 'The site must match the selected respite stay.');
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
            if (array_key_exists($key, $metadata) && $metadata[$key] !== null) {
                $metadata[$key] = $this->incident(
                    $stay,
                    (int) $metadata[$key],
                    $field,
                    $lock,
                )->id;
            }
        }
        foreach (['daily_note_id', 'note_id'] as $key) {
            if (array_key_exists($key, $metadata) && $metadata[$key] !== null) {
                $metadata[$key] = $this->dailyNote(
                    $stay,
                    (int) $metadata[$key],
                    $field,
                    $lock,
                )->id;
            }
        }
        if (array_key_exists('restraint_event_id', $metadata) && $metadata['restraint_event_id'] !== null) {
            $metadata['restraint_event_id'] = $this->restraint(
                $stay,
                (int) $metadata['restraint_event_id'],
                $field,
                $lock,
            )->id;
        }
        if (array_key_exists('behaviour_support_plan_id', $metadata) && $metadata['behaviour_support_plan_id'] !== null) {
            $plan = $requireCurrentPlans
                ? $this->currentPlan($stay, (int) $metadata['behaviour_support_plan_id'], $field, $lock)
                : $this->boundPlan($stay, (int) $metadata['behaviour_support_plan_id'], $field, $lock);
            $metadata['behaviour_support_plan_id'] = $plan->id;
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

    /**
     * @param  array<int, string>  $siteBypassPermissions
     */
    private function applyStayScope(
        Builder $query,
        ?User $user,
        array $siteBypassPermissions,
        bool $requireClientPermission,
    ): Builder {
        $siteIds = $this->siteAccess->accessibleSiteIds($user, $siteBypassPermissions);
        if (! $user || $siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereHas('client', function (Builder $clients) use ($siteIds, $user, $requireClientPermission): void {
                $clients->whereIn('site_id', $siteIds);

                if (! $requireClientPermission || $user->canDo('clients.viewAny')) {
                    return;
                }
                if (! $user->canDo('clients.viewAssigned')) {
                    $clients->whereRaw('1 = 0');

                    return;
                }

                $clients->whereHas('supportWorkers', fn (Builder $workers) => $workers->whereKey($user->id));
            })
            ->whereHas('booking', function (Builder $bookings) use ($siteIds): void {
                $bookings
                    ->whereColumn('respite_bookings.client_id', 'respite_stays.client_id')
                    ->where(function (Builder $locations) use ($siteIds): void {
                        $locations->whereNull('location_id')->orWhereIn('location_id', $siteIds);
                    });
            });
    }
}
