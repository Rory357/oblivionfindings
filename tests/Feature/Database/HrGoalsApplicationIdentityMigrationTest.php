<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrGoalsApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_01_000009_enforce_hr_goals_application_identity.php',
    );
}

function withHrGoalsApplicationIdentityDatabase(Closure $callback): void
{
    $connection = 'hr_goals_application_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-goals-identity-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary Goals migration database.');
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
        Schema::create('hr_goal_cycles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('status');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->index(['tenant_id', 'status']);
        });
        Schema::create('hr_goals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->unsignedBigInteger('parent_goal_id')->nullable();
            $table->string('goal_type');
            $table->string('status');
            $table->string('confidence');
            $table->date('due_date');
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'goal_type']);
            $table->index(['tenant_id', 'cycle_id']);
            $table->index(['tenant_id', 'confidence']);
        });
        Schema::create('hr_key_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('goal_id');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('status');
            $table->date('due_date')->nullable();
            $table->index(['goal_id', 'status']);
        });
        Schema::create('hr_goal_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before goal schema mutation when an application cycle name collides', function (): void {
    withHrGoalsApplicationIdentityDatabase(function (): void {
        DB::table('hr_goal_cycles')->insert([
            ['tenant_id' => 11, 'name' => 'FY27 Q1', 'status' => 'active', 'starts_at' => '2027-01-01', 'ends_at' => '2027-03-31'],
            ['tenant_id' => 22, 'name' => 'FY27 Q1', 'status' => 'active', 'starts_at' => '2027-01-01', 'ends_at' => '2027-03-31'],
        ]);
        $before = Schema::getIndexes('hr_goal_cycles');

        expect(fn () => hrGoalsApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'goal cycle name');

        expect(Schema::getIndexes('hr_goal_cycles'))->toBe($before)
            ->and(Schema::hasIndex('hr_goal_cycles', 'hr_goal_cycles_name_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_goals', 'hr_goals_user_status_due_idx'))->toBeFalse();
    });
});

it('fails before goal schema mutation when an application template name collides', function (): void {
    withHrGoalsApplicationIdentityDatabase(function (): void {
        DB::table('hr_goal_templates')->insert([
            ['tenant_id' => 11, 'name' => 'Improve service quality', 'is_active' => true],
            ['tenant_id' => 22, 'name' => 'Improve service quality', 'is_active' => false],
        ]);
        $before = Schema::getIndexes('hr_goal_templates');

        expect(fn () => hrGoalsApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'goal template name');

        expect(Schema::getIndexes('hr_goal_templates'))->toBe($before)
            ->and(Schema::hasIndex('hr_goal_templates', 'hr_goal_templates_name_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_goals', 'hr_goals_user_status_due_idx'))->toBeFalse();
    });
});

it('enforces and exactly rolls back application goal identities and read paths', function (): void {
    withHrGoalsApplicationIdentityDatabase(function (): void {
        DB::table('hr_goal_cycles')->insert([
            'tenant_id' => 11,
            'name' => 'FY27 Q1',
            'status' => 'active',
            'starts_at' => '2027-01-01',
            'ends_at' => '2027-03-31',
        ]);
        DB::table('hr_goal_templates')->insert([
            'tenant_id' => 11,
            'name' => 'Improve service quality',
            'is_active' => true,
        ]);

        $migration = hrGoalsApplicationIdentityMigration();
        $migration->up();

        foreach ([
            ['hr_goal_cycles', 'hr_goal_cycles_name_uq'],
            ['hr_goal_cycles', 'hr_goal_cycles_status_dates_idx'],
            ['hr_goal_templates', 'hr_goal_templates_name_uq'],
            ['hr_goal_templates', 'hr_goal_templates_active_name_idx'],
            ['hr_goals', 'hr_goals_user_status_due_idx'],
            ['hr_goals', 'hr_goals_cycle_type_status_idx'],
            ['hr_goals', 'hr_goals_parent_status_idx'],
            ['hr_goals', 'hr_goals_confidence_status_idx'],
            ['hr_key_results', 'hr_key_results_owner_status_due_idx'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeTrue();
        }

        foreach ([
            ['hr_goals', 'hr_goals_tenant_id_index'],
            ['hr_goals', 'hr_goals_tenant_id_user_id_index'],
            ['hr_goals', 'hr_goals_tenant_id_status_index'],
            ['hr_goals', 'hr_goals_tenant_id_goal_type_index'],
            ['hr_goals', 'hr_goals_tenant_id_cycle_id_index'],
            ['hr_goals', 'hr_goals_tenant_id_confidence_index'],
            ['hr_goal_cycles', 'hr_goal_cycles_tenant_id_index'],
            ['hr_goal_cycles', 'hr_goal_cycles_tenant_id_status_index'],
            ['hr_key_results', 'hr_key_results_tenant_id_index'],
            ['hr_goal_templates', 'hr_goal_templates_tenant_id_index'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeFalse();
        }

        expect(fn () => DB::table('hr_goal_cycles')->insert([
            'tenant_id' => 22,
            'name' => 'FY27 Q1',
            'status' => 'closed',
            'starts_at' => '2027-01-01',
            'ends_at' => '2027-03-31',
        ]))->toThrow(QueryException::class);
        expect(fn () => DB::table('hr_goal_templates')->insert([
            'tenant_id' => 22,
            'name' => 'Improve service quality',
            'is_active' => false,
        ]))->toThrow(QueryException::class);

        $migration->down();

        foreach ([
            ['hr_goal_cycles', 'hr_goal_cycles_name_uq'],
            ['hr_goal_cycles', 'hr_goal_cycles_status_dates_idx'],
            ['hr_goal_templates', 'hr_goal_templates_name_uq'],
            ['hr_goal_templates', 'hr_goal_templates_active_name_idx'],
            ['hr_goals', 'hr_goals_user_status_due_idx'],
            ['hr_goals', 'hr_goals_cycle_type_status_idx'],
            ['hr_goals', 'hr_goals_parent_status_idx'],
            ['hr_goals', 'hr_goals_confidence_status_idx'],
            ['hr_key_results', 'hr_key_results_owner_status_due_idx'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeFalse();
        }

        foreach ([
            ['hr_goals', 'hr_goals_tenant_id_index'],
            ['hr_goals', 'hr_goals_tenant_id_user_id_index'],
            ['hr_goals', 'hr_goals_tenant_id_status_index'],
            ['hr_goals', 'hr_goals_tenant_id_goal_type_index'],
            ['hr_goals', 'hr_goals_tenant_id_cycle_id_index'],
            ['hr_goals', 'hr_goals_tenant_id_confidence_index'],
            ['hr_goal_cycles', 'hr_goal_cycles_tenant_id_index'],
            ['hr_goal_cycles', 'hr_goal_cycles_tenant_id_status_index'],
            ['hr_key_results', 'hr_key_results_tenant_id_index'],
            ['hr_goal_templates', 'hr_goal_templates_tenant_id_index'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeTrue();
        }
    });
});
