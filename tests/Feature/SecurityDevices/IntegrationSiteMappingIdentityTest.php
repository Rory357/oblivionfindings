<?php

namespace Tests\Feature\SecurityDevices;

use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationSiteMappingIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_provider_location_cannot_be_mapped_to_two_canonical_sites(): void
    {
        $first = Site::factory()->create();
        $second = Site::factory()->create();
        IntegrationSiteConfig::query()->create([
            'site_id' => $first->id,
            'provider' => 'unifi',
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'mapped_external_site_id' => 'provider-site-1',
            'is_active' => true,
        ]);

        try {
            IntegrationSiteConfig::query()->create([
                'site_id' => $second->id,
                'provider' => 'unifi',
                'status' => IntegrationSiteConfig::STATUS_HYBRID,
                'mapped_external_site_id' => ' provider-site-1 ',
                'is_active' => true,
            ]);
            $this->fail('An ambiguous provider Site identity was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame(1062, (int) ($exception->errorInfo[1] ?? 0));
            $this->assertStringContainsString('integration_provider_external_site_unique', $exception->getMessage());
        }

        IntegrationSiteConfig::query()->create([
            'site_id' => $second->id,
            'provider' => 'milesight',
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'mapped_external_site_id' => 'provider-site-1',
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('integration_site_configs', 2);
    }

    public function test_unmapped_site_configs_remain_valid_for_multiple_sites(): void
    {
        foreach (Site::factory()->count(3)->create()->values() as $index => $site) {
            IntegrationSiteConfig::query()->create([
                'site_id' => $site->id,
                'provider' => 'unifi',
                'status' => IntegrationSiteConfig::STATUS_LOCAL_ONLY,
                'mapped_external_site_id' => match ($index) {
                    0 => null,
                    1 => '',
                    default => '   ',
                },
                'is_active' => false,
            ]);
        }

        $this->assertDatabaseCount('integration_site_configs', 3);
        $this->assertArrayNotHasKey(
            'mapped_external_site_identity_guard',
            IntegrationSiteConfig::query()->firstOrFail()->toArray(),
        );
    }
}
