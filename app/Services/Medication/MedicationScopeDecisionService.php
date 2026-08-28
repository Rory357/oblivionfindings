<?php

namespace App\Services\Medication;

use App\Models\BreakGlassAccessEvent;
use App\Models\BreakGlassPolicy;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationRound;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\MarScheduleService;
use App\Services\MedicationRuleService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The server-authoritative relationship and work-scope boundary for medication
 * writes. Every callback runs while the resolved aggregate rows remain locked.
 */
class MedicationScopeDecisionService
{
    private const GENERIC_NOT_FOUND = 'The requested medication action is not available.';

    private const GENERIC_NOT_ASSIGNED = 'You do not have a current assignment for this medication action.';

    public function __construct(
        private readonly MarScheduleService $schedule,
        private readonly UserSiteAccessService $siteAccess,
        private readonly MedicationGovernanceScopeService $medicationGovernance,
        private readonly MedicationRuleService $medicationRules,
    ) {}

    public function forAdministration(
        User $performer,
        ?Client $submittedClient,
        ClientMedication $submittedMedication,
        Carbon $actionAt,
        ?Carbon $scheduledFor,
        ?int $submittedShiftId,
        ?MedicationRound $submittedRound,
        Closure $callback,
        ?Closure $scopedInputResolver = null,
        array $authorizationUserIds = [],
    ): mixed {
        return DB::transaction(function () use (
            $performer,
            $submittedClient,
            $submittedMedication,
            $actionAt,
            $scheduledFor,
            $submittedShiftId,
            $submittedRound,
            $callback,
            $scopedInputResolver,
            $authorizationUserIds,
        ) {
            abort_unless($performer->canDo('medications.administer.record'), 403);

            $medicationSnapshot = ClientMedication::query()
                ->whereKey($submittedMedication->getKey())
                ->whereNull('deleted_at')
                ->first(['id', 'client_id']);
            $this->notFoundUnless($medicationSnapshot !== null);

            $client = Client::query()
                ->whereKey($medicationSnapshot->client_id)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless(
                $client !== null
                && ($submittedClient === null || (int) $client->id === (int) $submittedClient->getKey())
            );

            $medication = ClientMedication::query()
                ->whereKey($medicationSnapshot->id)
                ->where('client_id', $client->id)
                ->whereNull('deleted_at')
                ->whereNull('superseded_by')
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($medication !== null);
            $medication->setRelation('client', $client);
            $this->notFoundUnless(
                ! (bool) $medication->controlled_drug
                || $performer->canDo('medications.controlled.record')
            );
            $this->notFoundUnless(in_array(
                $this->clientSiteId($client),
                $this->siteAccess->accessibleSiteIds($performer, ['clinical.accessAllSites', 'sites.viewAll']),
                true,
            ));

            if ($scopedInputResolver !== null) {
                // A resolver may produce detailed validation errors, so first
                // perform the existing read-side projection of the canonical
                // current-work boundary. This query never locks or grants
                // authority; the locked decision below remains authoritative.
                $this->notFoundUnless(in_array(
                    (int) $client->id,
                    $this->clientIdsWithCurrentAuthority($performer, [(int) $client->id], $actionAt),
                    true,
                ));
            }

            $roundSnapshot = null;
            if ($submittedRound !== null) {
                $roundSnapshot = MedicationRound::query()
                    ->whereKey($submittedRound->getKey())
                    ->first([
                        'id',
                        'site_id',
                        'service_context_id',
                        'assigned_to',
                        'started_by',
                        'status',
                    ]);
                $this->notFoundUnless(
                    $roundSnapshot !== null
                    && $roundSnapshot->status === 'in_progress'
                    && $this->positiveId($roundSnapshot->site_id) === $this->clientSiteId($client)
                    && ($roundSnapshot->service_context_id === null
                        || (int) $roundSnapshot->service_context_id === (int) $client->service_context_id)
                    && $this->roundBelongsToPerformer($roundSnapshot, $performer)
                    && ! $medication->is_prn
                );
            }

            $scopedInput = $scopedInputResolver !== null
                ? $scopedInputResolver($client, $medication)
                : null;
            if (is_array($scopedInput)) {
                $scheduledFor = $scopedInput['scheduled_for'] ?? $scheduledFor;
                $actionAt = $scopedInput['action_at'] ?? $actionAt;
                if (array_key_exists('shift_id', $scopedInput)) {
                    $submittedShiftId = $scopedInput['shift_id'] !== null
                        ? (int) $scopedInput['shift_id']
                        : null;
                }
                $scopedWitnessId = data_get($scopedInput, 'payload.witnessed_by');
                if (is_numeric($scopedWitnessId) && (int) $scopedWitnessId > 0) {
                    $authorizationUserIds[] = (int) $scopedWitnessId;
                }
            }
            $this->notFoundUnless($actionAt->lessThanOrEqualTo(now()->addMinute()));

            // Freeze every performer/witness presence Shift as one ascending
            // set before resolving the selected performer Shift and before the
            // complete authorization User batch. This prevents cross-witness
            // A/B requests from acquiring Shift A -> Users -> Shift B in the
            // opposite order.
            $presenceUserIds = collect([(int) $performer->id, ...$authorizationUserIds])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->sort()
                ->values()
                ->all();
            $lockedPresenceShifts = $this->medicationGovernance->lockControlledWitnessPresenceShifts(
                $presenceUserIds,
                $this->clientSiteId($client),
                $actionAt,
                $submittedShiftId !== null ? [$submittedShiftId] : [],
            );

            if ($roundSnapshot === null) {
                if ($medication->is_prn) {
                    $this->notFoundUnless($scheduledFor === null);
                } else {
                    $this->assertScheduledCell($medication, $scheduledFor);
                }
            }
            $this->assertMedicationIsActiveFor($medication, $scheduledFor ?? $actionAt);

            [$shift, $breakGlass] = $this->resolveClientAuthority(
                $performer,
                $client,
                $actionAt,
                $submittedShiftId,
            );

            // EnhancedMar re-enters this exact rule-set lock inside the same
            // transaction. Freeze the complete current set before the outer
            // User/Profile authority batch so the nested path cannot invert
            // User -> Rule against settings publication (Rule -> User), and a
            // newly applicable countersign rule cannot introduce a late User.
            $this->medicationRules->requirementsFor($medication, true);

            [$performer, $breakGlass] = $this->lockCurrentAdministrationAuthority(
                $performer,
                $client,
                $medication,
                $actionAt,
                $breakGlass,
                $authorizationUserIds,
                $lockedPresenceShifts,
            );

            // Round lifecycle mutations use User/Profile -> Site -> Round. The
            // administration path must preserve that prefix: freeze identity
            // above, then constrain and lock the same binding only after the
            // complete current authority graph and active Site are held.
            $round = null;
            if ($roundSnapshot !== null) {
                $roundQuery = MedicationRound::query()
                    ->whereKey($roundSnapshot->id)
                    ->where('site_id', $roundSnapshot->site_id)
                    ->where('status', $roundSnapshot->status);
                foreach (['service_context_id', 'assigned_to', 'started_by'] as $column) {
                    $value = $roundSnapshot->getAttribute($column);
                    $value === null
                        ? $roundQuery->whereNull($column)
                        : $roundQuery->where($column, $value);
                }
                $round = $roundQuery->lockForUpdate()->first();
                $this->notFoundUnless($round !== null);
                $this->assertRoundCell($performer, $round, $client, $medication, $scheduledFor);
            }

            $decision = new MedicationScopeDecision(
                performer: $performer,
                client: $client,
                siteId: $this->clientSiteId($client),
                shift: $shift,
                breakGlassAccess: $breakGlass,
                medication: $medication,
                round: $round,
                lockedPresenceShifts: $lockedPresenceShifts,
                lockedPresenceEffectiveAt: $actionAt,
            );

            return $callback($decision, is_array($scopedInput) ? ($scopedInput['payload'] ?? null) : null);
        }, 3);
    }

    public function forPrnEffectiveness(
        User $performer,
        ClientMedicationAdministration $submittedAdministration,
        Carbon $actionAt,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($performer, $submittedAdministration, $actionAt, $callback) {
            $administrationSnapshot = ClientMedicationAdministration::query()
                ->whereKey($submittedAdministration->getKey())
                ->first(['id', 'client_id', 'client_medication_id', 'is_correction', 'corrected_of_id']);
            $this->notFoundUnless($administrationSnapshot !== null);

            $client = Client::query()
                ->whereKey($administrationSnapshot->client_id)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($client !== null);

            $medication = ClientMedication::query()
                ->whereKey($administrationSnapshot->client_medication_id)
                ->where('client_id', $client->id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless(
                $medication !== null
                && $medication->is_prn
            );
            $originalId = $administrationSnapshot->is_correction
                ? $administrationSnapshot->corrected_of_id
                : $administrationSnapshot->id;
            $this->notFoundUnless(is_numeric($originalId) && (int) $originalId > 0);
            $original = ClientMedicationAdministration::query()
                ->whereKey((int) $originalId)
                ->where('client_id', $client->id)
                ->where('client_medication_id', $medication->id)
                ->where(function ($query): void {
                    $query->where('is_correction', false)
                        ->orWhereNull('is_correction');
                })
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($original !== null);

            $administrationQuery = ClientMedicationAdministration::query()
                ->effectiveClinicalEvidence()
                ->whereKey($administrationSnapshot->id)
                ->where('client_id', $client->id)
                ->where('client_medication_id', $medication->id);
            $administration = (int) $administrationSnapshot->id === (int) $original->id
                ? $administrationQuery->first()
                : $administrationQuery->lockForUpdate()->first();
            $this->notFoundUnless($administration !== null && $administration->status === 'given');

            [$shift, $breakGlass] = $this->resolveClientAuthority($performer, $client, $actionAt, null);
            $this->assertMedicationIsActiveFor($medication, $actionAt);
            [$performer, $breakGlass] = $this->lockCurrentAdministrationAuthority(
                $performer,
                $client,
                $medication,
                $actionAt,
                $breakGlass,
            );

            return $callback(new MedicationScopeDecision(
                performer: $performer,
                client: $client,
                siteId: $this->clientSiteId($client),
                shift: $shift,
                breakGlassAccess: $breakGlass,
                medication: $medication,
                administration: $administration,
            ));
        }, 3);
    }

    public function forClient(
        User $performer,
        int $submittedClientId,
        Carbon $actionAt,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($performer, $submittedClientId, $actionAt, $callback) {
            $client = Client::query()->whereKey($submittedClientId)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null);
            [$shift, $breakGlass] = $this->resolveClientAuthority($performer, $client, $actionAt, null);

            return $callback(new MedicationScopeDecision(
                performer: $performer,
                client: $client,
                siteId: $this->clientSiteId($client),
                shift: $shift,
                breakGlassAccess: $breakGlass,
            ));
        }, 3);
    }

    public function forMedication(
        User $performer,
        ClientMedication $submittedMedication,
        Carbon $actionAt,
        Closure $callback,
        bool $requireAdministrable = false,
        ?int $submittedClientId = null,
        bool $allowCeased = false,
    ): mixed {
        return DB::transaction(function () use (
            $performer,
            $submittedMedication,
            $actionAt,
            $callback,
            $requireAdministrable,
            $submittedClientId,
            $allowCeased,
        ) {
            $medicationSnapshot = ClientMedication::query()
                ->whereKey($submittedMedication->getKey())
                ->whereNull('deleted_at')
                ->whereNull('superseded_by')
                ->first(['id', 'client_id']);
            $this->notFoundUnless($medicationSnapshot !== null);

            $client = Client::query()->whereKey($medicationSnapshot->client_id)->lockForUpdate()->first();
            $this->notFoundUnless(
                $client !== null
                && ($submittedClientId === null || (int) $client->id === $submittedClientId)
            );

            $medication = ClientMedication::query()
                ->whereKey($medicationSnapshot->id)
                ->where('client_id', $client->id)
                ->whereNull('deleted_at')
                ->whereNull('superseded_by')
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($medication !== null);

            $siteId = $this->clientSiteId($client);
            // Site is reference evidence, not part of the medication write
            // aggregate. Keeping this read non-locking avoids inverting the
            // Site -> Client -> medication order used by alert mutations.
            $site = Site::query()->whereKey($siteId)->first();
            $this->notFoundUnless($site !== null && (int) $site->id === $siteId);
            [$shift, $breakGlass] = $this->resolveClientAuthority($performer, $client, $actionAt, null);
            if (! $allowCeased) {
                $this->notFoundUnless($medication->state !== 'ceased' && $medication->ceased_at === null);
            }
            if ($requireAdministrable) {
                $this->assertMedicationIsActiveFor($medication, $actionAt);
            }

            return $callback(new MedicationScopeDecision(
                performer: $performer,
                client: $client,
                siteId: $this->clientSiteId($client),
                shift: $shift,
                breakGlassAccess: $breakGlass,
                medication: $medication,
            ));
        }, 3);
    }

    public function forPrescription(
        User $performer,
        MedicationPrescriberOrder $submittedPrescription,
        Carbon $actionAt,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($performer, $submittedPrescription, $actionAt, $callback) {
            $prescriptionSnapshot = MedicationPrescriberOrder::query()
                ->whereKey($submittedPrescription->getKey())
                ->first(['id', 'client_id', 'client_medication_id']);
            $this->notFoundUnless($prescriptionSnapshot !== null);

            $client = Client::query()->whereKey($prescriptionSnapshot->client_id)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null);

            $medication = null;
            if ($prescriptionSnapshot->client_medication_id !== null) {
                $medication = ClientMedication::query()
                    ->whereKey($prescriptionSnapshot->client_medication_id)
                    ->where('client_id', $client->id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();
                $this->notFoundUnless($medication !== null);
            }

            $prescriptionQuery = MedicationPrescriberOrder::query()
                ->whereKey($prescriptionSnapshot->id)
                ->where('client_id', $client->id);
            if ($medication !== null) {
                $prescriptionQuery->where('client_medication_id', $medication->id);
            } else {
                $prescriptionQuery->whereNull('client_medication_id');
            }
            $prescription = $prescriptionQuery->lockForUpdate()->first();
            $this->notFoundUnless($prescription !== null);
            $this->notFoundUnless(
                ! $prescription->requiresControlledView($medication)
                || $performer->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
            );

            [$shift, $breakGlass] = $this->resolveClientAuthority($performer, $client, $actionAt, null);

            return $callback(new MedicationScopeDecision(
                performer: $performer,
                client: $client,
                siteId: $this->clientSiteId($client),
                shift: $shift,
                breakGlassAccess: $breakGlass,
                medication: $medication,
                prescription: $prescription,
            ));
        }, 3);
    }

    /**
     * Batch, read-side projection of the same current-work boundary enforced
     * under lock by resolveClientAuthority(). This never grants authority; it
     * only keeps page mutation flags conservative until a locked write rechecks
     * the canonical Shift or break-glass aggregate.
     *
     * @param  array<int, int>  $clientIds
     * @return array<int, int>
     */
    public function clientIdsWithCurrentAuthority(
        User $performer,
        array $clientIds,
        Carbon $actionAt,
    ): array {
        $clientIds = collect($clientIds)
            ->filter(fn ($clientId): bool => is_numeric($clientId) && (int) $clientId > 0)
            ->map(fn ($clientId): int => (int) $clientId)
            ->unique()
            ->values()
            ->all();
        if ($clientIds === []) {
            return [];
        }

        $accessibleSiteIds = $this->siteAccess->accessibleSiteIds(
            $performer,
            ['clinical.accessAllSites', 'sites.viewAll'],
        );
        if ($accessibleSiteIds === []) {
            return [];
        }

        $clients = Client::query()
            ->whereIn('id', $clientIds)
            ->whereIn('site_id', $accessibleSiteIds)
            ->get(['id', 'site_id'])
            ->keyBy(fn (Client $client): int => (int) $client->id);
        if ($clients->isEmpty()) {
            return [];
        }

        $utc = $actionAt->copy()->utc();
        $shiftScopedClientIds = Client::query()
            ->whereIn('clients.id', $clients->keys())
            ->whereExists(function ($shift) use ($performer, $utc): void {
                $shift->selectRaw('1')
                    ->from('shifts')
                    ->where('shifts.user_id', $performer->id)
                    ->whereNotNull('shifts.actual_starts_at')
                    ->where('shifts.actual_starts_at', '<=', $utc)
                    ->where(function ($scope) use ($utc): void {
                        $scope->where(function ($active): void {
                            $active->where('shifts.status', 'in_progress')
                                ->whereNull('shifts.actual_ends_at');
                        })->orWhere(function ($completed) use ($utc): void {
                            $completed->where('shifts.status', 'completed')
                                ->whereNotNull('shifts.actual_ends_at')
                                ->where('shifts.actual_ends_at', '>=', $utc);
                        });
                    })
                    ->where(function ($site): void {
                        $site->whereColumn('shifts.site_id', 'clients.site_id')
                            ->orWhere(function ($derived): void {
                                $derived->whereNull('shifts.site_id')
                                    ->whereExists(function ($primaryClient): void {
                                        $primaryClient->selectRaw('1')
                                            ->from('clients as shift_primary_client')
                                            ->whereColumn('shift_primary_client.id', 'shifts.client_id')
                                            ->whereColumn('shift_primary_client.site_id', 'clients.site_id');
                                    });
                            });
                    })
                    ->where(function ($binding): void {
                        $binding->whereColumn('shifts.client_id', 'clients.id');

                        if (Schema::hasTable('shift_clients')) {
                            $binding->orWhereExists(function ($pivot): void {
                                $pivot->selectRaw('1')
                                    ->from('shift_clients')
                                    ->whereColumn('shift_clients.shift_id', 'shifts.id')
                                    ->whereColumn('shift_clients.client_id', 'clients.id');
                            });
                        }
                    });
            })
            ->pluck('clients.id')
            ->map(fn ($clientId): int => (int) $clientId);

        $breakGlassClientIds = collect();
        if ($performer->canDo('medications.breakglass')) {
            $directSiteIds = $this->siteAccess->accessibleSiteIds($performer);
            $eligibleClientIds = $clients
                ->filter(fn (Client $client): bool => in_array((int) $client->site_id, $directSiteIds, true))
                ->keys()
                ->all();
            if ($eligibleClientIds !== []) {
                $accessesByClient = ClientBreakGlassAccess::query()
                    ->whereIn('client_id', $eligibleClientIds)
                    ->where('user_id', $performer->id)
                    ->where('created_at', '<=', $utc)
                    ->where('expires_at', '>=', $utc)
                    ->where('expires_at', '>', now())
                    ->latest('created_at')
                    ->get()
                    ->groupBy(fn (ClientBreakGlassAccess $access): int => (int) $access->client_id);

                foreach ($eligibleClientIds as $clientId) {
                    $access = $accessesByClient->get((int) $clientId)?->first();
                    $client = $clients->get((int) $clientId);
                    if ($access instanceof ClientBreakGlassAccess
                        && $client instanceof Client
                        && $this->isCanonicalBreakGlass($access, (int) $client->site_id)) {
                        $breakGlassClientIds->push((int) $clientId);
                    }
                }
            }
        }

        return $shiftScopedClientIds
            ->merge($breakGlassClientIds)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function forRound(
        User $performer,
        MedicationRound $submittedRound,
        Carbon $actionAt,
        Closure $callback,
        array $allowedStatuses = [],
        bool $requireAssignment = true,
        bool $requireWorkScope = true,
        bool $lockCanonicalMembership = false,
        array $authorizationUserIds = [],
    ): mixed {
        $roundSnapshot = MedicationRound::query()
            ->whereKey($submittedRound->getKey())
            ->first(['id', 'site_id', 'service_context_id', 'status', 'assigned_to', 'started_by']);
        $this->notFoundUnless($roundSnapshot !== null);
        $siteId = $this->positiveId($roundSnapshot->site_id);
        $this->notFoundUnless(
            $siteId !== null
            && ($allowedStatuses === [] || in_array($roundSnapshot->status, $allowedStatuses, true))
            && (! $requireAssignment
                || $this->roundBelongsToPerformer($roundSnapshot, $performer))
        );

        return DB::transaction(function () use ($performer, $roundSnapshot, $siteId, $actionAt, $callback, $allowedStatuses, $requireAssignment, $requireWorkScope, $lockCanonicalMembership, $authorizationUserIds) {
            // Round and Shift mutations share this stable prefix:
            // ServiceContext -> aggregate Clients/Shifts -> complete
            // Users/RBAC/Profiles -> Site -> constrained round.

            $serviceContextId = null;
            if ($roundSnapshot->service_context_id !== null) {
                $serviceContextId = $this->positiveId($roundSnapshot->service_context_id);
                $this->notFoundUnless($serviceContextId !== null);

                $serviceContext = ServiceContext::query()
                    ->availableToSite($siteId)
                    ->whereKey($serviceContextId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first(['id']);
                $this->notFoundUnless($serviceContext !== null);
            }

            if ($lockCanonicalMembership && $roundSnapshot->status !== 'completed') {
                $this->lockCanonicalRoundMembership($siteId, $serviceContextId);
            }

            if ($requireWorkScope) {
                [$shift, $breakGlass, $client] = $this->resolveSiteAuthority($performer, $siteId, $actionAt);
            } else {
                $shift = null;
                $breakGlass = null;
                $client = null;
            }

            $userIds = collect([(int) $performer->id, ...$authorizationUserIds])
                ->map(fn (mixed $userId): int => (int) $userId)
                ->filter(fn (int $userId): bool => $userId > 0)
                ->unique()
                ->sort()
                ->values()
                ->all();
            $lockedUsers = $this->medicationGovernance->lockControlledWitnessUsers($userIds);
            $profiles = $this->medicationGovernance->lockCurrentStaffProfiles($lockedUsers, $userIds);
            $lockedUsers->each(function (User $user) use ($profiles): void {
                $user->setRelation('hrEmployeeProfile', $profiles->get((int) $user->id));
            });
            /** @var User|null $lockedPerformer */
            $lockedPerformer = $lockedUsers->get((int) $performer->id);
            $capability = $requireWorkScope
                ? 'medications.administer.record'
                : 'medications.orders.manage';
            abort_unless($lockedPerformer?->canDo($capability), 403);

            $this->medicationGovernance->lockCurrentMedicationSite($siteId);
            $this->notFoundUnless(in_array(
                $siteId,
                $this->siteAccess->accessibleSiteIds(
                    $lockedPerformer,
                    ['clinical.accessAllSites', 'sites.viewAll'],
                ),
                true,
            ));

            $roundQuery = MedicationRound::query()
                ->whereKey($roundSnapshot->id)
                ->where('site_id', $siteId);
            if ($serviceContextId === null) {
                $roundQuery->whereNull('service_context_id');
            } else {
                $roundQuery->where('service_context_id', $serviceContextId);
            }
            foreach (['assigned_to', 'started_by'] as $binding) {
                $roundSnapshot->{$binding} === null
                    ? $roundQuery->whereNull($binding)
                    : $roundQuery->where($binding, $roundSnapshot->{$binding});
            }
            $round = $roundQuery->lockForUpdate()->first();
            $this->notFoundUnless(
                $round !== null
                && ($allowedStatuses === [] || in_array($round->status, $allowedStatuses, true))
                && (! $requireAssignment
                    || $this->roundBelongsToPerformer($round, $lockedPerformer))
            );

            return $callback(new MedicationScopeDecision(
                performer: $lockedPerformer,
                client: $client,
                siteId: $siteId,
                shift: $shift,
                breakGlassAccess: $breakGlass,
                round: $round,
            ), $lockedUsers);
        }, 3);
    }

    public function recordBreakGlassUse(
        MedicationScopeDecision $decision,
        string $action,
        ?string $detail = null,
    ): void {
        if (! $decision->breakGlassAccess) {
            return;
        }

        BreakGlassAccessEvent::query()->create([
            'break_glass_access_id' => $decision->breakGlassAccess->id,
            'action' => mb_substr($action, 0, 100),
            'detail' => $detail !== null ? mb_substr($detail, 0, 255) : null,
        ]);
    }

    private function assertRoundCell(
        User $performer,
        MedicationRound $round,
        Client $client,
        ClientMedication $medication,
        ?Carbon $scheduledFor,
    ): void {
        $siteId = $this->clientSiteId($client);
        $this->notFoundUnless(
            $round->status === 'in_progress'
            && $this->positiveId($round->site_id) === $siteId
            && ($round->service_context_id === null
                || (int) $round->service_context_id === (int) $client->service_context_id)
            && $this->roundBelongsToPerformer($round, $performer)
            && ! $medication->is_prn
            && $scheduledFor !== null
        );

        $roundDate = $this->schedule->dateFromInput($round->round_date?->toDateString());
        $roundAt = $roundDate->copy()->setTimeFromTimeString((string) $round->scheduled_time);
        $window = max(0, (int) $round->window_minutes);
        $scheduledLocal = $scheduledFor->copy()->timezone($this->schedule->workerTimezone());

        $this->notFoundUnless(
            $scheduledLocal->toDateString() === $roundDate->toDateString()
            && $scheduledLocal->betweenIncluded(
                $roundAt->copy()->subMinutes($window),
                $roundAt->copy()->addMinutes($window),
            )
        );
        $this->assertScheduledCell($medication, $scheduledFor);
    }

    private function roundBelongsToPerformer(MedicationRound $round, User $performer): bool
    {
        if ($round->assigned_to !== null) {
            return $this->positiveId($round->assigned_to) === (int) $performer->id;
        }

        return $this->positiveId($round->started_by) === (int) $performer->id;
    }

    private function lockCanonicalRoundMembership(int $siteId, ?int $serviceContextId): void
    {
        // Lock every potential Client parent before its medication rows. New
        // orders and verification transitions use the same Client -> medication
        // order, so completion cannot certify an unstable canonical projection.
        $clientIds = Client::query()
            ->where('site_id', $siteId)
            ->when(
                $serviceContextId !== null,
                fn (Builder $query) => $query->where('service_context_id', $serviceContextId),
            )
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id'])
            ->pluck('id');

        if ($clientIds->isEmpty()) {
            return;
        }

        ClientMedication::query()
            ->whereIn('client_id', $clientIds)
            ->whereNull('deleted_at')
            ->whereNull('superseded_by')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    private function assertScheduledCell(ClientMedication $medication, ?Carbon $scheduledFor): void
    {
        $this->notFoundUnless($scheduledFor !== null && ! $medication->is_prn);
        $date = $this->schedule->dateFromInput(
            $scheduledFor->copy()->timezone($this->schedule->workerTimezone())->toDateString(),
        );
        $matches = collect($this->schedule->scheduledTimesForDate($medication, $date))
            ->contains(fn (Carbon $slot): bool => abs($slot->copy()->utc()->diffInSeconds($scheduledFor->copy()->utc(), false)) < 60);
        $this->notFoundUnless($matches);
    }

    private function assertMedicationIsActiveFor(ClientMedication $medication, Carbon $at): void
    {
        $date = $at->copy()->timezone($this->schedule->workerTimezone())->toDateString();
        if ($medication->state !== 'active'
            || ! (bool) $medication->active
            || $medication->superseded_by !== null
            || $medication->deleted_at !== null
            || ($medication->start_date && $date < $medication->start_date->toDateString())
            || ($medication->end_date && $date > $medication->end_date->toDateString())) {
            throw ValidationException::withMessages([
                'medication' => self::GENERIC_NOT_FOUND,
            ]);
        }
    }

    /** @return array{0: Shift|null, 1: ClientBreakGlassAccess|null} */
    private function resolveClientAuthority(
        User $performer,
        Client $client,
        Carbon $actionAt,
        ?int $submittedShiftId,
    ): array {
        $siteId = $this->clientSiteId($client);
        $this->notFoundUnless(in_array(
            $siteId,
            $this->siteAccess->accessibleSiteIds($performer, ['clinical.accessAllSites', 'sites.viewAll']),
            true,
        ));
        $shiftQuery = $this->coveringShiftQuery($performer, $siteId, $actionAt)
            ->where(function (Builder $scope) use ($client): void {
                $scope->where('client_id', $client->id);

                if (Schema::hasTable('shift_clients')) {
                    $scope->orWhereExists(function ($query) use ($client): void {
                        $query->selectRaw('1')
                            ->from('shift_clients')
                            ->whereColumn('shift_clients.shift_id', 'shifts.id')
                            ->where('shift_clients.client_id', $client->id);
                    });
                }
            });

        if ($submittedShiftId !== null) {
            $shiftQuery->whereKey($submittedShiftId);
        }

        $shift = $shiftQuery->lockForUpdate()->orderByDesc('actual_starts_at')->first();
        if ($shift) {
            return [$shift, null];
        }

        return [null, $this->activeBreakGlass($performer, $client, $siteId, $actionAt)];
    }

    /** @return array{0: Shift|null, 1: ClientBreakGlassAccess|null, 2: Client|null} */
    private function resolveSiteAuthority(User $performer, int $siteId, Carbon $actionAt): array
    {
        $this->notFoundUnless(in_array(
            $siteId,
            $this->siteAccess->accessibleSiteIds($performer, ['clinical.accessAllSites', 'sites.viewAll']),
            true,
        ));
        $shiftSnapshot = $this->coveringShiftQuery($performer, $siteId, $actionAt)
            ->orderByDesc('actual_starts_at')
            ->orderByDesc('id')
            ->first(['id', 'client_id']);
        if ($shiftSnapshot) {
            $clientId = $this->positiveId($shiftSnapshot->client_id);
            $this->notFoundUnless($clientId !== null);

            $client = Client::query()
                ->where('site_id', $siteId)
                ->whereKey($clientId)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($client !== null);

            $shift = $this->coveringShiftQuery($performer, $siteId, $actionAt)
                ->whereKey($shiftSnapshot->id)
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($shift !== null);

            return [$shift, null, $client];
        }

        // Emergency access is client-specific and minimum-necessary. It must
        // never widen into authority over a whole round or its residents.
        $this->notAssignedUnless(false);

        return [null, null, null];
    }

    private function coveringShiftQuery(User $performer, int $siteId, Carbon $actionAt): Builder
    {
        $utc = $actionAt->copy()->utc();

        return Shift::query()
            ->where('user_id', $performer->id)
            ->where(function (Builder $site) use ($siteId): void {
                $site->where('site_id', $siteId)
                    ->orWhere(function (Builder $derived) use ($siteId): void {
                        $derived->whereNull('site_id')
                            ->whereHas('client', fn (Builder $clients) => $clients->where('site_id', $siteId));
                    });
            })
            ->whereNotNull('actual_starts_at')
            ->where('actual_starts_at', '<=', $utc)
            ->where(function (Builder $scope) use ($utc): void {
                $scope->where(function (Builder $active): void {
                    $active->where('status', 'in_progress')->whereNull('actual_ends_at');
                })->orWhere(function (Builder $completed) use ($utc): void {
                    $completed->where('status', 'completed')
                        ->whereNotNull('actual_ends_at')
                        ->where('actual_ends_at', '>=', $utc);
                });
            });
    }

    private function activeBreakGlass(
        User $performer,
        Client $client,
        int $siteId,
        Carbon $actionAt,
    ): ClientBreakGlassAccess {
        $this->notAssignedUnless($performer->canDo('medications.breakglass'));
        $this->notAssignedUnless(in_array($siteId, $this->siteAccess->accessibleSiteIds($performer), true));

        $access = ClientBreakGlassAccess::query()
            ->where('client_id', $client->id)
            ->where('user_id', $performer->id)
            ->where('created_at', '<=', $actionAt->copy()->utc())
            ->where('expires_at', '>=', $actionAt->copy()->utc())
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->latest('created_at')
            ->first();
        $this->notAssignedUnless($access !== null && $this->isCanonicalBreakGlass($access, $siteId));

        return $access;
    }

    /**
     * Establish the linearization point for administration authority only
     * after Client, medication, Shift/round and break-glass aggregates are
     * locked. Actor and any co-signer User mutexes are acquired as one sorted
     * set, followed by current HR profiles and in-memory RBAC evaluation.
     *
     * @return array{0: User, 1: ClientBreakGlassAccess|null}
     */
    private function lockCurrentAdministrationAuthority(
        User $performer,
        Client $client,
        ClientMedication $medication,
        Carbon $actionAt,
        ?ClientBreakGlassAccess $breakGlass,
        array $authorizationUserIds = [],
        ?Collection $lockedPresenceShifts = null,
    ): array {
        $userIds = [(int) $performer->id, ...$authorizationUserIds];
        if ($breakGlass?->authorization_mode === 'co_sign' && $breakGlass->co_signed_by !== null) {
            $userIds[] = (int) $breakGlass->co_signed_by;
        }

        $lockedUsers = $this->medicationGovernance->lockControlledWitnessUsers($userIds);
        $profiles = $this->medicationGovernance->lockCurrentStaffProfiles(
            $lockedUsers,
            $userIds,
        );
        $lockedUsers->each(function (User $user) use ($profiles, $lockedPresenceShifts, $actionAt): void {
            $user->setRelation('hrEmployeeProfile', $profiles->get((int) $user->id));
            if ($lockedPresenceShifts !== null) {
                $user->setRelation('controlledMedicationPresenceShifts', $lockedPresenceShifts);
                $user->setRelation('controlledMedicationPresenceEffectiveAt', $actionAt);
            }
        });

        /** @var User|null $lockedPerformer */
        $lockedPerformer = $lockedUsers->get((int) $performer->id);
        abort_unless($lockedPerformer?->canDo('medications.administer.record'), 403);
        $this->notFoundUnless(
            ! (bool) $medication->controlled_drug
            || $lockedPerformer->canDo('medications.controlled.record'),
        );

        $siteId = $this->clientSiteId($client);
        $this->medicationGovernance->lockCurrentMedicationSite($siteId);
        $this->notFoundUnless(in_array(
            $siteId,
            $this->siteAccess->accessibleSiteIds(
                $lockedPerformer,
                ['clinical.accessAllSites', 'sites.viewAll'],
            ),
            true,
        ));

        if ($breakGlass !== null) {
            $this->notAssignedUnless($lockedPerformer->canDo('medications.breakglass'));
            $this->notAssignedUnless(in_array(
                $siteId,
                $this->siteAccess->accessibleSiteIds($lockedPerformer),
                true,
            ));

            if ($breakGlass->authorization_mode === 'co_sign') {
                /** @var User|null $coSigner */
                $coSigner = $lockedUsers->get((int) $breakGlass->co_signed_by);
                $this->notAssignedUnless(
                    $coSigner instanceof User
                    && ($coSigner->canDo('medications.breakglass') || $coSigner->canDo('medications.audit.view'))
                    && in_array($siteId, $this->siteAccess->accessibleSiteIds($coSigner), true),
                );
            }
        }

        return [$lockedPerformer, $breakGlass];
    }

    private function isCanonicalBreakGlass(ClientBreakGlassAccess $access, int $siteId): bool
    {
        if ($access->created_at === null
            || $access->expires_at === null
            || ! in_array($access->authorization_mode, ['self', 'co_sign'], true)
            || ! $access->acknowledged_min_necessary
            || ! $access->acknowledged_incident_report
            || ($access->authorization_mode === 'co_sign'
                && ($access->co_signed_by === null || (int) $access->co_signed_by === (int) $access->user_id))) {
            return false;
        }

        $policy = BreakGlassPolicy::current();
        if ($policy->reason_required && blank($access->reason)) {
            return false;
        }

        if ($access->authorization_mode === 'co_sign') {
            $coSigner = User::query()
                ->whereKey($access->co_signed_by)
                ->whereNotNull('approved_at')
                ->first();
            if (! $coSigner
                || (! $coSigner->canDo('medications.breakglass') && ! $coSigner->canDo('medications.audit.view'))
                || ! in_array($siteId, $this->siteAccess->accessibleSiteIds($coSigner), true)) {
                return false;
            }
        }

        $duration = $access->created_at->diffInMinutes($access->expires_at, false);

        return $duration >= 5 && $duration <= (int) $policy->max_minutes;
    }

    private function clientSiteId(Client $client): int
    {
        $siteId = $this->positiveId($client->site_id);
        $this->notFoundUnless($siteId !== null);

        return $siteId;
    }

    private function positiveId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function notFoundUnless(bool $condition): void
    {
        abort_unless($condition, 404, self::GENERIC_NOT_FOUND);
    }

    private function notAssignedUnless(bool $condition): void
    {
        abort_unless($condition, 403, self::GENERIC_NOT_ASSIGNED);
    }
}
