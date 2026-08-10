<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use RuntimeException;
use Throwable;

final class EloquentSnmpCompatibilityAuthorizer implements SnmpCompatibilityAuthorizer
{
    private const array ACTIVE_MIGRATION_STATUSES = [
        'approved',
        'replacement_scheduled',
        'migration_in_progress',
    ];

    public function __construct(private readonly CanonicalDeviceSiteResolver $siteResolver) {}

    public function authorize(int $siteId, int $deviceId, string $version, string $credentialReference): void
    {
        try {
            $canonicalSiteId = $this->siteResolver->resolve($deviceId);
        } catch (Throwable) {
            throw new RuntimeException('SNMP compatibility exception is not active.');
        }
        if ($canonicalSiteId !== $siteId || ! in_array($version, ['v1', 'v2c'], true)) {
            throw new RuntimeException('SNMP compatibility exception is not active.');
        }

        $exists = SnmpCompatibilityException::query()
            ->where('site_id', $siteId)
            ->where('device_id', $deviceId)
            ->where('version', $version)
            ->where('credential_reference', $credentialReference)
            ->whereIn('migration_status', self::ACTIVE_MIGRATION_STATUSES)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();

        if (! $exists) {
            throw new RuntimeException('SNMP compatibility exception is not active.');
        }
    }
}
