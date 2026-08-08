<?php

use App\Domain\SecurityDevices\Management\Models\DeviceConfigurationProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('rolls governed preset and device document lifecycle evidence down and back up on MySQL', function () {
    $path = database_path('migrations/2026_08_06_000040_retain_queclink_preset_and_device_document_lifecycle.php');
    /** @var Migration $migration */
    $migration = require $path;

    expect(Schema::hasColumns('queclink_presets', [
        'retired_at',
        'retired_by_user_id',
        'retirement_reason',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('device_documents', [
            'content_sha256',
            'removed_at',
            'removed_by_user_id',
            'removal_reason',
            'storage_deleted_at',
        ]))->toBeTrue();

    $orphan = DeviceConfigurationProfile::query()->create([
        'profile_key' => 'queclink:removed-preset',
        'version' => 1,
        'name' => 'Historical orphan',
        'provider' => 'queclink',
        'device_domain' => 'tracking',
        'target_category' => 'personal_tracker',
        'encrypted_payload' => ['tracking' => ['continuous_send_interval_seconds' => 90]],
        'verification_sections' => ['CFG'],
        'status' => DeviceConfigurationProfile::STATUS_ACTIVE,
        'is_system' => false,
    ]);
    $draft = DeviceConfigurationProfile::query()->create([
        'profile_key' => 'queclink:device-99:draft:retained-test',
        'version' => 1,
        'name' => 'Device draft',
        'provider' => 'queclink',
        'device_domain' => 'tracking',
        'target_category' => 'personal_tracker',
        'encrypted_payload' => ['tracking' => ['continuous_send_interval_seconds' => 120]],
        'verification_sections' => ['CFG'],
        'status' => DeviceConfigurationProfile::STATUS_ACTIVE,
        'is_system' => false,
    ]);

    $migration->down();

    expect(Schema::hasColumns('queclink_presets', [
        'retired_at',
        'retired_by_user_id',
        'retirement_reason',
    ]))->toBeFalse()
        ->and(Schema::hasColumns('device_documents', [
            'content_sha256',
            'removed_at',
            'removed_by_user_id',
            'removal_reason',
            'storage_deleted_at',
        ]))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumns('queclink_presets', [
        'retired_at',
        'retired_by_user_id',
        'retirement_reason',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('device_documents', [
            'content_sha256',
            'removed_at',
            'removed_by_user_id',
            'removal_reason',
            'storage_deleted_at',
        ]))->toBeTrue();

    expect($orphan->fresh()->status)->toBe(DeviceConfigurationProfile::STATUS_RETIRED)
        ->and($draft->fresh()->status)->toBe(DeviceConfigurationProfile::STATUS_ACTIVE);
});

it('refuses to discard durable Device document recovery and integrity evidence', function () {
    $path = database_path('migrations/2026_08_06_000042_add_recoverable_device_document_storage_lifecycle.php');
    /** @var Migration $migration */
    $migration = require $path;

    expect(Schema::hasColumns('device_documents', [
        'lifecycle_state',
        'upload_operation_uuid',
        'upload_requested_by_user_id',
        'staged_storage_path',
        'storage_verified_at',
        'lifecycle_error_code',
        'removal_operation_uuid',
        'removal_requested_at',
        'removal_requested_by_user_id',
        'removal_request_reason',
        'quarantine_storage_path',
    ]))->toBeTrue();

    $device = Device::factory()->create();
    DeviceDocument::query()->create([
        'device_id' => $device->id,
        'title' => 'Verified retained evidence',
        'category' => 'manual',
        'storage_disk' => DeviceDocument::DISK,
        'storage_path' => 'device_documents/verified-evidence.pdf',
        'original_name' => 'verified-evidence.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 8,
        'content_sha256' => hash('sha256', 'evidence'),
        'lifecycle_state' => DeviceDocument::STATE_ACTIVE,
        'storage_verified_at' => now(),
    ]);

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot remove the recoverable document-storage lifecycle');
});
