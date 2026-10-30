<?php

// Main-owned synthetic probes. Run from the exact submitted candidate checkout.
require_once getcwd().'/tests/Feature/FleetAssets/Pkg06bAssetProfileTest.php';

final class PKG06BMainBoundaryTest extends \Tests\Feature\FleetAssets\Pkg06bAssetProfileTest
{
    protected function configureIsolatedTestingDatabase(): void
    {
        static::$testDatabaseBaseName = 'of_main06b_boundary_20260927';
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

    public function test_main_ordinary_component_edit_cannot_split_an_active_kit(): void
    {
        $parent = $this->fixtureValue('asset');
        $origin = $this->fixtureValue('origin');
        $destination = $this->fixtureValue('destination');
        $manager = $this->fixtureValue('manager');
        $component = \App\Models\Asset::factory()->forSite($origin)->create([
            'category' => 'equipment', 'status' => 'active', 'home_site_id' => $origin->id,
        ]);
        $this->actingAs($manager)->postJson('/assets/'.$parent->id.'/profile-actions', [
            'action' => 'kit_add', 'component_asset_id' => $component->id, 'name' => 'Linked equipment',
            'reason' => 'Main synthetic kit fixture', 'request_key' => (string) \Illuminate\Support\Str::uuid(),
            'expected_version' => $parent->fresh()->asset_profile_version,
        ])->assertOk();

        $response = $this->putJson('/fleet-assets/assets/'.$component->id, [
            'name' => $component->name, 'site_id' => $destination->id,
            'home_site_id' => $destination->id, 'expected_version' => $component->fresh()->asset_profile_version,
        ]);
        $this->assertSame([
            'status' => 422, 'component_site' => $origin->id, 'parent_site' => $origin->id,
            'active_kit_links' => 1,
        ], [
            'status' => $response->status(), 'component_site' => $component->fresh()->site_id,
            'parent_site' => $parent->fresh()->site_id,
            'active_kit_links' => \App\Models\AssetKitItem::where('asset_id', $parent->id)->where('component_asset_id', $component->id)->whereNull('removed_at')->count(),
        ], 'Ordinary register edits must not bypass the governed kit movement/receipt boundary.');
    }

    public function test_main_profile_withholds_reviews_outside_finance_site_scope(): void
    {
        $parent = $this->fixtureValue('asset');
        $origin = $this->fixtureValue('origin');
        $destination = $this->fixtureValue('destination');
        $manager = $this->fixtureValue('manager');
        $makeUser = new ReflectionMethod(\Tests\Feature\FleetAssets\Pkg06bAssetProfileTest::class, 'user');
        $viewer = $makeUser->invoke($this, [$destination], ['assets.viewAny', 'finance.assets.view', 'securityDevices.devices.viewAllSites']);
        $permission = \App\Models\Permission::firstOrCreate(['key' => 'finance.assets.view'], ['description' => 'Finance assets', 'group' => 'finance']);
        $manager->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $request = $this->actingAs($manager->fresh())->postJson('/assets/'.$parent->id.'/finance-review', [
            'request_type' => 'fixed_asset_update', 'source' => 'vehicle', 'note' => 'Site-specific Finance review note',
            'amount' => '125.50', 'request_key' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertOk();
        $this->assertNotContains($origin->id, app(\App\Services\Fleet\VehicleFinanceService::class)->financeSiteIds($viewer));
        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($viewer)->allows('view', $parent));
        $queue = app(\App\Services\Fleet\VehicleFinanceReviewQueue::class)->present($viewer, [], $request->json('id'));
        $this->assertNull($queue['focus']);
        $this->actingAs($viewer)->get('/fleet-assets/assets/'.$parent->id)->assertOk()->assertInertia(
            fn (\Inertia\Testing\AssertableInertia $page) => $page->has('workspace.finance_reviews', 0)
        );
    }
}
