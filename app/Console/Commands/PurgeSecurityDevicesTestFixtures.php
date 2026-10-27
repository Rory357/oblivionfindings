<?php

namespace App\Console\Commands;

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\ControlRoomAlert;
use App\Models\Integration\IntegrationEvent;
use App\Models\ItAttachment;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogSubmission;
use App\Models\ItCatalogVersion;
use App\Models\ItChange;
use App\Models\ItEmailDelivery;
use App\Models\ItKbArticle;
use App\Models\ItMajorIncident;
use App\Models\ItMajorIncidentUpdate;
use App\Models\ItProblem;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItSecurityDesktopReleaseFixturePack;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItTicketComment;
use App\Models\ItTicketEvent;
use App\Models\ItTicketLink;
use App\Models\ItWorkTask;
use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class PurgeSecurityDevicesTestFixtures extends Command
{
    protected $signature = 'security-devices:purge-test-fixtures
        {--dry-run : List every test fixture pack, device, and Site that would be removed without writing}
        {--force : Apply the removal inside a database transaction}';

    protected $description = 'Remove release acceptance test fixtures, demo devices, and test Sites from Security & Devices';

    /** @var list<string> */
    private const array STANDALONE_FIXTURE_DEVICE_UIDS = [
        'CCT-BFTN2GEX',
        'DEMO-LOCATION-20260922',
    ];

    /** @var list<string> */
    private const array STANDALONE_FIXTURE_DEVICE_NAMES = [
        'UI Alignment Test Device',
        'Demo Location Tracker (simulated)',
    ];

    /** @var list<string> */
    private const array TEST_SITE_NAMES = [
        'RELEASE Site Alpha',
        'RELEASE Site Hidden',
        'RELEASE V10 Site Alpha',
        'RELEASE V10 Site Hidden',
        'Demo Location Site',
    ];

    /** @var array<string, class-string<Model>> */
    private const array MANIFEST_RECORD_MODELS = [
        'asset' => Asset::class,
        'client' => Client::class,
        'client_consent' => ClientConsent::class,
        'consent_type' => ConsentType::class,
        'consent_type_version' => ConsentTypeVersion::class,
        'control_room_alert' => ControlRoomAlert::class,
        'device' => Device::class,
        'device_asset_link' => DeviceAssetLink::class,
        'device_assignment' => DeviceAssignment::class,
        'device_event' => DeviceEvent::class,
        'fin_fixed_asset' => FinFixedAsset::class,
        'hr_employee_profile' => HrEmployeeProfile::class,
        'it_attachment' => ItAttachment::class,
        'it_catalog_item' => ItCatalogItem::class,
        'it_catalog_version' => ItCatalogVersion::class,
        'it_change' => ItChange::class,
        'it_kb_article' => ItKbArticle::class,
        'it_major_incident' => ItMajorIncident::class,
        'it_major_incident_update' => ItMajorIncidentUpdate::class,
        'it_problem' => ItProblem::class,
        'it_provisioning_request' => ItProvisioningRequest::class,
        'it_provisioning_workflow' => ItProvisioningWorkflow::class,
        'it_ticket' => ItTicket::class,
        'it_ticket_approval' => ItTicketApproval::class,
        'it_ticket_comment' => ItTicketComment::class,
        'it_ticket_link' => ItTicketLink::class,
        'it_work_task' => ItWorkTask::class,
        'integration_event' => IntegrationEvent::class,
        'site' => Site::class,
        'user' => User::class,
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! $dryRun && ! $force) {
            $this->error('Specify --dry-run to preview the inventory or --force to execute the removal.');

            return self::FAILURE;
        }

        if ($dryRun && $force) {
            $this->error('Choose either --dry-run or --force, not both.');

            return self::FAILURE;
        }

        $inventory = $this->buildInventory();
        $this->renderInventory($inventory);

        if ($dryRun) {
            $this->info('Dry run complete. No database rows were modified.');

            return self::SUCCESS;
        }

        $summary = DB::transaction(fn (): array => $this->executePurge($inventory), 3);
        $this->cleanupAttachmentFiles($inventory['packs']);

        $this->newLine();
        $this->info(sprintf(
            'Purge complete: %d relationships unlinked, %d devices hard-deleted, %d devices soft-deleted (history retained), %d Sites removed, %d fixture packs removed.',
            $summary['relationships_unlinked'],
            $summary['devices_hard_deleted'],
            $summary['devices_soft_deleted'],
            $summary['sites_removed'],
            $summary['packs_removed'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{
     *   packs: Collection<int, ItSecurityDesktopReleaseFixturePack>,
     *   devices: Collection<int, Device>,
     *   retained_device_ids: list<int>,
     *   sites: Collection<int, Site>,
     *   preserved_devices: Collection<int, Device>,
     *   pack_user_ids: list<int>
     * }
     */
    private function buildInventory(): array
    {
        $packs = Schema::hasTable('it_security_desktop_release_fixture_packs')
            ? ItSecurityDesktopReleaseFixturePack::query()->orderBy('id')->get()
            : collect();

        $packDeviceIds = [];
        $packSiteIds = [];
        $packUserIds = [];

        foreach ($packs as $pack) {
            foreach ((array) data_get($pack->manifest, 'records', []) as $record) {
                if (! is_array($record) || ! isset($record['type'], $record['id'])) {
                    continue;
                }
                $id = (int) $record['id'];
                if ($record['type'] === 'device') {
                    $packDeviceIds[] = $id;
                } elseif ($record['type'] === 'site') {
                    $packSiteIds[] = $id;
                } elseif ($record['type'] === 'user') {
                    $packUserIds[] = $id;
                }
            }
        }

        $devices = Device::query()
            ->where(function ($query) use ($packDeviceIds): void {
                if ($packDeviceIds !== []) {
                    $query->orWhereIn('id', array_values(array_unique($packDeviceIds)));
                }
                $query->orWhereIn('device_uid', self::STANDALONE_FIXTURE_DEVICE_UIDS)
                    ->orWhereIn('name', self::STANDALONE_FIXTURE_DEVICE_NAMES)
                    ->orWhere('name', 'like', 'RELEASE Alpha %')
                    ->orWhere('name', 'like', 'RELEASE V10 Alpha %')
                    ->orWhere('name', 'like', 'RELEASE %Hidden Device%');
            })
            ->orderBy('id')
            ->get();

        $fixtureDeviceIds = $devices->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
        $retainedDeviceIds = $this->findRetainedHistoryDeviceIds($fixtureDeviceIds);

        $candidateSites = Site::query()
            ->where(function ($query) use ($packSiteIds): void {
                if ($packSiteIds !== []) {
                    $query->orWhereIn('id', array_values(array_unique($packSiteIds)));
                }
                $query->orWhereIn('name', self::TEST_SITE_NAMES);
            })
            ->orderBy('id')
            ->get();

        $sites = $candidateSites->filter(function (Site $site) use ($fixtureDeviceIds): bool {
            $nonFixtureAssignmentExists = DeviceAssignment::query()
                ->where(function ($query) use ($site): void {
                    $query->where(function ($sub) use ($site): void {
                        $sub->where('assignable_type', 'site')
                            ->where('assignable_id', $site->id);
                    })->orWhere('custody_site_id', $site->id);
                })
                ->when($fixtureDeviceIds !== [], fn ($query) => $query->whereNotIn('device_id', $fixtureDeviceIds))
                ->exists();

            return ! $nonFixtureAssignmentExists;
        })->values();

        $preservedDevices = Device::query()
            ->when($fixtureDeviceIds !== [], fn ($query) => $query->whereNotIn('id', $fixtureDeviceIds))
            ->orderBy('id')
            ->get();

        return [
            'packs' => $packs,
            'devices' => $devices,
            'retained_device_ids' => $retainedDeviceIds,
            'sites' => $sites,
            'preserved_devices' => $preservedDevices,
            'pack_user_ids' => array_values(array_unique($packUserIds)),
        ];
    }

    /** @param array<string, mixed> $inventory */
    private function renderInventory(array $inventory): void
    {
        /** @var Collection<int, ItSecurityDesktopReleaseFixturePack> $packs */
        $packs = $inventory['packs'];
        /** @var Collection<int, Device> $devices */
        $devices = $inventory['devices'];
        /** @var list<int> $retainedIds */
        $retainedIds = $inventory['retained_device_ids'];
        /** @var Collection<int, Site> $sites */
        $sites = $inventory['sites'];
        /** @var Collection<int, Device> $preserved */
        $preserved = $inventory['preserved_devices'];

        $this->components->info(sprintf('Release fixture packs (%d)', $packs->count()));
        if ($packs->isNotEmpty()) {
            $this->table(
                ['ID', 'Pack key', 'State', 'Manifest records'],
                $packs->map(fn (ItSecurityDesktopReleaseFixturePack $pack): array => [
                    (string) $pack->id,
                    (string) $pack->pack_key,
                    (string) $pack->state,
                    (string) count((array) data_get($pack->manifest, 'records', [])),
                ])->all(),
            );
        }

        $this->components->info(sprintf('Test fixture devices to remove (%d)', $devices->count()));
        if ($devices->isNotEmpty()) {
            $this->table(
                ['ID', 'UID', 'Name', 'Provider', 'Disposition'],
                $devices->map(fn (Device $device): array => [
                    (string) $device->id,
                    (string) $device->device_uid,
                    (string) $device->name,
                    (string) ($device->provider ?? '—'),
                    in_array((int) $device->id, $retainedIds, true)
                        ? 'unlink + soft-delete (immutable history)'
                        : 'hard-delete',
                ])->all(),
            );
        }

        $this->components->info(sprintf('Test Sites to remove (%d)', $sites->count()));
        if ($sites->isNotEmpty()) {
            $this->table(
                ['ID', 'Name', 'Active'],
                $sites->map(fn (Site $site): array => [
                    (string) $site->id,
                    (string) $site->name,
                    $site->is_active ? 'yes' : 'no',
                ])->all(),
            );
        }

        $this->components->info(sprintf('Real devices preserved (%d)', $preserved->count()));
        if ($preserved->isNotEmpty()) {
            $this->table(
                ['ID', 'UID', 'Name', 'Provider'],
                $preserved->map(fn (Device $device): array => [
                    (string) $device->id,
                    (string) $device->device_uid,
                    (string) $device->name,
                    (string) ($device->provider ?? '—'),
                ])->all(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return array{
     *   relationships_unlinked: int,
     *   devices_hard_deleted: int,
     *   devices_soft_deleted: int,
     *   sites_removed: int,
     *   packs_removed: int
     * }
     */
    private function executePurge(array $inventory): array
    {
        /** @var Collection<int, ItSecurityDesktopReleaseFixturePack> $packs */
        $packs = $inventory['packs'];
        /** @var Collection<int, Device> $devices */
        $devices = $inventory['devices'];
        /** @var Collection<int, Site> $sites */
        $sites = $inventory['sites'];
        /** @var list<int> $packUserIds */
        $packUserIds = $inventory['pack_user_ids'];

        $fixtureDeviceIds = $devices->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
        $fixtureSiteIds = $sites->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
        $fallbackActorId = $this->resolveUnlinkActorId($packUserIds);

        $relationshipsUnlinked = $this->unlinkActiveDeviceRelationships($fixtureDeviceIds, $fallbackActorId);

        foreach ($packs as $pack) {
            $this->deleteD01BrowserRecords($pack);
        }

        $this->cleanMutableDeviceDependents($fixtureDeviceIds, $fixtureSiteIds);
        $this->cleanMutableSiteDependents($fixtureSiteIds);

        foreach ($packs as $pack) {
            $records = array_reverse((array) data_get($pack->manifest, 'records', []));
            foreach ($records as $record) {
                if (! is_array($record) || ! isset($record['type'], $record['id'])) {
                    continue;
                }
                if (in_array($record['type'], ['device', 'site'], true)) {
                    // Handled uniformly below with immutable-reference detection.
                    continue;
                }
                $this->deleteManifestRecord($record);
            }
        }

        $retainedDeviceIds = $this->findRetainedHistoryDeviceIds($fixtureDeviceIds);
        $devicesHardDeleted = 0;
        $devicesSoftDeleted = 0;
        $now = now();

        foreach ($devices as $device) {
            $deviceId = (int) $device->id;
            if (in_array($deviceId, $retainedDeviceIds, true)) {
                $this->softDeleteDevice($deviceId, $device->deleted_at, $now);
                $devicesSoftDeleted++;

                continue;
            }

            try {
                DB::transaction(fn () => DB::table('devices')->where('id', $deviceId)->delete());
                $devicesHardDeleted++;
            } catch (QueryException $exception) {
                if (! $this->isForeignKeyRestrictViolation($exception)) {
                    throw $exception;
                }
                $this->softDeleteDevice($deviceId, $device->deleted_at, $now);
                $devicesSoftDeleted++;
            }
        }

        $retainedSiteIds = $this->findRetainedHistorySiteIds($fixtureSiteIds);
        $sitesRemoved = 0;

        foreach ($sites as $site) {
            $siteId = (int) $site->id;
            if (in_array($siteId, $retainedSiteIds, true)) {
                $this->softDeleteSite($siteId, $site->deleted_at, $now);
                $sitesRemoved++;

                continue;
            }

            try {
                DB::transaction(fn () => DB::table('sites')->where('id', $siteId)->delete());
            } catch (QueryException $exception) {
                if (! $this->isForeignKeyRestrictViolation($exception)) {
                    throw $exception;
                }
                $this->softDeleteSite($siteId, $site->deleted_at, $now);
            }
            $sitesRemoved++;
        }

        $packsRemoved = 0;
        if ($packs->isNotEmpty()) {
            $packsRemoved = DB::table('it_security_desktop_release_fixture_packs')
                ->whereIn('id', $packs->pluck('id')->all())
                ->delete();
        }

        return [
            'relationships_unlinked' => $relationshipsUnlinked,
            'devices_hard_deleted' => $devicesHardDeleted,
            'devices_soft_deleted' => $devicesSoftDeleted,
            'sites_removed' => $sitesRemoved,
            'packs_removed' => $packsRemoved,
        ];
    }

    /** @param list<int> $fixtureDeviceIds */
    private function unlinkActiveDeviceRelationships(array $fixtureDeviceIds, ?int $fallbackActorId): int
    {
        if ($fixtureDeviceIds === [] || ! Schema::hasTable('device_relationships')) {
            return 0;
        }

        $activeRows = DB::table('device_relationships')
            ->whereNull('unlinked_at')
            ->where(function ($query) use ($fixtureDeviceIds): void {
                $query->whereIn('parent_device_id', $fixtureDeviceIds)
                    ->orWhereIn('child_device_id', $fixtureDeviceIds);
            })
            ->get(['id', 'created_by_user_id']);

        $unlinked = 0;
        $now = now();

        foreach ($activeRows as $row) {
            $actorId = $row->created_by_user_id !== null
                ? (int) $row->created_by_user_id
                : $fallbackActorId;

            if ($actorId === null) {
                continue;
            }

            $unlinked += DB::table('device_relationships')
                ->where('id', $row->id)
                ->whereNull('unlinked_at')
                ->update([
                    'unlinked_at' => $now,
                    'unlinked_by_user_id' => $actorId,
                    'unlink_reason' => 'Removed test fixture',
                    'updated_at' => $now,
                ]);
        }

        return $unlinked;
    }

    /**
     * @param  list<int>  $fixtureDeviceIds
     * @param  list<int>  $fixtureSiteIds
     */
    private function cleanMutableDeviceDependents(array $fixtureDeviceIds, array $fixtureSiteIds = []): void
    {
        if ($fixtureDeviceIds === [] && $fixtureSiteIds === []) {
            return;
        }

        if ($fixtureDeviceIds !== [] && Schema::hasTable('monitoring_discovery_candidate_matches')) {
            DB::table('monitoring_discovery_candidate_matches')
                ->whereIn('canonical_device_id', $fixtureDeviceIds)
                ->delete();
        }

        if ($fixtureDeviceIds !== [] && Schema::hasTable('monitoring_discovery_candidates')) {
            DB::table('monitoring_discovery_candidates')
                ->whereIn('canonical_device_id', $fixtureDeviceIds)
                ->update(['canonical_device_id' => null]);
        }

        // Delete evidence snapshots before device_events and control_room_alerts
        // to satisfy mon_incident_evidence_event_fk / alert FK RESTRICT rules.
        if (Schema::hasTable('monitoring_incident_evidence_snapshots')) {
            $eventIds = ($fixtureDeviceIds !== [] && Schema::hasTable('device_events'))
                ? DB::table('device_events')->whereIn('device_id', $fixtureDeviceIds)->pluck('id')->all()
                : [];
            $alertIds = ($fixtureDeviceIds !== [] && Schema::hasTable('control_room_alerts') && Schema::hasColumn('control_room_alerts', 'canonical_device_id'))
                ? DB::table('control_room_alerts')->whereIn('canonical_device_id', $fixtureDeviceIds)->pluck('id')->all()
                : [];

            DB::table('monitoring_incident_evidence_snapshots')
                ->where(function ($query) use ($fixtureDeviceIds, $fixtureSiteIds, $eventIds, $alertIds): void {
                    if ($fixtureDeviceIds !== [] && Schema::hasColumn('monitoring_incident_evidence_snapshots', 'device_id')) {
                        $query->orWhereIn('device_id', $fixtureDeviceIds);
                    }
                    if ($fixtureSiteIds !== [] && Schema::hasColumn('monitoring_incident_evidence_snapshots', 'site_id')) {
                        $query->orWhereIn('site_id', $fixtureSiteIds);
                    }
                    if ($eventIds !== [] && Schema::hasColumn('monitoring_incident_evidence_snapshots', 'device_event_id')) {
                        $query->orWhereIn('device_event_id', $eventIds);
                    }
                    if ($alertIds !== [] && Schema::hasColumn('monitoring_incident_evidence_snapshots', 'control_room_alert_id')) {
                        $query->orWhereIn('control_room_alert_id', $alertIds);
                    }
                })
                ->delete();
        }

        if ($fixtureDeviceIds === []) {
            return;
        }

        foreach ([
            ['device_assignments', 'device_id'],
            ['device_asset_links', 'device_id'],
            ['device_events', 'device_id'],
            ['cctv_camera_sources', 'device_id'],
            ['queclink_devices', 'device_id'],
            ['control_room_alerts', 'canonical_device_id'],
            ['integration_events', 'canonical_device_id'],
        ] as [$table, $column]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::table($table)->whereIn($column, $fixtureDeviceIds)->delete();
            }
        }

        if (Schema::hasTable('monitors')) {
            $immutableMonitorIds = [];
            if (Schema::hasTable('monitoring_metric_series') && Schema::hasColumn('monitoring_metric_series', 'monitor_id')) {
                $immutableMonitorIds = DB::table('monitoring_metric_series')
                    ->whereNotNull('monitor_id')
                    ->pluck('monitor_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();
            }

            DB::table('monitors')
                ->whereIn('device_id', $fixtureDeviceIds)
                ->when($immutableMonitorIds !== [], fn ($q) => $q->whereNotIn('id', $immutableMonitorIds))
                ->delete();
        }
    }

    /** @param list<int> $fixtureSiteIds */
    private function cleanMutableSiteDependents(array $fixtureSiteIds): void
    {
        if ($fixtureSiteIds === []) {
            return;
        }

        if (Schema::hasTable('monitoring_discovery_scopes')) {
            $scopeIds = DB::table('monitoring_discovery_scopes')
                ->whereIn('site_id', $fixtureSiteIds)
                ->pluck('id')
                ->all();

            if ($scopeIds !== [] && Schema::hasTable('monitoring_discovery_runs')) {
                $runIds = DB::table('monitoring_discovery_runs')
                    ->whereIn('discovery_scope_id', $scopeIds)
                    ->pluck('id')
                    ->all();

                if ($runIds !== [] && Schema::hasTable('monitoring_discovery_candidates')) {
                    $candidateIds = DB::table('monitoring_discovery_candidates')
                        ->whereIn('discovery_run_id', $runIds)
                        ->pluck('id')
                        ->all();

                    if ($candidateIds !== [] && Schema::hasTable('monitoring_discovery_candidate_matches')) {
                        DB::table('monitoring_discovery_candidate_matches')
                            ->whereIn('candidate_id', $candidateIds)
                            ->delete();
                    }

                    DB::table('monitoring_discovery_candidates')
                        ->whereIn('discovery_run_id', $runIds)
                        ->delete();
                }

                DB::table('monitoring_discovery_runs')
                    ->whereIn('discovery_scope_id', $scopeIds)
                    ->delete();
            }

            DB::table('monitoring_discovery_scopes')
                ->whereIn('site_id', $fixtureSiteIds)
                ->delete();
        }
    }

    private function deleteD01BrowserRecords(ItSecurityDesktopReleaseFixturePack $pack): void
    {
        $records = collect((array) data_get($pack->manifest, 'records', []));
        $catalogIds = $records
            ->filter(static fn (mixed $r): bool => is_array($r) && ($r['type'] ?? null) === 'it_catalog_item')
            ->pluck('id')
            ->all();
        $requesterIds = $records
            ->filter(static fn (mixed $r): bool => is_array($r) && ($r['type'] ?? null) === 'user')
            ->pluck('id')
            ->all();

        if ($catalogIds === [] || $requesterIds === [] || ! Schema::hasTable('it_catalog_submissions')) {
            return;
        }

        $ticketType = (new ItTicket)->getMorphClass();
        $submissions = ItCatalogSubmission::query()
            ->whereIn('catalog_item_id', $catalogIds)
            ->whereIn('requester_user_id', $requesterIds)
            ->where('result_type', $ticketType)
            ->get(['id', 'result_id']);

        $ticketIds = $submissions->pluck('result_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values();
        if ($ticketIds->isEmpty()) {
            return;
        }

        $commentIds = ItTicketComment::query()->whereIn('ticket_id', $ticketIds)->pluck('id');
        $deliveryIds = ItEmailDelivery::query()
            ->where(function ($query) use ($ticketIds, $commentIds): void {
                $query->whereIn('it_ticket_id', $ticketIds);
                if ($commentIds->isNotEmpty()) {
                    $query->orWhereIn('it_ticket_comment_id', $commentIds);
                }
            })
            ->pluck('id');

        do {
            $retryIds = $deliveryIds->isEmpty()
                ? collect()
                : ItEmailDelivery::query()->whereIn('retry_of_delivery_id', $deliveryIds)->pluck('id');
            $newIds = $retryIds->diff($deliveryIds);
            $deliveryIds = $deliveryIds->merge($newIds)->unique()->values();
        } while ($newIds->isNotEmpty());

        if ($deliveryIds->isNotEmpty()) {
            ItEmailDelivery::query()->whereIn('id', $deliveryIds)->delete();
        }
        ItTicketEvent::query()->where('subject_type', $ticketType)->whereIn('subject_id', $ticketIds)->delete();
        ItCatalogSubmission::query()->whereIn('id', $submissions->pluck('id'))->delete();
        ItTicket::query()->whereIn('id', $ticketIds)->delete();
    }

    /** @param array<string, mixed> $record */
    private function deleteManifestRecord(array $record): void
    {
        $type = (string) $record['type'];
        $id = (int) $record['id'];

        if ($type === 'it_ticket_watcher') {
            if (Schema::hasTable('it_ticket_watchers')) {
                DB::table('it_ticket_watchers')->where('id', $id)->delete();
            }

            return;
        }

        if ($type === 'monitoring_incident_evidence_snapshot') {
            if (Schema::hasTable('monitoring_incident_evidence_snapshots')) {
                DB::table('monitoring_incident_evidence_snapshots')->where('id', $id)->delete();
            }

            return;
        }

        $class = self::MANIFEST_RECORD_MODELS[$type] ?? null;
        if ($class === null) {
            return;
        }

        $query = $class::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query = $query->withTrashed();
        }

        $model = $query->whereKey($id)->first();
        if (! $model) {
            return;
        }

        if ($model instanceof ItTicketApproval) {
            DB::table($model->getTable())->where('id', $model->id)->delete();

            return;
        }

        if ($model instanceof ItCatalogVersion) {
            DB::table('it_catalog_items')
                ->where('id', $model->catalog_item_id)
                ->where('published_version_id', $model->id)
                ->update(['published_version_id' => null]);
            DB::table($model->getTable())->where('id', $model->id)->delete();

            return;
        }

        if ($model instanceof ItCatalogItem) {
            DB::table('it_catalog_items')
                ->where('id', $model->id)
                ->update(['published_version_id' => null]);
            if (Schema::hasTable('it_catalog_submissions')) {
                DB::table('it_catalog_submissions')->where('catalog_item_id', $model->id)->delete();
            }
            if (Schema::hasTable('it_catalog_versions')) {
                DB::table('it_catalog_versions')->where('catalog_item_id', $model->id)->delete();
            }
        }

        if ($model instanceof User) {
            $model->roles()->detach();
            $model->permissionOverrides()->detach();
            if ($this->userHasImmutableReference((int) $model->id)) {
                DB::table('users')->where('id', $model->id)->update(['approved_at' => null]);

                return;
            }
        }

        try {
            DB::transaction(function () use ($model): void {
                if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                    $model->forceDelete();
                } else {
                    $model->delete();
                }
            });
        } catch (QueryException $exception) {
            if (! $this->isForeignKeyRestrictViolation($exception)) {
                throw $exception;
            }

            $this->deactivateRestrictedManifestModel($model);
        }
    }

    private function deactivateRestrictedManifestModel(Model $model): void
    {
        $now = now();

        if ($model instanceof User) {
            DB::table('users')->where('id', $model->id)->update([
                'approved_at' => null,
                'updated_at' => $now,
            ]);

            return;
        }

        if ($model instanceof HrEmployeeProfile) {
            DB::table('hr_employee_profiles')->where('id', $model->id)->update([
                'is_active' => false,
                'primary_site_id' => null,
                'updated_at' => $now,
            ]);

            return;
        }

        if ($model instanceof Client) {
            DB::table('clients')->where('id', $model->id)->update([
                'site_id' => null,
                'deleted_at' => $model->deleted_at ?? $now,
                'updated_at' => $now,
            ]);

            return;
        }

        if ($model instanceof Asset) {
            DB::table('assets')->where('id', $model->id)->update([
                'site_id' => null,
                'home_site_id' => null,
                'deleted_at' => $model->deleted_at ?? $now,
                'updated_at' => $now,
            ]);

            return;
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            DB::table($model->getTable())->where('id', $model->id)->update([
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        throw new \RuntimeException(sprintf(
            'Manifest record %s#%s cannot be hard-deleted or safely deactivated.',
            $model::class,
            (string) $model->getKey(),
        ));
    }

    private function softDeleteDevice(int $deviceId, mixed $existingDeletedAt, mixed $now): void
    {
        DB::table('devices')->where('id', $deviceId)->update([
            'status' => DeviceStatus::Decommissioned->value,
            'health_status' => HealthStatus::Unknown->value,
            'deleted_at' => $existingDeletedAt ?? $now,
            'updated_at' => $now,
        ]);
    }

    private function softDeleteSite(int $siteId, mixed $existingDeletedAt, mixed $now): void
    {
        DB::table('sites')->where('id', $siteId)->update([
            'is_active' => false,
            'deleted_at' => $existingDeletedAt ?? $now,
            'updated_at' => $now,
        ]);
    }

    private function isForeignKeyRestrictViolation(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1451;
    }

    /**
     * @param  list<int>  $deviceIds
     * @return list<int>
     */
    private function findRetainedHistoryDeviceIds(array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }

        $retained = [];

        if (Schema::hasTable('device_relationships')) {
            $parents = DB::table('device_relationships')
                ->whereIn('parent_device_id', $deviceIds)
                ->pluck('parent_device_id')
                ->all();
            $children = DB::table('device_relationships')
                ->whereIn('child_device_id', $deviceIds)
                ->pluck('child_device_id')
                ->all();
            $retained = array_merge($retained, $parents, $children);
        }

        foreach ([
            ['device_command_requests', 'device_id'],
            ['monitoring_configuration_snapshots', 'device_id'],
            ['monitoring_metric_series', 'device_id'],
            ['monitoring_retention_tombstones', 'device_id'],
        ] as [$table, $column]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                $found = DB::table($table)->whereIn($column, $deviceIds)->pluck($column)->all();
                $retained = array_merge($retained, $found);
            }
        }

        return array_values(array_unique(array_map('intval', $retained)));
    }

    /**
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    private function findRetainedHistorySiteIds(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $retained = [];

        foreach ([
            ['device_command_requests', 'site_id'],
            ['monitoring_collectors', 'site_id'],
            ['monitoring_configuration_snapshots', 'site_id'],
            ['monitoring_metric_series', 'site_id'],
            ['monitoring_retention_tombstones', 'site_id'],
        ] as [$table, $column]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                $found = DB::table($table)->whereIn($column, $siteIds)->pluck($column)->all();
                $retained = array_merge($retained, $found);
            }
        }

        return array_values(array_unique(array_map('intval', $retained)));
    }

    private function userHasImmutableReference(int $userId): bool
    {
        foreach ([
            ['device_relationships', 'created_by_user_id'],
            ['device_relationships', 'unlinked_by_user_id'],
            ['device_command_requests', 'requested_by_user_id'],
            ['device_command_approvals', 'decided_by_user_id'],
        ] as [$table, $column]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)
                && DB::table($table)->where($column, $userId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<int> $excludedUserIds */
    private function resolveUnlinkActorId(array $excludedUserIds): ?int
    {
        $preferred = User::query()
            ->when($excludedUserIds !== [], fn ($q) => $q->whereNotIn('id', $excludedUserIds))
            ->whereNotNull('approved_at')
            ->orderBy('id')
            ->value('id');

        if ($preferred !== null) {
            return (int) $preferred;
        }

        $fallback = User::query()->orderBy('id')->value('id');

        return $fallback !== null ? (int) $fallback : null;
    }

    /** @param Collection<int, ItSecurityDesktopReleaseFixturePack> $packs */
    private function cleanupAttachmentFiles(Collection $packs): void
    {
        if ($packs->isEmpty()) {
            return;
        }

        $disk = Storage::disk(ItAttachment::DISK);
        foreach ($packs as $pack) {
            foreach ((array) data_get($pack->manifest, 'files', []) as $file) {
                $path = is_array($file) ? ($file['path'] ?? null) : null;
                if (is_string($path) && $path !== '' && $disk->exists($path)) {
                    $disk->delete($path);
                }
            }
        }
    }
}
