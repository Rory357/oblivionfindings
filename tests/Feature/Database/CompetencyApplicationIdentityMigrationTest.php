<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function competencyApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_27_000004_enforce_hr_competency_application_identity.php',
    );
}

function withCompetencyIdentityDatabase(Closure $callback): void
{
    $connection = 'competency_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-competency-identity-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary competency migration database.');
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
        Schema::create('hr_competencies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
        });
        Schema::create('hr_competency_assessments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_profile_id');
            $table->date('assessment_date');
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before competency schema mutation when an application name collides', function (): void {
    withCompetencyIdentityDatabase(function (): void {
        DB::table('hr_competencies')->insert([
            ['tenant_id' => 11, 'name' => 'Medication support'],
            ['tenant_id' => 22, 'name' => 'Medication support'],
        ]);
        $beforeCompetencies = Schema::getIndexes('hr_competencies');
        $beforeAssessments = Schema::getIndexes('hr_competency_assessments');

        expect(fn () => competencyApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'competency name');

        expect(Schema::getIndexes('hr_competencies'))->toBe($beforeCompetencies)
            ->and(Schema::getIndexes('hr_competency_assessments'))->toBe($beforeAssessments)
            ->and(Schema::hasIndex('hr_competencies', 'hr_competencies_name_uq'))->toBeFalse();
    });
});

it('enforces and rolls back application competency identity and read paths', function (): void {
    withCompetencyIdentityDatabase(function (): void {
        DB::table('hr_competencies')->insert([
            'tenant_id' => 11,
            'name' => 'Medication support',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $migration = competencyApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_competencies', 'hr_competencies_name_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_competencies', 'hr_competencies_active_sort_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_competency_assessments', 'hr_comp_assess_profile_date_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_competencies', 'hr_competencies_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_competency_assessments', 'hr_competency_assessments_tenant_id_index'))->toBeFalse();

        expect(fn () => DB::table('hr_competencies')->insert([
            'tenant_id' => 22,
            'name' => 'Medication support',
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasIndex('hr_competencies', 'hr_competencies_name_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_competencies', 'hr_competencies_active_sort_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_competency_assessments', 'hr_comp_assess_profile_date_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_competencies', 'hr_competencies_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_competency_assessments', 'hr_competency_assessments_tenant_id_index'))->toBeTrue();
    });
});
