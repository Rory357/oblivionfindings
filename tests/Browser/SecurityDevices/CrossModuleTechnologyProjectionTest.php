<?php

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Laravel\Dusk\Browser;

test('site client staff vehicle asset and finance profiles expose clear canonical technology projections on desktop', function () {
    $viewer = User::query()->where('email', 'admin@test.com')->firstOrFail();
    $site = Site::query()->where('name', 'QA Main Site')->firstOrFail();
    $client = Client::factory()->create([
        'organization_id' => $viewer->organization_id,
        'site_id' => $site->id,
        'first_name' => 'Mere',
        'last_name' => 'Browser',
    ]);
    $siteDevice = Device::factory()->itInfrastructure()->offline()->create([
        'name' => 'QA SD-WAN gateway',
        'subcategory' => 'edge_router',
    ]);
    $healthcareDevice = Device::factory()->iotHealthcare()->offline()->create([
        'name' => 'Mere browser bed sensor',
        'battery_level' => 61,
        'config' => [
            'connectivity_state' => 'offline',
            'integration_state' => 'healthy',
            'last_successful_delivery_at' => now()->subMinutes(5)->toIso8601String(),
            'clinical_reading' => 'CLINICAL-BROWSER-SENTINEL',
        ],
        'meta' => [
            'diagnosis' => 'DIAGNOSIS-BROWSER-SENTINEL',
        ],
    ]);
    $employee = User::factory()->create([
        'approved_at' => now(),
        'name' => 'QA Technology Worker',
    ]);
    $employeeProfile = HrEmployeeProfile::factory()->create([
        'user_id' => $employee->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
        'start_date' => today()->subMonths(6),
    ]);
    $staffDevice = Device::factory()->itInfrastructure()->offline()->create([
        'name' => 'QA staff laptop',
        'subcategory' => 'laptop',
    ]);
    $vehicle = Asset::factory()->vehicle()->create([
        'site_id' => $site->id,
        'home_site_id' => $site->id,
        'name' => 'QA Community Van',
    ]);
    $vehicleDevice = Device::factory()->tracking()->offline()->create([
        'name' => 'QA van telematics gateway',
        'subcategory' => 'telematics_gateway',
        'config' => [
            'raw_provider_payload' => 'FLEET-RAW-BROWSER-SENTINEL',
        ],
        'meta' => [
            'private_driver_location' => 'FLEET-LOCATION-BROWSER-SENTINEL',
        ],
    ]);
    $capitalAsset = Asset::factory()->create([
        'site_id' => $site->id,
        'name' => 'QA Technology Cabinet',
        'asset_tag' => 'QA-AST-TECH',
        'category' => 'equipment',
        'status' => 'active',
    ]);
    $fixedAsset = FinFixedAsset::factory()->create([
        'organization_id' => $viewer->organization_id,
        'linked_asset_id' => $capitalAsset->id,
        'asset_name' => 'QA Cabinet Financial Record',
        'asset_tag' => 'QA-FA-TECH',
        'status' => 'active',
        'purchase_cost' => '4800.00',
        'accumulated_depreciation' => '1200.00',
    ]);
    $capitalAssetDevice = Device::factory()->itInfrastructure()->offline()->create([
        'name' => 'QA Cabinet Aggregation Switch',
        'subcategory' => 'network_switch',
        'config' => [
            'raw_provider_payload' => 'ASSET-RAW-BROWSER-SENTINEL',
        ],
    ]);
    assignBrowserDevice($siteDevice, DeviceAssignment::TARGET_SITE, $site->id);
    assignBrowserDevice($healthcareDevice, DeviceAssignment::TARGET_CLIENT, $client->id);
    assignBrowserDevice($staffDevice, DeviceAssignment::TARGET_STAFF, $employee->id);
    DeviceAssetLink::query()->create([
        'device_id' => $vehicleDevice->id,
        'asset_id' => $vehicle->id,
        'link_type' => LinkType::InstalledIn,
        'linked_at' => now(),
    ]);
    DeviceAssetLink::query()->create([
        'device_id' => $capitalAssetDevice->id,
        'asset_id' => $capitalAsset->id,
        'link_type' => LinkType::InstalledIn,
        'linked_at' => now(),
    ]);

    $this->browse(function (Browser $browser) use (
        $viewer,
        $site,
        $client,
        $employeeProfile,
        $vehicle,
        $capitalAsset,
        $fixedAsset,
    ): void {
        $browser->loginAs($viewer);

        foreach ([[1440, 900], [1280, 800]] as [$width, $height]) {
            $browser->resize($width, $height)
                ->visit("/sites/{$site->id}?tab=technology")
                ->waitForText('Technology & monitoring', 25)
                ->assertSee('QA SD-WAN gateway')
                ->assertSee('Open full technology view')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->visit("/operations/clients/{$client->id}?tab=healthcare_devices")
                ->waitForText('Healthcare devices', 25)
                ->assertSee('Technical device status only')
                ->assertSee('Mere browser bed sensor')
                ->assertSee('Open full device workspace')
                ->assertDontSee('CLINICAL-BROWSER-SENTINEL')
                ->assertDontSee('DIAGNOSIS-BROWSER-SENTINEL')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->visit("/hr/people/{$employeeProfile->id}?tab=assets")
                ->waitForText('Issued equipment', 40)
                ->assertSee('Equipment & access')
                ->assertSee('QA staff laptop')
                ->assertSee('Joiner, mover & leaver workflows')
                ->assertSee('One read-only view of records owned by Security & Devices')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->visit("/fleet-assets/vehicles/{$vehicle->id}?tab=technology")
                ->waitForText('Technology here, vehicle operations in Fleet', 40)
                ->assertSee('Vehicle technology')
                ->assertSee('QA van telematics gateway')
                ->assertSee('Fleet tracking')
                ->assertDontSee('FLEET-RAW-BROWSER-SENTINEL')
                ->assertDontSee('FLEET-LOCATION-BROWSER-SENTINEL')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->visit("/fleet-assets/assets/{$capitalAsset->id}?tab=technology")
                ->waitForText('One asset, three clear owners', 40)
                ->assertSee('Technology & finance')
                ->assertSee('QA Technology Cabinet')
                ->assertSee('QA Cabinet Financial Record')
                ->assertSee('QA Cabinet Aggregation Switch')
                ->assertSee('Active records agree')
                ->assertDontSee('ASSET-RAW-BROWSER-SENTINEL')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->visit("/finance/fixed-assets/{$fixedAsset->id}")
                ->waitForText('One asset, three clear owners', 40)
                ->assertSee('QA Cabinet Financial Record')
                ->assertSee('QA Technology Cabinet')
                ->assertSee('QA Cabinet Aggregation Switch')
                ->assertSee('Read-only reconciliation')
                ->assertDontSee('ASSET-RAW-BROWSER-SENTINEL')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                );
        }

        $severeLogs = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry): bool => strtoupper((string) ($entry['level'] ?? '')) === 'SEVERE')
            ->values()
            ->all();

        $this->assertSame([], $severeLogs, json_encode($severeLogs));
    });
});

function assignBrowserDevice(Device $device, string $targetType, int $targetId): void
{
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => $targetType,
        'assignable_id' => $targetId,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
    ]);
}
