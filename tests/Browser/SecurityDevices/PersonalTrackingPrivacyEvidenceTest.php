<?php

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Models\Asset;
use App\Models\AssetTelemetrySnapshot;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\FleetTelemetryEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

test('personal tracking consent and cached access end visibly on desktop', function () {
    $viewer = User::query()->where('email', 'admin@test.com')->firstOrFail();
    $site = Site::query()->where('name', 'QA Main Site')->firstOrFail();
    $evidencePermissions = [
        'clients.viewAny',
        'assets.viewAny',
        'assets.telemetry.view',
        'fleet.viewAny',
    ];
    $evidenceRole = Role::query()->firstOrCreate(
        ['name' => 'personal_tracking_browser_evidence'],
        [
            'label' => 'Personal tracking browser evidence',
            'level' => 50,
            'type' => 'custom',
        ],
    );

    foreach ($evidencePermissions as $permissionKey) {
        Permission::query()->firstOrCreate(
            ['key' => $permissionKey],
            [
                'description' => $permissionKey,
                'group' => 'test',
                'module' => 'Test',
            ],
        );
    }

    $evidenceRole->permissions()->sync(
        Permission::query()
            ->whereIn('key', $evidencePermissions)
            ->pluck('id')
            ->all(),
    );
    $viewer->roles()->syncWithoutDetaching([$evidenceRole->id]);

    $evidenceRun = str()->uuid()->toString();
    $consentType = ConsentType::factory()->create([
        'name' => 'Personal Tracker Browser Safety Consent',
        'purpose' => 'Client personal safety tracking',
        'active' => true,
    ]);

    $cases = collect([
        ['width' => 1440, 'height' => 900, 'suffix' => 'wide'],
        ['width' => 1280, 'height' => 800, 'suffix' => 'compact'],
    ])->map(function (array $viewport) use ($viewer, $site, $consentType, $evidenceRun): array {
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'first_name' => 'Privacy',
            'last_name' => ucfirst($viewport['suffix']),
        ]);
        $consent = ClientConsent::query()->create([
            'client_id' => $client->id,
            'consent_type_id' => $consentType->id,
            'status' => 'given',
            'given_at' => now(),
            'given_by_user_id' => $viewer->id,
            'given_method' => 'electronic',
            'expires_at' => now()->addMonth(),
            'created_by' => $viewer->id,
            'updated_by' => $viewer->id,
        ]);
        $asset = Asset::factory()->forSite($site)->forClient($client->id)->create([
            'name' => "QA personal safety asset {$viewport['suffix']}",
        ]);
        $tracker = AssetTracker::query()->create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => "QA-PRIVACY-{$viewport['suffix']}-{$evidenceRun}",
            'status' => 'paired',
            'paired_at' => now(),
            'consent_id' => $consent->id,
        ]);
        $device = Device::factory()->tracking()->create([
            'name' => "QA privacy tracker {$viewport['suffix']}",
            'legacy_asset_tracker_id' => $tracker->id,
            'latitude' => -36.8485,
            'longitude' => 174.7633,
            'location_description' => "PRIVATE-ADDRESS-{$viewport['suffix']}",
            'external_ref' => [
                'provider_device_id' => "QA-PRIVACY-{$viewport['suffix']}-{$evidenceRun}",
                'location' => ['lat' => -36.8485, 'lng' => 174.7633],
            ],
        ]);
        DeviceAssetLink::query()->create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
            'linked_by_user_id' => $viewer->id,
        ]);
        $assignment = app(DeviceAssignmentService::class)->assign(
            $device,
            'client',
            $client->id,
            $viewer->id,
            consentId: $consent->id,
        );
        FleetTelemetryEvent::query()->create([
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'vendor' => 'queclink',
            'vendor_message_id' => "browser-privacy-{$viewport['suffix']}-{$evidenceRun}",
            'occurred_at' => now(),
            'received_at' => now(),
            'latitude' => -36.8485,
            'longitude' => 174.7633,
            'speed_kph' => 3.5,
            'battery_pct' => 82,
            'event_type' => 'position',
            'idempotency_key' => hash('sha256', "browser-privacy-{$viewport['suffix']}-{$evidenceRun}"),
            'raw_payload' => [],
            'consent_blocked' => false,
        ]);
        AssetTelemetrySnapshot::query()->create([
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'occurred_at' => now(),
            'received_at' => now(),
            'latitude' => -36.8485,
            'longitude' => 174.7633,
            'vendor_payload_hash' => hash('sha256', "browser-snapshot-{$viewport['suffix']}-{$evidenceRun}"),
            'vendor_metadata' => ['location' => ['lat' => -36.8485, 'lng' => 174.7633]],
            'consent_blocked' => false,
        ]);

        return [...$viewport, ...compact('client', 'consent', 'device', 'assignment')];
    });

    $this->browse(function (Browser $browser) use ($viewer, $cases): void {
        $browser->loginAs($viewer);

        foreach ($cases as $case) {
            /** @var Client $client */
            $client = $case['client'];
            /** @var ClientConsent $consent */
            $consent = $case['consent'];
            /** @var Device $device */
            $device = $case['device'];

            $browser->resize($case['width'], $case['height'])
                ->visit("/operations/clients/{$client->id}?tab=location")
                ->waitForText('Current location', 40);

            $browser->script('window.scrollTo(0, 620);');
            $browser->waitForText($device->name, 20)
                ->assertSee('Movement history')
                ->press('Show history')
                ->waitForText('1 points', 20)
                ->press('Export CSV')
                ->waitForText('Export location history', 20)
                ->assertSee('Operational reason')
                ->assertSee('This reason is audited')
                ->assertSee('History is limited to 90 days')
                ->press('Cancel')
                ->visit("/fleet-assets/devices?device={$device->id}")
                ->waitForText('Personal location is protected', 40)
                ->assertDontSee("PRIVATE-ADDRESS-{$case['suffix']}")
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->visit("/operations/clients/{$client->id}?tab=location")
                ->waitForText('Current location', 40);

            $browser->script('window.scrollTo(0, 620);');
            $browser->waitForText($device->name, 20);

            DB::transaction(function () use ($consent, $viewer): void {
                $lockedConsent = ClientConsent::query()
                    ->lockForUpdate()
                    ->findOrFail($consent->id);
                $lockedConsent->update([
                    'status' => 'withdrawn',
                    'withdrawn_at' => now(),
                    'withdrawn_by_user_id' => $viewer->id,
                    'withdrawal_reason' => 'Desktop cache revocation evidence',
                    'withdrawal_acknowledged' => true,
                    'updated_by' => $viewer->id,
                ]);
                app(PersonalTrackingPrivacyService::class)
                    ->stopForConsent($lockedConsent, $viewer->id);
            });

            $browser->script('window.dispatchEvent(new Event("focus"));');
            $browser->waitForText('Location access is not active', 20)
                ->assertSee('Cached location data has been removed from this view')
                ->assertDontSee($device->name)
                ->assertDontSee('Current location')
                ->assertDontSee('Movement history')
                ->assertDontSee('Export CSV')
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
