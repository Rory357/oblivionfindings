<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fleet_key_logs')) {
            return;
        }

        if (! Schema::hasColumn('fleet_key_logs', 'site_id')) {
            Schema::table('fleet_key_logs', function (Blueprint $table): void {
                $table->foreignId('site_id')
                    ->nullable()
                    ->after('asset_id')
                    ->constrained('sites')
                    ->restrictOnDelete();
                $table->index(
                    ['site_id', 'created_at', 'id'],
                    'fleet_key_logs_site_created_id_index',
                );
            });
        }

        $siteIds = DB::table('sites')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $siteIds = array_fill_keys($siteIds, true);

        DB::table('fleet_key_logs')
            ->whereNull('site_id')
            ->orderBy('id')
            ->chunkById(500, function ($logs) use ($siteIds): void {
                $assetIds = $logs->pluck('asset_id')->filter()->unique()->values();
                $assets = DB::table('assets')
                    ->whereIn('id', $assetIds)
                    ->get(['id', 'site_id', 'home_site_id', 'client_id', 'updated_at'])
                    ->keyBy('id');
                $clientIds = $assets->pluck('client_id')->filter()->unique()->values();
                $clients = DB::table('clients')
                    ->whereIn('id', $clientIds)
                    ->get(['id', 'site_id', 'updated_at'])
                    ->keyBy('id');
                $backfill = [];

                foreach ($logs as $log) {
                    $asset = $assets->get($log->asset_id);
                    if (! $asset || ! $this->unchangedAtEvent($asset->updated_at, $log->created_at)) {
                        continue;
                    }

                    $client = null;
                    if (is_numeric($asset->client_id) && (int) $asset->client_id > 0) {
                        $client = $clients->get($asset->client_id);
                        if (! $client || ! $this->unchangedAtEvent($client->updated_at, $log->created_at)) {
                            continue;
                        }
                    }

                    $siteId = $this->canonicalSiteId($asset, $client);
                    if ($siteId === null || ! isset($siteIds[$siteId])) {
                        continue;
                    }

                    $backfill[$siteId][] = (int) $log->id;
                }

                foreach ($backfill as $siteId => $logIds) {
                    DB::table('fleet_key_logs')
                        ->whereIn('id', $logIds)
                        ->whereNull('site_id')
                        ->update(['site_id' => (int) $siteId]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fleet_key_logs') || ! Schema::hasColumn('fleet_key_logs', 'site_id')) {
            return;
        }

        Schema::table('fleet_key_logs', function (Blueprint $table): void {
            $table->dropForeign(['site_id']);
            $table->dropIndex('fleet_key_logs_site_created_id_index');
            $table->dropColumn('site_id');
        });
    }

    private function unchangedAtEvent(mixed $updatedAt, mixed $eventAt): bool
    {
        if ($updatedAt === null || $eventAt === null) {
            return false;
        }

        $updatedTimestamp = strtotime((string) $updatedAt);
        $eventTimestamp = strtotime((string) $eventAt);

        return $updatedTimestamp !== false
            && $eventTimestamp !== false
            && $updatedTimestamp < $eventTimestamp;
    }

    private function canonicalSiteId(object $asset, ?object $client): ?int
    {
        $directSiteId = $this->positiveId($asset->site_id);
        $homeSiteId = $this->positiveId($asset->home_site_id);
        $clientId = $this->positiveId($asset->client_id);
        $clientSiteId = $this->positiveId($client?->site_id);

        if ($clientId !== null && $clientSiteId === null) {
            return null;
        }

        if ($directSiteId !== null) {
            return $clientSiteId === null || $clientSiteId === $directSiteId
                ? $directSiteId
                : null;
        }

        if ($homeSiteId !== null) {
            return $clientSiteId === null || $clientSiteId === $homeSiteId
                ? $homeSiteId
                : null;
        }

        return $clientSiteId;
    }

    private function positiveId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
};
