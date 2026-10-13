<?php

use App\Domain\SecurityDevices\Endpoints\Models\IntuneDeviceLink;
use App\Domain\SecurityDevices\Endpoints\Services\IntuneVisibilitySync;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('endpoint_management.intune', [
        'enabled' => true,
        'directory_id' => '11111111-1111-4111-8111-111111111111',
        'client_id' => '22222222-2222-4222-8222-222222222222',
        'client_secret' => 'test-only-secret',
        'maximum_pages' => 5, 'maximum_devices' => 100,
    ]);
    Http::preventStrayRequests();
});

function linkedIntuneFixture(string $managedId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'): IntuneDeviceLink
{
    return IntuneDeviceLink::query()->create([
        'device_id' => Device::factory()->create(['name' => 'Canonical computer', 'provider' => 'oblivion_native'])->id,
        'managed_device_id' => $managedId,
        'linked_by_user_id' => User::factory()->create()->id,
        'snapshot' => ['id' => $managedId, 'deviceName' => 'Earlier name', 'complianceState' => 'compliant'],
        'synced_at' => now()->subDay(), 'sync_status' => 'current',
    ]);
}

it('updates only explicitly linked observations without rewriting canonical device ownership or identity', function () {
    $link = linkedIntuneFixture();
    $deviceBefore = $link->device->getRawOriginal();
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-only-token']),
        'graph.microsoft.com/*' => Http::response(['value' => [
            ['id' => $link->managed_device_id, 'deviceName' => 'Microsoft name', 'complianceState' => 'noncompliant'],
            ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'deviceName' => 'Unmapped device'],
        ]]),
    ]);
    expect(app(IntuneVisibilitySync::class)->sync())->toBe(1)
        ->and($link->fresh()->snapshot['deviceName'])->toBe('Microsoft name')
        ->and($link->fresh()->sync_status)->toBe('current')
        ->and($link->device->fresh()->getRawOriginal())->toBe($deviceBefore)
        ->and(IntuneDeviceLink::query()->count())->toBe(1)
        ->and(Device::query()->count())->toBe(1);
});

it('retains older observations and their timestamp when a linked device disappears', function () {
    $link = linkedIntuneFixture();
    $oldTime = $link->synced_at->toISOString();
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-only-token']),
        'graph.microsoft.com/*' => Http::response(['value' => []]),
    ]);
    expect(app(IntuneVisibilitySync::class)->sync())->toBe(0)
        ->and($link->fresh()->sync_status)->toBe('missing')
        ->and($link->fresh()->synced_at->toISOString())->toBe($oldTime)
        ->and($link->fresh()->snapshot['deviceName'])->toBe('Earlier name');
});

it('retains all previous observations if any page fails and records the failed refresh', function () {
    $link = linkedIntuneFixture();
    $oldTime = $link->synced_at->toISOString();
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-only-token']),
        'graph.microsoft.com/*' => Http::sequence()
            ->push(['value' => [['id' => $link->managed_device_id, 'deviceName' => 'Incomplete new name']], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/deviceManagement/managedDevices?$skiptoken=next'])
            ->push(['error' => 'PRIVATE-UPSTREAM-DATA'], 403),
    ]);
    expect(fn () => app(IntuneVisibilitySync::class)->sync())->toThrow(RuntimeException::class, 'Previous observations remain available')
        ->and($link->fresh()->sync_status)->toBe('failed')
        ->and($link->fresh()->synced_at->toISOString())->toBe($oldTime)
        ->and($link->fresh()->snapshot['deviceName'])->toBe('Earlier name');
});

it('does not apply a fetch to a device mapping changed while the request was in flight', function () {
    $link = linkedIntuneFixture();
    $oldId = $link->managed_device_id;
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-only-token']),
        'graph.microsoft.com/*' => function () use ($link, $oldId) {
            $link->update([
                'managed_device_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'snapshot' => null, 'synced_at' => null, 'sync_status' => 'pending',
            ]);

            return Http::response(['value' => [
                ['id' => $oldId, 'deviceName' => 'Old identity'],
                ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'deviceName' => 'Fetched before mapping approval'],
            ]]);
        },
    ]);
    expect(app(IntuneVisibilitySync::class)->sync())->toBe(0)
        ->and($link->fresh()->sync_status)->toBe('pending')
        ->and($link->fresh()->snapshot)->toBeNull()
        ->and($link->fresh()->last_attempt_at)->toBeNull();
});

it('does not contact Microsoft when no canonical devices are mapped', function () {
    expect(app(IntuneVisibilitySync::class)->sync())->toBe(0);
    Http::assertNothingSent();
});
