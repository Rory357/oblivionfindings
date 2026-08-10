<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrApprovalApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_28_000003_enforce_hr_approval_application_identity.php',
    );
}

function withHrApprovalIdentityDatabase(Closure $callback): void
{
    $connection = 'hr_approval_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-approval-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary HR approval migration database.');
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
        Schema::create('hr_approval_chains', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('process_type');
            $table->boolean('is_active')->default(true);
            $table->index(['tenant_id', 'process_type']);
        });
        Schema::create('hr_approval_chain_steps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('approval_chain_id');
            $table->integer('step_order');
        });
        Schema::create('hr_approval_instances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('approval_chain_id');
            $table->string('status');
            $table->dateTime('initiated_at');
            $table->index(['tenant_id', 'status']);
        });
        Schema::create('hr_leave_approval_chains', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('approver_user_id');
            $table->unsignedBigInteger('delegate_user_id')->nullable();
            $table->unsignedInteger('approval_level');
            $table->boolean('is_active')->default(true);
            $table->unique(['tenant_id', 'user_id', 'approval_level'], 'hr_leave_chain_tenant_user_level_unique');
            $table->index(['tenant_id', 'user_id', 'is_active'], 'hr_leave_chain_tenant_user_active_idx');
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('rejects application identity collisions without changing approval indexes', function (): void {
    withHrApprovalIdentityDatabase(function (): void {
        DB::table('hr_approval_chains')->insert([
            ['id' => 1, 'tenant_id' => 1, 'name' => 'Leave approval', 'process_type' => 'leave', 'is_active' => true],
            ['id' => 2, 'tenant_id' => 2, 'name' => 'Leave approval', 'process_type' => 'leave', 'is_active' => true],
        ]);

        expect(fn () => hrApprovalApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'duplicate process/name rows')
            ->and(Schema::hasIndex('hr_approval_chains', 'hr_approval_chains_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_approval_chains', 'hr_approval_chain_process_name_uq'))->toBeFalse();
    });
});

it('enforces application approval identities and exactly restores compatibility indexes', function (): void {
    withHrApprovalIdentityDatabase(function (): void {
        $migration = hrApprovalApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_approval_chains', 'hr_approval_chain_process_name_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_approval_chain_steps', 'hr_approval_step_chain_order_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_approval_instances', 'hr_approval_instance_status_started_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_leave_approval_chains', 'hr_leave_chain_user_level_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_approval_chains', 'hr_approval_chains_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_leave_approval_chains', 'hr_leave_chain_tenant_user_level_unique'))->toBeFalse();

        DB::table('hr_leave_approval_chains')->insert([
            ['tenant_id' => 1, 'user_id' => 9, 'approver_user_id' => 10, 'approval_level' => 1, 'is_active' => true],
        ]);
        expect(fn () => DB::table('hr_leave_approval_chains')->insert([
            ['tenant_id' => 2, 'user_id' => 9, 'approver_user_id' => 11, 'approval_level' => 1, 'is_active' => true],
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasIndex('hr_approval_chains', 'hr_approval_chain_process_name_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_approval_chains', 'hr_approval_chains_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_approval_chains', 'hr_approval_chains_tenant_id_process_type_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_approval_instances', 'hr_approval_instances_tenant_id_status_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_leave_approval_chains', 'hr_leave_chain_tenant_user_level_unique'))->toBeTrue()
            ->and(Schema::hasIndex('hr_leave_approval_chains', 'hr_leave_chain_tenant_user_active_idx'))->toBeTrue();
    });
});
