<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringRuntimeHeartbeat;
use App\Domain\SecurityDevices\Screens\Setup\ServerHealth;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * S&D rebuild C6 gap: the old Monitoring page's runtime health on Setup › Hubs › Server check, for
 * admins only (plan: "Hub/queue health → Setup › Hubs › Server check (admins)"). Real readings or
 * "Not available"; failed deliveries can be tried again or stopped with the old page's keys.
 */
class ServerCheckHealthTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        config()->set('monitoring.external_heartbeat.enabled', false);
        config()->set('monitoring.signing', ['active_key_id' => 'server-check', 'keys' => ['server-check' => base64_encode(str_repeat("\x2b", SODIUM_CRYPTO_AUTH_KEYBYTES))]]);
        $this->home = Site::factory()->create(['name' => 'Synthetic House']);
    }

    /** @param  list<string>  $keys */
    private function userWith(array $keys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->home->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        foreach (['securityDevices.viewAny', ...$keys] as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user;
    }

    private function deadLetter(?Site $site): MonitoringDeadLetter
    {
        $message = (string) Str::uuid();

        return MonitoringDeadLetter::query()->create([
            'message_id' => $message,
            'consumer' => 'event-projector',
            'source' => 'collector:server-check-fixture',
            'sequence' => 2,
            'idempotency_key' => 'fixture:'.$message,
            'reason_code' => 'invalid_signature',
            'reason_message' => 'Envelope authentication failed.',
            'envelope_bytes' => 'PRIVATE-INVALID-ENVELOPE',
            'site_id' => $site?->id,
        ]);
    }

    public function test_the_monitoring_health_loads_on_the_server_check_card_for_admins_only(): void
    {
        $admin = $this->userWith(['securityDevices.integrations.view', 'settings.access.manage']);
        $this->actingAs($admin)->get('/security-devices/setup/hubs?tab=networks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.server_check', true)
                ->missing('server_health')
                ->loadDeferredProps('server-health', fn (Assert $fill) => $fill
                    ->has('server_health.service.label')
                    ->has('server_health.waiting.label')
                    ->has('server_health.failed.label')
                    ->where('server_health.watchdog.label', 'Off')
                    ->where('server_health.actor_id', $admin->id)));

        $it = $this->userWith(['securityDevices.integrations.view', 'securityDevices.integrations.manage']);
        $this->actingAs($it)->get('/security-devices/setup/hubs?tab=networks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.server_check', false)->where('server_health', null));
    }

    public function test_no_reading_says_not_available_never_a_made_up_figure(): void
    {
        $admin = $this->userWith(['securityDevices.integrations.view', 'settings.access.manage']);
        $health = app(ServerHealth::class)->present($admin);

        // No runtime heartbeat has ever been recorded.
        $this->assertSame('Not available', $health['service']['label']);
        $this->assertSame('neutral', $health['service']['tone']);
        $this->assertSame('Off', $health['watchdog']['label']);
    }

    public function test_a_stale_runtime_part_is_named_in_plain_words(): void
    {
        $admin = $this->userWith(['securityDevices.integrations.view', 'settings.access.manage']);
        foreach (['events', 'checks', 'discovery', 'provider', 'topology', 'maintenance', 'orchestration', 'commands'] as $component) {
            $stale = $component === 'events';
            MonitoringRuntimeHeartbeat::query()->create([
                'component' => $component,
                'queue' => 'monitoring-'.$component,
                'last_dispatched_token' => (string) Str::uuid(),
                'last_dispatched_at' => $stale ? now()->subHour() : now()->subSeconds(30),
                'last_consumed_token' => (string) Str::uuid(),
                'last_consumed_dispatch_at' => $stale ? now()->subHour() : now()->subSeconds(30),
                'last_consumed_at' => $stale ? now()->subHour() : now()->subSeconds(20),
            ]);
        }

        $health = app(ServerHealth::class)->present($admin);

        $this->assertSame('Needs fixing', $health['service']['label']);
        $this->assertStringContainsString('Device events isn’t answering', $health['service']['summary']);
    }

    public function test_failed_deliveries_are_offered_to_people_who_manage_connected_services_at_their_sites(): void
    {
        $elsewhere = Site::factory()->create();
        $mine = $this->deadLetter($this->home);
        $hidden = $this->deadLetter($elsewhere);
        $wide = $this->deadLetter(null);

        $manager = $this->userWith(['securityDevices.integrations.view', 'settings.access.manage', 'securityDevices.integrations.manage']);
        $failed = app(ServerHealth::class)->present($manager)['failed'];
        $ids = array_column($failed['rows'], 'id');
        $this->assertTrue($failed['can_act']);
        $this->assertContains($mine->id, $ids);
        $this->assertContains($wide->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
        $row = collect($failed['rows'])->firstWhere('id', $mine->id);
        $this->assertSame('Device events', $row['what']);
        $this->assertSame('Synthetic House', $row['where']);
        $this->assertSame('Its signature didn’t check out', $row['why']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['review_token']);
        $this->assertStringNotContainsString('PRIVATE-INVALID-ENVELOPE', json_encode($failed, JSON_THROW_ON_ERROR));

        $admin = $this->userWith(['securityDevices.integrations.view', 'settings.access.manage']);
        $plain = app(ServerHealth::class)->present($admin)['failed'];
        $this->assertFalse($plain['can_act']);
        $this->assertSame([], $plain['rows']);
    }

    public function test_durations_are_written_the_app_way(): void
    {
        $this->assertSame('under a minute ago', ServerHealth::ago(20));
        $this->assertSame('4 min ago', ServerHealth::ago(240));
        $this->assertSame('2 h 5 min ago', ServerHealth::ago(7500));
        $this->assertSame('1 day ago', ServerHealth::ago(90000));
    }
}
