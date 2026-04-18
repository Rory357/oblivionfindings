<?php

namespace App\Services\Integration\Adapters;

use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationTenantSecret;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\SyncResult;
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
     * via IntegrationTenantSecret.config['base_url'].
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

    public function testConnection(IntegrationTenantSecret $secret): bool
    {
        $apiKey = $this->decryptSecret($secret);
        if ($apiKey === null) {
            return false;
        }

        try {
            $baseUrl = $this->resolveBaseUrl($secret);
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
            Log::info('Queclink testConnection failed', [
                'tenant_id' => $secret->tenant_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function discoverSites(IntegrationTenantSecret $secret): array
    {
        // Site discovery is a scaffold stage — returns empty until PR C1.
        // Queclink groups devices by fleet / account, not by "site" in the
        // same sense as UniFi. The eventual implementation will fetch the
        // tenant's fleets and expose them here as external_id/name pairs.
        return [];
    }

    public function syncDevices(IntegrationSiteConfig $siteConfig, IntegrationTenantSecret $tenantSecret): SyncResult
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

    public function pullHealth(IntegrationSiteConfig $siteConfig, IntegrationTenantSecret $tenantSecret): array
    {
        return [];
    }

    public function pullEvents(IntegrationSiteConfig $siteConfig, IntegrationTenantSecret $tenantSecret, ?\DateTimeInterface $since = null): array
    {
        return [];
    }

    private function resolveBaseUrl(IntegrationTenantSecret $secret): string
    {
        $config = is_array($secret->config) ? $secret->config : [];
        $candidate = $config['base_url'] ?? self::DEFAULT_BASE_URL;

        return rtrim((string) $candidate, '/');
    }

    private function decryptSecret(IntegrationTenantSecret $secret): ?string
    {
        if (! $secret->secret_encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($secret->secret_encrypted);
        } catch (\Throwable $e) {
            Log::warning('Queclink secret decryption failed', [
                'tenant_id' => $secret->tenant_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
