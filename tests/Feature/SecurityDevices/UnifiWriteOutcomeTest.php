<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerSecretStore;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Support\FakeIntegrationSecretBackend;
use Tests\TestCase;

/** UniFi writes report what really happened and refuse settings that would be ignored. */
class UnifiWriteOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/security-devices/integrations/unifi';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $backend = new FakeIntegrationSecretBackend;
        $this->app->instance(SecretManagerSecretStore::class, $backend);
        $this->app->instance(SecretManagerLeaseIssuer::class, $backend);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    private function connection(array $attributes = []): IntegrationProviderConnection
    {
        return IntegrationProviderConnection::create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('outcome-test-key'),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            ...$attributes,
        ]);
    }

    private function defaults(?string $start, ?string $end): array
    {
        return ['config' => [
            'refresh_interval_minutes' => 15,
            'alert_motion_events' => false,
            'alert_device_offline' => true,
            'quiet_hours_start' => $start,
            'quiet_hours_end' => $end,
        ]];
    }

    public function test_quiet_hours_need_both_a_start_and_an_end(): void
    {
        $connection = $this->connection();

        $this->actingAs($this->admin)
            ->put(self::PREFIX.'/defaults', $this->defaults('22:00', null))
            ->assertSessionHasErrors([
                'config.quiet_hours_end' => 'Set an end time as well as a start time, or clear both to turn quiet hours off.',
            ])
            ->assertSessionMissing('unifi_action_outcome');
        $this->put(self::PREFIX.'/defaults', $this->defaults(null, '06:00'))
            ->assertSessionHasErrors([
                'config.quiet_hours_start' => 'Set a start time as well as an end time, or clear both to turn quiet hours off.',
            ]);
        $this->put(self::PREFIX.'/defaults', $this->defaults('22:00', '22:00'))
            ->assertSessionHasErrors([
                'config.quiet_hours_start' => 'Quiet hours must start and end at different times.',
            ]);
        $this->assertArrayNotHasKey('quiet_hours_start', $connection->fresh()->config ?? []);

        $marker = str_repeat('e', 32);
        $this->withHeader('X-Obli-UniFi-Action', $marker)
            ->put(self::PREFIX.'/defaults', $this->defaults('22:00', '06:00'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('unifi_action_outcome', [
                'actor_id' => $this->admin->id,
                'request_id' => $marker,
                'method' => 'put',
                'path' => self::PREFIX.'/defaults',
                'outcome' => 'saved',
            ]);
        $this->assertSame('22:00', $connection->fresh()->config['quiet_hours_start']);
        $this->assertSame('06:00', $connection->fresh()->config['quiet_hours_end']);

        $this->put(self::PREFIX.'/defaults', $this->defaults(null, null))
            ->assertSessionHasNoErrors();
        $this->assertNull($connection->fresh()->config['quiet_hours_start']);
        $this->assertNull($connection->fresh()->config['quiet_hours_end']);
    }

    public function test_disabling_an_already_disabled_connection_is_acknowledged_as_unchanged(): void
    {
        $this->connection();
        $marker = str_repeat('a', 32);
        $this->actingAs($this->admin)->withHeader('X-Obli-UniFi-Action', $marker)
            ->post(self::PREFIX.'/disable', ['reason' => 'provider_outage'])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('unifi_action_outcome.outcome', 'saved');
        $disabledAt = IntegrationProviderConnection::query()->forProvider('unifi')->firstOrFail()->disabled_at;
        $this->assertNotNull($disabledAt);

        $marker = str_repeat('b', 32);
        $expected = [
            'actor_id' => $this->admin->id,
            'request_id' => $marker,
            'method' => 'post',
            'path' => self::PREFIX.'/disable',
            'outcome' => 'unchanged',
        ];
        $this->withHeader('X-Obli-UniFi-Action', $marker)
            ->post(self::PREFIX.'/disable', ['reason' => 'security_review'])
            ->assertRedirect()
            ->assertSessionHas('info')
            ->assertSessionHas('unifi_action_outcome', $expected);
        $fresh = IntegrationProviderConnection::query()->forProvider('unifi')->firstOrFail();
        $this->assertSame('provider_outage', $fresh->disabled_reason);
        $this->assertSame($disabledAt->toDateTimeString(), $fresh->disabled_at?->toDateTimeString());

        $this->get(self::PREFIX)->assertOk()->assertInertia(fn ($page) => $page
            ->where('actionOutcome', $expected));
    }

    public function test_a_stale_provider_location_is_a_validation_message_not_a_bare_error(): void
    {
        $site = Site::factory()->create();
        $this->connection(['config' => ['discovered_sites' => [
            ['external_id' => 'current-location', 'name' => 'Current location'],
        ]]]);
        $staleToken = hash_hmac('sha256', 'unifi|removed-location', (string) config('app.key'));

        $this->actingAs($this->admin)->withHeader('X-Obli-UniFi-Action', str_repeat('c', 32))
            ->post(self::PREFIX.'/map-site', ['site_id' => $site->id, 'mapping_token' => $staleToken])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'mapping_token' => 'This UniFi location is no longer available. Sync UniFi locations, then try again.',
            ])
            ->assertSessionMissing('unifi_action_outcome');
        $this->assertDatabaseCount('integration_site_configs', 0);
    }
}
