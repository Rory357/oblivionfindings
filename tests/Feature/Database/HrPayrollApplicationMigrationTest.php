<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrPayrollApplicationMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_28_000004_realign_hr_payroll_application.php',
    );
}

function withHrPayrollApplicationDatabase(Closure $callback): void
{
    $connection = 'hr_payroll_application_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-payroll-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary HR payroll migration database.');
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
        Schema::create('hr_payroll_export_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_default']);
        });
        Schema::create('hr_payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status');
            $table->index(['tenant_id', 'period_start', 'status']);
        });
        Schema::create('hr_pay_rate_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->string('position_role')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('service_context_id')->nullable();
            $table->index(['tenant_id', 'is_active', 'priority'], 'hr_pay_rate_tenant_active_priority_idx');
            $table->index(['tenant_id', 'position_role', 'site_id'], 'hr_pay_rate_role_site_idx');
        });
        Schema::create('hr_leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('status');
            $table->string('leave_type');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->index(['tenant_id', 'status', 'starts_at']);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('rejects duplicate application payroll profile names before changing indexes', function (): void {
    withHrPayrollApplicationDatabase(function (): void {
        DB::table('hr_payroll_export_profiles')->insert([
            ['tenant_id' => 11, 'name' => 'Primary payroll', 'is_default' => true],
            ['tenant_id' => 22, 'name' => 'Primary payroll', 'is_default' => false],
        ]);

        expect(fn () => hrPayrollApplicationMigration()->up())
            ->toThrow(RuntimeException::class, 'duplicate names exist')
            ->and(Schema::hasIndex(
                'hr_payroll_export_profiles',
                'hr_payroll_export_profiles_tenant_id_name_unique',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_payroll_export_profiles',
                'hr_payroll_export_profiles_name_uq',
            ))->toBeFalse();
    });
});

it('rejects multiple application payroll defaults before changing indexes', function (): void {
    withHrPayrollApplicationDatabase(function (): void {
        DB::table('hr_payroll_export_profiles')->insert([
            ['tenant_id' => 11, 'name' => 'Primary payroll', 'is_default' => true],
            ['tenant_id' => 22, 'name' => 'Backup payroll', 'is_default' => true],
        ]);

        expect(fn () => hrPayrollApplicationMigration()->up())
            ->toThrow(RuntimeException::class, 'multiple defaults exist')
            ->and(Schema::hasIndex(
                'hr_payroll_export_profiles',
                'hr_payroll_export_profiles_tenant_id_is_default_index',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_payroll_export_profiles',
                'hr_payroll_export_profiles_one_default_uq',
            ))->toBeFalse();
    });
});

it('enforces application payroll identities and exactly restores compatibility indexes', function (): void {
    withHrPayrollApplicationDatabase(function (): void {
        $migration = hrPayrollApplicationMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_payroll_export_profiles', 'hr_payroll_export_profiles_name_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_payroll_export_profiles', 'hr_payroll_export_profiles_one_default_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_payroll_runs', 'hr_payroll_runs_period_status_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_pay_rate_rules', 'hr_pay_rate_active_priority_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_leave_requests', 'hr_leave_requests_payroll_window_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_payroll_runs', 'hr_payroll_runs_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_pay_rate_rules', 'hr_pay_rate_tenant_active_priority_idx'))->toBeFalse();

        DB::table('hr_payroll_export_profiles')->insert([
            'tenant_id' => 11,
            'name' => 'Primary payroll',
            'is_default' => true,
        ]);
        expect(fn () => DB::table('hr_payroll_export_profiles')->insert([
            'tenant_id' => 22,
            'name' => 'Secondary payroll',
            'is_default' => true,
        ]))->toThrow(QueryException::class);
        expect(fn () => DB::table('hr_payroll_export_profiles')->insert([
            'tenant_id' => 22,
            'name' => 'Primary payroll',
            'is_default' => false,
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasIndex('hr_payroll_export_profiles', 'hr_payroll_export_profiles_name_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_payroll_export_profiles', 'hr_payroll_export_profiles_tenant_id_name_unique'))->toBeTrue()
            ->and(Schema::hasIndex('hr_payroll_runs', 'hr_payroll_runs_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_payroll_runs', 'hr_payroll_runs_tenant_id_period_start_status_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_pay_rate_rules', 'hr_pay_rate_tenant_active_priority_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_leave_requests', 'hr_leave_requests_tenant_id_status_starts_at_index'))->toBeTrue();

        DB::table('hr_payroll_export_profiles')->insert([
            'tenant_id' => 22,
            'name' => 'Primary payroll',
            'is_default' => true,
        ]);
        expect(DB::table('hr_payroll_export_profiles')->count())->toBe(2);
    });
});
