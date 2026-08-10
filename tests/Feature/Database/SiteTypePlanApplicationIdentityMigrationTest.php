<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function siteTypePlanApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_01_000011_realign_site_type_plans_application_identity.php',
    );
}

function withSiteTypePlanIdentityDatabase(Closure $callback): void
{
    $connection = 'site_type_plan_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-site-plan-identity-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary Site plan migration database.');
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
        Schema::create('site_type_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
            $table->string('status');
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->index(['tenant_id', 'site_id']);
        });
        Schema::create('site_type_plan_pins', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('site_type_plan_id');
            $table->string('kind');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->index(['tenant_id', 'kind']);
        });
        Schema::create('site_house_rooms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
        });
        Schema::create('site_house_room_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('room_id');
            $table->date('assigned_until')->nullable();
            $table->date('assigned_from');
        });
        Schema::create('site_ho_resources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
            $table->boolean('is_active')->default(true);
            $table->string('name');
        });
        Schema::create('site_facility_zones', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
            $table->boolean('is_active')->default(true);
            $table->string('name');
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before plan schema mutation when a Site has duplicate current slots', function (): void {
    withSiteTypePlanIdentityDatabase(function (): void {
        DB::table('site_type_plans')->insert([
            ['tenant_id' => 11, 'site_id' => 8, 'status' => 'draft', 'version' => 1, 'deleted_at' => null],
            ['tenant_id' => 22, 'site_id' => 8, 'status' => 'draft', 'version' => 2, 'deleted_at' => null],
        ]);

        expect(fn () => siteTypePlanApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'canonical Site plan identity');

        expect(Schema::hasColumn('site_type_plans', 'current_slot'))->toBeFalse()
            ->and(Schema::hasIndex('site_type_plans', 'site_type_plans_site_current_uq'))->toBeFalse();
    });
});

it('enforces one active plan slot per Site and restores compatibility indexes on rollback', function (): void {
    withSiteTypePlanIdentityDatabase(function (): void {
        $draftId = DB::table('site_type_plans')->insertGetId([
            'tenant_id' => 11,
            'site_id' => 8,
            'status' => 'draft',
            'version' => 2,
            'deleted_at' => null,
        ]);
        $discardedId = DB::table('site_type_plans')->insertGetId([
            'tenant_id' => 11,
            'site_id' => 8,
            'status' => 'draft',
            'version' => 1,
            'deleted_at' => now(),
        ]);

        $migration = siteTypePlanApplicationIdentityMigration();
        $migration->up();

        expect(DB::table('site_type_plans')->find($draftId)->current_slot)->toBe('draft')
            ->and(DB::table('site_type_plans')->find($discardedId)->current_slot)->toBeNull();

        foreach ([
            ['site_type_plans', 'site_type_plans_site_current_uq'],
            ['site_type_plans', 'site_type_plans_site_version_idx'],
            ['site_type_plan_pins', 'site_type_plan_pins_plan_sort_idx'],
            ['site_type_plan_pins', 'site_type_plan_pins_device_kind_idx'],
            ['site_house_rooms', 'site_house_rooms_site_active_sort_idx'],
            ['site_house_room_history', 'site_room_history_open_dates_idx'],
            ['site_ho_resources', 'site_ho_resources_site_active_name_idx'],
            ['site_facility_zones', 'site_facility_zones_site_active_name_idx'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeTrue();
        }

        foreach ([
            ['site_type_plans', 'site_type_plans_tenant_id_index'],
            ['site_type_plans', 'site_type_plans_tenant_id_site_id_index'],
            ['site_type_plan_pins', 'site_type_plan_pins_tenant_id_index'],
            ['site_type_plan_pins', 'site_type_plan_pins_tenant_id_kind_index'],
            ['site_house_rooms', 'site_house_rooms_tenant_id_index'],
            ['site_house_room_history', 'site_house_room_history_tenant_id_index'],
            ['site_ho_resources', 'site_ho_resources_tenant_id_index'],
            ['site_facility_zones', 'site_facility_zones_tenant_id_index'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeFalse();
        }

        expect(fn () => DB::table('site_type_plans')->insert([
            'tenant_id' => 22,
            'site_id' => 8,
            'status' => 'draft',
            'current_slot' => 'draft',
            'version' => 3,
            'deleted_at' => null,
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasColumn('site_type_plans', 'current_slot'))->toBeFalse();

        foreach ([
            ['site_type_plans', 'site_type_plans_tenant_id_index'],
            ['site_type_plans', 'site_type_plans_tenant_id_site_id_index'],
            ['site_type_plan_pins', 'site_type_plan_pins_tenant_id_index'],
            ['site_type_plan_pins', 'site_type_plan_pins_tenant_id_kind_index'],
            ['site_house_rooms', 'site_house_rooms_tenant_id_index'],
            ['site_house_room_history', 'site_house_room_history_tenant_id_index'],
            ['site_ho_resources', 'site_ho_resources_tenant_id_index'],
            ['site_facility_zones', 'site_facility_zones_tenant_id_index'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeTrue();
        }
    });
});
