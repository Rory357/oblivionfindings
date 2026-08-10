<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrTrainingDriverApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_02_000020_realign_hr_training_driver_application_identity.php',
    );
}

function withHrTrainingDriverIdentityDatabase(Closure $callback): void
{
    $connection = 'hr_training_driver_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-training-driver-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary HR training migration database.');
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
        Schema::create('hr_courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('title');
            $table->string('code');
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'category']);
        });
        Schema::create('hr_course_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('tenant_id')->index();
            $table->date('session_date');
            $table->string('status')->default('scheduled');
            $table->index(['tenant_id', 'session_date']);
        });
        Schema::create('hr_course_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('status')->default('enrolled');
            $table->dateTime('completed_at')->nullable();
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'course_id']);
        });
        Schema::create('hr_course_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('hr_course_id');
            $table->date('due_at')->nullable();
            $table->string('status')->default('assigned');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'hr_course_id']);
            $table->index(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'user_id', 'hr_course_id'], 'hr_course_assign_unique');
        });
        Schema::create('hr_driver_eligibility', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('status');
            $table->date('licence_expires_at')->nullable()->index();
            $table->index(['tenant_id', 'status']);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before changing training indexes when application identities collide', function (): void {
    withHrTrainingDriverIdentityDatabase(function (): void {
        DB::table('hr_courses')->insert([
            ['tenant_id' => 11, 'title' => 'First aid', 'code' => ' FIRST-AID '],
            ['tenant_id' => 22, 'title' => 'First aid duplicate', 'code' => 'first-aid'],
        ]);
        DB::table('hr_course_assignments')->insert([
            ['tenant_id' => 11, 'user_id' => 5, 'hr_course_id' => 1, 'status' => 'assigned'],
            ['tenant_id' => 22, 'user_id' => 5, 'hr_course_id' => 1, 'status' => 'completed'],
        ]);
        $beforeCourses = Schema::getIndexes('hr_courses');
        $beforeAssignments = Schema::getIndexes('hr_course_assignments');

        expect(fn () => hrTrainingDriverApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'course code identity');

        expect(Schema::getIndexes('hr_courses'))->toBe($beforeCourses)
            ->and(Schema::getIndexes('hr_course_assignments'))->toBe($beforeAssignments)
            ->and(Schema::hasColumn('hr_courses', 'application_code_key'))->toBeFalse();
    });

    withHrTrainingDriverIdentityDatabase(function (): void {
        DB::table('hr_courses')->insert([
            ['id' => 1, 'tenant_id' => 11, 'title' => 'First aid', 'code' => 'FIRST-AID'],
        ]);
        DB::table('hr_course_assignments')->insert([
            ['tenant_id' => 11, 'user_id' => 5, 'hr_course_id' => 1, 'status' => 'assigned'],
            ['tenant_id' => 22, 'user_id' => 5, 'hr_course_id' => 1, 'status' => 'completed'],
        ]);

        expect(fn () => hrTrainingDriverApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'training assignment identity');
        expect(Schema::hasColumn('hr_courses', 'application_code_key'))->toBeFalse();
    });
});

it('enforces application training identities and restores exact compatibility indexes', function (): void {
    withHrTrainingDriverIdentityDatabase(function (): void {
        DB::table('hr_courses')->insert([
            ['id' => 1, 'tenant_id' => 11, 'title' => 'First aid', 'code' => 'FIRST-AID'],
        ]);
        DB::table('hr_course_assignments')->insert([
            'tenant_id' => 11,
            'user_id' => 5,
            'hr_course_id' => 1,
            'status' => 'assigned',
        ]);

        $migration = hrTrainingDriverApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_courses', 'hr_courses_code_key_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_courses', 'hr_courses_active_category_title_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_course_sessions', 'hr_course_sessions_status_date_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_course_enrollments', 'hr_course_enrollments_user_status_completed_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_course_assignments', 'hr_course_assignments_user_course_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_driver_eligibility', 'hr_driver_eligibility_status_expiry_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_courses', 'hr_courses_tenant_id_code_unique'))->toBeFalse()
            ->and(Schema::hasIndex('hr_course_assignments', 'hr_course_assign_unique'))->toBeFalse();

        expect(fn () => DB::table('hr_courses')->insert([
            'tenant_id' => 22,
            'title' => 'Duplicate code',
            'code' => ' first-aid ',
        ]))->toThrow(QueryException::class);
        expect(fn () => DB::table('hr_course_assignments')->insert([
            'tenant_id' => 22,
            'user_id' => 5,
            'hr_course_id' => 1,
            'status' => 'assigned',
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasColumn('hr_courses', 'application_code_key'))->toBeFalse()
            ->and(Schema::hasIndex('hr_courses', 'hr_courses_tenant_id_code_unique'))->toBeTrue()
            ->and(Schema::hasIndex('hr_courses', 'hr_courses_tenant_id_category_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_course_sessions', 'hr_course_sessions_tenant_id_session_date_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_course_enrollments', 'hr_course_enrollments_tenant_id_user_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_course_assignments', 'hr_course_assign_unique'))->toBeTrue()
            ->and(Schema::hasIndex('hr_driver_eligibility', 'hr_driver_eligibility_tenant_id_status_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_courses', 'hr_courses_code_key_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_course_assignments', 'hr_course_assignments_user_course_uq'))->toBeFalse();
    });
});
