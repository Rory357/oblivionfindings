<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrTimeTrackingApplicationIndexMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_01_000008_realign_hr_time_tracking_application_indexes.php',
    );
}

function withHrTimeTrackingApplicationIndexDatabase(Closure $callback): void
{
    $connection = 'hr_time_tracking_application_index_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-time-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary HR time migration database.');
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
        Schema::create('hr_attendance_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id');
            $table->string('status')->index();
            $table->dateTime('clock_in_at')->index();
            $table->index(['user_id', 'status', 'clock_in_at'], 'hr_attendance_user_status_clock_in_idx');
            $table->index(['tenant_id', 'clock_in_at'], 'hr_attendance_tenant_clock_in_idx');
        });
        Schema::create('hr_time_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->date('entry_date');
            $table->string('status');
            $table->index(['tenant_id', 'user_id', 'entry_date']);
            $table->index(['tenant_id', 'status']);
        });
        Schema::create('hr_time_entry_amendments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('hr_time_entry_id');
            $table->dateTime('created_at')->nullable();
            $table->index(['hr_time_entry_id', 'created_at']);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('replaces time-tracking partition indexes and restores them exactly', function (): void {
    withHrTimeTrackingApplicationIndexDatabase(function (): void {
        $migration = hrTimeTrackingApplicationIndexMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_attendance_sessions', 'hr_attendance_status_clock_in_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_time_entries', 'hr_time_entries_user_date_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_time_entries', 'hr_time_entries_status_date_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_attendance_sessions', 'hr_attendance_sessions_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_time_entries', 'hr_time_entries_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_time_entry_amendments', 'hr_time_entry_amendments_tenant_id_index'))->toBeFalse();

        $migration->down();

        expect(Schema::hasIndex('hr_attendance_sessions', 'hr_attendance_status_clock_in_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_time_entries', 'hr_time_entries_user_date_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_time_entries', 'hr_time_entries_status_date_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_attendance_sessions', 'hr_attendance_sessions_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_attendance_sessions', 'hr_attendance_tenant_clock_in_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_time_entries', 'hr_time_entries_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_time_entries', 'hr_time_entries_tenant_id_user_id_entry_date_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_time_entries', 'hr_time_entries_tenant_id_status_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_time_entry_amendments', 'hr_time_entry_amendments_tenant_id_index'))->toBeTrue();
    });
});
