<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Console\Command;

class BackfillDeviceMetaDefaults extends Command
{
    protected $signature = 'tracking:backfill-device-meta-defaults
        {--dry-run : Show what would change without writing}';

    protected $description = 'Initialise missing meta defaults (panic_active=false) on tracking devices.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $devices = Device::query()
            ->where('domain', 'tracking')
            ->get();

        $this->info("Scanning {$devices->count()} tracking devices"
            .($dryRun ? ' (dry-run)' : '').'...');

        $updated = 0;
        foreach ($devices as $device) {
            $meta = $device->meta ?? [];
            $changed = false;

            if (! array_key_exists('panic_active', $meta)) {
                $meta['panic_active'] = false;
                $changed = true;
            }

            if (! $changed) {
                continue;
            }

            $this->line("  device id={$device->id} imei={$device->imei} → adding panic_active=false");

            if (! $dryRun) {
                $device->forceFill(['meta' => $meta])->save();
            }
            $updated++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] ' : '')."Updated: {$updated}");

        return self::SUCCESS;
    }
}
