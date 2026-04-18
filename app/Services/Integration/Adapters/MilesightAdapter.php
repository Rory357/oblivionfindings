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
 * Milesight integration adapter.
 *
 * Scaffold stage: testConnection against the Milesight Development Platform
 * is functional. Site mapping (applications / gateways), LoRaWAN device
 * import, payload decoding, and event ingestion ship in PR D1.
 *
 * Primary focus is the LoRaWAN Cloud + Development Platform. Milesight IP
 * cameras and on-prem gateway bridges are later phases.
 */
class MilesightAdapter implements IntegrationAdapterInterface
{
    public const PROVIDER_SLUG = 'milesight';

    /**
     * Default base URL for the Milesight Development Platform.
     * Operators can override via IntegrationTenantSecret.config['base_url']
     * (e.g. for a self-hosted gateway bridge).
     */
    private const DEFAULT_BASE_URL = 'https://mdp-api.milesight.com';

    public function provider(): string
    {
        return self::PROVIDER_SLUG;
    }

    public function capabilities(): array
    {
        return [
            'iot',
            'environmental',
            'healthcare_sensors',
            'gateway_management',
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
            // Generic identity probe. The Milesight Development Platform
            // exposes `/api/user-api/v1/account` for authenticated tenants.
            // Any 2xx response is treated as a successful test.
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(10)
                ->get($baseUrl.'/api/user-api/v1/account');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::info('Milesight testConnection failed', [
                'tenant_id' => $secret->tenant_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function discoverSites(IntegrationTenantSecret $secret): array
    {
        // Returns the tenant's applications / gateways once the sync phase
        // lands. Scaffold stage returns empty.
        return [];
    }

    public function syncDevices(IntegrationSiteConfig $siteConfig, IntegrationTenantSecret $tenantSecret): SyncResult
    {
        return new SyncResult(
            processed: 0,
            created: 0,
            updated: 0,
            errored: 0,
            error: 'Milesight LoRaWAN device sync and payload decoding ship in a follow-up release.',
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
            Log::warning('Milesight secret decryption failed', [
                'tenant_id' => $secret->tenant_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
