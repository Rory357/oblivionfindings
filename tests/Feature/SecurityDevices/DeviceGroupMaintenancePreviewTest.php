<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringMaintenanceRevision;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\MaintenanceConfiguration;
use App\Domain\Monitoring\Services\MaintenanceHistory;
use App\Domain\Monitoring\Services\MonitoringPolicyAuthoringService;
use App\Domain\Monitoring\Services\MonitoringPolicyRules;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Services\DeviceGroupMaintenancePreview;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class DeviceGroupMaintenancePreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private DeviceGroup $group;

    private Device $device;

    private Site $site;

    private Monitor $monitor;

    private DeviceAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20T08:00:00Z'));
        $this->actor = User::factory()->create(['role' => null]);
        foreach (['securityDevices.viewAny', 'securityDevices.groups.manage', 'securityDevices.monitoring.manage', 'securityDevices.devices.viewAllSites', 'securityDevices.devices.view'] as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => 'Synthetic current preview authority', 'group' => 'securityDevices', 'module' => 'Security & Devices']);
            $this->actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        $this->actingAs($this->actor);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->device = Device::factory()->itInfrastructure()->create(['name' => 'Synthetic group gateway']);
        $this->assignment = DeviceAssignment::query()->create(['device_id' => $this->device->id, 'assignable_type' => 'site', 'assignable_id' => $this->site->id,
            'assignment_type' => 'permanent', 'assigned_at' => now()->subHour()]);
        $this->monitor = Monitor::factory()->create(['device_id' => $this->device->id, 'name' => 'Synthetic group reachability']);
        $this->group = DeviceGroup::query()->create(['name' => 'Synthetic maintenance group', 'type' => 'maintenance']);
        $this->group->devices()->attach($this->device->id);
        DB::table('monitoring_policy_history_epochs')->updateOrInsert(['owner' => 'maintenance'], ['started_at' => '2026-10-20 07:00:00.000000']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function values(array $patch = []): array
    {
        return [...['site_id' => (int) $this->site->id, 'monitor_id' => (int) $this->monitor->id, 'name' => 'Synthetic current maintenance rule',
            'starts_at' => '2026-10-20T22:00:00+13:00', 'ends_at' => '2026-10-21T01:00:00+13:00', 'timezone' => 'Pacific/Auckland',
            'reason' => 'Review the actual current Monitoring maintenance owner.'], ...$patch];
    }

    private function window(array $patch = []): MonitoringMaintenanceWindow
    {
        return app(MonitoringPolicyAuthoringService::class)->createMaintenanceWindow($this->actor, $this->values($patch));
    }

    private function input(array $patch = []): array
    {
        return [...['mode' => 'preview', 'expected_actor_id' => (string) $this->actor->id, 'device_id' => (string) $this->device->id,
            'monitor_id' => (string) $this->monitor->id, 'at' => '2026-10-20T10:00:00Z'], ...$patch];
    }

    private function url(array $query): string
    {
        return '/security-devices/device-groups/'.$this->group->id.'/maintenance-policy-preview?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** Match the browser GET: an Accept header with no JSON request body. */
    private function bodyFreeGetJson(string $url): TestResponse
    {
        return $this->get($url, ['Accept' => 'application/json']);
    }

    private function preview(array $patch = []): TestResponse
    {
        return $this->bodyFreeGetJson($this->url($this->input($patch)));
    }

    private function choices(): TestResponse
    {
        return $this->bodyFreeGetJson($this->url(['mode' => 'choices', 'expected_actor_id' => (string) $this->actor->id, 'device_id' => (string) $this->device->id]));
    }

    private function counts(): array
    {
        return [MonitoringMaintenanceWindow::query()->count(), MonitoringMaintenanceRevision::query()->count(), MonitorObservation::query()->count(),
            DeviceEvent::query()->count(), DB::table('device_group_members')->count()];
    }

    public function test_preview_retains_original_resolver_attribution_and_never_writes_or_grants_execution(): void
    {
        $window = $this->window();
        $before = $this->counts();
        $expected = app(MaintenanceHistory::class)->resolve($this->monitor->fresh(), CarbonImmutable::parse('2026-10-20T10:00:00Z'));
        $response = $this->preview()->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('schema_version', 1)->assertJsonPath('mode', 'preview')->assertJsonPath('actor_id', $this->actor->id)
            ->assertJsonPath('owner', 'Monitoring')->assertJsonPath('group.id', $this->group->id)
            ->assertJsonPath('device.id', $this->device->id)->assertJsonPath('site.id', $this->site->id)->assertJsonPath('monitor.id', $this->monitor->id)
            ->assertJsonPath('precedence', ['monitor', 'device', 'site'])->assertJsonPath('group_inheritance.supported', false)
            ->assertJsonPath('commits_change', false)->assertJsonPath('execution_authority', false)->assertJsonPath('provider_action', false)
            ->assertJsonPath('health_claim', false)->assertJsonPath('safety_response_suppression', false)
            ->assertJsonPath('resolution.state', 'applied')->assertJsonPath('resolution.winner.window_id', $window->id)
            ->assertJsonPath('resolution.winner.snapshot.timezone', 'Pacific/Auckland');
        self::assertSame($expected, $response->json('resolution'));
        self::assertSame($before, $this->counts());
        self::assertArrayNotHasKey('tenant_id', $response->json());
        // Optional synthetic producer evidence for the actual frontend contract check.
        $witnessDirectory = getenv('SD_GROUP_PREVIEW_WITNESS_DIR');
        if ($witnessDirectory !== false && is_dir($witnessDirectory)) {
            file_put_contents($witnessDirectory.'/producer-preview.json', json_encode($response->json(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            file_put_contents($witnessDirectory.'/producer-choices.json', json_encode($this->choices()->assertOk()->json(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }

    public function test_real_owner_start_is_inclusive_end_exclusive_and_utc_precision_is_exact(): void
    {
        $this->window();
        foreach (['2026-10-20T08:59:59.999999Z' => 'none', '2026-10-20T09:00:00.000000Z' => 'applied',
            '2026-10-20T11:59:59.999999Z' => 'applied', '2026-10-20T12:00:00.000000Z' => 'none'] as $at => $state) {
            $this->preview(['at' => $at])->assertOk()->assertJsonPath('requested_at', $at)->assertJsonPath('resolution.observed_at', $at)->assertJsonPath('resolution.state', $state);
        }
        $this->preview(['at' => '2026-10-20T10:00:00.123Z'])->assertOk()->assertJsonPath('requested_at', '2026-10-20T10:00:00.123000Z');
    }

    public function test_real_owner_monitor_rule_overrides_device_and_site_rules_without_group_inheritance(): void
    {
        $site = $this->window(['monitor_id' => null, 'name' => 'Synthetic Site rule']);
        $device = $this->window(['monitor_id' => null, 'device_id' => (int) $this->device->id, 'name' => 'Synthetic Device rule']);
        $monitor = $this->window(['name' => 'Synthetic Monitor rule']);
        $response = $this->preview()->assertOk()->assertJsonPath('resolution.winner.window_id', $monitor->id)
            ->assertJsonCount(1, 'resolution.matching')->assertJsonCount(2, 'resolution.overridden')->assertJsonPath('group_inheritance.supported', false);
        self::assertEqualsCanonicalizing([$site->id, $device->id], array_column($response->json('resolution.overridden'), 'window_id'));
    }

    public function test_equal_priority_legacy_conflict_is_returned_by_the_real_resolver_without_a_winner(): void
    {
        $first = $this->window();
        // Represent an existing duplicate with real attributed history; current authoring correctly rejects this overlap.
        $values = app(MonitoringPolicyRules::class)->maintenance($this->values(['name' => 'Synthetic retained duplicate']));
        $second = MonitoringMaintenanceWindow::query()->create([...$values, 'status' => 'active', 'version' => 1,
            'created_by_user_id' => $this->actor->id, 'updated_by_user_id' => $this->actor->id]);
        app(MaintenanceHistory::class)->record($second, $this->actor, 'create', null, $values['reason']);
        $response = $this->preview()->assertOk()->assertJsonPath('resolution.state', 'conflict')->assertJsonPath('resolution.reason_code', 'equal_priority_rules')
            ->assertJsonPath('resolution.winner', null)->assertJsonCount(2, 'resolution.matching');
        self::assertEqualsCanonicalizing([$first->id, $second->id], array_column($response->json('resolution.matching'), 'window_id'));
    }

    public function test_no_rule_and_unverified_occurrence_assignment_remain_explicit(): void
    {
        $this->preview()->assertOk()->assertJsonPath('resolution.state', 'none')->assertJsonPath('resolution.reason_code', 'no_applicable_maintenance')->assertJsonPath('resolution.winner', null);
        $this->window();
        $this->assignment->forceFill(['created_at' => CarbonImmutable::parse('2026-10-20T11:00:00Z')])->save();
        $this->preview()->assertOk()->assertJsonPath('resolution.state', 'unavailable')->assertJsonPath('resolution.reason_code', 'occurrence_assignment_unverified')->assertJsonPath('resolution.winner', null);
    }

    public function test_current_monitor_choices_are_bounded_private_and_do_not_disclose_foreign_targets(): void
    {
        Monitor::factory()->count(50)->create(['device_id' => $this->device->id, 'profile_id' => $this->monitor->profile_id]);
        $foreign = Monitor::factory()->create(['name' => 'Private foreign monitor']);
        $response = $this->choices()->assertOk()->assertJsonPath('mode', 'choices')->assertJsonPath('state', 'ready')->assertJsonPath('monitor_limit', 50)
            ->assertJsonPath('truncated', true)->assertJsonCount(50, 'monitors');
        foreach ($response->json('monitors') as $monitor) {
            self::assertSame((int) $this->device->id, $monitor['device_id']);
            self::assertNotSame($foreign->id, $monitor['id']);
            self::assertSame(['id', 'device_id', 'name', 'kind', 'is_enabled'], array_keys($monitor));
        }
        $response->assertDontSee('Private foreign monitor');
        self::assertArrayNotHasKey('resolution', $response->json());
    }

    public function test_membership_row_identity_never_substitutes_another_real_device(): void
    {
        $foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $foreignDevice = Device::factory()->itInfrastructure()->create(['name' => 'Private non-member gateway']);
        DeviceAssignment::query()->create(['device_id' => $foreignDevice->id, 'assignable_type' => 'site', 'assignable_id' => $foreignSite->id,
            'assignment_type' => 'permanent', 'assigned_at' => now()->subHour()]);
        $foreignMonitor = Monitor::factory()->create(['device_id' => $foreignDevice->id, 'name' => 'Private non-member monitor']);
        DB::table('device_group_members')->where('device_group_id', $this->group->id)->where('device_id', $this->device->id)
            ->update(['id' => $foreignDevice->id]);
        self::assertNotSame((int) $this->device->id, (int) $foreignDevice->id);
        $this->window();
        $this->choices()->assertOk()->assertJsonPath('device.id', $this->device->id)->assertJsonPath('site.id', $this->site->id)
            ->assertJsonCount(1, 'monitors')->assertJsonPath('monitors.0.id', $this->monitor->id)->assertDontSee('Private non-member');
        $this->preview()->assertOk()->assertJsonPath('device.id', $this->device->id)->assertJsonPath('site.id', $this->site->id)
            ->assertJsonPath('monitor.id', $this->monitor->id)->assertJsonPath('resolution.state', 'applied')->assertDontSee('Private non-member');
        $this->preview(['device_id' => (string) $foreignDevice->id, 'monitor_id' => (string) $foreignMonitor->id])->assertNotFound()->assertJsonPath('message', 'unavailable');
        $this->bodyFreeGetJson($this->url(['mode' => 'choices', 'expected_actor_id' => (string) $this->actor->id, 'device_id' => (string) $foreignDevice->id]))
            ->assertNotFound()->assertJsonPath('message', 'unavailable');
        $this->group->devices()->detach($this->device->id);
        $this->preview()->assertNotFound()->assertJsonPath('message', 'unavailable');
        $this->choices()->assertNotFound()->assertJsonPath('message', 'unavailable');
    }

    public function test_empty_monitor_choices_are_honest_and_create_nothing(): void
    {
        $this->monitor->delete();
        $before = $this->counts();
        $this->choices()->assertOk()->assertJsonPath('state', 'no_monitors')->assertJsonPath('monitors', [])->assertJsonPath('truncated', false);
        self::assertSame($before, $this->counts());
    }

    public function test_missing_foreign_monitor_and_foreign_member_reads_have_the_same_opaque_denial(): void
    {
        $foreign = Monitor::factory()->create(['name' => 'Private foreign monitor']);
        $missing = $this->preview(['monitor_id' => '999999999'])->assertNotFound()->json();
        $known = $this->preview(['monitor_id' => (string) $foreign->id])->assertNotFound()->json();
        $member = $this->preview(['device_id' => (string) $foreign->device_id, 'monitor_id' => (string) $foreign->id])->assertNotFound()->json();
        self::assertSame(['message' => 'unavailable'], $missing);
        self::assertSame($missing, $known);
        self::assertSame($missing, $member);
    }

    public function test_membership_and_operational_site_withdrawal_are_rechecked_for_both_modes(): void
    {
        $this->window();
        $this->preview()->assertOk();
        $this->group->devices()->detach($this->device->id);
        $this->preview()->assertNotFound()->assertJsonPath('message', 'unavailable');
        $this->choices()->assertNotFound()->assertJsonPath('message', 'unavailable');
        $this->group->devices()->attach($this->device->id);
        $this->preview()->assertOk();
        $this->choices()->assertOk();
        $this->site->forceFill(['is_active' => false])->save();
        $this->preview()->assertNotFound()->assertJsonPath('message', 'unavailable');
        $this->choices()->assertNotFound()->assertJsonPath('message', 'unavailable');
    }

    public function test_fresh_permission_withdrawal_denies_both_modes_even_with_a_cached_allowed_principal(): void
    {
        $this->actor->load(['permissionOverrides', 'roles.permissions']);
        foreach (['securityDevices.groups.manage', 'securityDevices.monitoring.manage', 'securityDevices.devices.viewAllSites'] as $key) {
            $this->preview()->assertOk();
            $this->choices()->assertOk();
            $id = Permission::query()->where('key', $key)->value('id');
            DB::table('permission_user')->where('user_id', $this->actor->id)->where('permission_id', $id)->update(['allowed' => false]);
            self::assertTrue($this->actor->canDo($key), 'The simulated principal deliberately retains its old grant.');
            $this->preview()->assertNotFound()->assertJsonPath('message', 'unavailable');
            $this->choices()->assertNotFound()->assertJsonPath('message', 'unavailable');
            DB::table('permission_user')->where('user_id', $this->actor->id)->where('permission_id', $id)->update(['allowed' => true]);
            $this->preview()->assertOk();
            $this->choices()->assertOk();
        }
    }

    public function test_expected_actor_and_fresh_approval_internal_role_and_verification_are_required(): void
    {
        $this->preview()->assertOk();
        $this->choices()->assertOk();
        $this->preview(['expected_actor_id' => (string) ($this->actor->id + 999)])->assertNotFound()->assertJsonPath('message', 'unavailable');
        $this->actor->load(['permissionOverrides', 'roles.permissions']);
        foreach (['approved_at' => null, 'email_verified_at' => null, 'role' => 'client'] as $field => $value) {
            $this->preview()->assertOk();
            $this->choices()->assertOk();
            $original = $this->actor->getRawOriginal($field);
            DB::table('users')->where('id', $this->actor->id)->update([$field => $value]);
            $this->preview()->assertNotFound()->assertJsonPath('message', 'unavailable');
            $this->choices()->assertNotFound()->assertJsonPath('message', 'unavailable');
            DB::table('users')->where('id', $this->actor->id)->update([$field => $original]);
            $this->preview()->assertOk();
            $this->choices()->assertOk();
        }
    }

    public function test_unknown_array_padded_duplicate_and_missing_query_values_are_rejected(): void
    {
        foreach ([['extra' => 'ignored'], ['device_id' => ['1']], ['expected_actor_id' => ' '.$this->actor->id], ['device_id' => '0'],
            ['monitor_id' => '01'], ['expected_actor_id' => '9223372036854775808'], ['mode' => 'other'], ['at' => ''], ['at' => ['2026-10-20T10:00:00Z']]] as $patch) {
            $this->preview($patch)->assertStatus(422)->assertJsonPath('message', 'invalid_request');
        }
        $withoutAt = $this->input();
        unset($withoutAt['at']);
        $this->bodyFreeGetJson($this->url($withoutAt))->assertStatus(422);
        $this->bodyFreeGetJson($this->url($this->input()).'&device_id='.$this->device->id)->assertStatus(422);
        $this->bodyFreeGetJson($this->url(['mode' => 'choices', 'expected_actor_id' => (string) $this->actor->id, 'device_id' => (string) $this->device->id, 'at' => '2026-10-20T10:00:00Z']))->assertStatus(422);
        $this->json('GET', $this->url($this->input()), ['unexpected' => 'body'])->assertStatus(422);
    }

    public function test_utc_input_never_silently_normalizes_offsets_dates_or_excess_precision(): void
    {
        foreach (['2026-10-20T10:00:00', '2026-10-20T10:00:00+00:00', '2026-10-20T23:00:00+13:00', '2026-02-30T10:00:00Z',
            '2026-10-20T24:00:00Z', '2026-10-20T10:00:60Z', '2026-10-20T10:00:00.1234567Z', '0000-10-20T10:00:00Z'] as $at) {
            $this->preview(['at' => $at])->assertStatus(422)->assertJsonPath('message', 'invalid_request');
        }
    }

    public function test_current_group_soft_deletion_and_unauthenticated_reads_are_denied(): void
    {
        $this->group->delete();
        // Route model binding misses the trashed group; the reply is the same private body as every other denial.
        $this->preview()->assertNotFound()->assertExactJson(['message' => 'unavailable'])->assertHeader('Cache-Control', 'no-store, private');
        $this->bodyFreeGetJson('/security-devices/device-groups/999999999/maintenance-policy-preview?'.http_build_query($this->input(), '', '&', PHP_QUERY_RFC3986))
            ->assertNotFound()->assertExactJson(['message' => 'unavailable'])->assertHeader('Cache-Control', 'no-store, private');
        $this->app['auth']->forgetGuards();
        $this->bodyFreeGetJson($this->url($this->input()))->assertUnauthorized();
    }

    public function test_a_site_removed_part_way_through_the_read_gets_the_same_private_denial(): void
    {
        // Soft-delete the Site straight after the canonical resolver confirms it is active, so the
        // owner's own Site re-read (firstOrFail) throws ModelNotFoundException mid-request.
        $stage = 'waiting_for_member_read';
        DB::listen(function ($query) use (&$stage): void {
            if ($stage === 'waiting_for_member_read' && str_contains($query->sql, 'device_group_members')) {
                $stage = 'waiting_for_resolver_site_check';
            } elseif ($stage === 'waiting_for_resolver_site_check' && preg_match('/exists\s*\(\s*select \* from [`"]?sites[`"]?\s/i', $query->sql) === 1) {
                $stage = 'site_removed';
                DB::table('sites')->where('id', $this->site->id)->update(['deleted_at' => now()]);
            }
        });
        $response = $this->choices()->assertNotFound()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['message' => 'unavailable']);
        self::assertSame('site_removed', $stage, 'The mid-read removal was not simulated.');
        $response->assertDontSee('No query results');
    }

    public function test_preview_reads_have_their_own_rate_limit(): void
    {
        $key = 'device-group-maintenance-preview'.sha1((string) $this->actor->getAuthIdentifier());
        for ($attempt = 0; $attempt < 30; $attempt++) {
            RateLimiter::hit($key, 60);
        }
        $this->choices()->assertStatus(429);
        $this->preview()->assertStatus(429);
        RateLimiter::clear($key);
        $this->choices()->assertOk();
    }

    public function test_show_adds_current_actor_and_read_metadata_without_replacing_group_data(): void
    {
        $this->get('/security-devices/device-groups/'.$this->group->id)->assertOk()
            ->assertInertia(fn ($page) => $page->component('security-devices/device-groups/show')
                ->where('actor_id', (int) $this->actor->id)->where('as_of', '2026-10-20T08:00:00.000000Z')
                ->where('can_preview_maintenance', true)
                ->where('group.id', (int) $this->group->id)->has('members.data', 1)->has('availableDevices'));
    }

    public function test_show_offers_the_preview_only_to_actors_the_read_would_serve(): void
    {
        $id = Permission::query()->where('key', 'securityDevices.monitoring.manage')->value('id');
        DB::table('permission_user')->where('user_id', $this->actor->id)->where('permission_id', $id)->update(['allowed' => false]);
        $this->actingAs($this->actor->fresh());
        // The group page itself stays available (groups.manage + all Sites), but the read would only ever 404.
        $this->get('/security-devices/device-groups/'.$this->group->id)->assertOk()
            ->assertInertia(fn ($page) => $page->component('security-devices/device-groups/show')->where('can_preview_maintenance', false));
        $this->choices()->assertForbidden();

        DB::table('permission_user')->where('user_id', $this->actor->id)->where('permission_id', $id)->update(['allowed' => true]);
        $this->actingAs($this->actor->fresh());
        $this->get('/security-devices/device-groups/'.$this->group->id)
            ->assertInertia(fn ($page) => $page->where('can_preview_maintenance', true));
        $this->choices()->assertOk();
    }

    public function test_preview_pages_site_revisions_in_bounded_queries_across_long_chains(): void
    {
        $window = $this->window();
        $firstRevision = MonitoringMaintenanceRevision::query()->where('window_id', $window->id)->sole();
        $baseSnapshot = app(MaintenanceHistory::class)->configuration($window->fresh());
        $targetVersion = (MaintenanceHistory::REVISION_PAGE_SIZE * 2) + 25;

        DB::transaction(function () use ($window, $firstRevision, $baseSnapshot, $targetVersion): void {
            $previousId = (int) $firstRevision->id;
            for ($version = 2; $version <= $targetVersion; $version++) {
                $instant = CarbonImmutable::parse('2026-10-20T08:00:00Z')->addSeconds($version)->format('Y-m-d H:i:s.u');
                $snapshot = [...$baseSnapshot, 'version' => $version, 'reason' => 'Synthetic revision '.$version];
                $previousId = (int) DB::table('monitoring_maintenance_revisions')->insertGetId([
                    'window_id' => (int) $window->id,
                    'version' => $version,
                    'site_id' => (int) $this->site->id,
                    'actor_user_id' => (int) $this->actor->id,
                    'supersedes_id' => $previousId,
                    'operation' => 'update',
                    'change_reason' => 'Synthetic revision '.$version,
                    'effective_from' => $instant,
                    'recorded_at' => $instant,
                    'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'snapshot_hash' => MaintenanceConfiguration::hash($snapshot),
                ]);
            }
            $window->forceFill([
                'version' => $targetVersion,
                'reason' => 'Synthetic revision '.$targetVersion,
                'updated_by_user_id' => $this->actor->id,
            ])->save();
        });

        $revisionQueries = [];
        DB::listen(function ($query) use (&$revisionQueries): void {
            $lower = strtolower($query->sql);
            if (str_contains($lower, 'monitoring_maintenance_revisions') && ! str_contains($lower, 'information_schema')) {
                $revisionQueries[] = $lower;
            }
        });

        $expected = app(MaintenanceHistory::class)->resolve($this->monitor->fresh(), CarbonImmutable::parse('2026-10-20T10:00:00Z'));
        $revisionQueries = [];
        $response = $this->preview()
            ->assertOk()
            ->assertJsonPath('resolution.state', 'applied')
            ->assertJsonPath('resolution.winner.window_id', $window->id)
            ->assertJsonPath('resolution.winner.version', $targetVersion);
        self::assertSame($expected, $response->json('resolution'));
        self::assertNotEmpty($revisionQueries);
        foreach ($revisionQueries as $sql) {
            self::assertStringContainsString('limit ', $sql);
        }
        $snapshotPages = array_values(array_filter(
            $revisionQueries,
            static fn (string $sql): bool => str_starts_with($sql, 'select * from'),
        ));
        self::assertCount(3, $snapshotPages);
    }

    public function test_preview_aborts_before_hydrating_unbounded_site_revisions_when_scan_limit_is_exceeded(): void
    {
        $window = $this->window();
        $baseSnapshot = app(MaintenanceHistory::class)->configuration($window->fresh());
        $encodedSnapshot = json_encode($baseSnapshot, JSON_THROW_ON_ERROR);
        $snapshotHash = MaintenanceConfiguration::hash($baseSnapshot);
        $extraCount = DeviceGroupMaintenancePreview::REVISION_SCAN_LIMIT;

        $rows = [];
        for ($version = 2; $version <= $extraCount + 1; $version++) {
            $rows[] = [
                'window_id' => (int) $window->id,
                'version' => $version,
                'site_id' => (int) $this->site->id,
                'actor_user_id' => (int) $this->actor->id,
                'supersedes_id' => null,
                'operation' => 'update',
                'change_reason' => 'Synthetic bounded scan test',
                'effective_from' => '2026-10-20 08:05:00.000000',
                'recorded_at' => '2026-10-20 08:05:00.000000',
                'snapshot' => $encodedSnapshot,
                'snapshot_hash' => $snapshotHash,
            ];
            if (count($rows) === 250) {
                DB::table('monitoring_maintenance_revisions')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('monitoring_maintenance_revisions')->insert($rows);
        }

        $revisionQueries = [];
        DB::listen(function ($query) use (&$revisionQueries): void {
            $lower = strtolower($query->sql);
            if (str_contains($lower, 'monitoring_maintenance_revisions') && ! str_contains($lower, 'information_schema')) {
                $revisionQueries[] = $lower;
            }
        });

        $this->preview()
            ->assertStatus(503)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['message' => 'unavailable']);

        self::assertNotEmpty($revisionQueries);
        foreach ($revisionQueries as $sql) {
            self::assertStringContainsString('limit ', $sql);
            self::assertStringNotContainsString('select * from', $sql);
        }
    }
}
