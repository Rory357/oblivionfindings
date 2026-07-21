<?php

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Support\LegacyStorageContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function monitoringFoundationMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_21_150000_refactor_monitoring_foundations_for_single_application.php',
    );
}

function withMonitoringMigrationDatabase(Closure $callback): void
{
    $connection = 'monitoring_migration_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-monitoring-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary monitoring migration database.');
    }

    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        createMonitoringMigrationSupportSchema();
        $foundation = require database_path('migrations/2026_07_18_100001_create_monitoring_foundation_tables.php');
        $foundation->up();
        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

function createMonitoringMigrationSupportSchema(): void
{
    Schema::create('sites', function (Blueprint $table): void {
        $table->id();
        $table->boolean('is_active')->default(true);
        $table->boolean('archived')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    Schema::create('devices', fn (Blueprint $table) => $table->id());
    Schema::create('site_rooms', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('site_id');
    });
    Schema::create('clients', function (Blueprint $table): void {
        $table->id();
        $table->string('status');
        $table->unsignedBigInteger('site_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    Schema::create('hr_employee_profiles', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->boolean('is_active')->default(true);
        $table->date('start_date')->nullable();
        $table->date('end_date')->nullable();
        $table->unsignedBigInteger('primary_site_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    Schema::create('asset_categories', function (Blueprint $table): void {
        $table->id();
        $table->string('slug');
    });
    Schema::create('assets', function (Blueprint $table): void {
        $table->id();
        $table->string('category')->nullable();
        $table->unsignedBigInteger('category_id')->nullable();
        $table->string('status');
        $table->unsignedBigInteger('site_id')->nullable();
        $table->unsignedBigInteger('home_site_id')->nullable();
        $table->unsignedBigInteger('client_id')->nullable();
    });
    Schema::create('device_assignments', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('device_id');
        $table->string('assignable_type');
        $table->unsignedBigInteger('assignable_id');
        $table->timestamp('assigned_at');
        $table->timestamp('released_at')->nullable();
    });
}

function seedMonitoringMigrationObservation(string $assignmentType, int $assignmentId): void
{
    DB::table('devices')->insert(['id' => 1]);
    DB::table('device_assignments')->insert([
        'device_id' => 1,
        'assignable_type' => $assignmentType,
        'assignable_id' => $assignmentId,
        'assigned_at' => now(),
    ]);
    DB::table('monitoring_profiles')->insert([
        LegacyStorageContext::column() => 1,
        'name' => 'Availability',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('monitors')->insert([
        LegacyStorageContext::column() => 1,
        'device_id' => 1,
        'profile_id' => 1,
        'kind' => MonitorKind::Icmp->value,
        'name' => 'Remote gateway',
        'target' => '10.0.0.1',
        'current_state' => MonitorState::Healthy->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('monitor_observations')->insert([
        LegacyStorageContext::column() => 1,
        'monitor_id' => 1,
        'source_key' => 'pre-migration-observation',
        'state' => MonitorState::Healthy->value,
        'observed_at' => now(),
        'ingested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('backfills immutable observation provenance through canonical room ownership', function () {
    withMonitoringMigrationDatabase(function (): void {
        DB::table('sites')->insert(['id' => 1, 'is_active' => true, 'archived' => false]);
        DB::table('devices')->insert(['id' => 1]);
        DB::table('site_rooms')->insert(['id' => 1, 'site_id' => 1]);
        DB::table('device_assignments')->insert([
            'device_id' => 1,
            'assignable_type' => 'room',
            'assignable_id' => 1,
            'assigned_at' => now(),
        ]);
        DB::table('monitoring_collectors')->insert([
            LegacyStorageContext::column() => 1,
            'collector_uuid' => '018f0000-0000-7000-8000-000000000151',
            'name' => 'Remote collector',
            'site_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('monitoring_profiles')->insert([
            LegacyStorageContext::column() => 1,
            'name' => 'Availability',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('monitors')->insert([
            LegacyStorageContext::column() => 1,
            'device_id' => 1,
            'profile_id' => 1,
            'collector_id' => 1,
            'kind' => MonitorKind::Icmp->value,
            'name' => 'Remote gateway',
            'target' => '10.0.0.1',
            'current_state' => MonitorState::Healthy->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('monitor_observations')->insert([
            LegacyStorageContext::column() => 1,
            'monitor_id' => 1,
            'source_key' => 'pre-migration-observation',
            'state' => MonitorState::Healthy->value,
            'observed_at' => now(),
            'ingested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        monitoringFoundationMigration()->up();

        $observation = DB::table('monitor_observations')->first();
        $columns = collect(Schema::getColumns('monitor_observations'))->keyBy('name');
        expect($observation->device_id)->toBe(1)
            ->and($observation->site_id)->toBe(1)
            ->and($observation->collector_id)->toBe(1)
            ->and($columns['device_id']['nullable'])->toBeTrue()
            ->and($columns['site_id']['nullable'])->toBeTrue()
            ->and($columns['collector_id']['nullable'])->toBeTrue();
    });
});

it('rejects soft-deleted canonical targets before expanding the observation schema', function (
    string $assignmentType,
    Closure $createTarget,
) {
    withMonitoringMigrationDatabase(function () use ($assignmentType, $createTarget): void {
        DB::table('sites')->insert([
            'id' => 1,
            'is_active' => true,
            'archived' => false,
            'deleted_at' => $assignmentType === 'site' ? now() : null,
        ]);
        $assignmentId = $createTarget();
        seedMonitoringMigrationObservation($assignmentType, $assignmentId);

        expect(fn () => monitoringFoundationMigration()->up())
            ->toThrow(RuntimeException::class);
        expect(Schema::hasColumn('monitor_observations', 'device_id'))->toBeFalse();
    });
})->with([
    'soft-deleted Site' => ['site', fn (): int => 1],
    'soft-deleted Client' => ['client', function (): int {
        DB::table('clients')->insert([
            'id' => 1,
            'status' => 'active',
            'site_id' => 1,
            'deleted_at' => now(),
        ]);

        return 1;
    }],
    'soft-deleted employee profile' => ['staff', function (): int {
        DB::table('hr_employee_profiles')->insert([
            'id' => 1,
            'user_id' => 7,
            'is_active' => true,
            'primary_site_id' => 1,
            'deleted_at' => now(),
        ]);

        return 7;
    }],
]);

it('fails before schema mutation when global collector identities collide', function () {
    withMonitoringMigrationDatabase(function (): void {
        $uuid = '018f0000-0000-7000-8000-000000000152';
        foreach ([11, 22] as $legacyValue) {
            DB::table('monitoring_collectors')->insert([
                LegacyStorageContext::column() => $legacyValue,
                'collector_uuid' => $uuid,
                'name' => "Collision {$legacyValue}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        expect(fn () => monitoringFoundationMigration()->up())
            ->toThrow(RuntimeException::class, 'Duplicate monitoring collector identifiers require reconciliation');
        expect(Schema::hasColumn('monitor_observations', 'device_id'))->toBeFalse();
    });
});

it('fails before schema mutation when global profile names collide', function () {
    withMonitoringMigrationDatabase(function (): void {
        foreach ([11, 22] as $legacyValue) {
            DB::table('monitoring_profiles')->insert([
                LegacyStorageContext::column() => $legacyValue,
                'name' => 'Core availability',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        expect(fn () => monitoringFoundationMigration()->up())
            ->toThrow(RuntimeException::class, 'Duplicate monitoring profile names require reconciliation');
        expect(Schema::hasColumn('monitor_observations', 'device_id'))->toBeFalse();
    });
});
