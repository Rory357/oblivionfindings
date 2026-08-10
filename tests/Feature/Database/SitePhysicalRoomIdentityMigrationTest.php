<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function sitePhysicalRoomIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_06_000038_consolidate_site_physical_room_identity.php',
    );
}

function withSitePhysicalRoomIdentityDatabase(Closure $callback): void
{
    $connection = 'site_physical_room_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-site-room-identity-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary Site room identity database.');
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
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('site_rooms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->string('linked_room_type')->nullable();
            $table->unsignedBigInteger('linked_room_id')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'name']);
        });
        Schema::create('site_house_rooms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'name']);
        });
        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->timestamps();
        });
        Schema::create('site_house_room_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('device_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('assignable_type');
            $table->unsignedBigInteger('assignable_id');
            $table->timestamps();
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('backfills deterministic room identity without raw id matching and rolls back without deleting history', function (): void {
    withSitePhysicalRoomIdentityDatabase(function (): void {
        DB::table('sites')->insert([['id' => 1], ['id' => 2]]);
        DB::table('site_house_rooms')->insert([
            ['id' => 10, 'tenant_id' => 1, 'site_id' => 1, 'name' => 'Bedroom A', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 20, 'tenant_id' => 1, 'site_id' => 1, 'name' => 'Quiet   Room', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 30, 'tenant_id' => 1, 'site_id' => 1, 'name' => 'Studio', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('site_rooms')->insert([
            ['id' => 10, 'tenant_id' => 1, 'site_id' => 2, 'name' => 'Raw ID collision', 'sort_order' => 1, 'linked_room_type' => null, 'linked_room_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 100, 'tenant_id' => 1, 'site_id' => 1, 'name' => 'Legacy bedroom label', 'sort_order' => 9, 'linked_room_type' => 'house_room', 'linked_room_id' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 101, 'tenant_id' => 1, 'site_id' => 1, 'name' => 'Duplicate legacy pointer', 'sort_order' => 10, 'linked_room_type' => 'house_room', 'linked_room_id' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 102, 'tenant_id' => 1, 'site_id' => 2, 'name' => 'Cross Site pointer', 'sort_order' => 2, 'linked_room_type' => 'house_room', 'linked_room_id' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 200, 'tenant_id' => 1, 'site_id' => 1, 'name' => ' quiet room ', 'sort_order' => 4, 'linked_room_type' => null, 'linked_room_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 300, 'tenant_id' => 1, 'site_id' => 1, 'name' => ' studio ', 'sort_order' => 5, 'linked_room_type' => null, 'linked_room_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 301, 'tenant_id' => 1, 'site_id' => 1, 'name' => 'STUDIO', 'sort_order' => 6, 'linked_room_type' => null, 'linked_room_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('assets')->insert([
            ['id' => 1, 'site_id' => 1, 'room_id' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'site_id' => 2, 'room_id' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'site_id' => null, 'room_id' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('site_house_room_history')->insert([
            'id' => 77,
            'room_id' => 10,
            'notes' => 'Preserve this placement history.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('device_assignments')->insert([
            'id' => 88,
            'assignable_type' => 'room',
            'assignable_id' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = sitePhysicalRoomIdentityMigration();
        $migration->up();

        $createdCanonicalId = (int) DB::table('site_house_rooms')->where('id', 30)->value('site_room_id');
        expect(DB::table('site_house_rooms')->where('id', 10)->value('site_room_id'))->toBe(100)
            ->and(DB::table('site_house_rooms')->where('id', 20)->value('site_room_id'))->toBe(200)
            ->and($createdCanonicalId)->not->toBeIn([10, 300, 301])
            ->and(DB::table('site_rooms')->where('id', 101)->value('linked_room_id'))->toBeNull()
            ->and(DB::table('site_rooms')->where('id', 102)->value('linked_room_id'))->toBeNull()
            ->and(DB::table('assets')->where('id', 1)->value('site_room_id'))->toBe(100)
            ->and(DB::table('assets')->where('id', 1)->value('room_id'))->toBe(10)
            ->and(DB::table('assets')->where('id', 2)->value('site_room_id'))->toBeNull()
            ->and(DB::table('assets')->where('id', 3)->value('site_room_id'))->toBeNull()
            ->and(DB::table('site_house_room_history')->where('id', 77)->value('room_id'))->toBe(10)
            ->and(DB::table('device_assignments')->where('id', 88)->value('assignable_id'))->toBe(100);

        expect(fn () => DB::table('site_house_rooms')->where('id', 20)->update(['site_room_id' => 10]))
            ->toThrow(QueryException::class);
        expect(fn () => DB::table('site_house_rooms')->where('id', 20)->update(['site_room_id' => 100]))
            ->toThrow(QueryException::class);
        expect(fn () => DB::table('assets')->where('id', 1)->update(['site_room_id' => 10]))
            ->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasColumn('site_house_rooms', 'site_room_id'))->toBeFalse()
            ->and(Schema::hasColumn('assets', 'site_room_id'))->toBeFalse()
            ->and(DB::table('site_house_room_history')->where('id', 77)->value('room_id'))->toBe(10)
            ->and(DB::table('device_assignments')->where('id', 88)->value('assignable_id'))->toBe(100)
            ->and(DB::table('site_rooms')->where('id', $createdCanonicalId)->exists())->toBeTrue();
    });
});
