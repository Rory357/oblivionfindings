<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('rolls the governed Queclink linkage down and back up on MySQL', function () {
    $path = database_path('migrations/2026_08_02_000017_link_queclink_commands_to_governed_device_commands.php');
    $profilePath = database_path('migrations/2026_08_02_000018_create_governed_device_configuration_profiles.php');
    /** @var Migration $migration */
    $migration = require $path;
    /** @var Migration $profileMigration */
    $profileMigration = require $profilePath;

    expect(Schema::hasColumns('queclink_pending_commands', [
        'raw_command_encrypted',
        'device_command_request_id',
        'device_command_attempt_id',
        'fulfilled_telemetry_event_id',
        'fulfilled_raw_frame_id',
        'sent_session_id',
        'fulfilled_at',
        'reconciliation_dispatched_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('queclink_raw_frames', [
            'encrypted_raw_frame',
            'encrypted_parsed_payload',
        ]))->toBeTrue()
        ->and(Schema::hasTable('device_configuration_profiles'))->toBeTrue()
        ->and(Schema::hasColumns('queclink_pending_commands', [
            'governed_sequence',
            'governed_role',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('queclink_presets', 'device_configuration_profile_id'))->toBeTrue();

    $profileMigration->down();

    $migration->down();

    expect(Schema::hasColumns('queclink_pending_commands', [
        'raw_command_encrypted',
        'device_command_request_id',
        'device_command_attempt_id',
        'fulfilled_telemetry_event_id',
        'fulfilled_raw_frame_id',
        'sent_session_id',
        'fulfilled_at',
        'reconciliation_dispatched_at',
    ]))->toBeFalse()
        ->and(Schema::hasColumns('queclink_raw_frames', [
            'encrypted_raw_frame',
            'encrypted_parsed_payload',
        ]))->toBeFalse();

    $migration->up();

    DB::table('queclink_presets')->insert([
        'name' => 'Migration safety profile',
        'slug' => 'migration-safety-profile',
        'target_category' => 'personal_tracker',
        'payload' => json_encode(['server' => ['main_host' => 'protected.example.test']], JSON_THROW_ON_ERROR),
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $profileMigration->up();

    expect(Schema::hasColumns('queclink_pending_commands', [
        'raw_command_encrypted',
        'device_command_request_id',
        'device_command_attempt_id',
        'fulfilled_telemetry_event_id',
        'fulfilled_raw_frame_id',
        'sent_session_id',
        'fulfilled_at',
        'reconciliation_dispatched_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('queclink_raw_frames', [
            'encrypted_raw_frame',
            'encrypted_parsed_payload',
        ]))->toBeTrue()
        ->and(Schema::hasTable('device_configuration_profiles'))->toBeTrue()
        ->and(Schema::hasColumns('queclink_pending_commands', [
            'governed_sequence',
            'governed_role',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('queclink_presets', 'device_configuration_profile_id'))->toBeTrue()
        ->and(DB::table('queclink_presets')->where('slug', 'migration-safety-profile')->value('payload'))->toBe('[]')
        ->and((string) DB::table('device_configuration_profiles')->value('encrypted_payload'))
        ->not->toContain('protected.example.test');
});
