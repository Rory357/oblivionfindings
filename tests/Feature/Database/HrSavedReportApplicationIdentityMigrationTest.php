<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrSavedReportApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_02_000022_realign_hr_saved_report_application_identity.php',
    );
}

function withHrSavedReportIdentityDatabase(Closure $callback): void
{
    $connection = 'hr_saved_report_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-saved-report-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary HR saved report migration database.');
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
        Schema::create('hr_saved_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('report_type');
            $table->json('fields');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['tenant_id', 'report_type']);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before index mutation when creator-owned report names collide', function (): void {
    withHrSavedReportIdentityDatabase(function (): void {
        DB::table('hr_saved_reports')->insert([
            [
                'tenant_id' => null,
                'name' => 'People register',
                'report_type' => 'employee',
                'fields' => json_encode(['name']),
                'created_by' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 22,
                'name' => 'People register',
                'report_type' => 'employee',
                'fields' => json_encode(['name']),
                'created_by' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $before = Schema::getIndexes('hr_saved_reports');

        expect(fn () => hrSavedReportApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'duplicate creator/name definitions');

        expect(Schema::getIndexes('hr_saved_reports'))->toBe($before)
            ->and(Schema::hasIndex('hr_saved_reports', 'hr_saved_reports_creator_name_uq'))->toBeFalse();
    });
});

it('enforces creator-owned identity and restores exact compatibility indexes', function (): void {
    withHrSavedReportIdentityDatabase(function (): void {
        DB::table('hr_saved_reports')->insert([
            'tenant_id' => null,
            'name' => 'People register',
            'report_type' => 'employee',
            'fields' => json_encode(['name']),
            'created_by' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = hrSavedReportApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_saved_reports', 'hr_saved_reports_creator_name_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_saved_reports', 'hr_saved_reports_creator_updated_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_saved_reports', 'hr_saved_reports_type_updated_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_saved_reports', 'hr_saved_reports_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_saved_reports', 'hr_saved_reports_tenant_id_report_type_index'))->toBeFalse();

        expect(fn () => DB::table('hr_saved_reports')->insert([
            'tenant_id' => 22,
            'name' => 'People register',
            'report_type' => 'leave',
            'fields' => json_encode(['employee_name']),
            'created_by' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);

        DB::table('hr_saved_reports')->insert([
            'tenant_id' => 22,
            'name' => 'People register',
            'report_type' => 'leave',
            'fields' => json_encode(['employee_name']),
            'created_by' => 11,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->down();

        expect(Schema::hasIndex('hr_saved_reports', 'hr_saved_reports_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_saved_reports', 'hr_saved_reports_tenant_id_report_type_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_saved_reports', 'hr_saved_reports_creator_name_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_saved_reports', 'hr_saved_reports_creator_updated_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_saved_reports', 'hr_saved_reports_type_updated_idx'))->toBeFalse()
            ->and(DB::table('hr_saved_reports')->count())->toBe(2);
    });
});
