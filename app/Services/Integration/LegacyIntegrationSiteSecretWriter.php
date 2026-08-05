<?php

namespace App\Services\Integration;

use App\Models\Integration\IntegrationSiteSecret;
use Illuminate\Support\Facades\Crypt;

/**
 * Compatibility boundary for providers that have not entered the governed
 * secret-manager cutover. UniFi and Milesight must never use this writer.
 */
final class LegacyIntegrationSiteSecretWriter
{
    public function store(
        int $siteId,
        string $provider,
        string $capability,
        ?string $baseUrl,
        #[\SensitiveParameter] string $secret,
        bool $enabled,
    ): IntegrationSiteSecret {
        if (in_array(strtolower(trim($provider)), ['unifi', 'milesight'], true)) {
            throw new \RuntimeException('This provider requires the governed secret manager.');
        }

        return IntegrationSiteSecret::updateOrCreate(
            [
                'site_id' => $siteId,
                'provider' => $provider,
                'capability' => $capability,
            ],
            [
                'base_url' => $baseUrl,
                'secret_encrypted' => Crypt::encryptString($secret),
                'is_enabled' => $enabled,
            ],
        );
    }
}
