<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\NetworkConfig\Services\NightlySettingsBackups;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * S&D Phase G (G1): tonight's settings backups for routers and switches, through the governed
 * request and the Site's hub. Runs every ten minutes in the night window and picks up where the last
 * run stopped, a few devices per hub at a time. Off until someone is named to ask for them
 * (security_devices.nightly_settings_backups.requested_by_user_id).
 */
final class SecurityDeviceNightlySettingsBackups extends Command
{
    protected $signature = 'security-devices:nightly-settings-backups {--dry-run : Say what would be asked for, and ask for nothing}';

    protected $description = 'Ask for tonight’s settings backup for each router and switch that can have one';

    public function handle(NightlySettingsBackups $nightly): int
    {
        $result = $nightly->run(CarbonImmutable::now('UTC'), (bool) $this->option('dry-run'));
        if ($result['off']) {
            $this->info('Nightly settings backups are off: nobody is named to ask for them.');

            return self::SUCCESS;
        }

        $this->info(($this->option('dry-run') ? 'Would ask for' : 'Asked for').": {$result['requested']}");
        if ($result['refused'] > 0) {
            $this->warn("Refused: {$result['refused']}");
            foreach (array_slice($result['refusals'], 0, 20) as $line) {
                $this->line('  '.$line);
            }
        }
        foreach ($result['skipped'] as $why => $count) {
            $this->line("Skipped ({$why}): {$count}");
        }

        return self::SUCCESS;
    }
}
