<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Reports\MonthlyReportEmail;
use App\Domain\SecurityDevices\Setup\Models\SecurityDeviceSetting;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Notifications\SecurityDevicesMonthlyReportNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S&D rebuild C4b: Overview › Reports (pg-sdr-18b), its PDF and CSV, the camera viewing log, the
 * monthly email, and "Export" on the Devices and Alerts lists. Real figures only, for the viewer's
 * Sites; privacy logs only for the keys that see camera pictures.
 */
class MonthlyReportTest extends TestCase
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
        // Thursday 29 October 2026, 1:00 pm NZ time.
        $this->travelTo(CarbonImmutable::parse('2026-10-29 00:00:00', 'UTC'));

        $this->home = Site::factory()->create(['name' => 'Aurora House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->other = Site::factory()->create(['name' => 'Kōwhai Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->viewer = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.reports.view'], $this->home);
    }

    /** @param  list<string>  $keys */
    private function person(array $keys, Site $site, string $name = 'Synthetic Manager'): User
    {
        $user = User::factory()->create(['name' => $name, 'approved_at' => now()->subYear(), 'email' => Str::slug($name).'-'.Str::random(6).'@example.test']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function device(Site $site, string $name, array $attributes, bool $watched = true): Device
    {
        $device = Device::factory()->create(['name' => $name, 'created_at' => now()->subMonths(3), ...$attributes]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now()->subMonths(3),
        ]);
        if ($watched) {
            Monitor::factory()->create([
                'device_id' => $device->id,
                'profile_id' => MonitoringProfile::factory()->create()->id,
                'current_state' => MonitorState::Healthy,
                'last_observation_at' => now()->subMinute(),
                'last_state_changed_at' => now()->subDays(2),
            ]);
        }

        return $device;
    }

    private function router(Site $site, string $name): Device
    {
        return $this->device($site, $name, ['domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'router']);
    }

    private function camera(Site $site, string $name): Device
    {
        return $this->device($site, $name, ['domain' => 'security', 'category' => 'cctv', 'subcategory' => 'dome_camera']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function alert(Device $device, Site $site, string $kind, string $opened, ?string $closed = null, array $attributes = []): DeviceAlert
    {
        return DeviceAlert::query()->create([
            'device_id' => $device->id,
            'site_id' => $site->id,
            'kind' => $kind,
            'severity' => in_array($kind, ['panic', 'alarm', 'offline'], true) ? 'critical' : 'warning',
            'title' => $device->name.': '.$kind,
            'state' => $closed === null ? DeviceAlert::STATE_OPEN : DeviceAlert::STATE_DONE,
            'opened_at' => CarbonImmutable::parse($opened, 'UTC'),
            'closed_at' => $closed === null ? null : CarbonImmutable::parse($closed, 'UTC'),
            'close_reason' => $closed === null ? null : 'recovered',
            ...$attributes,
        ]);
    }

    private function liveView(Device $camera, Site $site, User $actor, string $purpose, string $at): void
    {
        $collector = MonitoringCollector::factory()->create([
            'site_id' => $site->id, 'name' => $site->name.' hub', 'collector_uuid' => (string) Str::uuid(),
            'status' => 'online', 'approved_at' => now()->subMonth(), 'revoked_at' => null,
        ]);
        $scope = DiscoveryScope::factory()->create(['site_id' => $site->id, 'collector_id' => $collector->id, 'cidrs' => ['10.44.0.0/24'], 'port_bounds' => ['tcp' => [7441]]]);
        $recorder = $this->device($site, $site->name.' recorder', ['domain' => 'security', 'category' => 'cctv', 'subcategory' => 'nvr'], false);
        $sourceId = DB::table('cctv_sources')->insertGetId([
            'camera_id' => $camera->id, 'recorder_device_id' => $recorder->id, 'site_id' => $site->id, 'collector_id' => $collector->id,
            'discovery_scope_id' => $scope->id, 'actor_id' => $actor->id, 'revision' => 1, 'enabled' => true,
            'fingerprint' => str_repeat('a', 64), 'provider_fingerprint' => str_repeat('b', 64), 'configuration' => '{}',
            'verified_at' => now()->subMonth(), 'created_at' => now()->subMonth(), 'updated_at' => now()->subMonth(),
        ]);
        DB::table('cctv_live_sessions')->insert([
            'uuid' => (string) Str::uuid(), 'source_id' => $sourceId, 'camera_id' => $camera->id, 'actor_id' => $actor->id,
            'collector_id' => $collector->id, 'actor_fingerprint' => str_repeat('c', 64), 'browser_fingerprint' => str_repeat('d', 64),
            'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('e', 64), 'purpose' => $purpose, 'generation' => 1,
            'status' => 'stopped', 'authority_until' => $at, 'session_expires_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    public function test_the_reports_tab_counts_only_the_viewers_sites_from_what_was_recorded(): void
    {
        $router = $this->router($this->home, 'Aurora House router');
        $theirs = $this->router($this->other, 'Kōwhai Lodge router');
        // Two overlapping outages at Aurora House count once: 9:00–11:00 pm UTC on 7 Oct = 120 minutes.
        $this->alert($router, $this->home, 'offline', '2026-10-07 21:00:00', '2026-10-07 23:00:00');
        $this->alert($router, $this->home, 'offline', '2026-10-07 22:00:00', '2026-10-07 22:30:00');
        // Before October (NZ): 30 Sep 9:00 am UTC is 30 Sep 10:00 pm NZ, so it doesn't count.
        $this->alert($router, $this->home, 'offline', '2026-09-30 09:00:00', '2026-09-30 10:00:00');
        $this->alert($theirs, $this->other, 'offline', '2026-10-10 00:00:00', '2026-10-10 05:00:00');
        $this->alert($router, $this->home, 'battery', '2026-10-12 00:00:00', '2026-10-14 00:00:00');
        $this->alert($router, $this->home, 'panic', '2026-10-20 00:00:00', null, ['acknowledged_at' => CarbonImmutable::parse('2026-10-20 00:03:00', 'UTC')]);

        $this->actingAs($this->viewer)
            ->get('/security-devices?tab=reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'reports')
                ->where('can.reports', true)
                ->where('reports.month.key', '2026-10')
                ->where('reports.month.current', true)
                ->where('reports.offline.sites.0.name', 'Aurora House')
                ->where('reports.offline.sites.0.minutes', 120)
                ->has('reports.offline.sites', 1)
                ->where('reports.alerts.total', 4)
                ->where('reports.alerts.kinds.0.label', 'Device offline')
                ->where('reports.safety.month.count', 1)
                ->where('reports.safety.month.average_minutes', 3)
                ->where('reports.upkeep.batteries', 1)
                // Neither camera key: no camera viewing log at all.
                ->where('reports.camera_views', null)
                ->where('reports.email.on', false)
                ->where('reports.email.can_change', false));
    }

    public function test_a_past_month_is_the_full_nz_month_and_older_than_13_months_falls_back(): void
    {
        $router = $this->router($this->home, 'Aurora House router');
        // 30 Sep 9:00 am UTC is 30 Sep 10:00 pm NZ: September.
        $this->alert($router, $this->home, 'offline', '2026-09-30 09:00:00', '2026-09-30 10:00:00');

        $this->actingAs($this->viewer)->get('/security-devices?tab=reports&month=2026-09')
            ->assertInertia(fn ($page) => $page
                ->where('reports.month.key', '2026-09')
                ->where('reports.month.current', false)
                ->where('reports.offline.sites.0.minutes', 60));
        $this->actingAs($this->viewer)->get('/security-devices?tab=reports&month=2024-01')
            ->assertInertia(fn ($page) => $page->where('reports.month.key', '2026-10'));
    }

    public function test_without_the_reports_key_there_is_no_reports_tab_download_or_export(): void
    {
        $it = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.devices.update'], $this->home, 'Synthetic Tech');

        $this->actingAs($it)->get('/security-devices?tab=reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tab', 'overview')->where('reports', null)->where('can.reports', false));
        foreach (['/security-devices/monthly-report.pdf', '/security-devices/monthly-report.csv', '/security-devices/monthly-report/camera-views.csv', '/security-devices/exports/devices.csv', '/security-devices/exports/events.csv', '/security-devices/exports/upkeep.csv'] as $url) {
            $this->actingAs($it)->get($url)->assertForbidden();
        }
        $this->actingAs($this->viewer)->get('/security-devices/monthly-report.csv?site='.$this->other->id)->assertNotFound();
    }

    public function test_the_camera_viewing_log_shows_who_watched_and_why_only_to_people_who_may_see_pictures(): void
    {
        $watcher = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.reports.view', 'securityDevices.cctv.media.view'], $this->home, 'Synthetic Watcher');
        $door = $this->camera($this->home, 'Front door camera');
        $theirs = $this->camera($this->other, 'Back yard camera');
        $this->liveView($door, $this->home, $watcher, 'Checking a delivery at the front door', '2026-10-27 21:15:00');
        $this->liveView($theirs, $this->other, $watcher, 'Someone else’s house', '2026-10-27 22:15:00');

        $this->actingAs($watcher)->get('/security-devices?tab=reports')
            ->assertInertia(fn ($page) => $page
                ->where('reports.camera_views.live', true)
                ->where('reports.camera_views.total', 1)
                ->where('reports.camera_views.rows.0.who', 'Synthetic Watcher')
                ->where('reports.camera_views.rows.0.kind', 'live')
                ->where('reports.camera_views.rows.0.reason', 'Checking a delivery at the front door')
                ->where('reports.camera_views.rows.0.camera', 'Front door camera · Aurora House'));

        $csv = $this->actingAs($watcher)->get('/security-devices/monthly-report/camera-views.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Checking a delivery at the front door', $csv);
        $this->assertStringNotContainsString('Someone else', $csv);
        $this->assertTrue(AuditLog::query()->where('action', 'security_devices.report.downloaded')->where('meta->format', 'camera_views_csv')->exists());

        // The report key alone doesn't open the log.
        $this->actingAs($this->viewer)->get('/security-devices/monthly-report/camera-views.csv')->assertForbidden();
    }

    public function test_the_pdf_downloads_for_the_viewers_sites_and_is_audited(): void
    {
        $this->router($this->home, 'Aurora House router');

        $response = $this->actingAs($this->viewer)->get('/security-devices/monthly-report.pdf?month=2026-10');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertTrue(AuditLog::query()->where('action', 'security_devices.report.downloaded')->where('meta->format', 'pdf')->where('meta->camera_viewing_log', false)->exists());
    }

    public function test_the_monthly_email_is_off_by_default_changed_with_the_right_keys_and_audited(): void
    {
        $manager = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.reports.view', 'securityDevices.automations.manage'], $this->home, 'Synthetic Lead');
        $outsider = $this->person(['securityDevices.viewAny', 'securityDevices.devices.view'], $this->home, 'Synthetic Worker');

        $this->assertFalse(app(MonthlyReportEmail::class)->on());
        $this->actingAs($this->viewer)->patchJson('/security-devices/monthly-report/email', ['on' => true, 'recipients' => [$this->viewer->id]])->assertForbidden();
        $this->actingAs($manager)->patchJson('/security-devices/monthly-report/email', ['on' => true, 'recipients' => [$outsider->id]])->assertUnprocessable();
        $this->actingAs($manager)->patchJson('/security-devices/monthly-report/email', ['on' => true, 'recipients' => []])->assertUnprocessable();

        $this->actingAs($manager)->patchJson('/security-devices/monthly-report/email', ['on' => true, 'recipients' => [$this->viewer->id]])
            ->assertOk()
            ->assertJsonPath('email.on', true)
            ->assertJsonPath('email.recipients.0.name', 'Synthetic Manager');

        $this->assertSame(2, AuditLog::query()->where('action', 'security_devices.settings.changed')->count());
        $this->assertSame([$this->viewer->id], SecurityDeviceSetting::query()->where('key', MonthlyReportEmail::RECIPIENTS)->value('value'));
    }

    public function test_the_email_goes_once_on_the_1st_at_7_am_to_people_who_still_see_reports(): void
    {
        Notification::fake();
        $leaver = $this->person(['securityDevices.viewAny', 'securityDevices.reports.view'], $this->home, 'Synthetic Leaver');
        SecurityDeviceSetting::query()->create(['key' => MonthlyReportEmail::ON, 'value' => true]);
        SecurityDeviceSetting::query()->create(['key' => MonthlyReportEmail::RECIPIENTS, 'value' => [$this->viewer->id, $leaver->id]]);
        $leaver->permissionOverrides()->detach();

        // Sunday 1 November 2026, 6:30 am NZ time: not yet.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 17:30:00', 'UTC'));
        $this->artisan('security-devices:monthly-report-email')->assertSuccessful();
        Notification::assertNothingSent();

        // 7:05 am NZ time: October's report goes to the viewer only, once.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 18:05:00', 'UTC'));
        $this->artisan('security-devices:monthly-report-email')->assertSuccessful();
        $this->artisan('security-devices:monthly-report-email')->assertSuccessful();

        Notification::assertSentToTimes($this->viewer, SecurityDevicesMonthlyReportNotification::class, 1);
        Notification::assertNotSentTo($leaver, SecurityDevicesMonthlyReportNotification::class);
        Notification::assertSentTo($this->viewer, SecurityDevicesMonthlyReportNotification::class, fn ($notification): bool => $notification->month === '2026-10');
    }

    public function test_export_on_devices_and_alerts_streams_todays_csvs_for_the_viewers_devices_with_formulas_neutralised(): void
    {
        $this->router($this->home, '=HYPERLINK("http://example.test")');
        $this->router($this->other, 'Kōwhai Lodge router');

        $devices = $this->actingAs($this->viewer)->get('/security-devices/exports/devices.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $devices);
        $this->assertStringNotContainsString('Kōwhai Lodge router', $devices);

        $this->actingAs($this->viewer)->get('/security-devices/exports/events.csv')->assertOk();
        $this->actingAs($this->viewer)->get('/security-devices/exports/upkeep.csv')->assertOk();
        $this->actingAs($this->viewer)->get('/security-devices/devices')
            ->assertInertia(fn ($page) => $page->where('can.export', true));
        $this->actingAs($this->viewer)->get('/security-devices/exports/devices.csv?ids=1,x')->assertSessionHasErrors('ids');
    }
}
