<?php

namespace App\Console\Commands;

use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;

class SyncRbac extends Command
{
    protected $signature = 'rbac:sync {--force : Force the sync without confirmation}';

    protected $description = 'Sync the RBAC permission catalogue and role assignments from the canonical seeder';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            if (! $this->confirm('This will sync RBAC roles and permissions in production. Continue?')) {
                $this->components->warn('RBAC sync cancelled.');

                return self::INVALID;
            }
        }

        if ($this->call('db:seed', [
            '--class' => RbacSeeder::class,
            '--force' => true,
        ]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->components->info('RBAC sync completed successfully.');

        return self::SUCCESS;
    }
}
