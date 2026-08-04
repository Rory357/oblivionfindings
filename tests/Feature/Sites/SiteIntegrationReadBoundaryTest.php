<?php

namespace Tests\Feature\Sites;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteIntegrationReadBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_hardware_view_receives_only_bounded_integration_state(): void
    {
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create();
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'team_lead')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $this->assertTrue($viewer->canDo('siteHardware.view'));
        $this->assertFalse($viewer->canDo('integrations.manage_site_secrets'));

        IntegrationSiteConfig::create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'status' => 'RAW-LEGACY-CONFIG-STATUS',
            'mapped_external_site_id' => 'RAW-LEGACY-EXTERNAL-ID',
            'mapped_external_site_name' => 'RAW-LEGACY-EXTERNAL-NAME',
            'overrides' => ['protect_host_id' => 'RAW-LEGACY-OVERRIDE-HOST'],
            'is_active' => true,
        ]);
        IntegrationSiteSecret::create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'capability' => 'protect',
            'base_url' => 'https://RAW-LEGACY-BASE-URL.test',
            'secret_encrypted' => 'RAW-LEGACY-SITE-SECRET',
            'is_enabled' => true,
            'last_tested_at' => now(),
            'last_error' => 'RAW-LEGACY-SITE-ERROR',
        ]);
        IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => 'RAW-LEGACY-APPLICATION-SECRET',
            'secret_last4' => '9876',
            'status' => 'RAW-LEGACY-APPLICATION-STATUS',
            'last_tested_at' => now(),
            'last_synced_at' => now(),
            'last_error' => 'RAW-LEGACY-APPLICATION-ERROR',
            'config' => [
                'token' => 'RAW-LEGACY-TOKEN',
                'discovered_sites' => [['id' => 'RAW-LEGACY-DISCOVERED-SITE', 'name' => 'RAW-LEGACY-SITE-NAME']],
                'discovered_hosts' => [['id' => 'RAW-LEGACY-DISCOVERED-HOST', 'address' => '10.77.0.42']],
                'sites_synced_at' => 'RAW-LEGACY-DISCOVERY-TIME',
            ],
        ]);

        $response = $this->actingAs($viewer)->getJson("/sites/{$site->id}/integrations")->assertOk();
        $payload = $response->json();

        $this->assertSame('unifi', $payload['configs'][0]['provider']);
        $this->assertSame('unknown', $payload['configs'][0]['status']);
        $this->assertSame('mapped', $payload['configs'][0]['mapping_state']);
        $this->assertTrue($payload['configs'][0]['overrides_configured']);
        $this->assertSame('error', $payload['siteSecrets'][0]['state']);
        $this->assertSame('provider_failure', $payload['siteSecrets'][0]['failure_category']);
        $this->assertSame(1, $payload['providerConnections'][0]['discovery']['site_count']);
        $this->assertSame(1, $payload['providerConnections'][0]['discovery']['host_count']);
        $this->assertSame('unknown', $payload['providerConnections'][0]['status']);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([
            'RAW-LEGACY', '10.77.0.42', '9876', '"base_url":', '"last_error":', '"error_message":',
            '"mapped_external_site_id":', '"mapped_external_site_name":', '"overrides":', '"config":',
            '"discovered_sites":', '"discovered_hosts":', '"secret_encrypted":', '"secret_last4":',
        ] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $encoded);
        }
    }
}
