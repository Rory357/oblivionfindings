<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** S&D round 3 (pg-sdr-15p): Setup › Hubs & networks › Hubs, with a hub whose update failed and went back. */
class SetupHubsTabTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    private Site $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->home = Site::factory()->create(['name' => 'Kauri House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['name' => 'Kōwhai Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->home->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    }

    /** @param  array<string, mixed>  $attributes */
    private function hub(Site $site, array $attributes = []): MonitoringCollector
    {
        return MonitoringCollector::factory()->create([
            'site_id' => $site->id, 'name' => $site->name.' hub', 'collector_uuid' => (string) Str::uuid(),
            'status' => 'online', 'approved_at' => now()->subDays(30), 'revoked_at' => null,
            'last_seen_at' => now()->subMinute(), 'last_heartbeat_at' => now()->subMinute(), 'enrolled_at' => now()->subDays(31),
            'runtime_status' => ['platform' => 'linux', 'version' => '1.3.1'],
            ...$attributes,
        ]);
    }

    public function test_hubs_is_the_first_tab_and_lists_only_the_viewers_hubs(): void
    {
        $this->hub($this->home);
        $this->hub($this->other);
        $admin = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.integrations.view', 'securityDevices.hubs.approve']);

        $this->actingAs($admin)->get('/security-devices/setup/hubs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('section', 'hubs')
                ->where('tab', 'hubs')
                ->where('sections.1.tabs.0.label', 'Hubs')
                ->has('hubs.cards', 1)
                ->where('hubs.cards.0.name', 'Kauri House hub')
                ->where('hubs.cards.0.sub', 'Raspberry Pi or Linux · version 1.3.1')
                ->where('hubs.cards.0.flag.text', 'Online')
                ->where('hubs.banner', null)
                ->where('hubs.can.hold', true));
    }

    public function test_a_rolled_back_update_gets_a_banner_with_what_the_hub_reported(): void
    {
        $this->hub($this->home, [
            'update_ring' => 'early',
            'update_state' => [
                'outcome' => 'rolled_back', 'from_version' => '1.3.1', 'to_version' => '1.3.2',
                'detail' => 'Local store failed its check after the upgrade', 'reported_at' => now()->subHours(7)->toIso8601String(),
            ],
        ]);
        $admin = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.integrations.view', 'securityDevices.hubs.approve']);

        $response = $this->actingAs($admin)->get('/security-devices/setup/hubs?tab=hubs');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('hubs.cards.0.flag.text', 'Update failed')
            ->where('hubs.banner.title', 'Kauri House hub couldn’t update to 1.3.2, so it went back to 1.3.1')
            ->where('hubs.banner.label', 'Update failed · back on 1.3.1 by itself')
            ->where('hubs.for_it.0', ['What it reported', 'Local store failed its check after the upgrade'])
            ->where('hubs.for_it.2', ['Gone back by', 'The hub’s own updater, after 5 minutes with no check-in'])
            ->where('hubs.cards.0.lines.3.text', '**Test first**: gets updates before the other hubs'));
        // The test hub's failure holds the others: the banner says so.
        $this->assertStringContainsString('keep their version until a test-first hub has 1.3.2 working', (string) $response->getContent());
    }

    public function test_someone_who_may_open_setup_but_not_hub_panels_sees_a_quiet_line(): void
    {
        $this->hub($this->home);
        $reader = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.reports.view']);

        // reports.view alone doesn't open the Hubs & networks section at all.
        $this->actingAs($reader)->get('/security-devices/setup/hubs')->assertNotFound();

        $viewerOfServices = $this->person(['securityDevices.viewAny', 'securityDevices.integrations.view']);
        $this->actingAs($viewerOfServices)->get('/security-devices/setup/hubs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('hubs.locked', true)->where('hubs.cards', []));
    }
}
