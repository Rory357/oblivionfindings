<?php

namespace App\Domain\SecurityDevices\Console;

use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteSecret;
use App\Services\Integration\IntegrationSecretManager;
use Illuminate\Console\Command;
use Throwable;

final class BackfillIntegrationProviderSecrets extends Command
{
    protected $signature = 'security-devices:provider-secrets:backfill
        {--provider=* : Limit to unifi or milesight}
        {--finalize : Remove legacy encrypted values after verified cutover}';

    protected $description = 'Backfill UniFi and Milesight secrets into the governed secret manager.';

    public function handle(IntegrationSecretManager $secrets): int
    {
        $providers = array_values(array_unique(array_map(
            static fn (mixed $provider): string => strtolower(trim((string) $provider)),
            (array) $this->option('provider'),
        )));
        $providers = $providers === [] ? ['unifi', 'milesight'] : $providers;
        if (array_diff($providers, ['unifi', 'milesight']) !== []) {
            $this->error('Only UniFi and Milesight provider secrets are supported.');

            return self::FAILURE;
        }

        $migrated = 0;
        $failed = 0;
        IntegrationProviderConnection::query()
            ->whereIn('provider', $providers)
            ->orderBy('id')
            ->each(function (IntegrationProviderConnection $connection) use ($secrets, &$migrated, &$failed): void {
                try {
                    $migrated += $secrets->backfillApplication($connection);
                    if ((bool) $this->option('finalize')) {
                        $secrets->finalizeApplication($connection->fresh());
                    }
                } catch (Throwable) {
                    $failed++;
                }
            });

        IntegrationSiteSecret::query()
            ->whereIn('provider', $providers)
            ->orderBy('id')
            ->each(function (IntegrationSiteSecret $siteSecret) use ($secrets, &$migrated, &$failed): void {
                try {
                    $migrated += $secrets->backfillSite($siteSecret) ? 1 : 0;
                    if ((bool) $this->option('finalize')) {
                        $secrets->finalizeSite($siteSecret->fresh());
                    }
                } catch (Throwable) {
                    $failed++;
                }
            });

        $this->info("Provider secret cutover processed: {$migrated} migrated; {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
