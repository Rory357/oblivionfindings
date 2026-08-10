<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrSkillsApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_02_000014_realign_hr_skills_application_identity.php',
    );
}

function withHrSkillsIdentityDatabase(Closure $callback): void
{
    $connection = 'hr_skills_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-skills-identity-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary HR skills migration database.');
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
        Schema::create('hr_skills', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('category');
            $table->boolean('is_active')->default(true);
            $table->index(['tenant_id', 'category']);
        });
        Schema::create('hr_employee_skills', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_profile_id');
            $table->unsignedBigInteger('skill_id');
            $table->string('proficiency_level');
            $table->unique(['employee_profile_id', 'skill_id']);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before mutating skills indexes when application identities collide', function (): void {
    withHrSkillsIdentityDatabase(function (): void {
        DB::table('hr_skills')->insert([
            ['tenant_id' => 11, 'name' => 'First aid', 'category' => 'Clinical'],
            ['tenant_id' => 22, 'name' => 'First aid', 'category' => 'Clinical'],
        ]);
        $beforeSkills = Schema::getIndexes('hr_skills');
        $beforeAssessments = Schema::getIndexes('hr_employee_skills');

        expect(fn () => hrSkillsApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'skill identity');

        expect(Schema::getIndexes('hr_skills'))->toBe($beforeSkills)
            ->and(Schema::getIndexes('hr_employee_skills'))->toBe($beforeAssessments)
            ->and(Schema::hasIndex('hr_skills', 'hr_skills_category_name_uq'))->toBeFalse();
    });
});

it('enforces application skills identities and exactly restores compatibility indexes', function (): void {
    withHrSkillsIdentityDatabase(function (): void {
        DB::table('hr_skills')->insert([
            'tenant_id' => 11,
            'name' => 'First aid',
            'category' => 'Clinical',
            'is_active' => true,
        ]);

        $migration = hrSkillsApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_skills', 'hr_skills_category_name_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_skills', 'hr_skills_active_category_name_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_employee_skills', 'hr_emp_skills_skill_level_profile_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_skills', 'hr_skills_tenant_id_category_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_skills', 'hr_skills_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_employee_skills', 'hr_employee_skills_tenant_id_index'))->toBeFalse();

        expect(fn () => DB::table('hr_skills')->insert([
            'tenant_id' => 22,
            'name' => 'First aid',
            'category' => 'Clinical',
        ]))->toThrow(QueryException::class);

        DB::table('hr_skills')->insert([
            'tenant_id' => 22,
            'name' => 'First aid',
            'category' => 'Leadership',
        ]);

        $migration->down();

        expect(Schema::hasIndex('hr_skills', 'hr_skills_category_name_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_skills', 'hr_skills_active_category_name_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_employee_skills', 'hr_emp_skills_skill_level_profile_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_skills', 'hr_skills_tenant_id_category_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_skills', 'hr_skills_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_employee_skills', 'hr_employee_skills_tenant_id_index'))->toBeTrue();
    });
});
