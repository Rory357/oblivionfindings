<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetOwnership;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\FleetFuelLog;
use App\Models\FleetTrip;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Assets\AssetAssignmentService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AssetMutationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $hiddenSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->hiddenSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    }

    public function test_asset_register_create_update_and_picker_data_are_canonical_site_scoped(): void
    {
        $actor = $this->actor([
            'assets.viewAny',
            'assets.create',
            'assets.update',
            'clients.viewAny',
        ]);
        $visible = Asset::factory()->forSite($this->site)->create([
            'name' => 'Visible Asset',
            'home_site_id' => $this->hiddenSite->id,
        ]);
        $hidden = Asset::factory()->forSite($this->hiddenSite)->create(['name' => 'Hidden Asset']);
        $hiddenClient = Client::factory()->create(['site_id' => $this->hiddenSite->id]);

        $this->actingAs($actor)
            ->get('/fleet-assets/assets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assets.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$visible->id])
                ->where('assets.data.0.home_site', null)
                ->where('sites', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$this->site->id])
                ->where('clients', [])
            );

        $this->actingAs($actor)
            ->get("/fleet-assets/assets/{$visible->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('asset.home_site_id', null)
                ->where('asset.home_site', null));

        $this->actingAs($actor)
            ->get("/fleet-assets/assets?site_id={$this->hiddenSite->id}")
            ->assertNotFound();
        $this->actingAs($actor)
            ->get("/fleet-assets/assets/{$hidden->id}")
            ->assertNotFound();
        $this->actingAs($actor)
            ->get("/assets/{$hidden->id}/qr.svg")
            ->assertNotFound();

        $this->assertTrue(Gate::forUser($actor)->allows('view', $visible));
        $this->assertTrue(Gate::forUser($actor)->allows('update', $visible));
        $this->assertFalse(Gate::forUser($actor)->allows('view', $hidden));
        $this->assertSame(404, Gate::forUser($actor)->inspect('view', $hidden)->status());
        $this->assertFalse(Gate::forUser($actor)->allows('update', $hidden));

        $this->actingAs($actor)
            ->post('/fleet-assets/assets', $this->assetPayload([
                'name' => 'Forbidden create',
                'site_id' => $this->hiddenSite->id,
            ]))
            ->assertNotFound();
        $this->actingAs($actor)
            ->post('/fleet-assets/assets', $this->assetPayload([
                'name' => 'Retired creation bypass',
                'status' => 'retired',
            ]))
            ->assertSessionHasErrors('status');
        $this->actingAs($actor)
            ->post('/fleet-assets/assets', $this->assetPayload([
                'name' => 'Forbidden client create',
                'site_id' => null,
                'client_id' => $hiddenClient->id,
            ]))
            ->assertNotFound();
        $this->actingAs($actor)
            ->put("/fleet-assets/assets/{$hidden->id}", $this->assetPayload(['name' => 'Leaked update']))
            ->assertNotFound();
        $this->actingAs($actor)
            ->put("/fleet-assets/assets/{$visible->id}", $this->assetPayload([
                'name' => 'Forbidden move',
                'site_id' => $this->hiddenSite->id,
            ]))
            ->assertNotFound();

        $this->assertDatabaseMissing('assets', ['name' => 'Forbidden create']);
        $this->assertDatabaseMissing('assets', ['name' => 'Retired creation bypass']);
        $this->assertDatabaseMissing('assets', ['name' => 'Forbidden client create']);
        $this->assertSame('Visible Asset', $visible->fresh()->name);
        $this->assertSame('Hidden Asset', $hidden->fresh()->name);
    }

    public function test_assigned_only_asset_register_rows_exports_and_counts_follow_the_asset_policy(): void
    {
        $secondAccessibleSite = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $actor = $this->actor(['assets.viewAssigned']);
        $actor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $actor->hrEmployeeProfile()->firstOrFail()->update([
            'secondary_site_ids' => [$secondAccessibleSite->id],
        ]);

        $assignedClient = Client::factory()->create(['site_id' => $this->site->id]);
        $assignedClient->supportWorkers()->attach($actor->id);
        $visible = Asset::factory()->forSite($this->site)->create([
            'client_id' => $assignedClient->id,
            'name' => 'Assigned quarantined asset',
            'serial_number' => 'ASSIGNED-POLICY-SERIAL',
            'status' => 'out_of_service',
        ]);
        $unassigned = Asset::factory()->forSite($secondAccessibleSite)->create([
            'name' => 'Unassigned Site inventory',
            'serial_number' => 'UNASSIGNED-SITE-SERIAL',
        ]);

        $this->actingAs($actor)
            ->get('/fleet-assets/assets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assets.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$visible->id])
                ->where('assets.meta.total', 1)
                ->where('hero.total', 1)
                ->where('hero.active', 0)
                ->where('hero.maintenance', 1));

        $csv = $this->actingAs($actor)
            ->get('/fleet-assets/assets?export=csv')
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('ASSIGNED-POLICY-SERIAL', $csv);
        $this->assertStringNotContainsString('UNASSIGNED-SITE-SERIAL', $csv);
        $this->actingAs($actor)
            ->get("/fleet-assets/assets/{$unassigned->id}")
            ->assertNotFound();

        $applicationWideViewer = $this->actor(['assets.viewAny']);
        $applicationWideViewer->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->actingAs($applicationWideViewer)
            ->get('/fleet-assets/assets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assets.data', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$visible->id, $unassigned->id])->sort()->values()->all())
                ->where('assets.meta.total', 2)
                ->where('hero.total', 2));
    }

    public function test_asset_access_uses_direct_then_home_then_client_site_provenance_and_denies_conflicts(): void
    {
        $actor = $this->actor(['assets.viewAny']);
        $localClient = Client::factory()->create(['site_id' => $this->site->id]);
        $hiddenClient = Client::factory()->create(['site_id' => $this->hiddenSite->id]);

        $directLocal = Asset::factory()->create([
            'site_id' => $this->site->id,
            'home_site_id' => $this->hiddenSite->id,
            'client_id' => null,
        ]);
        $directHiddenWithLocalFallbacks = Asset::factory()->create([
            'site_id' => $this->hiddenSite->id,
            'home_site_id' => $this->site->id,
            'client_id' => $localClient->id,
        ]);
        $directLocalWithConflictingClient = Asset::factory()->create([
            'site_id' => $this->site->id,
            'home_site_id' => null,
            'client_id' => $hiddenClient->id,
        ]);
        $homeLocal = Asset::factory()->create([
            'site_id' => null,
            'home_site_id' => $this->site->id,
            'client_id' => $localClient->id,
        ]);
        $homeHiddenWithLocalClient = Asset::factory()->create([
            'site_id' => null,
            'home_site_id' => $this->hiddenSite->id,
            'client_id' => $localClient->id,
        ]);
        $clientLocalFallback = Asset::factory()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => $localClient->id,
        ]);
        $unattributed = Asset::factory()->create([
            'site_id' => null,
            'home_site_id' => null,
            'client_id' => null,
        ]);

        $access = app(SecurityDevicesAccessService::class);
        $expectedIds = collect([$directLocal->id, $homeLocal->id, $clientLocalFallback->id])
            ->sort()
            ->values()
            ->all();
        $this->assertSame($expectedIds, $access->accessibleAssets($actor)->pluck('id')->sort()->values()->all());

        foreach ([
            $directLocal,
            $directHiddenWithLocalFallbacks,
            $directLocalWithConflictingClient,
            $homeLocal,
            $homeHiddenWithLocalClient,
            $clientLocalFallback,
            $unattributed,
        ] as $asset) {
            $expected = in_array($asset->id, $expectedIds, true);
            $this->assertSame($expected, Gate::forUser($actor)->allows('view', $asset));
            $this->assertSame($expected, $access->assignableAsset($actor, $asset->id) !== null);
        }

        foreach ([
            $directHiddenWithLocalFallbacks,
            $directLocalWithConflictingClient,
            $homeHiddenWithLocalClient,
            $unattributed,
        ] as $hiddenAsset) {
            $this->assertNull($access->assignableAsset($actor, $hiddenAsset->id));
        }
    }

    public function test_every_asset_object_ability_reuses_the_canonical_visibility_boundary(): void
    {
        $actor = $this->actor([
            'assets.viewAny',
            'assets.update',
            'assets.delete',
            'assets.inspections.record',
            'assets.maintenance.record',
            'assets.documents.manage',
            'assets.ownership.manage',
            'assets.assignments.manage',
            'assets.geofences.manage',
            'assets.scan.record',
        ]);
        $visible = Asset::factory()->forSite($this->site)->create();
        $hidden = Asset::factory()->forSite($this->hiddenSite)->create();

        foreach ([
            'view',
            'update',
            'delete',
            'recordInspection',
            'recordMaintenance',
            'manageDocuments',
            'manageOwnership',
            'manageAssignments',
            'manageGeofences',
            'recordScan',
        ] as $ability) {
            $this->assertTrue(Gate::forUser($actor)->allows($ability, $visible), "{$ability} should allow the visible Asset.");
            $this->assertFalse(Gate::forUser($actor)->allows($ability, $hidden), "{$ability} should deny the hidden Asset.");
            $this->assertSame(404, Gate::forUser($actor)->inspect($ability, $hidden)->status());
        }
    }

    public function test_fleet_write_routes_conceal_hidden_vehicles_and_bulk_work_is_atomic(): void
    {
        $actor = $this->actor(['fleet.viewAny', 'fleet.manage']);
        $visible = Asset::factory()->vehicle()->forSite($this->site)->create(['name' => 'Visible Van']);
        $hidden = Asset::factory()->vehicle()->forSite($this->hiddenSite)->create(['name' => 'Hidden Van']);
        $trip = FleetTrip::query()->create([
            'asset_id' => $hidden->id,
            'started_at' => now()->subHour(),
            'status' => 'completed',
            'is_personal' => false,
        ]);

        $this->actingAs($actor)
            ->put("/fleet-assets/vehicles/{$hidden->id}", ['name' => 'Leaked Van'])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post('/fleet-assets/fuel', [
                'asset_id' => $hidden->id,
                'logged_at' => today()->toDateString(),
                'quantity_litres' => 20,
                'total_cost' => 50,
            ])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post('/fleet-assets/vehicles/bulk-action', [
                'action' => 'assign_site',
                'ids' => [$visible->id, $hidden->id],
                'site_id' => $this->site->id,
            ])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post("/fleet-assets/trips/{$trip->id}/toggle-personal")
            ->assertNotFound();
        $this->actingAs($actor)
            ->post("/fleet-assets/vehicles/{$hidden->id}/alerts-config", ['config' => ['speed' => 80]])
            ->assertNotFound();

        $this->assertSame('Visible Van', $visible->fresh()->name);
        $this->assertSame('Hidden Van', $hidden->fresh()->name);
        $this->assertFalse((bool) $trip->fresh()->is_personal);
        $this->assertSame(0, FleetFuelLog::query()->count());
    }

    public function test_ordinary_edits_cannot_retire_assets_or_move_live_assignment_and_device_provenance(): void
    {
        $targetSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $actor = $this->actor(['assets.viewAny', 'assets.update', 'fleet.viewAny', 'fleet.manage']);
        $actor->hrEmployeeProfile()->firstOrFail()->update(['secondary_site_ids' => [$targetSite->id]]);
        $asset = Asset::factory()->vehicle()->forSite($this->site)->create([
            'home_site_id' => $this->site->id,
            'status' => 'active',
        ]);
        $assignment = AssetAssignment::query()->create([
            'asset_id' => $asset->id,
            'assignee_type' => 'staff',
            'assignee_id' => $actor->id,
            'assigned_at' => now()->subDay(),
        ]);
        $device = Device::factory()->itInfrastructure()->create();
        $link = DeviceAssetLink::query()->create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => 'installed_in',
            'linked_at' => now()->subDay(),
        ]);

        $this->actingAs($actor)
            ->put("/fleet-assets/assets/{$asset->id}", $this->assetPayload([
                'name' => $asset->name,
                'status' => 'retired',
            ]))
            ->assertSessionHasErrors('status');
        $this->actingAs($actor)
            ->put("/fleet-assets/vehicles/{$asset->id}", ['status' => 'retired'])
            ->assertSessionHasErrors('status');
        $this->actingAs($actor)
            ->put("/fleet-assets/assets/{$asset->id}", $this->assetPayload([
                'name' => $asset->name,
                'site_id' => $targetSite->id,
                'home_site_id' => $targetSite->id,
            ]))
            ->assertSessionHasErrors('site_id');
        $this->actingAs($actor)
            ->post('/fleet-assets/vehicles/bulk-action', [
                'action' => 'assign_site',
                'ids' => [$asset->id],
                'site_id' => $targetSite->id,
            ])
            ->assertSessionHasErrors('site_id');

        $this->assertSame('active', $asset->fresh()->status);
        $this->assertSame($this->site->id, $asset->site_id);
        $assignment->update(['released_at' => now()]);
        $link->update(['unlinked_at' => now()]);

        $this->actingAs($actor)
            ->post('/fleet-assets/vehicles/bulk-action', [
                'action' => 'assign_site',
                'ids' => [$asset->id],
                'site_id' => $targetSite->id,
            ])
            ->assertRedirect();

        $asset->refresh();
        $this->assertSame($targetSite->id, $asset->site_id);
        $this->assertSame($targetSite->id, $asset->home_site_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fleet.vehicle.site_assigned',
            'auditable_id' => $asset->id,
        ]);
    }

    public function test_bulk_offline_state_and_mutation_audits_are_durable_per_vehicle(): void
    {
        $actor = $this->actor(['fleet.viewAny', 'fleet.manage']);
        $first = Asset::factory()->vehicle()->forSite($this->site)->create();
        $second = Asset::factory()->vehicle()->forSite($this->site)->create();

        $this->actingAs($actor)
            ->post('/fleet-assets/vehicles/bulk-action', [
                'action' => 'mark_offline',
                'ids' => [$second->id, $first->id],
            ])
            ->assertRedirect();

        $this->assertSame('offline', FleetVehicleStateSnapshot::query()->findOrFail($first->id)->status);
        $this->assertSame('offline', FleetVehicleStateSnapshot::query()->findOrFail($second->id)->status);
        $this->assertSame(2, AuditLog::query()
            ->where('action', 'fleet.vehicle.marked_offline')
            ->whereIn('auditable_id', [$first->id, $second->id])
            ->count());
    }

    public function test_asset_assignment_requires_one_site_valid_active_holder_and_explicit_release(): void
    {
        $actor = $this->actor([
            'assets.viewAny',
            'assets.assignments.manage',
            'staff.viewAny',
            'hazards.view',
            'clients.viewAny',
        ]);
        $asset = Asset::factory()->forSite($this->site)->create();
        $staff = $this->staffAt($this->site);
        $secondStaff = $this->staffAt($this->site);
        $hiddenStaff = $this->staffAt($this->hiddenSite);

        $this->actingAs($actor)
            ->post("/assets/{$asset->id}/assignments", [
                'assignee_type' => 'staff',
                'assignee_id' => $staff->id,
                'assigned_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertSessionHasErrors('assigned_at');

        $this->actingAs($actor)
            ->post("/assets/{$asset->id}/assignments", [
                'assignee_type' => 'staff',
                'assignee_id' => $hiddenStaff->id,
            ])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post("/assets/{$asset->id}/assignments", [
                'assignee_type' => 'staff',
                'assignee_id' => $staff->id,
            ])
            ->assertRedirect();
        $assignment = AssetAssignment::query()->where('asset_id', $asset->id)->firstOrFail();

        $this->actingAs($actor)
            ->post("/assets/{$asset->id}/assignments", [
                'assignee_type' => 'staff',
                'assignee_id' => $secondStaff->id,
            ])
            ->assertSessionHasErrors('assignee_id');
        $this->assertSame(1, AssetAssignment::query()->where('asset_id', $asset->id)->whereNull('released_at')->count());

        $this->actingAs($actor)
            ->post("/assets/{$asset->id}/assignments/{$assignment->id}/release")
            ->assertRedirect();
        $this->actingAs($actor)
            ->post("/assets/{$asset->id}/assignments", [
                'assignee_type' => 'staff',
                'assignee_id' => $secondStaff->id,
            ])
            ->assertRedirect();

        $this->assertSame(2, AssetAssignment::query()->where('asset_id', $asset->id)->count());
        $this->assertSame(1, AssetAssignment::query()->where('asset_id', $asset->id)->whereNull('released_at')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'assets.assignment.created', 'auditable_id' => $asset->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'assets.assignment.released', 'auditable_id' => $asset->id]);
    }

    public function test_competing_custody_transfers_are_serialized_and_preserve_assignment_history(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $actor = $this->actor([
            'assets.viewAny',
            'assets.assignments.manage',
            'staff.viewAny',
            'hazards.view',
        ]);
        $asset = Asset::factory()->forSite($this->site)->create();
        $firstStaff = $this->staffAt($this->site);
        $secondStaff = $this->staffAt($this->site);
        $database = $connection->getDatabaseName();
        $token = Str::uuid()->toString();
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."asset-custody-release-{$token}";
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."asset-custody-ready-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."asset-custody-ready-b-{$token}",
        ];
        $attemptPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."asset-custody-attempt-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."asset-custody-attempt-b-{$token}",
        ];
        $processes = [];
        $userIds = [$actor->id, $firstStaff->id, $secondStaff->id];

        // RefreshDatabase owns the outer transaction. Commit the fixtures so
        // independent workers can see them, then hold the canonical Asset row
        // while both custody requests queue behind the same FOR UPDATE lock.
        $connection->commit();

        try {
            $connection->beginTransaction();
            Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            $processes[] = $this->startAssetCustodyWorker(
                $asset->id,
                $actor->id,
                $firstStaff->id,
                $readyPaths[0],
                $attemptPaths[0],
                $releasePath,
                $database,
            );
            $processes[] = $this->startAssetCustodyWorker(
                $asset->id,
                $actor->id,
                $secondStaff->id,
                $readyPaths[1],
                $attemptPaths[1],
                $releasePath,
                $database,
            );

            $this->waitForAssetCustodyFiles($readyPaths, 'Both custody workers did not become ready.');
            touch($releasePath);
            $this->waitForAssetCustodyFiles($attemptPaths, 'Both custody workers did not reach the service call.');
            usleep(250_000);

            foreach ($processes as $process) {
                $this->assertTrue(
                    $process->isRunning(),
                    trim($process->getErrorOutput()) ?: 'A custody worker exited before the Asset row lock was released.',
                );
            }

            $connection->commit();

            $results = collect($processes)->map(function (Process $process): array {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'An Asset custody worker failed.',
                );

                return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            });

            $this->assertSame(['assigned', 'rejected'], $results->pluck('status')->sort()->values()->all());
            $this->assertSame(1, AssetAssignment::query()->where('asset_id', $asset->id)->whereNull('released_at')->count());

            $current = AssetAssignment::query()
                ->where('asset_id', $asset->id)
                ->whereNull('released_at')
                ->firstOrFail();
            $losingStaffId = $current->assignee_id === $firstStaff->id ? $secondStaff->id : $firstStaff->id;
            $service = app(AssetAssignmentService::class);
            $service->release($actor->fresh(), $asset->fresh(), $current);
            $service->assign($actor->fresh(), $asset->fresh(), [
                'assignee_type' => 'staff',
                'assignee_id' => $losingStaffId,
            ]);

            $this->assertSame(2, AssetAssignment::query()->where('asset_id', $asset->id)->count());
            $this->assertSame(1, AssetAssignment::query()->where('asset_id', $asset->id)->whereNull('released_at')->count());
            $this->assertSame(1, AssetAssignment::query()->where('asset_id', $asset->id)->whereNotNull('released_at')->count());
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            foreach ([...$readyPaths, ...$attemptPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            try {
                DB::table('asset_assignments')->where('asset_id', $asset->id)->delete();
                DB::table('audit_logs')->where('auditable_id', $asset->id)->where('action', 'like', 'assets.assignment.%')->delete();
                DB::table('assets')->where('id', $asset->id)->delete();
                DB::table('permission_user')->whereIn('user_id', $userIds)->delete();
                DB::table('role_user')->whereIn('user_id', $userIds)->delete();
                DB::table('hr_employee_profiles')->whereIn('user_id', $userIds)->delete();
                DB::table('users')->whereIn('id', $userIds)->delete();
                DB::table('sites')->where('id', $this->site->id)->delete();
            } finally {
                $connection->beginTransaction();
            }
        }
    }

    public function test_asset_ownership_transition_validates_target_and_retains_history(): void
    {
        $actor = $this->actor(['assets.viewAny', 'assets.ownership.manage', 'clients.viewAny']);
        $asset = Asset::factory()->forSite($this->site)->create();
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $hiddenClient = Client::factory()->create(['site_id' => $this->hiddenSite->id]);

        $this->actingAs($actor)
            ->post("/assets/{$asset->id}/ownerships", [
                'owner_type' => 'client',
                'owner_id' => $hiddenClient->id,
            ])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post("/assets/{$asset->id}/ownerships", [
                'owner_type' => 'organisation',
                'owner_id' => 999,
            ])
            ->assertSessionHasErrors('owner_type');
        $this->actingAs($actor)
            ->post("/assets/{$asset->id}/ownerships", [
                'owner_type' => 'site',
                'owner_id' => $this->site->id,
                'effective_from' => now()->subDay()->toIso8601String(),
            ])
            ->assertRedirect();
        $this->actingAs($actor)
            ->post("/assets/{$asset->id}/ownerships", [
                'owner_type' => 'client',
                'owner_id' => $client->id,
                'effective_from' => now()->subDays(2)->toIso8601String(),
            ])
            ->assertSessionHasErrors('effective_from');
        $this->actingAs($actor)
            ->post("/assets/{$asset->id}/ownerships", [
                'owner_type' => 'client',
                'owner_id' => $client->id,
                'effective_from' => now()->addDay()->toIso8601String(),
            ])
            ->assertSessionHasErrors('effective_from');
        $this->actingAs($actor)
            ->post("/assets/{$asset->id}/ownerships", [
                'owner_type' => 'client',
                'owner_id' => $client->id,
            ])
            ->assertRedirect();

        $this->assertSame(2, AssetOwnership::query()->where('asset_id', $asset->id)->count());
        $this->assertSame(1, AssetOwnership::query()->where('asset_id', $asset->id)->whereNull('effective_to')->count());
        $history = AssetOwnership::query()->where('asset_id', $asset->id)->orderBy('effective_from')->get();
        $this->assertTrue($history[0]->effective_to->equalTo($history[1]->effective_from));
        $this->assertSame(2, AuditLog::query()
            ->where('action', 'assets.ownership.changed')
            ->where('auditable_id', $asset->id)
            ->count());
    }

    public function test_asset_delete_route_retires_and_preserves_lifecycle_history(): void
    {
        $actor = $this->actor(['assets.viewAny', 'assets.delete']);
        $asset = Asset::factory()->forSite($this->site)->create(['status' => 'active']);
        $assignment = AssetAssignment::query()->create([
            'asset_id' => $asset->id,
            'assignee_type' => 'staff',
            'assignee_id' => $actor->id,
            'assigned_at' => now()->subDay(),
        ]);
        AssetOwnership::query()->create([
            'asset_id' => $asset->id,
            'owner_type' => 'site',
            'owner_id' => $this->site->id,
            'effective_from' => now()->subMonth(),
        ]);

        $this->actingAs($actor)
            ->delete("/assets/{$asset->id}")
            ->assertSessionHasErrors('asset');
        $assignment->update(['released_at' => now()]);

        $device = Device::factory()->itInfrastructure()->create();
        $link = DeviceAssetLink::query()->create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => 'installed_in',
            'linked_at' => now(),
        ]);
        $this->actingAs($actor)
            ->delete("/assets/{$asset->id}")
            ->assertSessionHasErrors('asset');
        $link->update(['unlinked_at' => now()]);

        $this->actingAs($actor)
            ->delete("/assets/{$asset->id}")
            ->assertRedirect(route('fleet-assets.assets.index'));

        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'status' => 'retired']);
        $this->assertDatabaseHas('asset_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseHas('asset_ownerships', ['asset_id' => $asset->id]);
        $this->assertDatabaseHas('device_asset_links', ['id' => $link->id]);
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subYear(),
            'end_date' => null,
        ]);

        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['description' => $key, 'group' => str($key)->before('.')->toString()],
            );
            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }

    private function staffAt(Site $site): User
    {
        $staff = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $staff->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subMonth(),
            'end_date' => null,
        ]);

        return $staff;
    }

    private function startAssetCustodyWorker(
        int $assetId,
        int $actorId,
        int $staffId,
        string $readyPath,
        string $attemptPath,
        string $releasePath,
        string $database,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$asset = App\Models\Asset::query()->findOrFail((int) $argv[2]);
$actor = App\Models\User::query()->findOrFail((int) $argv[3]);
$staffId = (int) $argv[4];
file_put_contents($argv[5], (string) Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
$deadline = microtime(true) + 15;
while (! is_file($argv[7])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the custody concurrency release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[6], 'attempting');
try {
    $assignment = $app->make(App\Services\Assets\AssetAssignmentService::class)->assign($actor, $asset, [
        'assignee_type' => 'staff',
        'assignee_id' => $staffId,
    ]);
    $result = ['status' => 'assigned', 'assignment_id' => $assignment->id, 'assignee_id' => $staffId];
} catch (Illuminate\Validation\ValidationException $exception) {
    $result = ['status' => 'rejected', 'assignee_id' => $staffId];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                (string) $assetId,
                (string) $actorId,
                (string) $staffId,
                $readyPath,
                $attemptPath,
                $releasePath,
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => $database,
                'QUEUE_CONNECTION' => 'sync',
            ],
        );
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /** @param list<string> $paths */
    private function waitForAssetCustodyFiles(array $paths, string $message): void
    {
        $deadline = microtime(true) + 15;

        do {
            if (collect($paths)->every(fn (string $path): bool => is_file($path))) {
                return;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException($message);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function assetPayload(array $overrides = []): array
    {
        return [
            'name' => 'Boundary Asset',
            'site_id' => $this->site->id,
            'status' => 'active',
            'risk_level' => 'low',
            'requires_inspection' => false,
            'requires_maintenance' => false,
            ...$overrides,
        ];
    }
}
