<?php

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Laravel\Dusk\Browser;

test('IT and Control Room distinguish live context from sealed monitoring incident evidence on desktop', function () {
    config()->set('queue.default', 'sync');
    $this->seed(SecurityDevicesSignalSeeder::class);

    $viewer = User::query()->where('email', 'admin@test.com')->firstOrFail();
    $site = Site::query()->where('name', 'QA Main Site')->firstOrFail();
    $device = Device::factory()->itInfrastructure()->create([
        'name' => 'QA Core Switch at incident time',
        'subcategory' => 'network_switch',
        'status' => DeviceStatus::Offline,
        'health_status' => HealthStatus::Critical,
        'config' => [
            'raw_provider_payload' => 'X06-RAW-BROWSER-SENTINEL',
        ],
    ]);

    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $viewer->id,
    ]);
    ControlRoomDevice::query()->create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'site_id' => $site->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);

    DeviceEvent::query()->create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now()->subMinute(),
        'payload' => [
            'message' => 'The SD-WAN path stopped answering health probes.',
            'raw_provider_payload' => 'X06-EVENT-RAW-BROWSER-SENTINEL',
        ],
    ]);

    $ticket = ItTicket::query()->sole();
    /** @var ControlRoomAlert $alert */
    $alert = $ticket->linked('source_alert')->firstOrFail()->linkable;

    $device->update([
        'name' => 'QA Core Switch live replacement',
        'status' => DeviceStatus::Active,
        'health_status' => HealthStatus::Healthy,
    ]);
    $ticket->update([
        'status' => 'in_progress',
        'assigned_to_user_id' => $viewer->id,
    ]);
    $alert->update([
        'status' => ControlRoomAlert::STATUS_TRIAGING,
        'severity' => 'critical',
    ]);

    $this->browse(function (Browser $browser) use ($viewer, $ticket, $alert): void {
        $browser->loginAs($viewer);

        foreach ([[1440, 900], [1280, 800]] as [$width, $height]) {
            $browser->resize($width, $height)
                ->visit("/it/tickets/{$ticket->id}")
                ->waitForText('Frozen when the incident was raised', 40)
                ->assertSee('Integrity verified')
                ->assertSee('QA Core Switch at incident time')
                ->assertSee('QA Core Switch live replacement')
                ->assertSee('The Device and Control Room records below are live now')
                ->assertDontSee('X06-RAW-BROWSER-SENTINEL')
                ->assertDontSee('X06-EVENT-RAW-BROWSER-SENTINEL')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->visit("/control-room/alerts/{$alert->id}")
                ->waitForText('Linked records', 40);

            clickMonitoringWorkspaceSection($browser, 'Linked records');
            $browser->waitForText('IT incident work', 20)
                ->assertSee($ticket->reference)
                ->assertSee('with '.$viewer->name);

            clickMonitoringWorkspaceSection($browser, 'Evidence');
            $browser->waitForText('Frozen when the incident was raised', 20)
                ->assertSee('QA Core Switch at incident time')
                ->assertSee('This sealed monitoring snapshot is evidence, not another work queue')
                ->assertDontSee('X06-RAW-BROWSER-SENTINEL')
                ->assertDontSee('X06-EVENT-RAW-BROWSER-SENTINEL')
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

function clickMonitoringWorkspaceSection(Browser $browser, string $label): void
{
    $encoded = json_encode($label, JSON_THROW_ON_ERROR);
    $browser->script(<<<JS
        (() => {
            const label = {$encoded};
            const button = [...document.querySelectorAll('[data-wizard-region="rail"] button')]
                .find((candidate) => candidate.textContent?.includes(label));
            if (!button) throw new Error('Missing Control Room workspace section: ' + label);
            button.click();
        })();
    JS);
}
