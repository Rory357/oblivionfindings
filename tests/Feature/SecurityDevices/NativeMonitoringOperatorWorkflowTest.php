<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Jobs\RunDiscoveryScope;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDependency;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\NativeMonitorCommandReceipt;
use App\Domain\Monitoring\Services\NativeMonitorCommandService;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class NativeMonitoringOperatorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public function test_manager_creates_direct_scope_and_monitor_without_projecting_raw_network_or_target(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();

        $scopeResponse = $this->actingAs($manager)->postJson('/security-devices/discovery/scopes', [
            'site_id' => $site->id,
            'name' => 'Primary SD-WAN network',
            'cidrs' => ['10.44.0.0/16'],
            'seed_hosts' => ['10.44.0.1'],
            'protocols' => ['icmp', 'tcp', 'tls'],
            'exclusions' => [],
            'port_bounds' => ['tcp' => [443], 'tls' => [443]],
            'max_targets_per_run' => 1024,
            'packets_per_second' => 20,
        ])->assertCreated()->assertJsonPath('scope.collection_mode', 'central_direct');

        $scope = DiscoveryScope::query()->findOrFail($scopeResponse->json('scope.id'));
        $this->assertNull($scope->collector_id);
        $this->assertSame(['10.44.0.0/16'], $scope->cidrs);

        $profile = MonitoringProfile::factory()->create(['name' => 'Standard direct policy']);
        $monitorResponse = $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', [
            'device_id' => $device->id,
            'profile_id' => $profile->id,
            'kind' => 'icmp',
            'name' => 'Gateway reachability',
            'target' => '10.44.0.10',
            'affects_availability' => true,
        ])->assertCreated()->assertJsonPath('monitor.collection_mode', 'central_direct');

        $monitor = Monitor::query()->findOrFail($monitorResponse->json('monitor.id'));
        $this->assertNull($monitor->collector_id);
        $this->assertSame('10.44.0.10', $monitor->target);
        $this->assertSame(['host' => '10.44.0.10'], $monitor->config);

        $this->actingAs($manager)
            ->patchJson("/security-devices/discovery/scopes/{$scope->id}", [
                'name' => 'Primary central Site network',
                'max_targets_per_run' => 512,
            ])
            ->assertOk()
            ->assertJsonPath('scope.network_range_count', 1);
        $this->assertSame(['10.44.0.0/16'], $scope->fresh()->cidrs);

        $this->actingAs($manager)
            ->patchJson("/security-devices/monitoring/native-monitors/{$monitor->id}", [
                'profile_id' => $profile->id,
                'name' => 'Gateway central reachability',
                'target' => '',
                'affects_availability' => true,
            ])
            ->assertOk()
            ->assertJsonPath('monitor.name', 'Gateway central reachability');
        $this->assertSame('10.44.0.10', $monitor->fresh()->target);

        $auditJson = AuditLog::query()
            ->whereIn('action', [
                'monitoring.discovery.scope.created',
                'monitoring.discovery.scope.updated',
                'monitoring.monitor.created',
                'monitoring.monitor.updated',
            ])
            ->get()
            ->map(fn (AuditLog $audit): array => $audit->meta)
            ->toJson();
        $this->assertStringNotContainsString('10.44.0.0/16', $auditJson);
        $this->assertStringNotContainsString('10.44.0.10', $auditJson);

        $monitoringPage = $this->actingAs($manager)->get('/security-devices/monitoring');
        $monitoringPage->assertOk()->assertDontSee('10.44.0.10', false);
        $discoveryPage = $this->actingAs($manager)->get('/security-devices/discovery?tab=scopes');
        $discoveryPage->assertOk()->assertDontSee('10.44.0.0/16', false);

        $this->actingAs($manager)
            ->postJson("/security-devices/monitoring/native-monitors/{$monitor->id}/deactivate", [
                'reason_code' => 'replaced',
            ])
            ->assertOk()
            ->assertJsonPath('monitor.enabled', false);
        $deactivationAudit = AuditLog::query()
            ->where('action', 'monitoring.monitor.deactivated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('replaced', $deactivationAudit->meta['reason_code']);
        $this->assertStringNotContainsString(
            '10.44.0.10',
            json_encode($deactivationAudit->meta, JSON_THROW_ON_ERROR),
        );
    }

    public function test_site_and_permission_boundaries_conceal_native_monitoring_objects(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $otherSite = $this->activeSite('Hidden Site');
        $otherDevice = $this->deviceAtSite($otherSite, 'Hidden gateway');
        $profile = MonitoringProfile::factory()->create();
        $hiddenScope = DiscoveryScope::factory()->create([
            'site_id' => $otherSite->id,
            'collector_id' => null,
        ]);
        $hiddenMonitor = Monitor::factory()->create([
            'device_id' => $otherDevice->id,
            'profile_id' => $profile->id,
            'collector_id' => null,
            'kind' => MonitorKind::Icmp,
        ]);

        $this->actingAs($manager)
            ->patchJson("/security-devices/monitoring/native-monitors/{$hiddenMonitor->id}", [
                'name' => 'Attempted hidden update',
            ])
            ->assertNotFound();
        $this->actingAs($manager)
            ->postJson("/security-devices/discovery/scopes/{$hiddenScope->id}/apply")
            ->assertNotFound();

        $viewer = $this->viewerAtSite($site, [
            'securityDevices.events.view',
            'securityDevices.integrations.view',
            'securityDevices.integrations.manage',
        ]);
        $this->actingAs($viewer)
            ->postJson('/security-devices/monitoring/native-monitors', [
                'device_id' => $device->id,
                'profile_id' => $profile->id,
                'kind' => 'icmp',
                'name' => 'Forbidden monitor',
                'target' => '10.44.0.10',
            ])
            ->assertForbidden();

        $this->assertSame($hiddenMonitor->name, $hiddenMonitor->fresh()->name);
        $this->assertDatabaseCount('monitoring_discovery_runs', 0);
    }

    public function test_target_must_be_inside_active_direct_site_scope_and_remote_scopes_are_not_mutable_here(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $profile = MonitoringProfile::factory()->create();
        DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'collector_id' => null,
            'cidrs' => ['10.44.0.0/16'],
            'protocols' => ['icmp', 'tcp'],
            'port_bounds' => ['tcp' => [443]],
            'exclusions' => [],
        ]);

        $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', [
            'device_id' => $device->id,
            'profile_id' => $profile->id,
            'kind' => 'http',
            'name' => 'Cross protocol attempt',
            'target' => 'https://10.44.0.10/health',
        ])->assertUnprocessable()->assertJsonValidationErrors('target');

        $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', [
            'device_id' => $device->id,
            'profile_id' => $profile->id,
            'kind' => 'icmp',
            'name' => 'Out of scope target',
            'target' => '10.99.0.10',
        ])->assertUnprocessable()->assertJsonValidationErrors('target');

        $hiddenSite = Site::factory()->create(['name' => 'Hidden Remote Collector Site']);
        $hiddenCollector = MonitoringCollector::factory()->create(['site_id' => $hiddenSite->id]);
        $inaccessibleRemoteScope = DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'collector_id' => $hiddenCollector->id,
        ]);
        $this->actingAs($manager)
            ->patchJson("/security-devices/discovery/scopes/{$inaccessibleRemoteScope->id}", [
                'name' => 'Attempted remote mutation',
            ])
            ->assertNotFound();

        $accessibleCollector = MonitoringCollector::factory()->create(['site_id' => $site->id]);
        $accessibleRemoteScope = DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'collector_id' => $accessibleCollector->id,
        ]);
        $this->actingAs($manager)
            ->patchJson("/security-devices/discovery/scopes/{$accessibleRemoteScope->id}", [
                'name' => 'Updated accessible hub scope',
            ])
            ->assertOk()
            ->assertJsonPath('scope.name', 'Updated accessible hub scope');

        $this->assertDatabaseCount('monitors', 0);
        $this->assertNotSame('Attempted remote mutation', $inaccessibleRemoteScope->fresh()->name);
    }

    public function test_monitor_deactivation_is_dependency_safe_and_scope_apply_is_bounded(): void
    {
        Queue::fake();
        [$manager, $site, $device] = $this->managerAtSite();
        $otherDevice = $this->deviceAtSite($site, 'Downstream switch');
        $profile = MonitoringProfile::factory()->create();
        $upstream = Monitor::factory()->create([
            'device_id' => $device->id,
            'profile_id' => $profile->id,
            'collector_id' => null,
            'kind' => MonitorKind::Icmp,
            'is_enabled' => true,
        ]);
        $downstream = Monitor::factory()->create([
            'device_id' => $otherDevice->id,
            'profile_id' => $profile->id,
            'collector_id' => null,
            'kind' => MonitorKind::Icmp,
            'is_enabled' => true,
        ]);
        MonitorDependency::query()->create([
            'site_id' => $site->id,
            'upstream_monitor_id' => $upstream->id,
            'downstream_monitor_id' => $downstream->id,
            'policy' => MonitorDependency::POLICY_SUPPRESS,
            'source' => 'manual',
            'confidence' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->postJson("/security-devices/monitoring/native-monitors/{$upstream->id}/deactivate", [
                'reason_code' => 'replaced',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('monitor');
        $this->assertTrue($upstream->fresh()->is_enabled);

        $scope = DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'collector_id' => null,
            'cidrs' => ['10.44.0.0/30'],
            'protocols' => ['icmp', 'tcp'],
            'port_bounds' => ['tcp' => [443]],
            'max_targets_per_run' => 4,
            'status' => 'active',
        ]);
        $apply = $this->actingAs($manager)
            ->postJson("/security-devices/discovery/scopes/{$scope->id}/apply")
            ->assertAccepted()
            ->assertJsonPath('run.planned_targets', 4);
        Queue::assertPushed(RunDiscoveryScope::class, 1);

        $this->actingAs($manager)
            ->postJson("/security-devices/discovery/scopes/{$scope->id}/deactivate", [
                'reason_code' => 'network_retired',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scope');
        DiscoveryRun::query()->findOrFail($apply->json('run.id'))->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();
        $this->actingAs($manager)
            ->postJson("/security-devices/discovery/scopes/{$scope->id}/deactivate", [
                'reason_code' => 'network_retired',
            ])
            ->assertOk()
            ->assertJsonPath('scope.status', 'inactive');
    }

    public function test_native_request_replay_returns_immutable_saved_configuration_without_reapplying_later_changes(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $input = $this->nativeReceiptInput($manager, $site, $device);
        $first = $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', $input)
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('receipt.request_uuid', $input['request_uuid'])
            ->assertJsonPath('receipt.actor_id', $manager->id)
            ->assertJsonPath('receipt.site_id', $site->id)
            ->assertJsonPath('receipt.current_matches_saved', true)
            ->assertJsonPath('receipt.confirms_observation', false);
        $id = $first->json('monitor.id');
        $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', $input)
            ->assertCreated()->assertJsonPath('monitor.id', $id);
        $this->assertSame(1, NativeMonitorCommandReceipt::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'monitoring.monitor.created')->count());
        $edit = [
            'request_uuid' => (string) Str::uuid(), 'expected_actor_id' => $manager->id,
            'expected_site_id' => $site->id, 'expected_device_id' => $device->id,
            'review_token' => $first->json('current_monitor.review_token'),
            'profile_id' => $input['profile_id'], 'name' => 'Later reviewed name', 'affects_availability' => false,
        ];
        $this->actingAs($manager)->patchJson("/security-devices/monitoring/native-monitors/{$id}", $edit)->assertOk();
        $replayed = $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', $input)
            ->assertCreated()->assertJsonPath('monitor.name', $input['name'])
            ->assertJsonPath('current_monitor.name', 'Later reviewed name')
            ->assertJsonPath('receipt.current_matches_saved', false);
        $this->assertSame('Later reviewed name', Monitor::query()->findOrFail($id)->name);
        $this->assertSame(1, AuditLog::query()->where('action', 'monitoring.monitor.updated')->count());
        $json = $replayed->getContent();
        foreach (['10.44.0.10', 'request_hash', 'definition_hash', 'credential_reference', '"config"', '"target"'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_native_request_recovery_is_read_only_and_current_scope_and_actor_bound(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $input = $this->nativeReceiptInput($manager, $site, $device);
        $saved = $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', $input)->assertCreated();
        $path = "/security-devices/monitoring/native-monitors/requests/{$input['request_uuid']}/recover";
        $body = Arr::only($input, ['expected_actor_id', 'expected_site_id', 'expected_device_id']);
        $this->actingAs($manager)->postJson($path, $body)->assertOk()->assertJsonPath('monitor.id', $saved->json('monitor.id'));
        $other = $this->viewerAtSite($site, ['securityDevices.monitoring.manage']);
        $this->actingAs($other)->postJson($path, [...$body, 'expected_actor_id' => $other->id])->assertNotFound();
        $this->actingAs($manager)->postJson($path, [...$body, 'expected_actor_id' => $other->id])->assertNotFound();
        $this->actingAs($manager)->postJson($path, [...$body, 'expected_device_id' => $device->id + 100000])->assertNotFound();
        $this->assertSame(1, AuditLog::query()->where('action', 'monitoring.monitor.created')->count());
        $this->assertSame(1, NativeMonitorCommandReceipt::query()->count());
    }

    public function test_native_request_binding_rejects_changed_payload_and_unknown_fields_without_another_write(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $input = $this->nativeReceiptInput($manager, $site, $device);
        $id = $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', $input)->assertCreated()->json('monitor.id');
        $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', [...$input, 'name' => 'Different request body'])->assertStatus(409);
        $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', [...$input, 'unsupported' => 'not accepted'])->assertUnprocessable()->assertJsonValidationErrors('form');
        $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors?target=private', $input)->assertBadRequest();
        $this->assertSame($input['name'], Monitor::query()->findOrFail($id)->name);
        $this->assertSame(1, NativeMonitorCommandReceipt::query()->count());
    }

    public function test_native_request_reviewed_site_and_saved_receipt_do_not_follow_a_transferred_device(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $input = $this->nativeReceiptInput($manager, $site, $device);
        $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', $input)->assertCreated();
        $destination = $this->activeSite('Destination Site also permitted');
        HrEmployeeProfile::query()->where('user_id', $manager->id)->firstOrFail()->update(['secondary_site_ids' => [$destination->id]]);
        DeviceAssignment::query()->where('device_id', $device->id)->whereNull('released_at')->update(['released_at' => now()]);
        DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $destination->id, 'assignment_type' => AssignmentType::Permanent, 'assigned_at' => now()]);
        $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', [...$input, 'request_uuid' => (string) Str::uuid()])->assertNotFound();
        $body = Arr::only($input, ['expected_actor_id', 'expected_site_id', 'expected_device_id']);
        $path = "/security-devices/monitoring/native-monitors/requests/{$input['request_uuid']}/recover";
        $this->actingAs($manager)->postJson($path, $body)->assertNotFound();
        $this->actingAs($manager)->postJson($path, [...$body, 'expected_site_id' => $destination->id])->assertNotFound();
        $this->assertSame(1, NativeMonitorCommandReceipt::query()->count());
        $this->actingAs($manager)->getJson('/security-devices/monitoring/native-monitors/requests?expected_actor_id='.$manager->id)->assertOk()->assertJsonCount(0, 'rows');
    }

    public function test_native_request_owner_rechecks_a_preloaded_actors_revoked_permission(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $input = $this->nativeReceiptInput($manager, $site, $device);
        $this->assertTrue($manager->canDo('securityDevices.monitoring.manage'));
        $permission = Permission::query()->where('key', 'securityDevices.monitoring.manage')->firstOrFail();
        $manager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        try {
            app(NativeMonitorCommandService::class)->execute($manager, 'create', null, $input);
            $this->fail('Revoked authority must not commit a receipt.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertSame(0, NativeMonitorCommandReceipt::query()->count());
        $this->assertSame(0, Monitor::query()->where('device_id', $device->id)->count());
    }

    public function test_native_deactivation_receipt_is_dependency_safe_and_replays_without_a_second_audit(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $input = $this->nativeReceiptInput($manager, $site, $device);
        $created = $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', $input)->assertCreated();
        $id = $created->json('monitor.id');
        $peer = Monitor::factory()->create(['device_id' => $device->id, 'profile_id' => $input['profile_id'], 'collector_id' => null, 'kind' => MonitorKind::Icmp]);
        $dependency = MonitorDependency::query()->create(['site_id' => $site->id, 'upstream_monitor_id' => $id, 'downstream_monitor_id' => $peer->id, 'policy' => MonitorDependency::POLICY_SUPPRESS, 'source' => 'manual', 'confidence' => 1, 'version' => 1, 'is_active' => true]);
        $body = [...Arr::only($input, ['expected_actor_id', 'expected_site_id', 'expected_device_id']),
            'request_uuid' => (string) Str::uuid(), 'review_token' => $created->json('current_monitor.review_token'), 'reason_code' => 'replaced'];
        $path = "/security-devices/monitoring/native-monitors/{$id}/deactivate";
        $this->actingAs($manager)->postJson($path, $body)->assertUnprocessable()->assertJsonValidationErrors('monitor');
        $this->assertTrue(Monitor::query()->findOrFail($id)->is_enabled);
        $this->assertFalse(NativeMonitorCommandReceipt::query()->where('request_uuid', $body['request_uuid'])->exists());
        $dependency->update(['is_active' => false]);
        $first = $this->actingAs($manager)->postJson($path, $body)->assertOk()->assertJsonPath('monitor.enabled', false);
        $this->actingAs($manager)->postJson($path, $body)->assertOk()->assertJsonPath('receipt.committed_at', $first->json('receipt.committed_at'));
        $this->assertSame(1, AuditLog::query()->where('action', 'monitoring.monitor.deactivated')->count());
    }

    public function test_native_receipt_failure_rolls_back_the_monitor_and_audit_and_committed_bindings_are_immutable(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $input = $this->nativeReceiptInput($manager, $site, $device);
        Event::listen('eloquent.updating: '.NativeMonitorCommandReceipt::class,
            function ($receipt) use ($input): void {
                if ($receipt->request_uuid === $input['request_uuid']) {
                    throw new \RuntimeException('Fixture receipt failure');
                }
            });
        try {
            app(NativeMonitorCommandService::class)->execute($manager, 'create', null, $input);
            $this->fail('A failed receipt must roll back the complete mutation.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Fixture receipt failure', $error->getMessage());
        }
        $this->assertSame(0, Monitor::query()->where('device_id', $device->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'monitoring.monitor.created')->count());
        $this->assertSame(0, NativeMonitorCommandReceipt::query()->count());
        $fresh = [...$input, 'request_uuid' => (string) Str::uuid()];
        $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', $fresh)->assertCreated();
        $receipt = NativeMonitorCommandReceipt::query()->firstOrFail();
        try {
            $receipt->forceFill(['operation' => 'update'])->save();
            $this->fail('Committed bindings must stay immutable.');
        } catch (\UnexpectedValueException $error) {
            $this->assertStringContainsString('original native monitor', $error->getMessage());
        }
        $this->assertSame('create', $receipt->fresh()->operation);
    }

    public function test_native_confirmation_storage_unavailable_returns_no_fabricated_save(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $input = $this->nativeReceiptInput($manager, $site, $device);
        $builder = Schema::getFacadeRoot();
        Schema::swap(new class($builder)
        {
            public function __construct(private object $inner) {}

            public function hasTable(string $table): bool
            {
                return $table === 'native_monitor_command_receipts' ? false : $this->inner->hasTable($table);
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->inner->{$method}(...$arguments);
            }
        });
        try {
            $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', $input)->assertStatus(503);
            $this->assertSame(0, Monitor::query()->where('device_id', $device->id)->count());
        } finally {
            Schema::swap($builder);
        }
    }

    public function test_native_review_token_rejects_an_editor_after_hidden_connection_settings_changed(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $input = $this->nativeReceiptInput($manager, $site, $device);
        $created = $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', $input)->assertCreated();
        $id = $created->json('monitor.id');
        $token = $created->json('current_monitor.review_token');
        $this->actingAs($manager)->patchJson("/security-devices/monitoring/native-monitors/{$id}", ['target' => '10.44.0.11'])->assertOk();
        $stale = ['request_uuid' => (string) Str::uuid(), 'expected_actor_id' => $manager->id,
            'expected_site_id' => $site->id, 'expected_device_id' => $device->id, 'review_token' => $token,
            'name' => 'Stale editor overwrite', 'profile_id' => $input['profile_id']];
        $this->actingAs($manager)->patchJson("/security-devices/monitoring/native-monitors/{$id}", $stale)->assertStatus(409);
        $this->assertSame($input['name'], Monitor::query()->findOrFail($id)->name);
        $this->assertSame('10.44.0.11', Monitor::query()->findOrFail($id)->target);
        $this->assertFalse(NativeMonitorCommandReceipt::query()->where('request_uuid', $stale['request_uuid'])->exists());
        $this->assertSame(2, NativeMonitorCommandReceipt::query()->count());
    }

    public function test_native_workspace_projects_the_current_actor_and_opaque_review_for_managed_monitor_context(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $input = $this->nativeReceiptInput($manager, $site, $device);
        $created = $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', $input)->assertCreated();
        $id = $created->json('monitor.id');
        $this->actingAs($manager)->get('/security-devices/monitoring')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('workspace.actor_id', $manager->id)
                ->where('workspace.monitor_management.actor_id', $manager->id)
                ->where('workspace.monitors', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => $row['id'] === $id
                    && $row['actions']['actor_id'] === $manager->id && preg_match('/^[0-9a-f]{64}$/D', $row['actions']['review_token']) === 1))
        )->assertDontSee('10.44.0.10', false);
    }

    public function test_saved_monitor_request_entry_survives_a_lost_create_response_and_keeps_later_changes_distinct(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $input = $this->nativeReceiptInput($manager, $site, $device);
        $saved = $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', $input)->assertCreated();
        $id = $saved->json('monitor.id');
        // The original mutation response is deliberately not used to recover the UUID.
        $entry = $this->actingAs($manager)->getJson('/security-devices/monitoring/native-monitors/requests?expected_actor_id='.$manager->id)
            ->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.receipt.request_uuid', $input['request_uuid']);
        $handle = $entry->json('rows.0.receipt');
        $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors/requests/'.$handle['request_uuid'].'/recover', [
            'expected_actor_id' => $handle['actor_id'], 'expected_site_id' => $handle['site_id'], 'expected_device_id' => $handle['device_id'],
        ])->assertOk()->assertJsonPath('monitor.id', $id);
        $this->assertSame(1, Monitor::query()->where('device_id', $device->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'monitoring.monitor.created')->count());
        $this->actingAs($manager)->patchJson('/security-devices/monitoring/native-monitors/'.$id, [
            'request_uuid' => (string) Str::uuid(), 'expected_actor_id' => $manager->id, 'expected_site_id' => $site->id,
            'expected_device_id' => $device->id, 'review_token' => $saved->json('current_monitor.review_token'), 'name' => 'Later approved name',
        ])->assertOk();
        $later = $this->actingAs($manager)->getJson('/security-devices/monitoring/native-monitors/requests?expected_actor_id='.$manager->id)->assertOk()->assertJsonCount(2, 'rows');
        $this->assertSame($input['name'], $later->json('rows.1.monitor.name'));
        $this->assertSame('Later approved name', $later->json('rows.1.current_monitor.name'));
        $this->assertFalse($later->json('rows.1.receipt.current_matches_saved'));
        $this->assertStringNotContainsString($input['target'], $later->getContent());
        $other = $this->viewerAtSite($site, ['securityDevices.monitoring.manage']);
        $this->actingAs($other)->getJson('/security-devices/monitoring/native-monitors/requests?expected_actor_id='.$other->id)->assertOk()->assertJsonCount(0, 'rows');
        $this->actingAs($other)->getJson('/security-devices/monitoring/native-monitors/requests?expected_actor_id='.$manager->id)->assertNotFound();
        $permission = Permission::query()->where('key', 'securityDevices.monitoring.manage')->firstOrFail();
        $manager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        try {
            app(NativeMonitorCommandService::class)->savedRequests($manager, (int) $manager->id, null);
            $this->fail('Preloaded permission cannot expose saved requests.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
    }

    public function test_saved_monitor_request_history_can_reach_an_original_request_beyond_the_first_page_without_writing(): void
    {
        [$manager, $site, $device] = $this->managerAtSite();
        $input = $this->nativeReceiptInput($manager, $site, $device);
        $current = $this->actingAs($manager)->postJson('/security-devices/monitoring/native-monitors', $input)->assertCreated();
        $id = $current->json('monitor.id');
        for ($index = 1; $index <= 26; $index++) {
            $current = $this->actingAs($manager)->patchJson('/security-devices/monitoring/native-monitors/'.$id, [
                'request_uuid' => (string) Str::uuid(), 'expected_actor_id' => $manager->id, 'expected_site_id' => $site->id,
                'expected_device_id' => $device->id, 'review_token' => $current->json('current_monitor.review_token'), 'name' => 'Approved change '.$index,
            ])->assertOk();
        }
        $path = '/security-devices/monitoring/native-monitors/requests?expected_actor_id='.$manager->id;
        $first = $this->actingAs($manager)->getJson($path)->assertOk()->assertJsonCount(25, 'rows');
        $cursor = $first->json('next_before_id');
        $this->assertIsInt($cursor);
        $last = $this->actingAs($manager)->getJson($path.'&before_id='.$cursor)->assertOk()->assertJsonCount(2, 'rows')->assertJsonPath('next_before_id', null);
        $this->assertSame($input['request_uuid'], $last->json('rows.1.receipt.request_uuid'));
        $this->assertSame($input['name'], $last->json('rows.1.monitor.name'));
        $this->assertSame('Approved change 26', $last->json('rows.1.current_monitor.name'));
        $this->assertSame(1, Monitor::query()->where('device_id', $device->id)->count());
        $this->assertSame(27, NativeMonitorCommandReceipt::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'monitoring.monitor.created')->count());
        $this->assertSame(26, AuditLog::query()->where('action', 'monitoring.monitor.updated')->count());
    }

    private function nativeReceiptInput(User $manager, Site $site, Device $device): array
    {
        Queue::fake();
        DiscoveryScope::factory()->create(['site_id' => $site->id, 'collector_id' => null, 'status' => 'active',
            'cidrs' => ['10.44.0.0/16'], 'seed_hosts' => [], 'protocols' => ['icmp', 'tcp'], 'exclusions' => [], 'port_bounds' => ['tcp' => [443]]]);
        $profile = MonitoringProfile::factory()->create(['name' => 'Reviewed native policy']);

        return ['request_uuid' => (string) Str::uuid(), 'expected_actor_id' => $manager->id,
            'expected_site_id' => $site->id, 'expected_device_id' => $device->id, 'device_id' => $device->id,
            'profile_id' => $profile->id, 'kind' => 'icmp', 'name' => 'Receipt gateway reachability',
            'target' => '10.44.0.10', 'affects_availability' => true];
    }

    /** @return array{User, Site, Device} */
    private function managerAtSite(): array
    {
        $site = $this->activeSite('Allowed monitoring Site');

        return [
            $this->viewerAtSite($site, [
                'securityDevices.events.view',
                'securityDevices.integrations.view',
                'securityDevices.integrations.manage',
                'securityDevices.monitoring.manage',
            ]),
            $site,
            $this->deviceAtSite($site, 'SD-WAN gateway'),
        ];
    }

    private function viewerAtSite(Site $site, array $permissions): User
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $overrides = Permission::query()
            ->whereIn('key', $permissions)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $viewer->permissionOverrides()->syncWithoutDetaching($overrides);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);

        return $viewer;
    }

    private function activeSite(string $name): Site
    {
        return Site::factory()->create([
            'name' => $name,
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
    }

    private function deviceAtSite(Site $site, string $name): Device
    {
        $device = Device::factory()->create(['name' => $name, 'status' => 'active']);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now()->subMinute(),
        ]);

        return $device;
    }
}
