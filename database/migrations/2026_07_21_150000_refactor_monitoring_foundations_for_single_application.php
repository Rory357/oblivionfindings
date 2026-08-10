<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoGlobalIdentityCollisions();
        $snapshots = $this->observationSnapshots();

        Schema::table('monitor_observations', function (Blueprint $table): void {
            $table->unsignedBigInteger('device_id')->nullable()->after('monitor_id');
            $table->unsignedBigInteger('site_id')->nullable()->after('device_id');
            $table->unsignedBigInteger('collector_id')->nullable()->after('site_id');
        });

        foreach ($snapshots as $snapshot) {
            DB::table('monitor_observations')
                ->where('id', $snapshot['observation_id'])
                ->update([
                    'device_id' => $snapshot['device_id'],
                    'site_id' => $snapshot['site_id'],
                    'collector_id' => $snapshot['collector_id'],
                ]);
        }

        // Keep the new snapshot columns nullable for one expand/contract release.
        // Old workers can continue writing while the application dual-writer is
        // rolled out; a later contract migration may enforce NOT NULL only after
        // the post-deploy backfill proves that no compatibility rows remain.
        Schema::table('monitor_observations', function (Blueprint $table): void {
            $table->foreign('device_id', 'monitor_observations_device_fk')
                ->references('id')->on('devices')->restrictOnDelete();
            $table->foreign('site_id', 'monitor_observations_site_fk')
                ->references('id')->on('sites')->restrictOnDelete();
            $table->foreign('collector_id', 'monitor_observations_collector_fk')
                ->references('id')->on('monitoring_collectors')->restrictOnDelete();

            $table->index(['device_id', 'observed_at'], 'monitor_observations_device_observed_idx');
            $table->index(['site_id', 'observed_at'], 'monitor_observations_site_observed_idx');
            $table->index(['collector_id', 'observed_at'], 'monitor_observations_collector_observed_idx');
            $table->index(['state', 'observed_at'], 'monitor_observations_state_observed_idx');
        });

        Schema::table('monitoring_collectors', function (Blueprint $table): void {
            $table->unique('collector_uuid', 'monitoring_collectors_uuid_uq');
        });

        Schema::table('monitoring_collectors', function (Blueprint $table): void {
            $table->dropUnique('monitoring_collectors_tenant_uuid_uq');
            $table->dropIndex('monitoring_collectors_tenant_status_idx');
            $table->index(['site_id', 'status'], 'monitoring_collectors_site_status_idx');
            $table->index(['status', 'last_seen_at'], 'monitoring_collectors_status_seen_idx');
        });

        Schema::table('monitoring_profiles', function (Blueprint $table): void {
            $table->unique('name', 'monitoring_profiles_name_uq');
        });

        Schema::table('monitoring_profiles', function (Blueprint $table): void {
            $table->dropUnique('monitoring_profiles_tenant_name_uq');
            $table->index(['is_active', 'name'], 'monitoring_profiles_active_name_idx');
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropIndex('monitors_tenant_state_idx');
            $table->index(['current_state', 'is_enabled'], 'monitors_state_enabled_idx');
            $table->index(['collector_id', 'is_enabled'], 'monitors_collector_enabled_idx');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropIndex('monitors_state_enabled_idx');
            $table->dropIndex('monitors_collector_enabled_idx');
            $table->index(['tenant_id', 'current_state'], 'monitors_tenant_state_idx');
        });

        Schema::table('monitoring_profiles', function (Blueprint $table): void {
            $table->dropIndex('monitoring_profiles_active_name_idx');
            $table->unique(['tenant_id', 'name'], 'monitoring_profiles_tenant_name_uq');
        });

        Schema::table('monitoring_profiles', function (Blueprint $table): void {
            $table->dropUnique('monitoring_profiles_name_uq');
        });

        Schema::table('monitoring_collectors', function (Blueprint $table): void {
            $table->dropIndex('monitoring_collectors_status_seen_idx');
            $table->dropIndex('monitoring_collectors_site_status_idx');
            $table->unique(['tenant_id', 'collector_uuid'], 'monitoring_collectors_tenant_uuid_uq');
            $table->index(['tenant_id', 'status'], 'monitoring_collectors_tenant_status_idx');
        });

        Schema::table('monitoring_collectors', function (Blueprint $table): void {
            $table->dropUnique('monitoring_collectors_uuid_uq');
        });

        Schema::table('monitor_observations', function (Blueprint $table): void {
            $table->dropForeign('monitor_observations_collector_fk');
            $table->dropForeign('monitor_observations_site_fk');
            $table->dropForeign('monitor_observations_device_fk');
            $table->dropIndex('monitor_observations_state_observed_idx');
            $table->dropIndex('monitor_observations_collector_observed_idx');
            $table->dropIndex('monitor_observations_site_observed_idx');
            $table->dropIndex('monitor_observations_device_observed_idx');
            $table->dropColumn(['device_id', 'site_id', 'collector_id']);
        });
    }

    private function assertNoGlobalIdentityCollisions(): void
    {
        $collectorCollision = DB::table('monitoring_collectors')
            ->select('collector_uuid')
            ->groupBy('collector_uuid')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($collectorCollision) {
            throw new RuntimeException(
                'Duplicate monitoring collector identifiers require reconciliation before global identity can be enforced.',
            );
        }

        $profileCollision = DB::table('monitoring_profiles')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($profileCollision) {
            throw new RuntimeException(
                'Duplicate monitoring profile names require reconciliation before global identity can be enforced.',
            );
        }
    }

    /** @return list<array{observation_id: int, device_id: int, site_id: int, collector_id: ?int}> */
    private function observationSnapshots(): array
    {
        return DB::table('monitor_observations as observations')
            ->join('monitors', 'monitors.id', '=', 'observations.monitor_id')
            ->leftJoin('monitoring_collectors as collectors', 'collectors.id', '=', 'monitors.collector_id')
            ->select([
                'observations.id as observation_id',
                'monitors.device_id',
                'monitors.collector_id',
                'collectors.site_id as collector_site_id',
            ])
            ->orderBy('observations.id')
            ->get()
            ->map(function (object $row): array {
                $siteId = $this->canonicalSiteIdForDevice((int) $row->device_id);
                if ($row->collector_id !== null && (int) $row->collector_site_id !== $siteId) {
                    throw new RuntimeException(
                        'Existing monitoring observation collector and Device Site provenance must be reconciled.',
                    );
                }

                $siteIsActive = DB::table('sites')
                    ->where('id', $siteId)
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
                    ->exists();

                if (! $siteIsActive) {
                    throw new RuntimeException(
                        'Existing monitoring observations reference an unavailable Site.',
                    );
                }

                return [
                    'observation_id' => (int) $row->observation_id,
                    'device_id' => (int) $row->device_id,
                    'site_id' => $siteId,
                    'collector_id' => $row->collector_id === null ? null : (int) $row->collector_id,
                ];
            })
            ->all();
    }

    private function canonicalSiteIdForDevice(int $deviceId): int
    {
        $assignmentSiteIds = DB::table('device_assignments')
            ->where('device_id', $deviceId)
            ->whereNull('released_at')
            ->where('assigned_at', '<=', now())
            ->orderBy('id')
            ->get(['assignable_type', 'assignable_id'])
            ->map(fn (object $assignment) => collect($this->siteIdsForAssignment(
                (string) $assignment->assignable_type,
                (int) $assignment->assignable_id,
            ))->unique()->values());

        if ($assignmentSiteIds->isEmpty()
            || $assignmentSiteIds->contains(fn ($siteIds): bool => $siteIds->count() !== 1)) {
            throw new RuntimeException(
                'Existing monitoring observations require one canonical active device Site before provenance can be snapshotted.',
            );
        }

        $siteIds = $assignmentSiteIds->flatten()->unique()->values();
        if ($siteIds->count() !== 1) {
            throw new RuntimeException(
                'Existing monitoring observations require one canonical active device Site before provenance can be snapshotted.',
            );
        }

        return (int) $siteIds->first();
    }

    /** @return list<int> */
    private function siteIdsForAssignment(string $type, int $targetId): array
    {
        return match ($type) {
            'site' => [$targetId],
            'room' => $this->oneSiteId(DB::table('site_rooms')->where('id', $targetId)->value('site_id')),
            'client' => $this->oneSiteId(
                DB::table('clients')
                    ->where('id', $targetId)
                    ->whereNull('deleted_at')
                    ->where('status', 'active')
                    ->value('site_id'),
            ),
            'staff' => DB::table('hr_employee_profiles')
                ->where('user_id', $targetId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
                ->whereNotNull('primary_site_id')
                ->pluck('primary_site_id')
                ->filter(fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
                ->map(fn (mixed $siteId): int => (int) $siteId)
                ->unique()
                ->values()
                ->all(),
            'vehicle' => $this->vehicleSiteIds($targetId),
            default => [],
        };
    }

    /** @return list<int> */
    private function vehicleSiteIds(int $assetId): array
    {
        $asset = DB::table('assets')
            ->leftJoin('asset_categories', 'asset_categories.id', '=', 'assets.category_id')
            ->where('assets.id', $assetId)
            ->where('assets.status', 'active')
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(assets.category) = ?', ['vehicle'])
                ->orWhereRaw('LOWER(asset_categories.slug) = ?', ['vehicle']))
            ->first(['assets.site_id', 'assets.home_site_id', 'assets.client_id']);

        if ($asset === null) {
            return [];
        }

        $siteIds = collect([$asset->site_id, $asset->home_site_id]);
        if ($asset->client_id !== null) {
            $clientSiteId = DB::table('clients')
                ->where('id', $asset->client_id)
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->value('site_id');

            if ($clientSiteId === null) {
                return [];
            }

            $siteIds->push($clientSiteId);
        }

        return $siteIds
            ->filter(fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function oneSiteId(mixed $siteId): array
    {
        return is_numeric($siteId) && (int) $siteId > 0 ? [(int) $siteId] : [];
    }
};
