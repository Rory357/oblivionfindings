<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrCaseDisciplinaryApplicationIndexMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_02_000021_realign_hr_cases_disciplinary_application_indexes.php',
    );
}

function withHrCaseDisciplinaryIndexDatabase(Closure $callback): void
{
    $connection = 'hr_case_disciplinary_index_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-case-disciplinary-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary HR case migration database.');
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
        Schema::create('hr_cases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('status')->default('open');
            $table->string('severity')->default('medium');
            $table->dateTime('opened_at');
            $table->index(['tenant_id', 'status']);
            $table->index(['user_id', 'status']);
        });
        Schema::create('hr_disciplinary_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('employee_user_id');
            $table->string('stage');
            $table->dateTime('response_deadline')->nullable();
            $table->index(['case_id']);
            $table->index(['employee_user_id', 'stage']);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('uses application-shaped case indexes and restores the exact compatibility indexes', function (): void {
    withHrCaseDisciplinaryIndexDatabase(function (): void {
        DB::table('hr_cases')->insert([
            'id' => 1,
            'tenant_id' => 11,
            'user_id' => 20,
            'assigned_to' => 30,
            'status' => 'open',
            'severity' => 'high',
            'opened_at' => now(),
        ]);
        DB::table('hr_disciplinary_actions')->insert([
            'id' => 1,
            'tenant_id' => 11,
            'case_id' => 1,
            'employee_user_id' => 20,
            'stage' => 'response_period',
            'response_deadline' => now()->addDay(),
        ]);

        $migration = hrCaseDisciplinaryApplicationIndexMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_cases', 'hr_cases_status_severity_opened_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_cases', 'hr_cases_assignee_status_opened_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_disciplinary_actions', 'hr_disciplinary_stage_response_deadline_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_disciplinary_actions', 'hr_disciplinary_case_stage_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_cases', 'hr_cases_tenant_id_status_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_cases', 'hr_cases_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_disciplinary_actions', 'hr_disciplinary_actions_tenant_id_index'))->toBeFalse()
            ->and(DB::table('hr_cases')->where('id', 1)->value('status'))->toBe('open')
            ->and(DB::table('hr_disciplinary_actions')->where('id', 1)->value('stage'))->toBe('response_period');

        $migration->down();

        expect(Schema::hasIndex('hr_cases', 'hr_cases_tenant_id_status_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_cases', 'hr_cases_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_disciplinary_actions', 'hr_disciplinary_actions_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_cases', 'hr_cases_status_severity_opened_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_cases', 'hr_cases_assignee_status_opened_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_disciplinary_actions', 'hr_disciplinary_stage_response_deadline_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_disciplinary_actions', 'hr_disciplinary_case_stage_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_cases', 'hr_cases_user_id_status_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_disciplinary_actions', 'hr_disciplinary_actions_employee_user_id_stage_index'))->toBeTrue()
            ->and(DB::table('hr_cases')->count())->toBe(1)
            ->and(DB::table('hr_disciplinary_actions')->count())->toBe(1);
    });
});
