<?php

namespace App\Services\Medication;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationDestruction;
use App\Models\MedicationPharmacyOrder;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Closure;
use Illuminate\Support\Facades\DB;

final class MedicationGovernanceScopeService
{
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

    public function __construct(private UserSiteAccessService $siteAccess) {}

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
        $snapshot = ClientMedication::query()
            ->whereKey($medicationId)
            ->whereNull('deleted_at')
            ->first(['id', 'client_id']);
        $this->notFoundUnless($snapshot !== null);

        $clientId = (int) $snapshot->client_id;
        $this->notFoundUnless($expectedClientId === null || $clientId === $expectedClientId);

        return DB::transaction(function () use ($actor, $medicationId, $clientId, $capability, $callback) {
            $this->assertCapability($actor, $capability);

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
        $snapshot = ClientMedicationStock::query()
            ->whereKey($submittedStock->getKey())
            ->first(['id', 'client_medication_id']);
        $this->notFoundUnless($snapshot !== null);

        $medicationSnapshot = ClientMedication::query()
            ->whereKey($snapshot->client_medication_id)
            ->whereNull('deleted_at')
            ->first(['id', 'client_id']);
        $this->notFoundUnless($medicationSnapshot !== null);

        return DB::transaction(function () use ($actor, $snapshot, $medicationSnapshot, $callback) {
            $this->assertCapability($actor, self::STOCK_CAPABILITY);

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

    public function forPharmacyOrder(
        User $actor,
        MedicationPharmacyOrder $submittedOrder,
        Closure $callback,
    ): mixed {
        $snapshot = MedicationPharmacyOrder::query()
            ->whereKey($submittedOrder->getKey())
            ->first(['id', 'client_id', 'client_medication_id']);
        $this->notFoundUnless($snapshot !== null && $this->positiveId($snapshot->client_medication_id) !== null);

        return DB::transaction(function () use ($actor, $snapshot, $callback) {
            $this->assertCapability($actor, self::STOCK_CAPABILITY);

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
        $snapshot = ClientControlledDrugDiscrepancy::query()
            ->whereKey($submittedDiscrepancy->getKey())
            ->first(['id', 'client_id', 'client_medication_id', 'service_context_id']);
        $this->notFoundUnless($snapshot !== null);

        return DB::transaction(function () use ($actor, $snapshot, $callback) {
            $this->assertCapability($actor, self::CONTROLLED_CAPABILITY);

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
        $snapshot = MedicationDestruction::withTrashed()
            ->whereKey($submittedDestruction->getKey())
            ->first(['id', 'client_id', 'client_medication_id', 'site_id']);
        $this->notFoundUnless($snapshot !== null);

        return DB::transaction(function () use ($actor, $snapshot, $callback) {
            $this->assertCapability($actor, self::CONTROLLED_CAPABILITY);

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

    private function assertCapability(User $actor, string $capability): void
    {
        abort_unless($actor->canDo($capability), 403);
    }

    private function assertSiteAccess(User $actor, int $siteId): void
    {
        $this->siteAccess->assertCanAccessSiteId(
            $actor,
            $siteId,
            self::SITE_BYPASS_PERMISSIONS,
            self::NOT_FOUND,
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
