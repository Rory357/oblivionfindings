<?php

// Corrected-candidate privacy regressions plus Main's original missing-source case.
// No owner-preview or operational database is used.
$_SERVER['TEST_TOKEN'] = (string) getmypid();
require_once getcwd().'/tests/Feature/FleetAssets/Pkg07MapPrivacyTest.php';

final class PKG07MainCorrectionTest extends \Tests\Feature\FleetAssets\Pkg07MapPrivacyTest
{
    protected function configureIsolatedTestingDatabase(): void
    {
        static::$testDatabaseBaseName = 'of_main07_correction_20260927';
        parent::configureIsolatedTestingDatabase();
    }

    protected function pruneStaleIsolatedDatabases(PDO $pdo, string $baseName, string $currentDatabase): void {}

    protected function clearTestingMaintenanceMode(): void {}

    protected function loadSchemaDumpIntoTestingDatabase(string $host, string $port, string $username, string $password, string $database): bool
    {
        return $this->loadSchemaDumpViaPdo(getcwd().'/database/schema/mysql-schema.sql', $host, $port, $username, $password, $database);
    }

    public function test_main_original_missing_boundary_removal_regression(): void
    {
        $site = \App\Models\Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = \App\Models\User::factory()->create([
            'email' => 'main07-'.\Illuminate\Support\Str::uuid().'@example.test',
            'approved_at' => now(), 'role' => 'support_worker',
        ]);
        \App\Domain\Hr\Models\HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
        ]);
        foreach (['fleet.viewAny', 'fleet.manage'] as $key) {
            $permission = \App\Models\Permission::firstOrCreate(['key' => $key], [
                'description' => $key, 'group' => 'test', 'module' => 'Test',
            ]);
            $user->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }
        $vehicle = \App\Models\Asset::factory()->vehicle()->forSite($site)->create(['client_id' => null]);
        $assignment = \App\Models\FleetVehicleGeofenceAssignment::create([
            'asset_id' => $vehicle->id, 'geofence_id' => null, 'label' => 'Retained former boundary',
            'origin' => 'linked', 'geometry_hash' => str_repeat('a', 64),
            'geometry_snapshot' => ['type' => 'circle', 'center' => ['lat' => -41.29, 'lng' => 174.77], 'radius_m' => 180],
            'monitoring' => 'inactive', 'state' => 'active', 'lock_version' => 1,
            'created_by_user_id' => $user->id, 'updated_by_user_id' => $user->id,
        ]);
        $location = $this->actingAs($user)->getJson('/fleet-assets/vehicles/'.$vehicle->id.'/location')->assertOk();
        $this->putJson('/fleet-assets/vehicles/'.$vehicle->id.'/geofences/selection', [
            'keep_assignment_ids' => [], 'add_geofence_ids' => [],
            'expected_version' => $location->json('geofences.version'),
        ])->assertOk();
        $this->assertSame('removed', $assignment->fresh()->state);
        $this->assertNotNull($assignment->fresh()->geometry_snapshot);
        $version = \Illuminate\Support\Facades\DB::table('boundary_rule_versions')->where('assignment_id', $assignment->id)->sole();
        $snapshot = json_decode($version->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertNull($version->boundary_id);
        $this->assertSame($vehicle->id, $snapshot['asset_id']);
        $this->assertSame('removed', $snapshot['state']);
        $this->assertSame($user->id, $snapshot['removed_by_user_id']);
        $this->assertSame($assignment->geometry_snapshot, $snapshot['geometry_snapshot']);
    }
}
