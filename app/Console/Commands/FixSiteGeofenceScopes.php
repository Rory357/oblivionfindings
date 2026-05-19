<?php

namespace App\Console\Commands;

use App\Models\AssetGeofence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class FixSiteGeofenceScopes extends Command
{
    protected $signature = 'tracking:fix-site-geofence-scopes
        {--dry-run : Show what would change without writing}';

    protected $description = 'Walk every site-scoped AssetGeofence and set its scope from sites.type. Idempotent.';

    public function handle(): int
    {
        if (! Schema::hasTable('asset_geofences') || ! Schema::hasTable('sites')) {
            $this->error('asset_geofences or sites table missing.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $geofences = AssetGeofence::query()
            ->whereNotNull('site_id')
            ->whereNull('asset_id')
            ->with('site:id,name,type')
            ->get();

        $this->info("Scanning {$geofences->count()} site-scoped geofences"
            .($dryRun ? ' (dry-run)' : '').'...');

        $updated = 0;
        $skipped = 0;
        $orphaned = 0;

        foreach ($geofences as $gf) {
            if (! $gf->site) {
                $orphaned++;
                $this->warn("  geofence id={$gf->id} site_id={$gf->site_id} → site missing");

                continue;
            }

            $desired = match ($gf->site->type) {
                'house', 'residential' => 'house',
                'facility' => 'asset',
                default => 'site',
            };

            if ($gf->scope === $desired) {
                $skipped++;

                continue;
            }

            $this->line("  geofence id={$gf->id} site={$gf->site->name} ({$gf->site->type}) scope: ".var_export($gf->scope, true)." → {$desired}");

            if (! $dryRun) {
                $gf->update(['scope' => $desired]);
            }
            $updated++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] ' : '')."Updated: {$updated}  Skipped: {$skipped}  Orphaned: {$orphaned}");

        return self::SUCCESS;
    }
}
