<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DailyDiscoveryScheduler;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Screens\Devices\NetworkScans;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** S&D rebuild C5: Setup (scope, honest sections, the scan switches and the change history). */
class SetupScreensTest extends TestCase
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

        $this->home = Site::factory()->create(['name' => 'Aurora House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['name' => 'Kōwhai Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->viewer = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.integrations.view', 'securityDevices.reports.view']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->home->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    }

    private function hub(Site $site, string $status = 'online'): MonitoringCollector
    {
        return MonitoringCollector::factory()->create([
            'site_id' => $site->id, 'name' => $site->name, 'collector_uuid' => (string) Str::uuid(),
            'status' => $status, 'approved_at' => now()->subDay(), 'revoked_at' => null, 'last_seen_at' => now()->subHour(),
        ]);
    }

    private function network(Site $site, ?MonitoringCollector $hub, string $range, ?string $daily = '06:00'): DiscoveryScope
    {
        return DiscoveryScope::factory()->create([
            'site_id' => $site->id, 'collector_id' => $hub?->id, 'cidrs' => [$range], 'daily_scan_at' => $hub ? $daily : null,
        ]);
    }

    public function test_setup_needs_the_integrations_view_permission(): void
    {
        $outsider = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view']);

        $this->actingAs($outsider)->get('/security-devices/setup')->assertForbidden();
        $this->actingAs($this->viewer)->get('/security-devices/setup')->assertOk();
    }

    public function test_the_overview_shows_only_built_sections_and_the_viewers_own_sites(): void
    {
        $hub = $this->hub($this->home);
        MonitoringCollector::factory()->create([
            'site_id' => $this->home->id, 'name' => 'Spare PC', 'collector_uuid' => (string) Str::uuid(),
            'status' => 'online', 'approved_at' => null, 'revoked_at' => null, 'enrolled_at' => now()->subHour(),
        ]);
        $this->hub($this->other, 'offline');

        $response = $this->actingAs($this->viewer)->get('/security-devices/setup');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('security-devices/setup/index')
            ->where('section', 'overview')
            ->where('sections.0.key', 'overview')
            ->where('sections.1.key', 'hubs')
            ->where('sections.1.tabs.0.key', 'networks')
            ->where('sections.2.key', 'services')
            ->where('sections.3.key', 'history')
            ->has('sections', 4)
            ->has('meters', 6)
            ->where('meters.1.key', 'hubs')
            ->where('meters.1.value', '1 of 1')
            ->where('overview.todo.0.text', '**Spare PC is waiting for approval.** It gets no work and no saved logins until an admin approves it.')
            // Approving needs securityDevices.hubs.approve, which this viewer hasn't got.
            ->where('overview.todo.0.action', null)
            ->where('can.manage', false));

        // Kōwhai Lodge's hub (offline) isn't the viewer's: it never shows or counts.
        $this->assertStringNotContainsString('Kōwhai Lodge', $response->getContent());
        expect($hub->fresh()->approved_at)->not->toBeNull();
    }

    public function test_networks_and_scans_lists_each_network_with_its_hub_last_and_next_scan(): void
    {
        CarbonImmutable::setTestNow('2026-11-02T01:00:00Z'); // 2:00 pm in Auckland
        $hub = $this->hub($this->home);
        $scope = $this->network($this->home, $hub, '10.20.0.0/24');
        DiscoveryRun::factory()->create([
            'discovery_scope_id' => $scope->id, 'status' => 'completed',
            'started_at' => now()->subHours(7), 'completed_at' => now()->subHours(7)->addMinutes(3),
            'found_count' => 37, 'proposed_count' => 2,
        ]);
        $this->network($this->other, $this->hub($this->other), '10.30.0.0/24');

        $response = $this->actingAs($this->viewer)->get('/security-devices/setup/hubs?tab=networks');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('section', 'hubs')
            ->where('tab', 'networks')
            ->has('networks.rows', 1)
            ->where('networks.rows.0.range', '10.20.0.0/24')
            ->where('networks.rows.0.checked_by', 'Aurora House hub')
            ->where('networks.rows.0.last.text', '{time} · 3 min')
            ->where('networks.rows.0.found', '37 · 2 new')
            ->where('networks.rows.0.next.text', '{time}')
            // Next 6:00 am in Auckland is tomorrow, 17:00 UTC today.
            ->where('networks.rows.0.next.at', '2026-11-02T17:00:00+00:00')
            ->where('settings.scans\.daily.on', true)
            ->where('settings.scans\.server_direct.on', false)
            ->where('settings.scans\.server_direct.can_change', false));
        $this->assertStringNotContainsString('10.30.0.0/24', $response->getContent());
    }

    public function test_only_a_manager_changes_the_switches_and_each_change_is_in_the_history(): void
    {
        $this->actingAs($this->viewer)
            ->patchJson('/security-devices/setup/settings', ['changes' => ['scans.server_direct' => true]])
            ->assertForbidden();

        $manager = $this->person(['securityDevices.viewAny', 'securityDevices.integrations.view', 'securityDevices.integrations.manage']);
        $this->actingAs($manager)
            ->patchJson('/security-devices/setup/settings', ['changes' => ['scans.server_direct' => true, 'scans.daily' => true]])
            ->assertOk()
            ->assertJsonPath('changed', ['scans.server_direct'])
            ->assertJsonPath('settings.scans\.server_direct.on', true)
            ->assertJsonPath('settings.scans\.server_direct.loosens_when_on', true);

        $this->actingAs($manager)
            ->patchJson('/security-devices/setup/settings', ['changes' => ['scans.nonsense' => true]])
            ->assertUnprocessable();

        $this->actingAs($this->viewer)->get('/security-devices/setup/history')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('history.total', 1)
                ->where('history.rows.0.what', 'Turned on scans by the Oblivion server itself')
                ->where('history.rows.0.where', 'Every Site'));

        $csv = $this->actingAs($this->viewer)->get('/security-devices/setup/history/export');
        $csv->assertOk();
        expect($csv->streamedContent())->toContain('Turned on scans by the Oblivion server itself');
    }

    public function test_the_server_scans_a_network_itself_only_when_setup_allows_it(): void
    {
        $manager = $this->person(['securityDevices.viewAny', 'securityDevices.integrations.view', 'securityDevices.integrations.manage']);
        $scans = app(NetworkScans::class);

        try {
            $scans->add($manager, $this->home->id, '10.20.0.0/24', null, ['everything'], null);
            $this->fail('A network without a hub was added while server scans were off.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['hub_id'][0])->toContain('Setup › Networks & scans');
        }

        app(SecurityDevicesSettings::class)->update($manager, ['scans.server_direct' => true]);
        $scope = app(NetworkScans::class)->add($manager, $this->home->id, '10.20.0.0/24', null, ['everything'], null);
        expect($scope->collector_id)->toBeNull();
    }

    public function test_switching_daily_scans_off_pauses_every_daily_scan(): void
    {
        CarbonImmutable::setTestNow('2026-11-02T18:00:00Z'); // 7:00 am in Auckland, after 6:00 am
        $hub = $this->hub($this->home);
        $scope = $this->network($this->home, $hub, '10.20.0.0/24');
        $scope->forceFill(['updated_at' => now()->subDays(2)])->saveQuietly();
        $manager = $this->person(['securityDevices.viewAny', 'securityDevices.integrations.view', 'securityDevices.integrations.manage']);

        app(SecurityDevicesSettings::class)->update($manager, ['scans.daily' => false]);
        expect(app(DailyDiscoveryScheduler::class)->startDue())->toBe(0)
            ->and(DiscoveryRun::query()->count())->toBe(0);
    }

    public function test_saved_logins_show_their_state_and_never_anything_secret(): void
    {
        $this->hub($this->home);
        CredentialReference::query()->create([
            'reference_key' => 'router-backup', 'site_id' => $this->home->id, 'provider' => 'mikrotik', 'purpose' => 'device_management',
            'capabilities' => ['network.config.backup'], 'secret_manager_reference' => 'oblivion/sites/1/mikrotik/very-secret-path',
            'secret_manager_reference_hash' => hash('sha256', 'x'), 'status' => 'active', 'rotation_status' => 'current',
            'test_status' => 'failed', 'version' => 1, 'created_by_user_id' => $this->viewer->id,
        ]);
        CredentialReference::query()->create([
            'reference_key' => 'other-router', 'site_id' => $this->other->id, 'provider' => 'mikrotik', 'purpose' => 'device_management',
            'capabilities' => ['network.config.backup'], 'secret_manager_reference' => 'oblivion/sites/2/mikrotik/other',
            'secret_manager_reference_hash' => hash('sha256', 'y'), 'status' => 'active', 'rotation_status' => 'current',
            'test_status' => 'passed', 'version' => 1, 'created_by_user_id' => $this->viewer->id,
        ]);

        $response = $this->actingAs($this->viewer)->get('/security-devices/setup/services?tab=logins');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('tab', 'logins')
            ->has('logins.rows', 1)
            ->where('logins.rows.0.used_for', 'MikroTik login')
            ->where('logins.rows.0.used_by', 'Aurora House hub')
            ->where('logins.rows.0.state.text', 'Login failed')
            ->where('logins.kept.0.1', 'Encrypted in the Oblivion database'));
        $this->assertStringNotContainsString('very-secret-path', $response->getContent());
        $this->assertStringNotContainsString('other-router', $response->getContent());
    }

    public function test_supported_devices_splits_the_drivers_into_two_honest_tables(): void
    {
        $this->actingAs($this->viewer)->get('/security-devices/setup/services?tab=supported')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('supported.tables.0.title', 'Alarms and doors')
                ->where('supported.tables.1.title', 'Network, cameras, sensors and computers')
                ->has('supported.tables.1.rows.0.level.text'));
    }
}
