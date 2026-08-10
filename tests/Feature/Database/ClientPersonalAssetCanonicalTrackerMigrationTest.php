<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function clientPersonalAssetCanonicalTrackerMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_05_000031_add_canonical_tracker_device_to_client_personal_assets.php',
    );
}

function withClientPersonalAssetTrackerDatabase(Closure $callback): void
{
    $connection = 'client_personal_asset_tracker_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-client-asset-tracker-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary client asset tracker database.');
    }

    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_location_hardware_id')->nullable();
        });
        Schema::create('client_personal_assets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tracker_hardware_id')->nullable();
            $table->timestamps();
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('backfills only unambiguous canonical tracker references and retains legacy history', function (): void {
    withClientPersonalAssetTrackerDatabase(function (): void {
        DB::table('devices')->insert([
            ['id' => 10, 'legacy_location_hardware_id' => 101],
            ['id' => 20, 'legacy_location_hardware_id' => 202],
            ['id' => 21, 'legacy_location_hardware_id' => 202],
        ]);
        DB::table('client_personal_assets')->insert([
            [
                'id' => 1,
                'tracker_hardware_id' => 101,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'tracker_hardware_id' => 202,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $migration = clientPersonalAssetCanonicalTrackerMigration();
        $migration->up();

        expect(Schema::hasColumn('client_personal_assets', 'tracker_device_id'))->toBeTrue()
            ->and(DB::table('client_personal_assets')->where('id', 1)->value('tracker_device_id'))->toBe(10)
            ->and(DB::table('client_personal_assets')->where('id', 1)->value('tracker_hardware_id'))->toBe(101)
            ->and(DB::table('client_personal_assets')->where('id', 2)->value('tracker_device_id'))->toBeNull()
            ->and(DB::table('client_personal_assets')->where('id', 2)->value('tracker_hardware_id'))->toBe(202);

        $migration->down();

        expect(Schema::hasColumn('client_personal_assets', 'tracker_device_id'))->toBeFalse()
            ->and(DB::table('client_personal_assets')->where('id', 1)->value('tracker_hardware_id'))->toBe(101);
    });
});
