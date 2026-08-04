<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrCalendarApplicationMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_28_000005_realign_hr_calendar_application.php',
    );
}

function withHrCalendarApplicationDatabase(Closure $callback): void
{
    $connection = 'hr_calendar_application_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-calendar-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary HR calendar migration database.');
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
        Schema::create('hr_calendar_event_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('key');
            $table->string('label');
            $table->unsignedInteger('sort')->default(0);
            $table->unique(['tenant_id', 'key']);
        });
        Schema::create('hr_calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('archived_at')->nullable();
            $table->unsignedBigInteger('recurrence_parent_id')->nullable();
            $table->date('exception_date')->nullable();
            $table->index(['tenant_id', 'starts_at', 'ends_at']);
        });
        Schema::create('hr_calendar_event_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('event_id');
            $table->timestamp('created_at')->nullable();
            $table->index('event_id', 'hr_cal_attach_event_idx');
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('rejects duplicate application category keys before changing indexes', function (): void {
    withHrCalendarApplicationDatabase(function (): void {
        DB::table('hr_calendar_event_categories')->insert([
            ['tenant_id' => 11, 'key' => 'training', 'label' => 'Training', 'sort' => 1],
            ['tenant_id' => 22, 'key' => 'training', 'label' => 'Learning', 'sort' => 2],
        ]);

        expect(fn () => hrCalendarApplicationMigration()->up())
            ->toThrow(RuntimeException::class, 'duplicate keys exist')
            ->and(Schema::hasIndex(
                'hr_calendar_event_categories',
                'hr_calendar_event_categories_tenant_id_key_unique',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_calendar_event_categories',
                'hr_calendar_event_categories_key_uq',
            ))->toBeFalse();
    });
});

it('rejects duplicate recurring occurrence overrides before changing indexes', function (): void {
    withHrCalendarApplicationDatabase(function (): void {
        DB::table('hr_calendar_events')->insert([
            [
                'tenant_id' => 11,
                'starts_at' => '2026-07-01 09:00:00',
                'ends_at' => '2026-07-01 09:30:00',
                'recurrence_parent_id' => 9,
                'exception_date' => '2026-07-01',
            ],
            [
                'tenant_id' => 22,
                'starts_at' => '2026-07-01 10:00:00',
                'ends_at' => '2026-07-01 10:30:00',
                'recurrence_parent_id' => 9,
                'exception_date' => '2026-07-01',
            ],
        ]);

        expect(fn () => hrCalendarApplicationMigration()->up())
            ->toThrow(RuntimeException::class, 'duplicate occurrence overrides exist')
            ->and(Schema::hasIndex('hr_calendar_events', 'hr_calendar_events_parent_exception_uq'))
            ->toBeFalse();
    });
});

it('enforces application Calendar identities and exactly restores compatibility indexes', function (): void {
    withHrCalendarApplicationDatabase(function (): void {
        $migration = hrCalendarApplicationMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_calendar_event_categories', 'hr_calendar_event_categories_key_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_calendar_events', 'hr_calendar_events_active_range_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_calendar_events', 'hr_calendar_events_parent_exception_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_calendar_event_attachments', 'hr_calendar_attach_event_created_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_calendar_events', 'hr_calendar_events_tenant_id_index'))->toBeFalse();

        DB::table('hr_calendar_event_categories')->insert([
            'tenant_id' => 11,
            'key' => 'training',
            'label' => 'Training',
            'sort' => 1,
        ]);
        expect(fn () => DB::table('hr_calendar_event_categories')->insert([
            'tenant_id' => 22,
            'key' => 'training',
            'label' => 'Learning',
            'sort' => 2,
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasIndex('hr_calendar_event_categories', 'hr_calendar_event_categories_key_uq'))->toBeFalse()
            ->and(Schema::hasIndex(
                'hr_calendar_event_categories',
                'hr_calendar_event_categories_tenant_id_key_unique',
            ))->toBeTrue()
            ->and(Schema::hasIndex('hr_calendar_events', 'hr_calendar_events_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_calendar_event_attachments',
                'hr_calendar_event_attachments_tenant_id_index',
            ))->toBeTrue();
    });
});
