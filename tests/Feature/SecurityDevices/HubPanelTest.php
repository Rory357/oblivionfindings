<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S&D Phase B, the hub panel (sdr-13) and where it opens from: scoped to the hub's own Site, never
 * naming a covered Site the viewer can't see, with `can` flags by the hub routes' own rule.
 */
class HubPanelTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    private Site $other;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->home = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['name' => 'Synthetic Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->viewer = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->viewer->id,
            'primary_site_id' => $this->home->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $this->grant($this->viewer, ['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.integrations.view']);
    }

    /** @param list<string> $keys */
    private function grant(User $user, array $keys): void
    {
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }
    }

    /** @param list<int> $covered */
    private function hub(Site $site, array $covered = [], array $attributes = []): MonitoringCollector
    {
        return MonitoringCollector::factory()->create([
            'site_id' => $site->id,
            'name' => $site->name,
            'collector_uuid' => (string) Str::uuid(),
            'status' => 'online',
            'approved_at' => now()->subDay(),
            'revoked_at' => null,
            'last_seen_at' => now()->subMinute(),
            'config' => $covered === [] ? [] : ['covered_site_ids' => $covered],
            'runtime_status' => ['version' => '1.3.1', 'platform' => 'linux'],
            ...$attributes,
        ]);
    }

    public function test_another_sites_hub_never_shows(): void
    {
        $theirs = $this->hub($this->other);

        $this->actingAs($this->viewer)->getJson("/security-devices/hubs/{$theirs->id}/panel")->assertNotFound();
    }

    public function test_the_panel_needs_more_than_seeing_devices(): void
    {
        $ours = $this->hub($this->home);
        $plain = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $plain->id, 'primary_site_id' => $this->home->id, 'secondary_site_ids' => [], 'is_active' => true]);
        $this->grant($plain, ['securityDevices.viewAny', 'securityDevices.devices.view']);

        $this->actingAs($plain)->getJson("/security-devices/hubs/{$ours->id}/panel")->assertForbidden();
    }

    public function test_the_panel_says_what_the_server_knows_and_leaves_out_a_covered_site_the_viewer_cant_see(): void
    {
        $ours = $this->hub($this->home, [$this->other->id]);
        DiscoveryScope::factory()->create(['site_id' => $this->home->id, 'collector_id' => $ours->id, 'cidrs' => ['10.40.0.0/24'], 'daily_scan_at' => '06:00']);
        DiscoveryScope::factory()->create(['site_id' => $this->other->id, 'collector_id' => $ours->id, 'cidrs' => ['10.50.0.0/24']]);
        AuditLog::query()->create([
            'user_id' => $this->viewer->id,
            'action' => 'security_devices.hub.approved',
            'auditable_type' => $ours->getMorphClass(),
            'auditable_id' => $ours->id,
            'meta' => [],
        ]);

        $response = $this->actingAs($this->viewer)->getJson("/security-devices/hubs/{$ours->id}/panel")
            ->assertOk()
            ->assertJsonPath('hub.label', 'Synthetic House hub')
            ->assertJsonPath('hub.state', 'online')
            ->assertJsonPath('hub.platform', 'linux')
            ->assertJsonPath('hub.version', '1.3.1')
            ->assertJsonCount(1, 'sites')
            ->assertJsonPath('sites.0.name', 'Synthetic House')
            ->assertJsonCount(1, 'networks')
            ->assertJsonPath('networks.0.range', '10.40.0.0/24')
            ->assertJsonPath('networks.0.daily_at', '06:00')
            ->assertJsonPath('events.0.text', 'Approved by '.$this->viewer->name)
            // Only seeing devices and the hubs: no hub action at all.
            ->assertJsonPath('can.edit', false)
            ->assertJsonPath('can.remove', false)
            ->assertJsonPath('can.approve', false);

        $this->assertStringNotContainsString('Synthetic Lodge', $response->getContent());
        $this->assertStringNotContainsString('10.50.0.0', $response->getContent());
    }

    public function test_actions_show_only_when_the_viewer_may_manage_every_site_the_hub_covers(): void
    {
        $this->grant($this->viewer, ['securityDevices.integrations.manage', 'securityDevices.hubs.approve']);
        $ours = $this->hub($this->home);
        $wide = $this->hub($this->home, [$this->other->id], ['name' => 'Synthetic wide']);

        $this->actingAs($this->viewer)->getJson("/security-devices/hubs/{$ours->id}/panel")
            ->assertOk()
            ->assertJsonPath('can.edit', true)
            ->assertJsonPath('can.remove', true)
            ->assertJsonPath('can.replace', true)
            ->assertJsonPath('can.check_update', true)
            ->assertJsonPath('can.approve', false);

        // It also covers a Site this person can't see: the server would refuse, so nothing shows.
        $this->actingAs($this->viewer)->getJson("/security-devices/hubs/{$wide->id}/panel")
            ->assertOk()
            ->assertJsonPath('can.edit', false)
            ->assertJsonPath('can.remove', false);
    }

    public function test_sites_and_the_site_page_open_the_panel_only_for_a_hub_at_a_site_the_viewer_sees(): void
    {
        $this->grant($this->viewer, ['securityDevices.integrations.manage']);
        $theirs = $this->hub($this->other, [$this->home->id]);

        // Watched from a Site the viewer can't see: the line stays, the panel doesn't open.
        $this->actingAs($this->viewer)->get('/security-devices/sites')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cards.0.hub', null)
                ->where('can.add_hub', true)
                ->where('can.approve_hub', false));

        $ours = $this->hub($this->home);
        $this->actingAs($this->viewer)->get('/security-devices/sites')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cards.0.hub.id', $ours->id)
                ->where('cards.0.hub.label', 'Synthetic House hub'));
        $this->actingAs($this->viewer)->get('/security-devices/sites/'.$this->home->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('hub.id', $ours->id)->where('can.add_hub', true));

        $this->assertNotSame($theirs->id, $ours->id);
    }
}
