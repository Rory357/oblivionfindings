<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function payslipApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_28_000001_enforce_hr_payslip_application_identity.php',
    );
}

function withPayslipIdentityDatabase(Closure $callback): void
{
    $connection = 'payslip_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-payslip-identity-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary payslip migration database.');
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
        Schema::create('hr_payslips', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->date('pay_period_start');
            $table->date('pay_period_end');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'pay_period_start']);
            $table->index(['tenant_id', 'status']);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before payslip schema mutation when an employee period collides', function (): void {
    withPayslipIdentityDatabase(function (): void {
        DB::table('hr_payslips')->insert([
            [
                'tenant_id' => 11,
                'user_id' => 7,
                'pay_period_start' => '2026-07-01',
                'pay_period_end' => '2026-07-14',
                'status' => 'draft',
            ],
            [
                'tenant_id' => 22,
                'user_id' => 7,
                'pay_period_start' => '2026-07-01',
                'pay_period_end' => '2026-07-14',
                'status' => 'paid',
            ],
        ]);

        expect(fn () => payslipApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'payslip identity');
        expect(Schema::hasIndex('hr_payslips', 'hr_payslips_user_period_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_payslips', 'hr_payslips_tenant_id_status_index'))->toBeTrue();
    });
});

it('enforces and exactly rolls back application payslip identity and read paths', function (): void {
    withPayslipIdentityDatabase(function (): void {
        DB::table('hr_payslips')->insert([
            'tenant_id' => 11,
            'user_id' => 7,
            'pay_period_start' => '2026-07-01',
            'pay_period_end' => '2026-07-14',
            'status' => 'draft',
        ]);

        $migration = payslipApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_payslips', 'hr_payslips_user_period_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_payslips', 'hr_payslips_status_period_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_payslips', 'hr_payslips_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_payslips', 'hr_payslips_tenant_id_status_index'))->toBeFalse();

        expect(fn () => DB::table('hr_payslips')->insert([
            'tenant_id' => 22,
            'user_id' => 7,
            'pay_period_start' => '2026-07-01',
            'pay_period_end' => '2026-07-14',
            'status' => 'paid',
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasIndex('hr_payslips', 'hr_payslips_user_period_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_payslips', 'hr_payslips_status_period_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_payslips', 'hr_payslips_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_payslips', 'hr_payslips_tenant_id_user_id_pay_period_start_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_payslips', 'hr_payslips_tenant_id_status_index'))->toBeTrue();
    });
});
