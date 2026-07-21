<?php

namespace App\Services\Integration\Adapters;

use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\SyncResult;
use App\Support\SafeOperationalData;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Queclink integration adapter.
 *
 * Scaffold stage: this adapter implements the IntegrationAdapterInterface and
 * provides a functional testConnection against the Queclink API, but the full
 * device sync / event stream is not yet wired. The Queclink telemetry adapter
 * in `App\Services\Fleet\Telemetry\QueclinkAdapter` continues to own webhook
 * payload normalisation; this class will own device-registry sync once PR C1
 * lands.
 */
class QueclinkAdapter implements IntegrationAdapterInterface
{
    public const PROVIDER_SLUG = 'queclink';

    /**
     * Default base URL for the Queclink IoT platform. Operators can override
     * via IntegrationProviderConnection.config['base_url'].
     */
    private const DEFAULT_BASE_URL = 'https://ims.queclink.com';

    public function provider(): string
    {
        return self::PROVIDER_SLUG;
    }

    public function capabilities(): array
    {
        return [
            'tracking',
            'telemetry',
            'device_health',
            'event_stream',
        ];
    }

    public function testConnection(IntegrationProviderConnection $connection): bool
    {
        $apiKey = $this->decryptSecret($connection);
        if ($apiKey === null) {
            return false;
        }

        try {
            $baseUrl = $this->resolveBaseUrl($connection);
            // Lightweight probe: HEAD against the account / status endpoint.
            // Queclink's exact identity endpoint differs between product
            // lines (IoT Platform vs GV-series controller). We use a generic
            // probe that returns 2xx when credentials are valid; any other
            // status is treated as failure.
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(10)
                ->get($baseUrl.'/api/v1/account');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::info('Queclink testConnection failed', SafeOperationalData::logContext([
                'provider_connection_id' => $connection->id,
                'error_category' => SafeOperationalData::failureCategory($e),
            ]));

            return false;
        }
    }

    public function discoverSites(IntegrationProviderConnection $connection): array
    {
        // Site discovery is a scaffold stage — returns empty until PR C1.
        // Queclink groups devices by fleet / account, not by "site" in the
        // same sense as UniFi. The eventual implementation will fetch the
        // tenant's fleets and expose them here as external_id/name pairs.
        return [];
    }

    public function syncDevices(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): SyncResult
    {
        // Device sync is deferred to PR C1. Returning a graceful
        // "not implemented" result keeps sync orchestration honest rather
        // than silently succeeding with zero devices.
        return new SyncResult(
            processed: 0,
            created: 0,
            updated: 0,
            errored: 0,
            error: 'Queclink device sync is not yet implemented. Credentials and connection testing are available; sync ships in a follow-up release.',
        );
    }

    public function pullHealth(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): array
    {
        return [];
    }

    public function pullEvents(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection, ?\DateTimeInterface $since = null): array
    {
        return [];
    }

    private function resolveBaseUrl(IntegrationProviderConnection $connection): string
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $candidate = $config['base_url'] ?? self::DEFAULT_BASE_URL;

        return rtrim((string) $candidate, '/');
    }

    private function decryptSecret(IntegrationProviderConnection $connection): ?string
    {
        if (! $connection->secret_encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($connection->secret_encrypted);
        } catch (\Throwable $e) {
            Log::warning('Queclink secret decryption failed', SafeOperationalData::logContext([
                'provider_connection_id' => $connection->id,
                'error_category' => SafeOperationalData::failureCategory($e),
            ]));

            return null;
        }
    }
}
