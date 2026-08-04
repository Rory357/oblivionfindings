<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetFinanceTechnologyProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public function test_asset_and_finance_profiles_share_one_permission_safe_reconciliation_projection(): void
    {
        $site = $this->site();
        $asset = Asset::factory()->create([
            'site_id' => $site->id,
            'name' => 'Community office gateway cabinet',
            'asset_tag' => 'AST-TECH-001',
            'category' => 'equipment',
            'status' => 'active',
        ]);
        $fixedAsset = FinFixedAsset::factory()->create([
            'linked_asset_id' => $asset->id,
            'asset_name' => 'Gateway cabinet capital asset',
            'asset_tag' => 'FA-TECH-001',
            'status' => 'active',
            'purchase_cost' => '1000.00',
            'accumulated_depreciation' => '200.00',
        ]);
        $device = Device::factory()->create([
            'domain' => 'network_it',
            'name' => 'Office aggregation switch',
            'config' => ['raw_provider_payload' => 'RAW-CONFIG-SENTINEL'],
            'meta' => ['secret_note' => 'RAW-META-SENTINEL'],
        ]);
        $this->install($device, $asset);
        $viewer = $this->admin();

        $assetResponse = $this->actingAs($viewer)
            ->get("/fleet-assets/assets/{$asset->id}?tab=technology")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('fleet-assets/assets/show')
                ->where('asset_finance_technology.reconciliation.state', 'active_reconciled')
                ->where('asset_finance_technology.operational_asset.id', $asset->id)
                ->where('asset_finance_technology.finance.id', $fixedAsset->id)
                ->where('asset_finance_technology.finance.book_value', 800)
                ->where('asset_finance_technology.technology.devices.0.id', $device->id)
                ->where('asset.trackers.0.id', $device->id));

        $financeResponse = $this->actingAs($viewer)
            ->get("/finance/fixed-assets/{$fixedAsset->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('finance/fixed-assets/Show')
                ->where('assetReconciliation.reconciliation.state', 'active_reconciled')
                ->where('assetReconciliation.operational_asset.id', $asset->id)
                ->where('assetReconciliation.technology.devices.0.id', $device->id)
                ->missing('linkedAsset')
                ->missing('linkedDevices')
                ->missing('asset.organization_id')
                ->missing('asset.linked_asset_id')
                ->missing('asset.linkedAsset'));

        foreach ([$assetResponse, $financeResponse] as $response) {
            $payload = $response->getContent();
            $this->assertStringNotContainsString('RAW-CONFIG-SENTINEL', $payload);
            $this->assertStringNotContainsString('RAW-META-SENTINEL', $payload);
        }
    }

    public function test_disposal_reconciliation_keeps_each_follow_up_in_its_owning_module(): void
    {
        $site = $this->site();
        $asset = Asset::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $fixedAsset = FinFixedAsset::factory()->create([
            'linked_asset_id' => $asset->id,
            'status' => 'disposed',
            'disposed_date' => today(),
            'disposal_proceeds' => '250.00',
        ]);
        $assignment = AssetAssignment::query()->create([
            'asset_id' => $asset->id,
            'assignee_type' => 'staff',
            'assignee_id' => $this->admin()->id,
            'purpose' => 'Issued equipment',
            'assigned_at' => now()->subMonth(),
        ]);
        $device = Device::factory()->create(['domain' => 'network_it']);
        $link = $this->install($device, $asset);
        $viewer = $this->admin();

        $this->assertAssetReconciliationState($viewer, $asset, 'operational_retirement_required');

        $asset->update(['status' => 'retired']);
        $this->assertAssetReconciliationState($viewer, $asset, 'assignment_recovery_required');

        $assignment->update(['released_at' => now()]);
        $this->assertAssetReconciliationState($viewer, $asset, 'technology_recovery_required');

        $link->update(['unlinked_at' => now()]);
        $this->assertAssetReconciliationState($viewer, $asset, 'disposed_reconciled');

        $this->assertSame('disposed', $fixedAsset->fresh()->status);
        $this->assertSame('retired', $asset->fresh()->status);
    }

    public function test_source_permissions_and_site_access_hide_operational_and_device_details(): void
    {
        $site = $this->site();
        $outsideSite = $this->site();
        $asset = Asset::factory()->create([
            'site_id' => $site->id,
            'name' => 'Accessible operational asset',
        ]);
        $outsideAsset = Asset::factory()->create([
            'site_id' => $outsideSite->id,
            'name' => 'OUTSIDE-ASSET-NAME-SENTINEL',
        ]);
        $fixedAsset = FinFixedAsset::factory()->create([
            'linked_asset_id' => $asset->id,
            'asset_name' => 'Accessible finance record',
            'status' => 'active',
        ]);
        $outsideFixedAsset = FinFixedAsset::factory()->create([
            'linked_asset_id' => $outsideAsset->id,
            'asset_name' => 'Outside finance record',
            'status' => 'active',
        ]);
        $outsideDevice = Device::factory()->create([
            'domain' => 'network_it',
            'name' => 'OUTSIDE-DEVICE-NAME-SENTINEL',
        ]);
        $this->install($outsideDevice, $outsideAsset);

        $viewer = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
            'start_date' => today()->subYear(),
        ]);
        $this->grant($viewer, [
            'assets.viewAny',
            'finance.assets.view',
            'securityDevices.devices.view',
        ]);

        $this->actingAs($viewer)
            ->get("/fleet-assets/assets/{$asset->id}")
            ->assertOk();
        $this->actingAs($viewer)
            ->get("/fleet-assets/assets/{$outsideAsset->id}")
            ->assertNotFound();

        $outsideFinanceResponse = $this->actingAs($viewer)
            ->get("/finance/fixed-assets/{$outsideFixedAsset->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assetReconciliation.reconciliation.state', 'operational_source_restricted')
                ->where('assetReconciliation.operational_asset', null)
                ->where('assetReconciliation.technology', null));
        $this->assertStringNotContainsString('OUTSIDE-ASSET-NAME-SENTINEL', $outsideFinanceResponse->getContent());
        $this->assertStringNotContainsString('OUTSIDE-DEVICE-NAME-SENTINEL', $outsideFinanceResponse->getContent());

        $this->deny($viewer, 'securityDevices.devices.view');
        $noTechnology = $this->actingAs($viewer->fresh())
            ->get("/fleet-assets/assets/{$asset->id}?tab=technology")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('asset_finance_technology.permissions.finance', true)
                ->where('asset_finance_technology.permissions.technology', false)
                ->where('asset_finance_technology.technology', null)
                ->where('asset.trackers', []));
        $this->assertStringNotContainsString('OUTSIDE-DEVICE-NAME-SENTINEL', $noTechnology->getContent());

        $financePermission = Permission::query()->where('key', 'finance.assets.view')->firstOrFail();
        $viewer->permissionOverrides()->updateExistingPivot($financePermission->id, ['allowed' => false]);
        $viewer->unsetRelation('permissionOverrides');
        $withoutFinance = $this->actingAs($viewer->fresh())
            ->get("/fleet-assets/assets/{$asset->id}?tab=technology")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('asset_finance_technology.reconciliation.state', 'finance_restricted')
                ->where('asset_finance_technology.permissions.finance', false)
                ->where('asset_finance_technology.finance', null));
        $this->assertStringNotContainsString('Accessible finance record', $withoutFinance->getContent());
        $this->assertNotNull($fixedAsset);
    }

    private function assertAssetReconciliationState(User $viewer, Asset $asset, string $state): void
    {
        $this->actingAs($viewer)
            ->get("/fleet-assets/assets/{$asset->id}?tab=technology")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('asset_finance_technology.reconciliation.state', $state));
    }

    private function site(): Site
    {
        return Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
    }

    private function admin(): User
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        return $viewer;
    }

    private function install(Device $device, Asset $asset): DeviceAssetLink
    {
        return DeviceAssetLink::query()->create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);
    }

    /** @param list<string> $keys */
    private function grant(User $viewer, array $keys): void
    {
        $permissions = Permission::query()->whereIn('key', $keys)->get();
        $this->assertCount(count($keys), $permissions);
        $viewer->permissionOverrides()->syncWithoutDetaching(
            $permissions->mapWithKeys(fn (Permission $permission): array => [
                $permission->id => ['allowed' => true],
            ])->all(),
        );
    }

    private function deny(User $viewer, string $key): void
    {
        $permission = Permission::query()->where('key', $key)->firstOrFail();
        $viewer->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $viewer->unsetRelation('permissionOverrides');
    }
}
