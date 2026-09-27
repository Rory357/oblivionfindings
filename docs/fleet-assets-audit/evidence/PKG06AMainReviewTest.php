<?php

// Reproduction-only probes against the candidate application; no application code is copied.
// Run from checkout6421 with its phpunit.xml, autoloader and schema-only test snapshot.
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PKG06AMainReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Site $site;
    private SiteRoom $room;
    private HrEmployeeProfile $profile;

    protected function configureIsolatedTestingDatabase(): void
    {
        static::$testDatabaseBaseName = 'of_main06a_review_20260927';
        parent::configureIsolatedTestingDatabase();
    }

    protected function pruneStaleIsolatedDatabases(PDO $pdo, string $baseName, string $currentDatabase): void {}

    protected function clearTestingMaintenanceMode(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->room = SiteRoom::create(['site_id' => $this->site->id, 'name' => 'Synthetic original-site room']);
        $this->actor = User::factory()->create(['approved_at' => now()]);
        $this->profile = HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null]);
        foreach (['assets.viewAny', 'assets.scan.record', 'assets.create', 'assets.update'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'assets']);
            $this->actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        $this->actingAs($this->actor);
    }

    public function test_reproduces_undone_extra_asset_disclosure_after_its_site_changes(): void
    {
        $expected = Asset::factory()->forSite($this->site)->create(['site_room_id' => $this->room->id, 'status' => 'active']);
        $extra = Asset::factory()->forSite($this->site)->create(['site_room_id' => $this->room->id, 'name' => 'SYNTHETIC HIDDEN EXTRA', 'status' => 'active']);
        $count = $this->postJson('/fleet-assets/asset-register/stocktakes', ['request_id' => (string) Str::uuid(),
            'site_id' => $this->site->id, 'site_room_id' => $this->room->id, 'asset_ids' => [$expected->id]])->assertCreated()->json();
        $url = '/fleet-assets/asset-register/stocktakes/'.$count['id'];
        foreach ([
            ['action' => 'found', 'key' => 'asset-'.$extra->id, 'asset_id' => $extra->id, 'confirm_extra' => true],
            ['action' => 'undo', 'key' => 'asset-'.$extra->id],
            ['action' => 'found', 'key' => 'asset-'.$expected->id],
            ['action' => 'finish'],
        ] as $command) {
            $count = $this->patchJson($url, [...$command, 'version' => $count['version'], 'command_id' => (string) Str::uuid()])->assertOk()->json();
        }
        $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $extra->update(['site_id' => $otherSite->id, 'site_room_id' => null]);
        $this->get('/fleet-assets/assets/'.$extra->id)->assertNotFound();
        // These assertions document the defect: the now-hidden asset name survives in accessible history.
        $this->getJson($url)->assertOk()->assertSee('SYNTHETIC HIDDEN EXTRA');
        $this->assertDatabaseMissing('asset_stocktake_asset_refs', ['asset_stocktake_id' => $count['id'], 'asset_id' => $extra->id]);
    }

    public function test_reproduces_foreign_room_retained_and_presented_after_ordinary_site_edit(): void
    {
        $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->profile->update(['secondary_site_ids' => [$otherSite->id]]);
        $this->actingAs($this->actor->fresh());
        $this->post('/fleet-assets/assets', ['name' => 'SYNTHETIC MOVED ASSET', 'site_id' => $this->site->id,
            'site_room_id' => $this->room->id, 'status' => 'active', 'risk_level' => 'medium'])->assertRedirect();
        $asset = Asset::where('name', 'SYNTHETIC MOVED ASSET')->firstOrFail();
        $this->put('/fleet-assets/assets/'.$asset->id, ['name' => $asset->name, 'site_id' => $otherSite->id,
            'status' => 'active', 'risk_level' => 'medium'])->assertRedirect();
        $this->assertSame($otherSite->id, $asset->fresh()->site_id);
        $this->assertSame($this->room->id, $asset->fresh()->site_room_id);
        $this->profile->update(['primary_site_id' => $otherSite->id, 'secondary_site_ids' => []]);
        $this->actingAs($this->actor->fresh());
        $this->get('/fleet-assets/assets?site_id='.$otherSite->id)->assertOk()->assertInertia(fn ($page) => $page
            ->component('fleet-assets/assets/index')->where('assets.data.0.room.name', 'Synthetic original-site room')->has('rooms', 0));
    }
}
