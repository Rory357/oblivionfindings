<?php

namespace App\Services\Medication;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationIdempotencyResult;
use App\Models\MedicationDestruction;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationScheduledStockCount;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public function __construct(
        private UserSiteAccessService $siteAccess,
        private HrCurrentStaffService $currentStaff,
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
     * @param array<int, int> $siteIds
     * @return Builder<*>
     */
    public function scopeCanonicalClientMedicationRows(
        Builder $query,
        array $siteIds,
        bool $allowNullMedication = true,
    ): Builder {
        $table = $query->getModel()->getTable();
        $medicationMatchesClient = fn (Builder $medication) => $medication
            ->whereColumn('client_medications.client_id', $table.'.client_id')
            ->whereHas('client', fn (Builder $client) => $client->whereIn('site_id', $siteIds));

        $query->whereHas('client', fn (Builder $client) => $client->whereIn('site_id', $siteIds));

        if (! $allowNullMedication) {
            return $query->whereHas('medication', $medicationMatchesClient);
        }

        return $query->where(function (Builder $row) use ($table, $medicationMatchesClient): void {
            $row->whereNull($table.'.client_medication_id')
                ->orWhereHas('medication', $medicationMatchesClient);
        });
    }

    /** @param array<int, int> $siteIds */
    public function staffPicker(array $siteIds, ?int $excludedUserId = null): Collection
    {
        return $this->currentStaff->currentUsersQuery()
            ->with('hrEmployeeProfile:id,user_id,primary_site_id,secondary_site_ids')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'approved_at'])
            ->filter(function (User $user) use ($siteIds, $excludedUserId): bool {
                if ($excludedUserId !== null && $user->id === $excludedUserId) {
                    return false;
                }

                $profile = $user->hrEmployeeProfile;
                $assignedSiteIds = collect([
                    $profile?->primary_site_id,
                    ...(is_array($profile?->secondary_site_ids) ? $profile->secondary_site_ids : []),
                ])->filter(fn ($siteId) => is_numeric($siteId) && (int) $siteId > 0)
                    ->map(fn ($siteId) => (int) $siteId);

                return $assignedSiteIds->intersect($siteIds)->isNotEmpty();
            })
            ->values()
            ->map(fn (User $user) => $user->only(['id', 'name']));
    }

    /** @param array<int, int> $siteIds */
    public function controlledWitnessPicker(array $siteIds, ?int $excludedUserId = null): Collection
    {
        $eligibleIds = $this->staffPicker($siteIds, $excludedUserId)->pluck('id');

        return User::query()
            ->whereIn('id', $eligibleIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn (User $user) => $user->canDo('medications.controlled.witness'))
            ->values()
            ->map(fn (User $user) => $user->only(['id', 'name']));
    }

    public function forClient(
        User $actor,
        int $clientId,
        string $capability,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($actor, $clientId, $capability, $callback) {
            $this->assertCapability($actor, $capability);

            $client = Client::query()->whereKey($clientId)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null && $this->positiveId($client->site_id) !== null);
            $this->assertSiteAccess($actor, (int) $client->site_id);

            return $callback($client);
        }, 3);
    }

    public function forMedication(
        User $actor,
        int $medicationId,
        string $capability,
        Closure $callback,
        ?int $expectedClientId = null,
    ): mixed {
        return DB::transaction(function () use ($actor, $medicationId, $capability, $callback, $expectedClientId) {
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
            $this->assertSiteAccess($actor, (int) $client->site_id);

            return $callback($client, $medication);
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
            $this->assertSiteAccess($actor, (int) $client->site_id);

            return $callback($client, $medication, $stock);
        }, 3);
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
            $this->assertSiteAccess($actor, (int) $client->site_id);

            return $callback($client, $medication, $count, $stock);
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
    ): mixed {
        return $this->forPharmacyOrderWithCapability(
            $actor,
            $submittedOrder,
            self::CONTROLLED_CAPABILITY,
            $callback,
        );
    }

    private function forPharmacyOrderWithCapability(
        User $actor,
        MedicationPharmacyOrder $submittedOrder,
        string $capability,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($actor, $submittedOrder, $capability, $callback) {
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
            $this->assertSiteAccess($actor, (int) $client->site_id);

            return $callback($client, $medication, $order);
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
                $medication = ClientMedication::query()
                    ->whereKey($snapshot->client_medication_id)
                    ->where('client_id', $client->id)
                    ->whereNull('deleted_at')
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
            $this->assertSiteAccess($actor, (int) $client->site_id);

            return $callback($client, $medication, $discrepancy);
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
                ->first(['id', 'client_id', 'client_medication_id', 'site_id']);
            $this->notFoundUnless($snapshot !== null);

            $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->first();
            $this->notFoundUnless(
                $client !== null
                && $this->positiveId($client->site_id) !== null
                && ($snapshot->site_id === null || (int) $snapshot->site_id === (int) $client->site_id)
            );

            $medication = null;
            if ($snapshot->client_medication_id !== null) {
                $medication = ClientMedication::query()
                    ->whereKey($snapshot->client_medication_id)
                    ->where('client_id', $client->id)
                    ->whereNull('deleted_at')
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
            $this->assertSiteAccess($actor, (int) $client->site_id);

            return $callback($client, $medication, $destruction);
        }, 3);
    }

    /**
     * Lock every recorder/witness User row in one canonical order before any
     * HR profile lock. Callers with multiple witnesses must pass the complete
     * set once so opposite request ordering cannot invert row-lock order.
     *
     * @param array<int, int> $userIds
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

        $users = User::query()
            ->whereIn('id', $ids->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (User $user) => (int) $user->id);
        $this->notFoundUnless($users->count() === $ids->count());

        return $users;
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
    ): User {
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
        $witness = $lockedUsers->get($witnessId);
        $this->notFoundUnless($witness !== null);

        $eligibleAtSite = $this->currentStaff->currentUsersQuery()
            ->whereKey($witnessId)
            ->whereHas('hrEmployeeProfile', function ($profileQuery) use ($client): void {
                $profileQuery->where(function ($siteQuery) use ($client): void {
                    $siteQuery->where('primary_site_id', $client->site_id)
                        ->orWhereJsonContains('secondary_site_ids', (int) $client->site_id);
                });
            })->exists();

        $this->notFoundUnless(
            $eligibleAtSite
            && $witness->canDo('medications.controlled.witness'),
        );

        $today = now()->toDateString();
        $profile = HrEmployeeProfile::query()
            ->where('user_id', $witnessId)
            ->active()
            ->atSite((int) $client->site_id)
            ->where(function ($dates) use ($today): void {
                $dates->whereNull('start_date')
                    ->orWhereDate('start_date', '<=', $today);
            })
            ->where(function ($dates) use ($today): void {
                $dates->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $today);
            })
            ->lockForUpdate()
            ->first();
        $this->notFoundUnless($profile !== null);

        if (blank($credential)) {
            throw ValidationException::withMessages([
                $credentialErrorKey => 'Witness password is required before this controlled drug record can be signed.',
            ]);
        }

        if (! Hash::check((string) $credential, (string) $witness->password)) {
            throw ValidationException::withMessages([
                $credentialErrorKey => 'Witness password did not match.',
            ]);
        }

        return $witness;
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
    public function idempotencyResult(string $scope, array $data, ?string $requestFingerprint = null): ?array
    {
        $requestUuid = $data['client_request_uuid'] ?? null;
        if (! is_string($requestUuid) || $requestUuid === '') {
            return null;
        }

        $result = MedicationIdempotencyResult::query()
            ->where('scope', $scope)
            ->where('request_uuid', $requestUuid)
            ->lockForUpdate()
            ->first();

        if (! $result || $result->expires_at?->isPast()) {
            return null;
        }

        $payload = $result->response_payload;
        $storedFingerprint = $payload['_request_fingerprint'] ?? null;
        if ($requestFingerprint !== null && $storedFingerprint !== $requestFingerprint) {
            throw ValidationException::withMessages([
                'client_request_uuid' => 'This request identifier was already used with different medication stock-count details.',
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
    ): array
    {
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

        if ($existing && ! $existing->expires_at?->isPast()) {
            $storedPayload = $existing->response_payload;
            if ($requestFingerprint !== null && ($storedPayload['_request_fingerprint'] ?? null) !== $requestFingerprint) {
                throw ValidationException::withMessages([
                    'client_request_uuid' => 'This request identifier was already used with different medication stock-count details.',
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
            'expires_at' => now()->addDays(7),
        ]);

        return $payload;
    }

    private function assertCapability(User $actor, string $capability): void
    {
        abort_unless($actor->canDo($capability), 403);
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
