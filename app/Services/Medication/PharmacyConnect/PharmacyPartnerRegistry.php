<?php

namespace App\Services\Medication\PharmacyConnect;

use Illuminate\Contracts\Config\Repository;

final class PharmacyPartnerRegistry
{
    public function __construct(private readonly Repository $config) {}

    public function enabled(): bool
    {
        return $this->config->get('emar-pharmacy-connect.enabled', false) === true;
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new PharmacyConnectionException('connection_disabled', 'Connected ordering is not enabled for this installation.');
        }
    }

    /** Internal deployment-only configuration; never return this array to a reader. */
    public function partner(string $key): array
    {
        $partners = $this->config->get('emar-pharmacy-connect.partners', []);
        $partner = is_array($partners) ? ($partners[$key] ?? null) : null;
        if (! is_array($partner) || ($partner['protocol'] ?? null) !== 'oblivion-json-v1'
            || ! is_string($partner['endpoint'] ?? null) || ! is_array($partner['site_references'] ?? null)
            || ! is_string($partner['outbound_token'] ?? null) || strlen($partner['outbound_token']) < 32
            || ! is_string($partner['acknowledgment_secret'] ?? null) || strlen($partner['acknowledgment_secret']) < 32
            || strpbrk($partner['outbound_token'], "\r\n") !== false) {
            throw new PharmacyConnectionException('partner_not_ready', 'The approved pharmacy partner has not been configured.');
        }
        $endpoint = parse_url($partner['endpoint']);
        if (! is_array($endpoint) || ($endpoint['scheme'] ?? null) !== 'https' || empty($endpoint['host'])
            || isset($endpoint['user']) || isset($endpoint['pass']) || isset($endpoint['query']) || isset($endpoint['fragment'])) {
            throw new PharmacyConnectionException('destination_not_approved', 'The approved pharmacy destination is unavailable.');
        }

        return $partner;
    }

    public function forSite(string $key, int $siteId): array
    {
        $partner = $this->partner($key);
        $reference = $partner['site_references'][$siteId] ?? null;
        if (! is_string($reference) || ! preg_match('/^[a-zA-Z0-9._\/-]{1,100}$/D', $reference)) {
            throw new PharmacyConnectionException('site_not_approved', 'This house is not approved for that pharmacy connection.');
        }

        return $partner;
    }

    public function fingerprint(array $partner, int $siteId): string
    {
        return hash('sha256', json_encode([
            $partner['protocol'], $partner['endpoint'], $partner['site_references'][$siteId] ?? null,
            $partner['supports_idempotency'] ?? false, $partner['definitive_failure_statuses'] ?? [],
        ], JSON_THROW_ON_ERROR));
    }

    public function options(array $authorizedSites): array
    {
        $options = [];
        foreach (array_keys((array) $this->config->get('emar-pharmacy-connect.partners', [])) as $key) {
            try {
                $partner = $this->partner((string) $key);
                $sites = array_values(array_filter($authorizedSites, fn ($id) => isset($partner['site_references'][$id])));
                if ($sites !== []) {
                    $options[] = ['key' => (string) $key, 'label' => (string) ($partner['label'] ?? $key),
                        'protocol_label' => 'Generic pharmacy bridge (requires partner agreement)', 'site_ids' => $sites];
                }
            } catch (PharmacyConnectionException) {
                // An incomplete deployment entry is not an available recipient.
            }
        }

        return $options;
    }
}
