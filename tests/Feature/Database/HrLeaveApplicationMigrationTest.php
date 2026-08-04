<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrLeaveApplicationMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_28_000006_realign_hr_leave_application.php',
    );
}

function withHrLeaveApplicationDatabase(Closure $callback): void
{
    $connection = 'hr_leave_application_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-leave-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary HR leave migration database.');
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
        Schema::create('hr_leave_balances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('leave_type');
            $table->integer('year');
            $table->unique(['tenant_id', 'user_id', 'leave_type', 'year']);
        });
        Schema::create('hr_leave_balance_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id');
            $table->string('leave_type');
            $table->integer('year');
            $table->string('entry_type');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->index(
                ['tenant_id', 'user_id', 'leave_type', 'year'],
                'hr_leave_ledger_tenant_user_type_year_idx',
            );
            $table->index(['source_type', 'source_id'], 'hr_leave_ledger_source_idx');
        });
        Schema::create('hr_public_holidays', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->date('date');
            $table->string('region')->nullable();
            $table->boolean('is_national')->default(true);
            $table->integer('year');
            $table->index(['year', 'date']);
        });
        Schema::create('staff_time_offs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('hr_leave_request_id')->nullable();
            $table->string('type');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
        });
        Schema::create('hr_leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('escalated_to')->nullable();
            $table->string('status');
            $table->dateTime('approval_due_at')->nullable();
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('rejects marker-partitioned leave balance duplicates before changing indexes', function (): void {
    withHrLeaveApplicationDatabase(function (): void {
        DB::table('hr_leave_balances')->insert([
            ['tenant_id' => 11, 'user_id' => 7, 'leave_type' => 'annual', 'year' => 2026],
            ['tenant_id' => 22, 'user_id' => 7, 'leave_type' => 'annual', 'year' => 2026],
        ]);

        expect(fn () => hrLeaveApplicationMigration()->up())
            ->toThrow(RuntimeException::class, 'duplicate user, type, and year rows exist')
            ->and(Schema::hasIndex(
                'hr_leave_balances',
                'hr_leave_balances_tenant_id_user_id_leave_type_year_unique',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_leave_balances',
                'hr_leave_balances_user_type_year_uq',
            ))->toBeFalse();
    });
});

it('rejects duplicate leave projections before changing indexes', function (): void {
    withHrLeaveApplicationDatabase(function (): void {
        DB::table('staff_time_offs')->insert([
            [
                'tenant_id' => 11,
                'hr_leave_request_id' => 99,
                'type' => 'annual',
                'starts_at' => '2026-08-01 00:00:00',
                'ends_at' => '2026-08-01 23:59:59',
            ],
            [
                'tenant_id' => 22,
                'hr_leave_request_id' => 99,
                'type' => 'annual',
                'starts_at' => '2026-08-01 00:00:00',
                'ends_at' => '2026-08-01 23:59:59',
            ],
        ]);

        expect(fn () => hrLeaveApplicationMigration()->up())
            ->toThrow(RuntimeException::class, 'duplicate links exist')
            ->and(Schema::hasIndex('staff_time_offs', 'staff_time_offs_leave_request_uq'))
            ->toBeFalse();
    });
});

it('enforces application Leave identities and exactly restores compatibility indexes', function (): void {
    withHrLeaveApplicationDatabase(function (): void {
        $migration = hrLeaveApplicationMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_leave_balances', 'hr_leave_balances_user_type_year_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_leave_balance_ledgers', 'hr_leave_ledger_source_entry_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_public_holidays', 'hr_public_holidays_date_region_uq'))->toBeTrue()
            ->and(Schema::hasIndex('staff_time_offs', 'staff_time_offs_leave_request_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_leave_requests', 'hr_leave_requests_approver_queue_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_leave_balances', 'hr_leave_balances_tenant_id_index'))->toBeFalse();

        DB::table('hr_leave_balances')->insert([
            'tenant_id' => 11,
            'user_id' => 7,
            'leave_type' => 'annual',
            'year' => 2026,
        ]);
        expect(fn () => DB::table('hr_leave_balances')->insert([
            'tenant_id' => 22,
            'user_id' => 7,
            'leave_type' => 'annual',
            'year' => 2026,
        ]))->toThrow(QueryException::class);

        DB::table('hr_public_holidays')->insert([
            'tenant_id' => 11,
            'date' => '2026-08-03',
            'region' => 'auckland',
            'is_national' => false,
            'year' => 2026,
        ]);
        expect(fn () => DB::table('hr_public_holidays')->insert([
            'tenant_id' => 22,
            'date' => '2026-08-03',
            'region' => 'auckland',
            'is_national' => false,
            'year' => 2026,
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasIndex('hr_leave_balances', 'hr_leave_balances_user_type_year_uq'))->toBeFalse()
            ->and(Schema::hasIndex(
                'hr_leave_balances',
                'hr_leave_balances_tenant_id_user_id_leave_type_year_unique',
            ))->toBeTrue()
            ->and(Schema::hasIndex('hr_leave_balance_ledgers', 'hr_leave_ledger_source_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_public_holidays', 'hr_public_holidays_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('staff_time_offs', 'staff_time_offs_tenant_id_index'))->toBeTrue();

        DB::table('hr_leave_balances')->insert([
            'tenant_id' => 22,
            'user_id' => 7,
            'leave_type' => 'annual',
            'year' => 2026,
        ]);
        expect(DB::table('hr_leave_balances')->count())->toBe(2);
    });
});
