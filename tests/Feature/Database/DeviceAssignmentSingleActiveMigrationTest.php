<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function deviceAssignmentSingleActiveMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_05_000029_enforce_single_active_device_assignment.php',
    );
}

function withDeviceAssignmentIntegrityDatabase(Closure $callback): void
{
    $connection = 'device_assignment_integrity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-device-assignment-integrity-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary device-assignment migration database.');
    }

    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        Schema::create('device_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->string('assignable_type');
            $table->unsignedBigInteger('assignable_id');
            $table->string('assignment_type')->default('permanent');
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('collection_started_at')->nullable();
            $table->timestamp('collection_stopped_at')->nullable();
            $table->string('collection_stop_reason')->nullable();
            $table->string('withdrawal_outcome')->nullable();
            $table->timestamps();
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('repairs poisoned active rows deterministically and enforces one active assignment per device', function (): void {
    withDeviceAssignmentIntegrityDatabase(function (): void {
        $assignedAt = '2026-08-05 10:00:00';
        $timestamps = [
            'created_at' => '2026-08-05 10:01:00',
            'updated_at' => '2026-08-05 10:01:00',
        ];

        DB::table('device_assignments')->insert([
            [
                'id' => 1,
                'device_id' => 41,
                'assignable_type' => 'site',
                'assignable_id' => 7,
                'assigned_at' => $assignedAt,
                'released_at' => null,
                'collection_started_at' => $assignedAt,
                ...$timestamps,
            ],
            [
                'id' => 2,
                'device_id' => 41,
                'assignable_type' => 'room',
                'assignable_id' => 9,
                'assigned_at' => $assignedAt,
                'released_at' => null,
                'collection_started_at' => null,
                ...$timestamps,
            ],
            [
                'id' => 3,
                'device_id' => 41,
                'assignable_type' => 'site',
                'assignable_id' => 4,
                'assigned_at' => '2026-08-01 09:00:00',
                'released_at' => '2026-08-02 09:00:00',
                'collection_started_at' => null,
                ...$timestamps,
            ],
        ]);

        $migration = deviceAssignmentSingleActiveMigration();
        $migration->up();

        $stale = DB::table('device_assignments')->find(1);
        $winner = DB::table('device_assignments')->find(2);

        expect($stale->released_at)->toBe($assignedAt)
            ->and($stale->collection_stopped_at)->toBe($assignedAt)
            ->and($stale->collection_stop_reason)->toBe('canonical_assignment_integrity_repair')
            ->and($winner->released_at)->toBeNull()
            ->and(DB::table('device_assignments')->where('device_id', 41)->whereNull('released_at')->count())->toBe(1)
            ->and(DB::table('device_assignments')->where('device_id', 41)->whereNotNull('released_at')->count())->toBe(2)
            ->and(Schema::hasColumn('device_assignments', 'active_device_id'))->toBeFalse()
            ->and(Schema::hasIndex('device_assignments', 'device_assignments_one_active_device_uq'))->toBeTrue();

        expect(fn () => DB::table('device_assignments')->insert([
            'device_id' => 41,
            'assignable_type' => 'site',
            'assignable_id' => 12,
            'assigned_at' => '2026-08-05 11:00:00',
            ...$timestamps,
        ]))->toThrow(QueryException::class);

        DB::table('device_assignments')->insert([
            'device_id' => 41,
            'assignable_type' => 'site',
            'assignable_id' => 12,
            'assigned_at' => '2026-08-05 11:00:00',
            'released_at' => '2026-08-05 12:00:00',
            ...$timestamps,
        ]);

        $migration->down();

        expect(Schema::hasColumn('device_assignments', 'active_device_id'))->toBeFalse()
            ->and(DB::table('device_assignments')->where('device_id', 41)->count())->toBe(4);
    });
});
