<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function compensationApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_27_000007_enforce_hr_compensation_application_identity.php',
    );
}

function withCompensationIdentityDatabase(Closure $callback): void
{
    $connection = 'compensation_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-compensation-identity-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary compensation migration database.');
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
        Schema::create('hr_salary_bands', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('position_role');
            $table->string('band_name');
            $table->date('effective_from');
            $table->boolean('is_active')->default(true);
            $table->index(['tenant_id', 'position_role']);
        });
        Schema::create('hr_compensation_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_profile_id');
            $table->date('effective_date');
            $table->index(
                ['tenant_id', 'employee_profile_id', 'effective_date'],
                'hr_comp_hist_tenant_emp_date',
            );
        });
        Schema::create('hr_compensation_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('status');
            $table->date('effective_date');
            $table->unsignedBigInteger('created_by')->nullable();
        });
        Schema::create('hr_compensation_review_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('compensation_review_id');
            $table->unsignedBigInteger('employee_profile_id');
            $table->string('status');
        });
        Schema::create('hr_bonus_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_profile_id');
            $table->string('bonus_type');
            $table->string('status');
            $table->date('payment_date');
            $table->index(['tenant_id', 'status']);
            $table->index(['employee_profile_id', 'bonus_type']);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before compensation schema mutation when an application salary band identity collides', function (): void {
    withCompensationIdentityDatabase(function (): void {
        DB::table('hr_salary_bands')->insert([
            [
                'tenant_id' => 11,
                'position_role' => 'support_worker',
                'band_name' => 'Band A',
                'effective_from' => '2026-07-01',
            ],
            [
                'tenant_id' => 22,
                'position_role' => 'support_worker',
                'band_name' => 'Band A',
                'effective_from' => '2026-07-01',
            ],
        ]);
        $before = Schema::getIndexes('hr_salary_bands');

        expect(fn () => compensationApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'salary band role, name and effective date');
        expect(Schema::getIndexes('hr_salary_bands'))->toBe($before)
            ->and(Schema::hasIndex(
                'hr_salary_bands',
                'hr_salary_bands_role_name_effective_uq',
            ))->toBeFalse();
    });
});

it('fails before compensation schema mutation when a review employee identity collides', function (): void {
    withCompensationIdentityDatabase(function (): void {
        DB::table('hr_compensation_review_items')->insert([
            [
                'compensation_review_id' => 7,
                'employee_profile_id' => 9,
                'status' => 'pending',
            ],
            [
                'compensation_review_id' => 7,
                'employee_profile_id' => 9,
                'status' => 'approved',
            ],
        ]);
        $before = Schema::getIndexes('hr_compensation_review_items');

        expect(fn () => compensationApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'compensation review employee');
        expect(Schema::getIndexes('hr_compensation_review_items'))->toBe($before)
            ->and(Schema::hasIndex(
                'hr_compensation_review_items',
                'hr_compensation_review_items_review_profile_uq',
            ))->toBeFalse();
    });
});

it('enforces and rolls back compensation application identities and read paths', function (): void {
    withCompensationIdentityDatabase(function (): void {
        DB::table('hr_salary_bands')->insert([
            'tenant_id' => 11,
            'position_role' => 'support_worker',
            'band_name' => 'Band A',
            'effective_from' => '2026-07-01',
        ]);
        DB::table('hr_compensation_review_items')->insert([
            'compensation_review_id' => 7,
            'employee_profile_id' => 9,
            'status' => 'pending',
        ]);

        $migration = compensationApplicationIdentityMigration();
        $migration->up();

        foreach ([
            ['hr_salary_bands', 'hr_salary_bands_role_name_effective_uq'],
            ['hr_salary_bands', 'hr_salary_bands_active_role_effective_idx'],
            ['hr_compensation_history', 'hr_compensation_history_profile_effective_idx'],
            ['hr_compensation_reviews', 'hr_compensation_reviews_status_effective_idx'],
            ['hr_compensation_reviews', 'hr_compensation_reviews_created_status_idx'],
            ['hr_compensation_review_items', 'hr_compensation_review_items_review_profile_uq'],
            ['hr_compensation_review_items', 'hr_compensation_review_items_profile_status_idx'],
            ['hr_bonus_payments', 'hr_bonus_payments_status_payment_idx'],
            ['hr_bonus_payments', 'hr_bonus_payments_profile_status_idx'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeTrue();
        }

        foreach ([
            ['hr_salary_bands', 'hr_salary_bands_tenant_id_index'],
            ['hr_salary_bands', 'hr_salary_bands_tenant_id_position_role_index'],
            ['hr_compensation_history', 'hr_compensation_history_tenant_id_index'],
            ['hr_compensation_history', 'hr_comp_hist_tenant_emp_date'],
            ['hr_compensation_reviews', 'hr_compensation_reviews_tenant_id_index'],
            ['hr_bonus_payments', 'hr_bonus_payments_tenant_id_index'],
            ['hr_bonus_payments', 'hr_bonus_payments_tenant_id_status_index'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeFalse();
        }

        expect(fn () => DB::table('hr_salary_bands')->insert([
            'tenant_id' => 22,
            'position_role' => 'support_worker',
            'band_name' => 'Band A',
            'effective_from' => '2026-07-01',
        ]))->toThrow(QueryException::class);
        expect(fn () => DB::table('hr_compensation_review_items')->insert([
            'compensation_review_id' => 7,
            'employee_profile_id' => 9,
            'status' => 'rejected',
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasIndex(
            'hr_salary_bands',
            'hr_salary_bands_role_name_effective_uq',
        ))->toBeFalse()
            ->and(Schema::hasIndex(
                'hr_compensation_review_items',
                'hr_compensation_review_items_review_profile_uq',
            ))->toBeFalse()
            ->and(Schema::hasIndex(
                'hr_salary_bands',
                'hr_salary_bands_tenant_id_position_role_index',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_compensation_history',
                'hr_comp_hist_tenant_emp_date',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_bonus_payments',
                'hr_bonus_payments_tenant_id_status_index',
            ))->toBeTrue();
    });
});
