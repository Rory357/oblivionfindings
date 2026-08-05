<?php

namespace App\Domain\SecurityDevices\Console;

use App\Services\Integration\IntegrationSecretManager;
use Illuminate\Console\Command;

final class RetryIntegrationProviderSecretCleanup extends Command
{
    protected $signature = 'security-devices:provider-secrets:cleanup
        {--limit=100 : Maximum value-free cleanup pointers to process}';

    protected $description = 'Retry pending external cleanup for revoked provider secret versions.';

    public function handle(IntegrationSecretManager $secrets): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 500],
        ]);
        if ($limit === false) {
            $this->error('The cleanup limit must be between 1 and 500.');

            return self::INVALID;
        }

        $result = $secrets->retryPendingCleanup((int) $limit);
        $this->info(
            "Provider secret cleanup processed: {$result['processed']}; cleaned: {$result['cleaned']}; remaining: {$result['remaining']}.",
        );

        return $result['remaining'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
