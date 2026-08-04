<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function benefitApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_27_000006_enforce_hr_benefit_application_identity.php',
    );
}

function withBenefitIdentityDatabase(Closure $callback): void
{
    $connection = 'benefit_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-benefit-identity-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary benefit migration database.');
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
        Schema::create('hr_benefit_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('type');
            $table->boolean('is_active')->default(true);
            $table->index(['tenant_id', 'type']);
        });
        Schema::create('hr_benefit_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_profile_id');
            $table->unsignedBigInteger('benefit_plan_id');
            $table->string('status');
            $table->index(
                ['tenant_id', 'employee_profile_id'],
                'hr_benefit_enroll_tenant_emp',
            );
            $table->index(
                ['tenant_id', 'benefit_plan_id'],
                'hr_benefit_enroll_tenant_plan',
            );
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before benefit schema mutation when an application plan name collides', function (): void {
    withBenefitIdentityDatabase(function (): void {
        DB::table('hr_benefit_plans')->insert([
            ['tenant_id' => 11, 'name' => 'Health cover', 'type' => 'health_insurance'],
            ['tenant_id' => 22, 'name' => 'Health cover', 'type' => 'health_insurance'],
        ]);
        $before = Schema::getIndexes('hr_benefit_plans');

        expect(fn () => benefitApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'benefit plan name');
        expect(Schema::getIndexes('hr_benefit_plans'))->toBe($before)
            ->and(Schema::hasIndex('hr_benefit_plans', 'hr_benefit_plans_name_uq'))->toBeFalse();
    });
});

it('fails before benefit schema mutation when an employee plan enrollment collides', function (): void {
    withBenefitIdentityDatabase(function (): void {
        DB::table('hr_benefit_enrollments')->insert([
            [
                'tenant_id' => 11,
                'employee_profile_id' => 7,
                'benefit_plan_id' => 9,
                'status' => 'active',
            ],
            [
                'tenant_id' => 22,
                'employee_profile_id' => 7,
                'benefit_plan_id' => 9,
                'status' => 'terminated',
            ],
        ]);
        $before = Schema::getIndexes('hr_benefit_enrollments');

        expect(fn () => benefitApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'employee benefit plan enrollment');
        expect(Schema::getIndexes('hr_benefit_enrollments'))->toBe($before)
            ->and(Schema::hasIndex(
                'hr_benefit_enrollments',
                'hr_benefit_enrollments_profile_plan_uq',
            ))->toBeFalse();
    });
});

it('enforces and rolls back benefit application identities and read paths', function (): void {
    withBenefitIdentityDatabase(function (): void {
        DB::table('hr_benefit_plans')->insert([
            'tenant_id' => 11,
            'name' => 'Health cover',
            'type' => 'health_insurance',
        ]);
        DB::table('hr_benefit_enrollments')->insert([
            'tenant_id' => 11,
            'employee_profile_id' => 7,
            'benefit_plan_id' => 9,
            'status' => 'active',
        ]);

        $migration = benefitApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_benefit_plans', 'hr_benefit_plans_name_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_benefit_plans', 'hr_benefit_plans_active_type_name_idx'))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_benefit_enrollments',
                'hr_benefit_enrollments_profile_plan_uq',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_benefit_enrollments',
                'hr_benefit_enrollments_plan_status_idx',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_benefit_enrollments',
                'hr_benefit_enrollments_profile_status_idx',
            ))->toBeTrue()
            ->and(Schema::hasIndex('hr_benefit_plans', 'hr_benefit_plans_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_benefit_plans', 'hr_benefit_plans_tenant_id_type_index'))->toBeFalse()
            ->and(Schema::hasIndex(
                'hr_benefit_enrollments',
                'hr_benefit_enrollments_tenant_id_index',
            ))->toBeFalse()
            ->and(Schema::hasIndex('hr_benefit_enrollments', 'hr_benefit_enroll_tenant_emp'))->toBeFalse()
            ->and(Schema::hasIndex('hr_benefit_enrollments', 'hr_benefit_enroll_tenant_plan'))->toBeFalse();

        expect(fn () => DB::table('hr_benefit_plans')->insert([
            'tenant_id' => 22,
            'name' => 'Health cover',
            'type' => 'health_insurance',
        ]))->toThrow(QueryException::class);
        expect(fn () => DB::table('hr_benefit_enrollments')->insert([
            'tenant_id' => 22,
            'employee_profile_id' => 7,
            'benefit_plan_id' => 9,
            'status' => 'terminated',
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasIndex('hr_benefit_plans', 'hr_benefit_plans_name_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_benefit_plans', 'hr_benefit_plans_active_type_name_idx'))->toBeFalse()
            ->and(Schema::hasIndex(
                'hr_benefit_enrollments',
                'hr_benefit_enrollments_profile_plan_uq',
            ))->toBeFalse()
            ->and(Schema::hasIndex(
                'hr_benefit_enrollments',
                'hr_benefit_enrollments_plan_status_idx',
            ))->toBeFalse()
            ->and(Schema::hasIndex('hr_benefit_plans', 'hr_benefit_plans_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_benefit_plans', 'hr_benefit_plans_tenant_id_type_index'))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_benefit_enrollments',
                'hr_benefit_enrollments_tenant_id_index',
            ))->toBeTrue()
            ->and(Schema::hasIndex('hr_benefit_enrollments', 'hr_benefit_enroll_tenant_emp'))->toBeTrue()
            ->and(Schema::hasIndex('hr_benefit_enrollments', 'hr_benefit_enroll_tenant_plan'))->toBeTrue();
    });
});
