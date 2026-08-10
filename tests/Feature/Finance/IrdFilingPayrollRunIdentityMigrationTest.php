<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function irdPayrollRunIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_22_124000_enforce_ird_payroll_run_filing_identity.php',
    );
}

function withIrdPayrollRunIdentityDatabase(Closure $callback): void
{
    $connection = 'ird_payroll_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-ird-payroll-identity-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary IRD identity migration database.');
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
        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

function createLegacyIrdFilingIdentityTable(): void
{
    Schema::create('fin_ird_filings', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('payroll_run_id')->nullable();
        $table->string('filing_type')->default('payday');
        $table->index('payroll_run_id');
    });
}

it('installs the nullable payday identity and restores the legacy index on rollback', function (): void {
    withIrdPayrollRunIdentityDatabase(function (): void {
        createLegacyIrdFilingIdentityTable();
        $migration = irdPayrollRunIdentityMigration();
        $migration->up();

        $unique = collect(Schema::getIndexes('fin_ird_filings'))
            ->firstWhere('name', 'fin_ird_filings_payroll_run_id_uq');
        expect($unique['columns'] ?? null)->toBe(['payroll_run_id'])
            ->and($unique['unique'] ?? null)->toBeTrue();

        DB::table('fin_ird_filings')->insert(['payroll_run_id' => null]);
        DB::table('fin_ird_filings')->insert(['payroll_run_id' => null]);
        DB::table('fin_ird_filings')->insert(['payroll_run_id' => 701]);
        expect(fn () => DB::table('fin_ird_filings')->insert(['payroll_run_id' => 701]))
            ->toThrow(QueryException::class);

        $migration->down();
        $indexes = collect(Schema::getIndexes('fin_ird_filings'))->keyBy('name');
        expect($indexes)->not->toHaveKey('fin_ird_filings_payroll_run_id_uq')
            ->and($indexes)->toHaveKey('fin_ird_filings_payroll_run_id_index')
            ->and($indexes['fin_ird_filings_payroll_run_id_index']['unique'] ?? null)->toBeFalse();

        DB::table('fin_ird_filings')->insert(['payroll_run_id' => 701]);
        expect(DB::table('fin_ird_filings')->where('payroll_run_id', 701)->count())->toBe(2);
    });
});

it('refuses existing duplicate payroll identities before changing the legacy index', function (): void {
    withIrdPayrollRunIdentityDatabase(function (): void {
        createLegacyIrdFilingIdentityTable();
        DB::table('fin_ird_filings')->insert([
            ['payroll_run_id' => 801],
            ['payroll_run_id' => 801],
        ]);

        expect(fn () => irdPayrollRunIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'payroll run 801 already has 2 IRD filings');

        $indexes = collect(Schema::getIndexes('fin_ird_filings'))->keyBy('name');
        expect($indexes)->not->toHaveKey('fin_ird_filings_payroll_run_id_uq')
            ->and($indexes)->toHaveKey('fin_ird_filings_payroll_run_id_index')
            ->and($indexes['fin_ird_filings_payroll_run_id_index']['unique'] ?? null)->toBeFalse();
    });
});

it('recovers when the unique identity exists before legacy index cleanup completes', function (): void {
    withIrdPayrollRunIdentityDatabase(function (): void {
        createLegacyIrdFilingIdentityTable();
        Schema::table('fin_ird_filings', function (Blueprint $table): void {
            $table->unique('payroll_run_id', 'fin_ird_filings_payroll_run_id_uq');
        });

        irdPayrollRunIdentityMigration()->up();

        $indexes = collect(Schema::getIndexes('fin_ird_filings'))->keyBy('name');
        expect($indexes)->toHaveKey('fin_ird_filings_payroll_run_id_uq')
            ->and($indexes['fin_ird_filings_payroll_run_id_uq']['unique'] ?? null)->toBeTrue()
            ->and($indexes)->not->toHaveKey('fin_ird_filings_payroll_run_id_index');
    });
});
