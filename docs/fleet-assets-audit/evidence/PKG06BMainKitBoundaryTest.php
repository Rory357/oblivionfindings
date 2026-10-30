<?php

// Corrected Main probe: include the ordinary editor's required status/risk fields
// and first prove that the same ordinary edit contract succeeds before kit linking.
$_SERVER['TEST_TOKEN'] = (string) getmypid();
require_once getcwd().'/tests/Feature/FleetAssets/Pkg06bAssetProfileTest.php';

final class PKG06BMainKitBoundaryTest extends \Tests\Feature\FleetAssets\Pkg06bAssetProfileTest
{
    protected function configureIsolatedTestingDatabase(): void
    {
        static::$testDatabaseBaseName = 'of_main06b_kit_20260927';
        parent::configureIsolatedTestingDatabase();
    }

    protected function pruneStaleIsolatedDatabases(PDO $pdo, string $baseName, string $currentDatabase): void {}

    protected function clearTestingMaintenanceMode(): void {}

    protected function loadSchemaDumpIntoTestingDatabase(string $host, string $port, string $username, string $password, string $database): bool
    {
        return $this->loadSchemaDumpViaPdo(getcwd().'/database/schema/mysql-schema.sql', $host, $port, $username, $password, $database);
    }

    private function fixtureValue(string $name): mixed
    {
        return (new ReflectionProperty(\Tests\Feature\FleetAssets\Pkg06bAssetProfileTest::class, $name))->getValue($this);
    }

    public function test_main_valid_ordinary_component_edit_cannot_split_an_active_kit(): void
    {
        $parent = $this->fixtureValue('asset');
        $origin = $this->fixtureValue('origin');
        $destination = $this->fixtureValue('destination');
        $manager = $this->fixtureValue('manager');
        $component = \App\Models\Asset::factory()->forSite($origin)->create([
            'category' => 'equipment', 'status' => 'active', 'risk_level' => 'low', 'home_site_id' => $origin->id,
        ]);
        $base = ['name' => $component->name, 'category' => 'equipment', 'status' => 'active', 'risk_level' => 'low'];
        $this->actingAs($manager)->putJson('/fleet-assets/assets/'.$component->id, $base + [
            'site_id' => $origin->id, 'home_site_id' => $origin->id,
            'expected_version' => $component->fresh()->asset_profile_version,
        ])->assertRedirect('/fleet-assets/assets/'.$component->id);
        $this->postJson('/assets/'.$parent->id.'/profile-actions', [
            'action' => 'kit_add', 'component_asset_id' => $component->id, 'name' => 'Linked equipment',
            'reason' => 'Main synthetic kit fixture', 'request_key' => (string) \Illuminate\Support\Str::uuid(),
            'expected_version' => $parent->fresh()->asset_profile_version,
        ])->assertOk();
        $response = $this->putJson('/fleet-assets/assets/'.$component->id, $base + [
            'site_id' => $destination->id, 'home_site_id' => $destination->id,
            'expected_version' => $component->fresh()->asset_profile_version,
        ]);
        $this->assertSame([
            'status' => 422, 'component_site' => $origin->id, 'parent_site' => $origin->id, 'active_kit_links' => 1,
        ], [
            'status' => $response->status(), 'component_site' => $component->fresh()->site_id,
            'parent_site' => $parent->fresh()->site_id,
            'active_kit_links' => \App\Models\AssetKitItem::where('asset_id', $parent->id)->where('component_asset_id', $component->id)->whereNull('removed_at')->count(),
        ], 'A complete, otherwise valid ordinary edit must not split a currently linked kit.');
    }
}
