<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrSuccessionSiteIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_03_000023_realign_hr_succession_site_identity.php',
    );
}

function withHrSuccessionSiteIdentityDatabase(Closure $callback): void
{
    $connection = 'hr_succession_site_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-succession-site-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary HR succession migration database.');
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
            $table->string('name');
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('hr_positions', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
        Schema::create('hr_employee_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('primary_site_id')->nullable();
            $table->json('secondary_site_ids')->nullable();
        });
        Schema::create('hr_succession_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('role_title');
            $table->string('department')->nullable();
            $table->string('risk_level')->default('medium');
            $table->unsignedBigInteger('current_holder_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
        });
        Schema::create('hr_succession_candidates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('succession_plan_id');
            $table->unsignedBigInteger('employee_profile_id');
            $table->string('readiness')->default('developing');
            $table->timestamps();
            $table->index(['succession_plan_id', 'readiness']);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before succession schema mutation when legacy Site provenance is missing or ambiguous', function (): void {
    withHrSuccessionSiteIdentityDatabase(function (): void {
        DB::table('hr_succession_plans')->insert([
            'tenant_id' => 7,
            'role_title' => 'Unproven role',
            'is_active' => true,
        ]);
        $before = Schema::getIndexes('hr_succession_plans');

        expect(fn () => hrSuccessionSiteIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'no current holder or candidate evidence');

        expect(Schema::getIndexes('hr_succession_plans'))->toBe($before)
            ->and(Schema::hasColumn('hr_succession_plans', 'site_id'))->toBeFalse()
            ->and(Schema::hasColumn('hr_succession_plans', 'active_site_role_key'))->toBeFalse();
    });

    withHrSuccessionSiteIdentityDatabase(function (): void {
        DB::table('sites')->insert([
            ['id' => 1, 'name' => 'North'],
            ['id' => 2, 'name' => 'South'],
        ]);
        DB::table('users')->insert([
            ['id' => 10, 'name' => 'One'],
            ['id' => 20, 'name' => 'Two'],
        ]);
        DB::table('hr_employee_profiles')->insert([
            [
                'id' => 101,
                'user_id' => 10,
                'primary_site_id' => 1,
                'secondary_site_ids' => json_encode([2], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 202,
                'user_id' => 20,
                'primary_site_id' => 2,
                'secondary_site_ids' => json_encode([1], JSON_THROW_ON_ERROR),
            ],
        ]);
        DB::table('hr_succession_plans')->insert([
            'id' => 50,
            'tenant_id' => 7,
            'role_title' => 'Ambiguous role',
            'is_active' => true,
        ]);
        DB::table('hr_succession_candidates')->insert([
            ['succession_plan_id' => 50, 'employee_profile_id' => 101],
            ['succession_plan_id' => 50, 'employee_profile_id' => 202],
        ]);

        expect(fn () => hrSuccessionSiteIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'unambiguous Site provenance');

        expect(Schema::hasColumn('hr_succession_plans', 'site_id'))->toBeFalse();
    });
});

it('backfills and enforces succession Site identity and restores exact compatibility indexes', function (): void {
    withHrSuccessionSiteIdentityDatabase(function (): void {
        DB::table('sites')->insert([
            ['id' => 1, 'name' => 'North'],
            ['id' => 2, 'name' => 'South'],
        ]);
        DB::table('users')->insert([
            ['id' => 10, 'name' => 'Holder'],
            ['id' => 20, 'name' => 'Candidate'],
        ]);
        DB::table('hr_employee_profiles')->insert([
            [
                'id' => 101,
                'user_id' => 10,
                'primary_site_id' => 1,
                'secondary_site_ids' => json_encode([2], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 202,
                'user_id' => 20,
                'primary_site_id' => 2,
                'secondary_site_ids' => json_encode([1], JSON_THROW_ON_ERROR),
            ],
        ]);
        DB::table('hr_succession_plans')->insert([
            'id' => 50,
            'tenant_id' => 7,
            'role_title' => ' Service Manager ',
            'department' => 'Operations',
            'risk_level' => 'critical',
            'current_holder_user_id' => 10,
            'is_active' => true,
        ]);
        DB::table('hr_succession_candidates')->insert([
            'succession_plan_id' => 50,
            'employee_profile_id' => 202,
            'readiness' => 'ready_1_year',
        ]);

        $migration = hrSuccessionSiteIdentityMigration();
        $migration->up();

        expect((int) DB::table('hr_succession_plans')->where('id', 50)->value('site_id'))->toBe(1)
            ->and(Schema::hasColumn('hr_succession_plans', 'site_id'))->toBeTrue()
            ->and(Schema::hasColumn('hr_succession_plans', 'active_site_role_key'))->toBeTrue()
            ->and(Schema::hasIndex('hr_succession_plans', 'hr_succession_plans_active_site_role_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_succession_plans', 'hr_succession_plans_site_active_risk_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_succession_plans', 'hr_succession_plans_site_department_active_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_succession_plans', 'hr_succession_plans_site_holder_active_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_succession_candidates', 'hr_succession_candidates_plan_profile_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_succession_plans', 'hr_succession_plans_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_succession_plans', 'hr_succession_plans_tenant_id_is_active_index'))->toBeFalse();

        expect(fn () => DB::table('hr_succession_plans')->insert([
            'tenant_id' => 99,
            'site_id' => 1,
            'role_title' => 'service manager',
            'risk_level' => 'high',
            'is_active' => true,
        ]))->toThrow(QueryException::class);
        expect(fn () => DB::table('hr_succession_candidates')->insert([
            'succession_plan_id' => 50,
            'employee_profile_id' => 202,
            'readiness' => 'ready_now',
        ]))->toThrow(QueryException::class);

        DB::table('hr_succession_plans')->insert([
            'tenant_id' => 99,
            'site_id' => 1,
            'role_title' => 'service manager',
            'risk_level' => 'high',
            'is_active' => false,
        ]);

        $migration->down();

        expect(Schema::hasColumn('hr_succession_plans', 'site_id'))->toBeFalse()
            ->and(Schema::hasColumn('hr_succession_plans', 'active_site_role_key'))->toBeFalse()
            ->and(Schema::hasIndex('hr_succession_plans', 'hr_succession_plans_active_site_role_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_succession_candidates', 'hr_succession_candidates_plan_profile_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_succession_plans', 'hr_succession_plans_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_succession_plans', 'hr_succession_plans_tenant_id_is_active_index'))->toBeTrue();
    });
});
