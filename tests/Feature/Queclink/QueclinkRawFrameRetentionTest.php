<?php

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Jobs\PrunePersonalTrackingTelemetry;
use App\Models\LegalHold;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkRawFrame;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.queclink.listener.raw_frame_retention_days', 30);

    $this->createRawFrame = function (?QueclinkDevice $device, string $marker, int $daysOld): QueclinkRawFrame {
        $createdAt = now()->subDays($daysOld);
        $canonicalDeviceId = is_numeric($device?->device_id) ? (int) $device->device_id : null;
        $assignmentId = $canonicalDeviceId === null
            ? null
            : DeviceAssignment::query()
                ->where('device_id', $canonicalDeviceId)
                ->where('assigned_at', '<=', $createdAt)
                ->where(fn ($query) => $query
                    ->whereNull('released_at')
                    ->orWhere('released_at', '>=', $createdAt))
                ->orderByDesc('assigned_at')
                ->orderByDesc('id')
                ->value('id');

        return QueclinkRawFrame::query()->forceCreate([
            'queclink_device_id' => $device?->id,
            'canonical_device_id' => $canonicalDeviceId,
            'device_assignment_id' => $assignmentId,
            'binding_uuid' => $device?->binding_uuid,
            'imei' => $device?->imei,
            'direction' => QueclinkRawFrame::DIRECTION_INBOUND,
            'frame_type' => QueclinkRawFrame::FRAME_RESP,
            'command_word' => 'GTHBD',
            'raw_frame' => $marker,
            'parse_ok' => true,
            'session_id' => 'retention-test-session',
            'remote_address' => '192.0.2.10:5000',
            'created_at' => $createdAt,
        ]);
    };
});

it('removes stale paired pending and unpaired raw intake while preserving active holds', function () {
    $canonicalDevice = Device::factory()->tracking()->create();
    $heldCanonicalDevice = Device::factory()->tracking()->create();
    $paired = QueclinkDevice::query()->create([
        'imei' => '864696060004173',
        'status' => QueclinkDevice::STATUS_PAIRED,
        'device_id' => $canonicalDevice->id,
    ]);
    $heldProvider = QueclinkDevice::query()->create([
        'imei' => '864696060004174',
        'status' => QueclinkDevice::STATUS_PENDING,
    ]);
    $stalePendingProvider = QueclinkDevice::query()->create([
        'imei' => '864696060004178',
        'status' => QueclinkDevice::STATUS_PENDING,
    ]);
    $heldCanonical = QueclinkDevice::query()->create([
        'imei' => '864696060004175',
        'status' => QueclinkDevice::STATUS_PAIRED,
        'device_id' => $heldCanonicalDevice->id,
    ]);

    $stalePaired = ($this->createRawFrame)($paired, 'stale-paired', 40);
    $stalePending = ($this->createRawFrame)($stalePendingProvider, 'stale-pending', 40);
    $staleUnpaired = ($this->createRawFrame)(null, 'stale-unpaired', 40);
    $recent = ($this->createRawFrame)($paired, 'recent', 2);
    $directlyHeld = ($this->createRawFrame)($paired, 'directly-held', 40);
    $providerHeld = ($this->createRawFrame)($heldProvider, 'provider-held', 40);
    $canonicalHeld = ($this->createRawFrame)($heldCanonical, 'canonical-held', 40);
    $releasedHoldFrame = ($this->createRawFrame)($paired, 'released-hold', 40);

    LegalHold::factory()->create([
        'holdable_type' => QueclinkRawFrame::class,
        'holdable_id' => $directlyHeld->id,
        'status' => 'active',
    ]);
    LegalHold::factory()->create([
        'holdable_type' => QueclinkDevice::class,
        'holdable_id' => $heldProvider->id,
        'status' => 'active',
    ]);
    LegalHold::factory()->create([
        'holdable_type' => Device::class,
        'holdable_id' => $heldCanonicalDevice->id,
        'status' => 'active',
    ]);
    LegalHold::factory()->create([
        'holdable_type' => QueclinkRawFrame::class,
        'holdable_id' => $releasedHoldFrame->id,
        'status' => 'released',
    ]);

    (new PrunePersonalTrackingTelemetry)->handle();

    expect(QueclinkRawFrame::query()->whereKey($stalePaired->id)->exists())->toBeFalse()
        ->and(QueclinkRawFrame::query()->whereKey($stalePending->id)->exists())->toBeFalse()
        ->and(QueclinkRawFrame::query()->whereKey($staleUnpaired->id)->exists())->toBeFalse()
        ->and(QueclinkRawFrame::query()->whereKey($releasedHoldFrame->id)->exists())->toBeFalse()
        ->and(QueclinkRawFrame::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(QueclinkRawFrame::query()->whereKey($directlyHeld->id)->exists())->toBeTrue()
        ->and(QueclinkRawFrame::query()->whereKey($providerHeld->id)->exists())->toBeTrue()
        ->and(QueclinkRawFrame::query()->whereKey($canonicalHeld->id)->exists())->toBeTrue();
});

it('applies assignment retention to raw frames and preserves an active assignment hold', function () {
    $staff = User::factory()->create();
    $expiredDevice = Device::factory()->tracking()->create();
    $heldDevice = Device::factory()->tracking()->create();

    $expiredAssignment = DeviceAssignment::query()->create([
        'device_id' => $expiredDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_STAFF,
        'assignable_id' => $staff->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subDays(10),
        'retention_days' => 1,
    ]);
    $heldAssignment = DeviceAssignment::query()->create([
        'device_id' => $heldDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_STAFF,
        'assignable_id' => $staff->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subDays(10),
        'retention_days' => 1,
    ]);

    $expiredProvider = QueclinkDevice::query()->create([
        'imei' => '864696060004176',
        'status' => QueclinkDevice::STATUS_PAIRED,
        'device_id' => $expiredDevice->id,
    ]);
    $heldProvider = QueclinkDevice::query()->create([
        'imei' => '864696060004177',
        'status' => QueclinkDevice::STATUS_PAIRED,
        'device_id' => $heldDevice->id,
    ]);
    $expired = ($this->createRawFrame)($expiredProvider, 'assignment-expired', 3);
    $held = ($this->createRawFrame)($heldProvider, 'assignment-held', 3);

    LegalHold::factory()->create([
        'holdable_type' => DeviceAssignment::class,
        'holdable_id' => $heldAssignment->id,
        'status' => 'active',
    ]);

    (new PrunePersonalTrackingTelemetry)->handle();

    expect(QueclinkRawFrame::query()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(QueclinkRawFrame::query()->whereKey($held->id)->exists())->toBeTrue()
        ->and($expiredAssignment->exists)->toBeTrue();
});

it('preserves frame-time holds after release and re-pair without protecting the new binding', function () {
    $staff = User::factory()->create();
    $historicalDevice = Device::factory()->tracking()->create();
    $currentDevice = Device::factory()->tracking()->create();
    $historicalAssignment = DeviceAssignment::query()->create([
        'device_id' => $historicalDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_STAFF,
        'assignable_id' => $staff->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subDays(60),
        'released_at' => now()->subDays(20),
        'retention_days' => 1,
    ]);
    $provider = QueclinkDevice::query()->create([
        'imei' => '864696060004179',
        'status' => QueclinkDevice::STATUS_PAIRED,
        'device_id' => $currentDevice->id,
        'binding_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    ]);

    $historical = QueclinkRawFrame::query()->forceCreate([
        'queclink_device_id' => $provider->id,
        'canonical_device_id' => $historicalDevice->id,
        'device_assignment_id' => $historicalAssignment->id,
        'binding_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'imei' => $provider->imei,
        'direction' => QueclinkRawFrame::DIRECTION_INBOUND,
        'frame_type' => QueclinkRawFrame::FRAME_RESP,
        'command_word' => 'GTHBD',
        'raw_frame' => 'historical-held-frame',
        'parse_ok' => true,
        'created_at' => now()->subDays(40),
    ]);
    $current = QueclinkRawFrame::query()->forceCreate([
        'queclink_device_id' => $provider->id,
        'canonical_device_id' => $currentDevice->id,
        'device_assignment_id' => null,
        'binding_uuid' => $provider->binding_uuid,
        'imei' => $provider->imei,
        'direction' => QueclinkRawFrame::DIRECTION_INBOUND,
        'frame_type' => QueclinkRawFrame::FRAME_RESP,
        'command_word' => 'GTHBD',
        'raw_frame' => 'current-binding-frame',
        'parse_ok' => true,
        'created_at' => now()->subDays(40),
    ]);

    LegalHold::factory()->create([
        'holdable_type' => DeviceAssignment::class,
        'holdable_id' => $historicalAssignment->id,
        'status' => 'active',
    ]);

    (new PrunePersonalTrackingTelemetry)->handle();

    expect(QueclinkRawFrame::query()->whereKey($historical->id)->exists())->toBeTrue()
        ->and(QueclinkRawFrame::query()->whereKey($current->id)->exists())->toBeFalse();
});

it('keeps persisted frame-time lineage immutable', function () {
    $canonicalDevice = Device::factory()->tracking()->create();
    $provider = QueclinkDevice::query()->create([
        'imei' => '864696060004180',
        'status' => QueclinkDevice::STATUS_PAIRED,
        'device_id' => $canonicalDevice->id,
        'binding_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    ]);
    $frame = ($this->createRawFrame)($provider, 'immutable-frame', 2);

    expect(fn () => $frame->update([
        'canonical_device_id' => null,
        'device_assignment_id' => null,
        'binding_uuid' => null,
    ]))->toThrow(RuntimeException::class, 'Queclink raw frame evidence is immutable.')
        ->and($frame->refresh()->canonical_device_id)->toBe($canonicalDevice->id)
        ->and($frame->binding_uuid)->toBe('cccccccc-cccc-4ccc-8ccc-cccccccccccc');
});

it('fails closed when the governed maximum retention setting is invalid', function () {
    config()->set('services.queclink.listener.raw_frame_retention_days', 0);
    $stale = ($this->createRawFrame)(null, 'must-remain', 400);

    expect(fn () => (new PrunePersonalTrackingTelemetry)->handle())
        ->toThrow(InvalidArgumentException::class)
        ->and(QueclinkRawFrame::query()->whereKey($stale->id)->exists())->toBeTrue();
});
