<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Cctv\Models\HikvisionDiscoveryRequest;
use App\Support\SafeOperationalData;
use PHPUnit\Framework\TestCase;

final class HikvisionDiscoveryPrivacyTest extends TestCase
{
    public function test_discovery_audit_boundary_discards_credentials_observations_free_text_and_request_context(): void
    {
        $record = new HikvisionDiscoveryRequest;
        self::assertTrue(SafeOperationalData::protectsRequestContext($record));
        $scope = ['device_id' => 23, 'site_id' => 9, 'site_ids' => [9]];
        $safe = SafeOperationalData::auditMeta([
            'actor_id' => 1, 'request_uuid' => '45a5011c-e8ad-4bba-a588-c10454dc08be',
            'source' => ['password' => 'private-credential'], 'inventory' => ['serial' => 'private-observation'],
            'purpose' => 'private-free-text', 'ip_address' => '10.44.0.10', 'user_agent' => 'private-agent',
            'before' => ['status' => 'dispatched', 'password' => 'private-credential'],
            'after' => ['status' => 'observed', 'serial_number' => 'private-observation', 'configuration' => ['tls_pin' => 'private-pin']],
        ], $record, $scope);
        self::assertSame(['before' => ['status' => 'dispatched'], 'after' => ['status' => 'observed'], 'scope' => $scope], $safe);
        self::assertStringNotContainsString('private-', json_encode($safe, JSON_THROW_ON_ERROR));
    }
}
