<?php

namespace App\Services\Medication\PharmacyConnect;

use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Models\MedicationPharmacyConnection;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PharmacyConnectionService
{
    public function __construct(
        private readonly PharmacyPartnerRegistry $partners,
        private readonly PharmacyDispatchPresentation $presentation,
        private readonly MedicationGovernanceScopeService $scope,
        private readonly PeopleMutationLockService $peopleLocks,
        private readonly MedicationEventRecorder $events,
    ) {}

    public function read(User $actor): array
    {
        $sites = $this->scope->readerSiteIds($actor, ['medications.settings.manage', 'medications.audit.view', 'medications.pharmacy.connect.manage']);
        $installed = Schema::hasTable('medication_pharmacy_connections');
        $connections = $installed ? MedicationPharmacyConnection::orderBy('name')->get()
            ->filter(fn ($connection) => $connection->site_ids !== [] && array_diff($connection->site_ids, $sites) === [])
            ->map(fn ($connection) => $this->presentation->connection($connection))->values()->all() : [];
        $partners = $this->partners->options($sites);

        return ['enabled' => $this->partners->enabled(), 'installed' => $installed, 'connections' => $connections,
            'partners' => $partners, 'sites' => Site::whereIn('id', $sites)->where('is_active', true)->where('archived', false)->whereNull('archived_at')->get(['id', 'name'])->toArray(),
            'can_manage' => $this->canManage($actor),
            'unavailable_reason' => ! $installed ? 'installation_required' : (! $this->partners->enabled() ? 'connection_disabled' : ($partners === [] ? 'partner_not_ready' : null))];
    }

    public function save(User $actor, array $data, ?int $connectionId = null): array
    {
        abort_unless($this->canManage($actor), 403);
        if (! Schema::hasTable('medication_pharmacy_connections')) {
            throw new PharmacyConnectionException('installation_required', 'Connected pharmacy ordering has not been installed.');
        }

        return DB::transaction(function () use ($actor, $data, $connectionId): array {
            $locks = $this->peopleLocks->lock([(int) $actor->id]);
            $current = $locks['users']->get((int) $actor->id);
            abort_unless($current instanceof User && $this->canManage($current), 403);
            $this->scope->lockCurrentStaffProfiles($locks['users'], [(int) $current->id]);
            $authorized = $this->scope->readerSiteIds($current, 'medications.pharmacy.connect.manage');
            $requestedSites = array_values(array_unique(array_map('intval', $data['site_ids'])));
            sort($requestedSites, SORT_NUMERIC);
            abort_unless($requestedSites !== [] && array_diff($requestedSites, $authorized) === [], 404);
            foreach ($requestedSites as $siteId) {
                $this->scope->lockCurrentMedicationSite($siteId);
                $this->partners->forSite($data['partner_key'], $siteId);
            }
            $connection = $connectionId ? MedicationPharmacyConnection::whereKey($connectionId)->lockForUpdate()->firstOrFail() : null;
            $eventSites = $requestedSites;
            if ($connection) {
                abort_unless(array_diff($connection->site_ids, $authorized) === [], 404);
                $eventSites = array_values(array_unique([...$requestedSites, ...array_map('intval', $connection->site_ids)]));
                sort($eventSites, SORT_NUMERIC);
                if ($connection->version !== $data['expected_version']) {
                    throw new PharmacyConnectionException('connection_changed', 'Someone changed this connection. Refresh before saving.', 409);
                }
                if ($connection->partner_key !== $data['partner_key']) {
                    throw new PharmacyConnectionException('partner_binding_immutable', 'Create a new connection for a different pharmacy partner.', 409);
                }
                $connection->forceFill(['name' => trim($data['name']), 'site_ids' => $requestedSites, 'enabled' => $data['enabled'],
                    'version' => $connection->version + 1, 'updated_by' => $current->id])->save();
            } else {
                $connection = MedicationPharmacyConnection::create(['name' => trim($data['name']), 'partner_key' => $data['partner_key'],
                    'site_ids' => $requestedSites, 'enabled' => $data['enabled'], 'created_by' => $current->id, 'updated_by' => $current->id]);
            }
            $this->events->appendMany(array_map(fn ($siteId) => new MedicationEventData(siteId: $siteId, kind: 'pharmacy.connection.saved',
                subjectType: 'pharmacy_connection', subjectId: (string) $connection->id, actorId: $current->id,
                occurredAt: CarbonImmutable::now('UTC'), summary: 'Approved pharmacy connection changed',
                facts: ['connection_id' => $connection->id, 'version' => $connection->version, 'enabled' => $connection->enabled]), $eventSites));

            return ['connection' => $this->presentation->connection($connection)];
        }, 3);
    }

    private function canManage(User $actor): bool
    {
        return $actor->canDo('medications.view') && $actor->canDo('medications.settings.manage') && $actor->canDo('medications.pharmacy.connect.manage');
    }
}
