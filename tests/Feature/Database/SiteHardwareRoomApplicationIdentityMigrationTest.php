<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function siteHardwareRoomApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_02_000012_realign_site_hardware_rooms_application_identity.php',
    );
}

function withSiteHardwareRoomIdentityDatabase(Closure $callback, bool $withCompatibilityIndex = true): void
{
    $connection = 'site_hardware_room_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-site-hardware-room-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary Site hardware-room migration database.');
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
        Schema::create('site_rooms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->integer('sort_order')->default(0);
        });
        if ($withCompatibilityIndex) {
            Schema::table('site_rooms', function (Blueprint $table): void {
                $table->index('tenant_id', 'site_rooms_tenant_id_index');
            });
        }

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before hardware-room schema mutation when a Site has duplicate names', function (): void {
    withSiteHardwareRoomIdentityDatabase(function (): void {
        DB::table('site_rooms')->insert([
            ['tenant_id' => 11, 'site_id' => 8, 'name' => 'Network room', 'sort_order' => 1],
            ['tenant_id' => 22, 'site_id' => 8, 'name' => 'Network room', 'sort_order' => 2],
        ]);

        expect(fn () => siteHardwareRoomApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'canonical Site hardware-room identity');

        expect(Schema::hasIndex('site_rooms', 'site_rooms_site_name_uq'))->toBeFalse()
            ->and(Schema::hasIndex('site_rooms', 'site_rooms_tenant_id_index'))->toBeTrue();
    });
});

it('supports a clean migration chain where the earlier application migration removed the compatibility index', function (): void {
    withSiteHardwareRoomIdentityDatabase(function (): void {
        DB::table('site_rooms')->insert([
            'tenant_id' => 11,
            'site_id' => 8,
            'name' => 'Network room',
            'sort_order' => 1,
        ]);

        $migration = siteHardwareRoomApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex('site_rooms', 'site_rooms_site_name_uq'))->toBeTrue()
            ->and(Schema::hasIndex('site_rooms', 'site_rooms_site_sort_idx'))->toBeTrue()
            ->and(Schema::hasIndex('site_rooms', 'site_rooms_tenant_id_index'))->toBeFalse();
    }, withCompatibilityIndex: false);
});

it('enforces Site room identity and restores the compatibility index on rollback', function (): void {
    withSiteHardwareRoomIdentityDatabase(function (): void {
        DB::table('site_rooms')->insert([
            'tenant_id' => 11,
            'site_id' => 8,
            'name' => 'Network room',
            'sort_order' => 1,
        ]);

        $migration = siteHardwareRoomApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex('site_rooms', 'site_rooms_site_name_uq'))->toBeTrue()
            ->and(Schema::hasIndex('site_rooms', 'site_rooms_site_sort_idx'))->toBeTrue()
            ->and(Schema::hasIndex('site_rooms', 'site_rooms_tenant_id_index'))->toBeFalse();

        expect(fn () => DB::table('site_rooms')->insert([
            'tenant_id' => 22,
            'site_id' => 8,
            'name' => 'Network room',
            'sort_order' => 2,
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasIndex('site_rooms', 'site_rooms_site_name_uq'))->toBeFalse()
            ->and(Schema::hasIndex('site_rooms', 'site_rooms_site_sort_idx'))->toBeFalse()
            ->and(Schema::hasIndex('site_rooms', 'site_rooms_tenant_id_index'))->toBeTrue();
    });
});
