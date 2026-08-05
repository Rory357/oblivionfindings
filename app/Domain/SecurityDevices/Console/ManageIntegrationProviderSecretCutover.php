<?php

namespace App\Domain\SecurityDevices\Console;

use App\Models\Integration\IntegrationProviderConnection;
use App\Services\Integration\IntegrationSecretManager;
use Illuminate\Console\Command;
use Throwable;

final class ManageIntegrationProviderSecretCutover extends Command
{
    protected $signature = 'security-devices:provider-secrets:cutover
        {provider : unifi or milesight}
        {action : rollback or restore}
        {--purpose=primary : primary or webhook}';

    protected $description = 'Roll back or restore a governed UniFi or Milesight provider secret cutover.';

    public function handle(IntegrationSecretManager $secrets): int
    {
        $provider = strtolower(trim((string) $this->argument('provider')));
        $action = strtolower(trim((string) $this->argument('action')));
        $purpose = strtolower(trim((string) $this->option('purpose')));

        if (! in_array($provider, ['unifi', 'milesight'], true)
            || ! in_array($action, ['rollback', 'restore'], true)
            || ! in_array($purpose, [
                IntegrationSecretManager::PURPOSE_PRIMARY,
                IntegrationSecretManager::PURPOSE_WEBHOOK,
            ], true)
            || ($provider === 'unifi' && $purpose === IntegrationSecretManager::PURPOSE_WEBHOOK)) {
            $this->error('The requested provider secret cutover operation is unsupported.');

            return self::INVALID;
        }

        $connection = IntegrationProviderConnection::query()->forProvider($provider)->first();
        if ($connection === null) {
            $this->error('The provider connection does not exist.');

            return self::FAILURE;
        }

        try {
            $action === 'rollback'
                ? $secrets->rollbackApplication($connection, $purpose)
                : $secrets->restoreApplication($connection, $purpose);
        } catch (Throwable) {
            $this->error('The provider secret cutover operation failed.');

            return self::FAILURE;
        }

        $this->info('The provider secret cutover operation completed.');

        return self::SUCCESS;
    }
}
