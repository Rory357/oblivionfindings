<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Fleet\MaintenanceConfigurationService;
use Illuminate\Console\Command;

class ConfigureMaintenance extends Command
{
    protected $signature = 'maintenance:configure
        {--input= : Absolute path to one explicitly approved JSON configuration}
        {--actor-id= : Current staff member with fleet.maintenance.configure at the site}
        {--dry-run : Validate exact scope and contents without writing}';

    protected $description = 'Validate and record one approved, site-scoped maintenance configuration version';

    public function handle(MaintenanceConfigurationService $service): int
    {
        $path = (string) $this->option('input');
        $actorId = (int) $this->option('actor-id');
        if ($path === '' || ! is_file($path) || $actorId < 1) {
            $this->error('Provide an existing JSON input file and a current actor ID.');
            return self::FAILURE;
        }
        $input = json_decode((string) file_get_contents($path), true);
        if (! is_array($input) || array_is_list($input)) {
            $this->error('The input must be one JSON configuration object.');
            return self::FAILURE;
        }
        $actor = User::query()->findOrFail($actorId);
        if ($this->option('dry-run')) {
            $data = $service->validate($actor, $input);
            $this->info('Validated '.$data['kind'].' for approved site '.$data['site_id'].'; no write.');
            return self::SUCCESS;
        }
        $result = $service->apply($actor, $input);
        $this->info('Recorded '.$result['kind'].' version '.$result['version'].' (ID '.$result['id'].').');

        return self::SUCCESS;
    }
}
