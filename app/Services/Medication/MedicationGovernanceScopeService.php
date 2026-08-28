<?php

namespace App\Services\Medication;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCovertAuthorisation;
use App\Models\MedicationDestruction;
use App\Models\MedicationIdempotencyResult;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationReview;
use App\Models\MedicationRoundTemplate;
use App\Models\MedicationScheduledStockCount;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class MedicationGovernanceScopeService
{
    public const MODULE_VIEW_CAPABILITY = 'medications.view';

    public const CONTROLLED_VIEW_CAPABILITY = 'medications.controlled.view';

    public const CONTROLLED_CAPABILITY = 'medications.controlled.record';

    public const STOCK_CAPABILITY = 'medications.stock.update';

    /**
     * Explicit application-wide Site permissions. They broaden Site visibility
     * only; they never replace the action capability checked by this service.
     *
     * @var array<int, string>
     */
    public const SITE_BYPASS_PERMISSIONS = ['clinical.accessAllSites', 'sites.viewAll'];

    private const NOT_FOUND = 'The requested medication record was not found.';

    /** @var array<int, string> */
    private const MUTATION_AUTHORIZATION_EVIDENCE = [
        self::MODULE_VIEW_CAPABILITY,
        'medications.administer.record',
        'medications.administer.correct',
        'medications.administer.override_safety',
        'medications.controlled.view',
        'medications.controlled.record',
        'medications.controlled.witness',
        'medications.stock.update',
        'medications.orders.manage',
        'medications.breakglass',
        'medications.audit.view',
        'fleet.manage',
        'fleet.medication.manage',
        'clinical.accessAllSites',
        'sites.viewAll',
    ];

    public function __construct(
        private UserSiteAccessService $siteAccess,
        private HrCurrentStaffService $currentStaff,
        private ControlledMedicationTransportWitnessService $controlledWitnesses,
        private AuthorizationEvidenceLockService $authorizationEvidence,
    ) {}

    /**
     * Resolve the canonical Site boundary for an eMAR reader. Global Site
     * permissions broaden Site scope only; they never replace either required
     * medication capability.
     *
     * @return array<int, int>
     */
    public function readerSiteIds(
        User $actor,
        string|array $capability,
        ?int $requestedSiteId = null,
        ?int $requestedClientId = null,
    ): array {
        $capabilities = is_array($capability) ? $capability : [$capability];
        abort_unless(
            $actor->canDo(self::MODULE_VIEW_CAPABILITY)
            && collect($capabilities)->contains(fn (string $permission) => $actor->canDo($permission)),
            403,
        );

        $siteIds = $this->siteAccess->accessibleSiteIds($actor, self::SITE_BYPASS_PERMISSIONS);

        if ($requestedSiteId !== null) {
            $this->notFoundUnless(in_array($requestedSiteId, $siteIds, true));
        }

        if ($requestedClientId !== null) {
            $client = Client::query()
                ->whereKey($requestedClientId)
                ->whereIn('site_id', $siteIds)
                ->first(['id', 'site_id']);
            $this->notFoundUnless(
                $client !== null
                && ($requestedSiteId === null || (int) $client->site_id === $requestedSiteId),
            );
        }

        return $siteIds;
    }

    /**
     * Resolve approved Sites for medication report surfaces. Report access is
     * intentionally independent of module browsing; controlled content adds
     * its exact reader capability without widening Site access.
     *
     * @return array<int, int>
     */
    public function reportSiteIds(
        User $actor,
        ?int $requestedSiteId = null,
        ?int $requestedClientId = null,
        bool $controlled = false,
    ): array {
        abort_unless(
            $actor->canDo('medications.reports.export') || $actor->canDo('reports.viewAny'),
            403,
        );
        if ($controlled) {
            abort_unless($actor->canDo(self::CONTROLLED_VIEW_CAPABILITY), 403);
        }

        $siteIds = $this->siteAccess->accessibleSiteIds($actor, self::SITE_BYPASS_PERMISSIONS);

        if ($requestedSiteId !== null) {
            $this->notFoundUnless(in_array($requestedSiteId, $siteIds, true));
        }

        if ($requestedClientId !== null) {
            $client = Client::query()
                ->whereKey($requestedClientId)
                ->whereIn('site_id', $siteIds)
                ->first(['id', 'site_id']);
            $this->notFoundUnless(
                $client !== null
                && ($requestedSiteId === null || (int) $client->site_id === $requestedSiteId),
            );
        }

        return $requestedSiteId !== null ? [$requestedSiteId] : $siteIds;
    }

    /** @param array<int, int> $siteIds */
    public function sitePicker(array $siteIds): Collection
    {
        return Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereIn('id', $siteIds)
            ->orderBy('name')
            ->get(['id', 'name', 'brand_colour']);
    }

    /** @param array<int, int> $siteIds */
    public function clientPicker(array $siteIds): Collection
    {
        return Client::query()
            ->whereIn('site_id', $siteIds)
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);
    }

    public function readableMedication(User $actor, int $medicationId): ClientMedication
    {
        $siteIds = $this->readerSiteIds($actor, self::MODULE_VIEW_CAPABILITY);

        return ClientMedication::query()
            ->current()
            ->whereKey($medicationId)
            ->whereHas('client', fn (Builder $query) => $query->whereIn('site_id', $siteIds))
            ->firstOrFail();
    }

    /**
     * @param Builder<*> $query
     * @param  array<int, int>|null  $siteIds  Null preserves internal all-Site
     *                                         scope while still enforcing canonical client/medication ownership.
     * @return Builder<*>
     */
    public function scopeCanonicalClientMedicationRows(
        Builder $query,
        ?array $siteIds,
        bool $allowNullMedication = true,
    ): Builder {
        $table = $query->getModel()->getTable();
        $medicationMatchesClient = fn (QueryBuilder $medication) => $medication
            ->selectRaw('1')
            ->from('client_medications')
            ->whereColumn('client_medications.id', $table.'.client_medication_id')
            ->whereColumn('client_medications.client_id', $table.'.client_id');

        $query->whereHas(
            'client',
            fn (Builder $client) => $client->when(
                $siteIds !== null,
                fn (Builder $query) => $query->whereIn('site_id', $siteIds),
            ),
        );

        if (! $allowNullMedication) {
            return $query->whereExists($medicationMatchesClient);
        }

        return $query->where(function (Builder $row) use ($table, $medicationMatchesClient): void {
            $row->whereNull($table.'.client_medication_id')
                ->orWhereExists($medicationMatchesClient);
        });
    }

    /**
     * Remove rows linked to a controlled medication without relying on the
     * relationship's soft-delete scope. Historical controlled classification
     * remains privacy-sensitive after an order is discontinued.
     *
     * @param Builder<*> $query
     * @return Builder<*>
     */
    public function scopeWithoutControlledMedicationRows(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereNotExists(fn (QueryBuilder $medication) => $medication
            ->selectRaw('1')
            ->from('client_medications')
            ->whereColumn('client_medications.id', $table.'.client_medication_id')
            ->where('client_medications.controlled_drug', true));
    }

    /**
     * Resolve a syringe-driver aggregate through canonical same-Client
     * medication links. A driver is indivisible clinical evidence: malformed,
     * unlinked, cross-Client, or partially hidden contents conceal the whole
     * aggregate rather than leaking its rate, notes, checks, or remaining
     * volume through an ordinary linked item.
     *
     * @param  array<int, mixed>  $contents
     * @return array<int, array<string, mixed>>|null
     */
    public function visibleSyringeDriverContents(Client $client, array $contents, bool $includeControlled): ?array
    {
        if ($contents === []) {
            return null;
        }

        $linkedMedicationIds = collect($contents)
            ->map(function ($item): ?int {
                if (! is_array($item)
                    || ! array_key_exists('client_medication_id', $item)
                    || ! is_numeric($item['client_medication_id'])
                    || (int) $item['client_medication_id'] <= 0) {
                    return null;
                }

                return (int) $item['client_medication_id'];
            });
        if ($linkedMedicationIds->contains(null)) {
            return null;
        }

        $linkedMedicationIds = $linkedMedicationIds
            ->unique()
            ->values();

        $medications = ClientMedication::withTrashed()
            ->where('client_id', $client->id)
            ->whereIn('id', $linkedMedicationIds)
            ->get(['id', 'controlled_drug'])
            ->keyBy(fn (ClientMedication $medication) => (int) $medication->id);

        if ($medications->count() !== $linkedMedicationIds->count()
            || (! $includeControlled
                && $medications->contains(fn (ClientMedication $medication): bool => (bool) $medication->controlled_drug))) {
            return null;
        }

        return collect($contents)
            ->map(function (array $item) use ($medications): array {
                $medication = $medications->get((int) $item['client_medication_id']);

                return [
                    ...$item,
                    'client_medication_id' => (int) $medication->id,
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<int, int> $siteIds */
    public function staffPicker(array $siteIds, ?int $excludedUserId = null): Collection
    {
        return $this->prescriptionWitnessStaffPicker($siteIds, $excludedUserId)
            ->map(fn (array $staff): array => [
                'id' => $staff['id'],
                'name' => $staff['name'],
            ]);
    }

    /**
     * Current medication-order witnesses with only the Site membership needed
     * to filter the client-specific picker. Site IDs are intersected with the
     * reader's already-approved medication Site scope.
     *
     * @param  array<int, int>  $siteIds
     */
    public function prescriptionWitnessStaffPicker(array $siteIds, ?int $excludedUserId = null): Collection
    {
        $approvedSiteIds = collect($siteIds)
            ->filter(fn ($siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
            ->map(fn ($siteId): int => (int) $siteId)
            ->unique()
            ->sort()
            ->values();
        if ($approvedSiteIds->isEmpty()) {
            return collect();
        }

        return $this->currentStaff->currentUsersQuery()
            ->with('hrEmployeeProfile:id,user_id,primary_site_id,secondary_site_ids')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'approved_at'])
            ->filter(function (User $user) use ($approvedSiteIds, $excludedUserId): bool {
                if ($excludedUserId !== null && $user->id === $excludedUserId) {
                    return false;
                }

                $profile = $user->hrEmployeeProfile;
                $assignedSiteIds = collect([
                    $profile?->primary_site_id,
                    ...(is_array($profile?->secondary_site_ids) ? $profile->secondary_site_ids : []),
                ])->filter(fn ($siteId) => is_numeric($siteId) && (int) $siteId > 0)
                    ->map(fn ($siteId) => (int) $siteId)
                    ->unique();

                return $assignedSiteIds->intersect($approvedSiteIds)->isNotEmpty();
            })
            ->values()
            ->map(function (User $user) use ($approvedSiteIds): array {
                $profile = $user->hrEmployeeProfile;
                $assignedSiteIds = collect([
                    $profile?->primary_site_id,
                    ...(is_array($profile?->secondary_site_ids) ? $profile->secondary_site_ids : []),
                ])->filter(fn ($siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
                    ->map(fn ($siteId): int => (int) $siteId)
                    ->intersect($approvedSiteIds)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                return [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'site_ids' => $assignedSiteIds,
                ];
            });
    }

    /** @param array<int, int> $siteIds */
    public function controlledWitnessPicker(array $siteIds, ?int $excludedUserId = null): Collection
    {
        return $this->controlledWitnesses
            ->eligibleWitnessesForSites($siteIds, now(), $excludedUserId)
            ->values()
            ->map(fn (User $user) => $user->only(['id', 'name']));
    }

    public function forClient(
        User $actor,
        int $clientId,
        string $capability,
        Closure $callback,
        array $authorizationUserIds = [],
        ?CarbonInterface $authorizationEffectiveAt = null,
        bool $lockPresence = false,
    ): mixed {
        return DB::transaction(function () use (
            $actor,
            $clientId,
            $capability,
            $callback,
            $authorizationUserIds,
            $authorizationEffectiveAt,
            $lockPresence,
        ) {
            $this->assertCapability($actor, $capability);

            $client = Client::query()->whereKey($clientId)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null && $this->positiveId($client->site_id) !== null);
            [$lockedActor, $lockedUsers] = $this->lockMutationActor(
                $actor,
                (int) $client->site_id,
                $capability,
                $authorizationUserIds,
                $authorizationEffectiveAt,
                lockPresence: $lockPresence,
            );

            return $this->invokeWithLockedActorEvidence(
                $actor,
                $lockedActor,
                fn () => $callback($client, $lockedActor, $lockedUsers),
            );
        }, 3);
    }

    public function forMedication(
        User $actor,
        int $medicationId,
        string $capability,
        Closure $callback,
        ?int $expectedClientId = null,
        array $authorizationUserIds = [],
        ?CarbonInterface $authorizationEffectiveAt = null,
    ): mixed {
        return DB::transaction(function () use ($actor, $medicationId, $capability, $callback, $expectedClientId, $authorizationUserIds, $authorizationEffectiveAt) {
            $this->assertCapability($actor, $capability);

            $snapshot = ClientMedication::query()
                ->whereKey($medicationId)
                ->whereNull('deleted_at')
                ->first(['id', 'client_id']);
            $this->notFoundUnless($snapshot !== null);

            $clientId = (int) $snapshot->client_id;
            $this->notFoundUnless($expectedClientId === null || $clientId === $expectedClientId);

            $client = Client::query()->whereKey($clientId)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null && $this->positiveId($client->site_id) !== null);

            $medication = ClientMedication::query()
                ->whereKey($medicationId)
                ->where('client_id', $client->id)
                ->whereNull('deleted_at')
                ->whereNull('superseded_by')
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($medication !== null);
            [$lockedActor, $lockedUsers] = $this->lockMutationActor(
                $actor,
                (int) $client->site_id,
                $capability,
                $authorizationUserIds,
                $authorizationEffectiveAt,
            );

            return $this->invokeWithLockedActorEvidence(
                $actor,
                $lockedActor,
                fn () => $callback($client, $medication, $lockedActor, $lockedUsers),
            );
        }, 3);
    }

    public function forStock(
        User $actor,
        ClientMedicationStock $submittedStock,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($actor, $submittedStock, $callback) {
            $this->assertCapability($actor, self::STOCK_CAPABILITY);

            $snapshot = ClientMedicationStock::query()
                ->whereKey($submittedStock->getKey())
                ->first(['id', 'client_medication_id']);
            $this->notFoundUnless($snapshot !== null);

            $medicationSnapshot = ClientMedication::query()
                ->whereKey($snapshot->client_medication_id)
                ->whereNull('deleted_at')
                ->first(['id', 'client_id']);
            $this->notFoundUnless($medicationSnapshot !== null);

            $client = Client::query()->whereKey($medicationSnapshot->client_id)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null && $this->positiveId($client->site_id) !== null);

            $medication = ClientMedication::query()
                ->whereKey($medicationSnapshot->id)
                ->where('client_id', $client->id)
                ->whereNull('deleted_at')
                ->whereNull('superseded_by')
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($medication !== null);

            $stock = ClientMedicationStock::query()
                ->whereKey($snapshot->id)
                ->where('client_medication_id', $medication->id)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($stock !== null);
            [$lockedActor, $lockedUsers] = $this->lockMutationActor(
                $actor,
                (int) $client->site_id,
                self::STOCK_CAPABILITY,
            );

            return $this->invokeWithLockedActorEvidence(
                $actor,
                $lockedActor,
                fn () => $callback($client, $medication, $stock, $lockedActor, $lockedUsers),
            );
        }, 3);
    }

    public function forCovertAuthorisation(
        User $actor,
        MedicationCovertAuthorisation $submittedAuthorisation,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($actor, $submittedAuthorisation, $callback) {
            $this->assertCapability($actor, 'medications.orders.manage');

            $snapshot = MedicationCovertAuthorisation::query()
                ->whereKey($submittedAuthorisation->getKey())
                ->first(['id', 'client_id', 'client_medication_id']);
            $this->notFoundUnless($snapshot !== null);

            $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null && $this->positiveId($client->site_id) !== null);

            $medication = ClientMedication::withTrashed()
                ->whereKey($snapshot->client_medication_id)
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($medication !== null);

            $authorisation = MedicationCovertAuthorisation::query()
                ->whereKey($snapshot->id)
                ->where('client_id', $client->id)
                ->where('client_medication_id', $medication->id)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($authorisation !== null);
            [$lockedActor, $lockedUsers] = $this->lockMutationActor(
                $actor,
                (int) $client->site_id,
                'medications.orders.manage',
            );

            return $this->invokeWithLockedActorEvidence(
                $actor,
                $lockedActor,
                fn () => $callback($client, $medication, $authorisation, $lockedActor, $lockedUsers),
            );
        }, 3);
    }

    public function forReview(
        User $actor,
        MedicationReview $submittedReview,
        Closure $callback,
        array $authorizationUserIds = [],
    ): mixed {
        return DB::transaction(function () use ($actor, $submittedReview, $callback, $authorizationUserIds) {
            $this->assertCapability($actor, 'medications.orders.manage');

            $snapshot = MedicationReview::query()
                ->whereKey($submittedReview->getKey())
                ->first(['id', 'client_id', 'reviewer_user_id']);
            $this->notFoundUnless($snapshot !== null);

            $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null && $this->positiveId($client->site_id) !== null);

            $review = MedicationReview::query()
                ->whereKey($snapshot->id)
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($review !== null);
            [$lockedActor, $lockedUsers] = $this->lockMutationActor(
                $actor,
                (int) $client->site_id,
                'medications.orders.manage',
                array_values(array_unique(array_filter([
                    ...$authorizationUserIds,
                    $snapshot->reviewer_user_id,
                ]))),
                lockPresence: false,
            );

            return $this->invokeWithLockedActorEvidence(
                $actor,
                $lockedActor,
                fn () => $callback($client, $review, $lockedActor, $lockedUsers),
            );
        }, 3);
    }

    public function forClientRecord(
        User $actor,
        Model $submittedRecord,
        string $capability,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($actor, $submittedRecord, $capability, $callback) {
            $this->assertCapability($actor, $capability);
            $modelClass = $submittedRecord::class;

            $snapshot = $modelClass::query()
                ->whereKey($submittedRecord->getKey())
                ->first(['id', 'client_id']);
            $this->notFoundUnless($snapshot !== null && $this->positiveId($snapshot->client_id) !== null);

            $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null && $this->positiveId($client->site_id) !== null);

            $record = $modelClass::query()
                ->whereKey($snapshot->id)
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($record !== null);
            [$lockedActor, $lockedUsers] = $this->lockMutationActor(
                $actor,
                (int) $client->site_id,
                $capability,
            );

            return $this->invokeWithLockedActorEvidence(
                $actor,
                $lockedActor,
                fn () => $callback($client, $record, $lockedActor, $lockedUsers),
            );
        }, 3);
    }

    /** @return array<int, int> */
    public function mutationSiteIds(User $actor, string $capability): array
    {
        $this->assertCapability($actor, $capability);

        return $this->siteAccess->accessibleSiteIds($actor, self::SITE_BYPASS_PERMISSIONS);
    }

    public function forNewRoundTemplate(
        User $actor,
        int $siteId,
        ?int $serviceContextId,
        ?int $defaultAssigneeId,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($actor, $siteId, $serviceContextId, $defaultAssigneeId, $callback) {
            $this->assertCapability($actor, 'medications.orders.manage');

            // Round lifecycle shares this prefix with Shift lifecycle and the
            // materializer: Context -> complete Users/RBAC/Profiles -> Site.
            $contextIds = collect([$serviceContextId])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->sort()
                ->values();
            $contexts = ServiceContext::query()
                ->whereIn('id', $contextIds->all())
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'site_id', 'is_active'])
                ->keyBy('id');
            $this->notFoundUnless($contexts->count() === $contextIds->count());

            [$lockedActor, $lockedUsers] = $this->lockMutationActor(
                $actor,
                $siteId,
                'medications.orders.manage',
                array_filter([$defaultAssigneeId]),
                lockSite: false,
                lockPresence: false,
            );

            $contextSiteIds = $contexts->pluck('site_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0);
            $siteIds = collect([$siteId, ...$contextSiteIds->all()])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->sort()
                ->values();
            $sites = Site::query()
                ->whereIn('id', $siteIds->all())
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id'])
                ->keyBy('id');
            $this->notFoundUnless($sites->count() === $siteIds->count());

            $canonicalSiteId = $this->lockedRoundTemplateSiteId(
                $lockedActor,
                $siteId,
                $serviceContextId,
                $contexts,
                $sites,
            );

            return $this->invokeWithLockedActorEvidence(
                $actor,
                $lockedActor,
                fn () => $callback($canonicalSiteId, $lockedActor, $lockedUsers),
            );
        }, 3);
    }

    public function forRoundTemplate(
        User $actor,
        MedicationRoundTemplate $submittedTemplate,
        Closure $callback,
        array $authorizationUserIds = [],
        array $additionalSiteIds = [],
        array $additionalServiceContextIds = [],
    ): mixed {
        $this->assertCapability($actor, 'medications.orders.manage');
        $snapshot = MedicationRoundTemplate::query()
            ->whereKey($submittedTemplate->getKey())
            ->first(['id', 'site_id', 'service_context_id', 'default_assigned_to']);
        $this->notFoundUnless($snapshot !== null);

        return DB::transaction(function () use ($actor, $snapshot, $callback, $authorizationUserIds, $additionalSiteIds, $additionalServiceContextIds) {
            $this->assertCapability($actor, 'medications.orders.manage');

            // Context is the pre-User aggregate prefix shared with Shift
            // lifecycle and round materializer. Lock the complete proposed
            // graph before taking the template row last; a concurrent retarget
            // is rejected by the final constrained read.
            $contextIds = collect([
                $this->positiveId($snapshot->service_context_id),
                ...$additionalServiceContextIds,
            ])->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->sort()
                ->values();
            $contexts = ServiceContext::query()
                ->whereIn('id', $contextIds->all())
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'site_id', 'is_active'])
                ->keyBy('id');
            $this->notFoundUnless($contexts->count() === $contextIds->count());
            $currentContextSiteId = $snapshot->service_context_id !== null
                ? $this->positiveId($contexts->get((int) $snapshot->service_context_id)?->site_id)
                : null;
            $siteId = $this->positiveId($snapshot->site_id) ?? $currentContextSiteId;
            $this->notFoundUnless(
                $snapshot->site_id === null
                    || $currentContextSiteId === null
                    || (int) $snapshot->site_id === $currentContextSiteId,
            );
            [$lockedActor, $lockedUsers] = $this->lockMutationActor(
                $actor,
                $siteId,
                'medications.orders.manage',
                array_values(array_unique(array_filter([
                    ...$authorizationUserIds,
                    $snapshot->default_assigned_to,
                ]))),
                lockSite: false,
                lockPresence: false,
            );

            $siteIds = collect([
                $siteId,
                ...$additionalSiteIds,
                ...$contexts->pluck('site_id')->all(),
            ])->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->sort()
                ->values();
            $sites = Site::query()
                ->whereIn('id', $siteIds->all())
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id'])
                ->keyBy('id');
            $this->notFoundUnless($sites->count() === $siteIds->count());
            if ($siteId !== null) {
                $this->notFoundUnless($sites->has($siteId));
                $this->assertSiteAccess($lockedActor, $siteId);
            }

            $query = MedicationRoundTemplate::query()->whereKey($snapshot->id);
            foreach (['site_id', 'service_context_id', 'default_assigned_to'] as $binding) {
                $snapshot->{$binding} === null
                    ? $query->whereNull($binding)
                    : $query->where($binding, $snapshot->{$binding});
            }
            $template = $query->lockForUpdate()->first();
            $this->notFoundUnless($template !== null);

            return $this->invokeWithLockedActorEvidence(
                $actor,
                $lockedActor,
                fn () => $callback($template, $siteId, $lockedActor, $lockedUsers, $contexts, $sites),
            );
        }, 3);
    }

    /**
     * Resolve a round-template Site only from the already-locked Context/Site
     * union. This method deliberately performs no database reads or locks.
     *
     * @param  Collection<int, ServiceContext>  $lockedContexts
     * @param  Collection<int, Site>  $lockedSites
     */
    public function lockedRoundTemplateSiteId(
        User $actor,
        ?int $siteId,
        ?int $serviceContextId,
        Collection $lockedContexts,
        Collection $lockedSites,
    ): ?int {
        $this->assertCapability($actor, 'medications.orders.manage');

        $contextSiteId = null;
        if ($serviceContextId !== null) {
            /** @var ServiceContext|null $context */
            $context = $lockedContexts->get($serviceContextId);
            $this->notFoundUnless($context instanceof ServiceContext && $context->is_active);
            $contextSiteId = $this->positiveId($context->site_id);
            $this->notFoundUnless($siteId === null || $contextSiteId === null || $siteId === $contextSiteId);
        }

        $effectiveSiteId = $siteId ?? $contextSiteId;
        if ($effectiveSiteId === null) {
            $this->notFoundUnless(
                $actor->canDo('clinical.accessAllSites') || $actor->canDo('sites.viewAll'),
            );

            return null;
        }

        $this->notFoundUnless($lockedSites->has($effectiveSiteId));
        $this->assertSiteAccess($actor, $effectiveSiteId);

        return $effectiveSiteId;
    }

    /**
     * Lock a scheduled-count aggregate in the canonical parent-first order:
     * Client, medication, scheduled count, then stock.
     */
    public function forScheduledStockCount(
        User $actor,
        int $submittedClientId,
        MedicationScheduledStockCount $submittedCount,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($actor, $submittedClientId, $submittedCount, $callback) {
            $this->assertCapability($actor, self::STOCK_CAPABILITY);

            $snapshot = MedicationScheduledStockCount::query()
                ->whereKey($submittedCount->getKey())
                ->first(['id', 'client_id', 'client_medication_id']);
            $this->notFoundUnless(
                $snapshot !== null
                && (int) $snapshot->client_id === $submittedClientId,
            );

            $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null && $this->positiveId($client->site_id) !== null);

            $medication = ClientMedication::query()
                ->whereKey($snapshot->client_medication_id)
                ->where('client_id', $client->id)
                ->whereNull('deleted_at')
                ->whereNull('superseded_by')
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($medication !== null);

            $count = MedicationScheduledStockCount::query()
                ->whereKey($snapshot->id)
                ->where('client_id', $client->id)
                ->where('client_medication_id', $medication->id)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($count !== null);

            $stock = ClientMedicationStock::query()
                ->where('client_medication_id', $medication->id)
                ->lockForUpdate()
                ->first();
            [$lockedActor, $lockedUsers] = $this->lockMutationActor(
                $actor,
                (int) $client->site_id,
                self::STOCK_CAPABILITY,
            );

            return $this->invokeWithLockedActorEvidence(
                $actor,
                $lockedActor,
                fn () => $callback($client, $medication, $count, $stock, $lockedActor, $lockedUsers),
            );
        }, 3);
    }

    public function forPharmacyOrder(
        User $actor,
        MedicationPharmacyOrder $submittedOrder,
        Closure $callback,
    ): mixed {
        return $this->forPharmacyOrderWithCapability(
            $actor,
            $submittedOrder,
            self::STOCK_CAPABILITY,
            $callback,
        );
    }

    public function forControlledPharmacyOrder(
        User $actor,
        MedicationPharmacyOrder $submittedOrder,
        Closure $callback,
        array $authorizationUserIds = [],
    ): mixed {
        return $this->forPharmacyOrderWithCapability(
            $actor,
            $submittedOrder,
            self::CONTROLLED_CAPABILITY,
            $callback,
            $authorizationUserIds,
        );
    }

    private function forPharmacyOrderWithCapability(
        User $actor,
        MedicationPharmacyOrder $submittedOrder,
        string $capability,
        Closure $callback,
        array $authorizationUserIds = [],
    ): mixed {
        return DB::transaction(function () use ($actor, $submittedOrder, $capability, $callback, $authorizationUserIds) {
            $this->assertCapability($actor, $capability);

            $snapshot = MedicationPharmacyOrder::query()
                ->whereKey($submittedOrder->getKey())
                ->first(['id', 'client_id', 'client_medication_id']);
            $this->notFoundUnless($snapshot !== null && $this->positiveId($snapshot->client_medication_id) !== null);

            $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null && $this->positiveId($client->site_id) !== null);

            $medication = ClientMedication::query()
                ->whereKey($snapshot->client_medication_id)
                ->where('client_id', $client->id)
                ->whereNull('deleted_at')
                ->whereNull('superseded_by')
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($medication !== null);

            $order = MedicationPharmacyOrder::query()
                ->whereKey($snapshot->id)
                ->where('client_id', $client->id)
                ->where('client_medication_id', $medication->id)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($order !== null);
            [$lockedActor, $lockedUsers] = $this->lockMutationActor(
                $actor,
                (int) $client->site_id,
                $capability,
                $authorizationUserIds,
            );

            return $this->invokeWithLockedActorEvidence(
                $actor,
                $lockedActor,
                fn () => $callback($client, $medication, $order, $lockedActor, $lockedUsers),
            );
        }, 3);
    }

    public function forDiscrepancy(
        User $actor,
        ClientControlledDrugDiscrepancy $submittedDiscrepancy,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($actor, $submittedDiscrepancy, $callback) {
            $this->assertCapability($actor, self::CONTROLLED_CAPABILITY);

            $snapshot = ClientControlledDrugDiscrepancy::query()
                ->whereKey($submittedDiscrepancy->getKey())
                ->first(['id', 'client_id', 'client_medication_id', 'service_context_id']);
            $this->notFoundUnless($snapshot !== null);

            $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->first();
            $this->notFoundUnless(
                $client !== null
                && $this->positiveId($client->site_id) !== null
                && ($snapshot->service_context_id === null
                    || (int) $snapshot->service_context_id === (int) $client->service_context_id)
            );

            $medication = null;
            if ($snapshot->client_medication_id !== null) {
                $medication = ClientMedication::withTrashed()
                    ->whereKey($snapshot->client_medication_id)
                    ->where('client_id', $client->id)
                    ->lockForUpdate()
                    ->first();
                $this->notFoundUnless($medication !== null && (bool) $medication->controlled_drug);
            }

            $discrepancy = ClientControlledDrugDiscrepancy::query()
                ->whereKey($snapshot->id)
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($discrepancy !== null);
            [$lockedActor, $lockedUsers] = $this->lockMutationActor(
                $actor,
                (int) $client->site_id,
                self::CONTROLLED_CAPABILITY,
            );

            return $this->invokeWithLockedActorEvidence(
                $actor,
                $lockedActor,
                fn () => $callback($client, $medication, $discrepancy, $lockedActor, $lockedUsers),
            );
        }, 3);
    }

    public function forDestruction(
        User $actor,
        MedicationDestruction $submittedDestruction,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($actor, $submittedDestruction, $callback) {
            $this->assertCapability($actor, self::CONTROLLED_CAPABILITY);

            $snapshot = MedicationDestruction::withTrashed()
                ->whereKey($submittedDestruction->getKey())
                ->first(['id', 'client_id', 'client_medication_id', 'site_id', 'is_controlled_drug']);
            $this->notFoundUnless($snapshot !== null);

            $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->first();
            $this->notFoundUnless(
                $client !== null
                && $this->positiveId($client->site_id) !== null
                && ($snapshot->site_id === null || (int) $snapshot->site_id === (int) $client->site_id)
            );

            $medication = null;
            if ($snapshot->client_medication_id !== null) {
                $medication = ClientMedication::withTrashed()
                    ->whereKey($snapshot->client_medication_id)
                    ->where('client_id', $client->id)
                    ->lockForUpdate()
                    ->first();
                $this->notFoundUnless($medication !== null);
            }

            $destruction = MedicationDestruction::withTrashed()
                ->whereKey($snapshot->id)
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($destruction !== null);
            [$lockedActor, $lockedUsers] = $this->lockMutationActor(
                $actor,
                (int) $client->site_id,
                self::CONTROLLED_CAPABILITY,
            );
            $this->notFoundUnless(
                ! (bool) $destruction->is_controlled_drug
                || $lockedActor->canDo(self::CONTROLLED_VIEW_CAPABILITY),
            );

            return $this->invokeWithLockedActorEvidence(
                $actor,
                $lockedActor,
                fn () => $callback($client, $medication, $destruction, $lockedActor, $lockedUsers),
            );
        }, 3);
    }

    /**
     * Lock every recorder/witness User row in one canonical order before any
     * HR profile lock. Callers with multiple witnesses must pass the complete
     * set once so opposite request ordering cannot invert row-lock order.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, User>
     */
    public function lockControlledWitnessUsers(array $userIds): Collection
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Controlled medication witnesses must be locked in the governing transaction.');
        }

        $ids = collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values();
        $this->notFoundUnless($ids->isNotEmpty());

        return $this->authorizationEvidence->lockForUsers(
            $ids->all(),
            self::MUTATION_AUTHORIZATION_EVIDENCE,
        );
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $additionalShiftIds
     * @return Collection<int, Shift>
     */
    public function lockControlledWitnessPresenceShifts(
        array $userIds,
        int $siteId,
        CarbonInterface $effectiveAt,
        array $additionalShiftIds = [],
    ): Collection {
        return $this->controlledWitnesses->lockPresenceShiftsAtSite(
            $userIds,
            $siteId,
            $effectiveAt,
            $additionalShiftIds,
        );
    }

    /**
     * Lock and verify the mutable HR records that establish current staff Site
     * membership. Call this only after locking the corresponding User rows in
     * canonical order, then lock Profiles by ascending profile ID (the shared
     * People-writer order), so an ordinary witnessed write cannot race employment
     * or Site reassignment between authorization and durable clinical evidence.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, HrEmployeeProfile>
     */
    public function lockCurrentStaffProfilesAtSite(
        Collection $lockedUsers,
        array $userIds,
        int $siteId,
        ?CarbonInterface $effectiveAt = null,
    ): Collection {
        $ids = collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values();
        $this->notFoundUnless($siteId > 0 && $ids->isNotEmpty());
        $profiles = $this->lockCurrentStaffProfiles($lockedUsers, $ids->all(), $effectiveAt);
        $this->notFoundUnless($ids->every(function (int $id) use ($profiles, $siteId): bool {
            $profile = $profiles->get($id);

            return (int) $profile->primary_site_id === $siteId
                || collect($profile->secondary_site_ids ?? [])->contains(
                    fn (mixed $candidate): bool => (int) $candidate === $siteId,
                );
        }));

        return $profiles;
    }

    /**
     * Lock current employment evidence after the corresponding sorted User
     * mutex set. Site-bypass permissions can legitimately broaden access, so
     * this variant proves current staff status without requiring every central
     * worker's HR profile to name the target Site.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, HrEmployeeProfile>
     */
    public function lockCurrentStaffProfiles(
        Collection $lockedUsers,
        array $userIds,
        ?CarbonInterface $effectiveAt = null,
    ): Collection {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Medication employment records must be locked in the governing transaction.');
        }

        $ids = collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values();
        $this->notFoundUnless($ids->isNotEmpty());
        $this->notFoundUnless($ids->every(function (int $id) use ($lockedUsers): bool {
            $user = $lockedUsers->get($id);

            return $user instanceof User
                && $user->approved_at !== null
                && ! in_array($user->role, ['client', 'next_of_kin'], true)
                && ! $user->hasRole('client', 'next_of_kin');
        }));

        $day = ($effectiveAt ?? now())
            ->copy()
            ->timezone(config('app.worker_timezone', 'Pacific/Auckland'))
            ->toDateString();
        $profiles = HrEmployeeProfile::withTrashed()
            ->whereIn('user_id', $ids->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (HrEmployeeProfile $profile) => (int) $profile->user_id);
        $this->notFoundUnless($profiles->count() === $ids->count());
        $this->notFoundUnless($ids->every(function (int $id) use ($profiles, $day): bool {
            $profile = $profiles->get($id);

            return $profile instanceof HrEmployeeProfile
                && ! $profile->trashed()
                && $profile->is_active
                && ($profile->start_date === null || $profile->start_date->toDateString() <= $day)
                && ($profile->end_date === null || $profile->end_date->toDateString() >= $day);
        }));

        return $profiles;
    }

    public function lockCurrentMedicationSite(int $siteId): Site
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Medication Site evidence must be locked in the governing transaction.');
        }

        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->first(['id']);
        $this->notFoundUnless($site !== null);

        return $site;
    }

    /**
     * Lock the current actor evidence used by dashboard-alert transitions.
     * Client and medication rows must already be held; the caller locks Site
     * only after this User/RBAC/Profile prefix.
     */
    public function lockCurrentAlertActor(User $actor, int $siteId, bool $controlled): User
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Medication alert authority must be locked in the governing transaction.');
        }

        $lockedUsers = $this->authorizationEvidence->lockForUsers(
            [(int) $actor->id],
            [
                self::MODULE_VIEW_CAPABILITY,
                'medications.administer.correct',
                self::CONTROLLED_VIEW_CAPABILITY,
                self::CONTROLLED_CAPABILITY,
                ...self::SITE_BYPASS_PERMISSIONS,
            ],
        );
        /** @var User|null $lockedActor */
        $lockedActor = $lockedUsers->get((int) $actor->id);
        $this->notFoundUnless($lockedActor instanceof User);
        $profiles = $this->lockCurrentStaffProfiles($lockedUsers, [(int) $actor->id]);
        $lockedActor->setRelation('hrEmployeeProfile', $profiles->get((int) $actor->id));

        abort_unless(
            $lockedActor->canDo(self::MODULE_VIEW_CAPABILITY)
                && $lockedActor->canDo('medications.administer.correct'),
            403,
        );
        if ($controlled) {
            $this->notFoundUnless(
                $lockedActor->canDo(self::CONTROLLED_VIEW_CAPABILITY)
                    && $lockedActor->canDo(self::CONTROLLED_CAPABILITY),
            );
        }
        $this->assertSiteAccess($lockedActor, $siteId);

        return $lockedActor;
    }

    /** @param Collection<int, User>|null $lockedUsers */
    public function confirmedControlledWitness(
        User $actor,
        Client $client,
        int $witnessId,
        ?string $credential,
        string $witnessErrorKey = 'witnessed_by',
        string $credentialErrorKey = 'witness_credential',
        ?int $recorderId = null,
        ?Collection $lockedUsers = null,
        ?CarbonInterface $effectiveAt = null,
        ?Closure $beforeCredentialCheck = null,
        ?Collection $lockedPresenceShifts = null,
    ): User {
        return $this->confirmedControlledWitnessAttestation(
            actor: $actor,
            client: $client,
            witnessId: $witnessId,
            credential: $credential,
            witnessErrorKey: $witnessErrorKey,
            credentialErrorKey: $credentialErrorKey,
            recorderId: $recorderId,
            lockedUsers: $lockedUsers,
            effectiveAt: $effectiveAt,
            beforeCredentialCheck: $beforeCredentialCheck,
            lockedPresenceShifts: $lockedPresenceShifts,
        )['witness'];
    }

    /**
     * Confirm a controlled-medication witness and retain the immutable basis
     * for their authority, competency, employment and physical presence.
     *
     * @param  Collection<int, User>|null  $lockedUsers
     * @return array{
     *   witness: User,
     *   witnessed_at: CarbonInterface,
     *   method: string,
     *   authority_permission: string,
     *   employment_profile_id: int,
     *   competency_state: string,
     *   competency_assessment_id: int,
     *   presence_source: string,
     *   presence_record_id: int,
     *   presence_started_at: string,
     *   presence_ends_at: ?string
     * }
     */
    public function confirmedControlledWitnessAttestation(
        User $actor,
        Client $client,
        int $witnessId,
        ?string $credential,
        string $witnessErrorKey = 'witnessed_by',
        string $credentialErrorKey = 'witness_credential',
        ?int $recorderId = null,
        ?Collection $lockedUsers = null,
        ?CarbonInterface $effectiveAt = null,
        ?Closure $beforeCredentialCheck = null,
        ?Collection $lockedPresenceShifts = null,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Controlled medication witnesses must be confirmed in the governing transaction.');
        }

        if ($recorderId !== null && $witnessId === $recorderId) {
            throw ValidationException::withMessages([
                $witnessErrorKey => 'The witness must be a different person from the person recording the medication.',
            ]);
        }

        $lockedUsers ??= $this->lockControlledWitnessUsers([
            (int) $actor->id,
            $recorderId ?? (int) $actor->id,
            $witnessId,
        ]);
        $requiredUserIds = collect([
            (int) $actor->id,
            $recorderId ?? (int) $actor->id,
            $witnessId,
        ])->unique();
        $this->notFoundUnless(
            $requiredUserIds->every(fn (int $id) => $lockedUsers->has($id)),
        );
        $lockedActor = $lockedUsers->get((int) $actor->id);
        $witness = $lockedUsers->get($witnessId);
        $this->notFoundUnless($lockedActor instanceof User && $witness instanceof User);
        if (
            $lockedPresenceShifts === null
            && $lockedActor->relationLoaded('controlledMedicationPresenceShifts')
        ) {
            $candidatePresenceShifts = $lockedActor->getRelation('controlledMedicationPresenceShifts');
            $lockedPresenceShifts = $candidatePresenceShifts instanceof Collection
                ? $candidatePresenceShifts
                : null;
        }
        if (
            $effectiveAt === null
            && $lockedActor->relationLoaded('controlledMedicationPresenceEffectiveAt')
        ) {
            $candidateEffectiveAt = $lockedActor->getRelation('controlledMedicationPresenceEffectiveAt');
            $effectiveAt = $candidateEffectiveAt instanceof CarbonInterface
                ? $candidateEffectiveAt
                : null;
        }
        $profiles = $this->lockCurrentStaffProfiles(
            $lockedUsers,
            $requiredUserIds->values()->all(),
        );
        $lockedUsers->each(function (User $user) use ($profiles): void {
            $user->setRelation('hrEmployeeProfile', $profiles->get((int) $user->id));
        });
        $this->notFoundUnless($lockedActor->canDo(self::CONTROLLED_CAPABILITY));
        $this->assertSiteAccess($lockedActor, (int) $client->site_id);

        $authenticated = $this->controlledWitnesses->authenticate(
            $lockedActor,
            (int) $client->site_id,
            $witnessId,
            $credential,
            $effectiveAt ?? now(),
            $witnessErrorKey,
            $credentialErrorKey,
            $beforeCredentialCheck,
            $lockedUsers,
            $lockedPresenceShifts,
        );
        $this->notFoundUnless((int) $authenticated['witness']->id === (int) $witness->id);
        $authenticated['witness'] = $witness;

        return $authenticated;
    }

    /** Build a bounded durable key from the complete canonical action scope. */
    public function idempotencyScope(
        string $action,
        int $actorId,
        int $clientId,
        int $medicationId,
        ?int $aggregateId = null,
    ): string {
        return $action.':'.hash('sha256', implode('|', [
            $actorId,
            $clientId,
            $medicationId,
            $aggregateId ?? '-',
        ]));
    }

    /** @return array<string, mixed>|null */
    public function idempotencyResult(
        string $scope,
        array $data,
        ?string $requestFingerprint = null,
        string $conflictMessage = 'This request identifier was already used with different medication stock-count details.',
        bool $durable = false,
    ): ?array {
        $requestUuid = $data['client_request_uuid'] ?? null;
        if (! is_string($requestUuid) || $requestUuid === '') {
            return null;
        }

        $result = MedicationIdempotencyResult::query()
            ->where('scope', $scope)
            ->where('request_uuid', $requestUuid)
            ->lockForUpdate()
            ->first();

        if (! $result || (! $durable && $result->expires_at?->isPast())) {
            return null;
        }

        $payload = $result->response_payload;
        $storedFingerprint = $payload['_request_fingerprint'] ?? null;
        if ($requestFingerprint !== null && $storedFingerprint !== $requestFingerprint) {
            throw ValidationException::withMessages([
                'client_request_uuid' => $conflictMessage,
            ]);
        }

        unset($payload['_request_fingerprint']);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function rememberIdempotencyResult(
        string $scope,
        array $data,
        array $payload,
        ?string $requestFingerprint = null,
        string $conflictMessage = 'This request identifier was already used with different medication stock-count details.',
        bool $durable = false,
    ): array {
        $requestUuid = $data['client_request_uuid'] ?? null;
        if (! is_string($requestUuid) || $requestUuid === '') {
            return $payload;
        }

        if (DB::transactionLevel() < 1) {
            throw new LogicException('Medication idempotency results must be stored in the governing transaction.');
        }

        $existing = MedicationIdempotencyResult::query()
            ->where('scope', $scope)
            ->where('request_uuid', $requestUuid)
            ->lockForUpdate()
            ->first();

        if ($existing && ($durable || ! $existing->expires_at?->isPast())) {
            $storedPayload = $existing->response_payload;
            if ($requestFingerprint !== null && ($storedPayload['_request_fingerprint'] ?? null) !== $requestFingerprint) {
                throw ValidationException::withMessages([
                    'client_request_uuid' => $conflictMessage,
                ]);
            }
            unset($storedPayload['_request_fingerprint']);

            return $storedPayload;
        }

        if ($existing) {
            $existing->delete();
        }

        $storedPayload = $payload;
        if ($requestFingerprint !== null) {
            $storedPayload['_request_fingerprint'] = $requestFingerprint;
        }

        MedicationIdempotencyResult::create([
            'scope' => $scope,
            'request_uuid' => $requestUuid,
            'response_payload' => $storedPayload,
            'expires_at' => $durable ? null : now()->addDays(7),
        ]);

        return $payload;
    }

    private function assertCapability(User $actor, string $capability): void
    {
        abort_unless($actor->canDo($capability), 403);
    }

    /**
     * Recheck controlled/stock mutation authority from current locked evidence
     * after the complete clinical aggregate is locked. Other medication
     * capabilities retain their established contract and are outside this
     * current-evidence packet.
     */
    /**
     * Establish the current mutation authority point after the canonical
     * clinical aggregate and any complete presence-Shift union are locked.
     * Every capability uses the same User/RBAC/Profile prefix; controlled and
     * stock actions are not special cases.
     *
     * @return array{0: User, 1: Collection<int, User>}
     */
    private function lockMutationActor(
        User $actor,
        ?int $siteId,
        string $capability,
        array $authorizationUserIds = [],
        ?CarbonInterface $authorizationEffectiveAt = null,
        bool $lockSite = true,
        bool $lockPresence = true,
    ): array {
        $userIds = collect([(int) $actor->id, ...$authorizationUserIds])
            ->map(fn ($userId): int => (int) $userId)
            ->filter(fn (int $userId): bool => $userId > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $presenceEffectiveAt = $authorizationEffectiveAt ?? now();
        $lockedPresenceShifts = $lockPresence && $authorizationUserIds !== [] && $siteId !== null
            ? $this->controlledWitnesses->lockPresenceShiftsAtSite(
                $userIds,
                $siteId,
                $presenceEffectiveAt,
            )
            : null;
        $lockedUsers = $this->authorizationEvidence->lockForUsers(
            $userIds,
            array_values(array_unique([
                ...self::MUTATION_AUTHORIZATION_EVIDENCE,
                $capability,
            ])),
        );
        /** @var User|null $lockedActor */
        $lockedActor = $lockedUsers->get((int) $actor->id);
        $this->notFoundUnless($lockedActor instanceof User);

        $profiles = $this->lockCurrentStaffProfiles(
            $lockedUsers,
            $userIds,
        );
        $lockedUsers->each(function (User $lockedUser) use ($profiles): void {
            $lockedUser->setRelation(
                'hrEmployeeProfile',
                $profiles->get((int) $lockedUser->id),
            );
        });
        if ($lockedPresenceShifts !== null) {
            $lockedUsers->each(function (User $lockedUser) use ($lockedPresenceShifts, $presenceEffectiveAt): void {
                $lockedUser->setRelation('controlledMedicationPresenceShifts', $lockedPresenceShifts);
                $lockedUser->setRelation('controlledMedicationPresenceEffectiveAt', $presenceEffectiveAt);
            });
        }
        $this->assertCapability($lockedActor, $capability);
        if ($siteId !== null && $lockSite) {
            $this->lockCurrentMedicationSite($siteId);
            $this->assertSiteAccess($lockedActor, $siteId);
        } elseif ($siteId === null) {
            $this->notFoundUnless(
                $lockedActor->canDo('clinical.accessAllSites')
                    || $lockedActor->canDo('sites.viewAll'),
            );
        }

        return [$lockedActor, $lockedUsers];
    }

    /**
     * Legacy controller callbacks may still close over the authenticated model
     * instead of accepting the locked actor argument. Publish the current
     * authorization evidence only while that callback runs; leaving a bounded
     * permission projection attached would false-deny unrelated downstream
     * capabilities on the same request actor.
     */
    private function invokeWithLockedActorEvidence(
        User $requestActor,
        User $lockedActor,
        Closure $callback,
    ): mixed {
        $originalRelations = $requestActor->getRelations();
        $requestActor->setRawAttributes($lockedActor->getAttributes(), true);
        $requestActor->setRelations($lockedActor->getRelations());

        try {
            return $callback();
        } finally {
            $requestActor->setRelations($originalRelations);
        }
    }

    private function assertSiteAccess(User $actor, int $siteId): void
    {
        $this->notFoundUnless(
            in_array(
                $siteId,
                $this->siteAccess->accessibleSiteIds($actor, self::SITE_BYPASS_PERMISSIONS),
                true,
            ),
        );
    }

    private function notFoundUnless(bool $condition): void
    {
        abort_unless($condition, 404, self::NOT_FOUND);
    }

    private function positiveId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
