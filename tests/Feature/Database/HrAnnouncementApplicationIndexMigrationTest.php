<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrAnnouncementApplicationIndexMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_28_000007_realign_hr_announcement_application_indexes.php',
    );
}

function withHrAnnouncementApplicationIndexDatabase(Closure $callback): void
{
    $connection = 'hr_announcement_application_index_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-announcement-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary HR announcement migration database.');
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
        Schema::create('hr_announcements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('status', 16)->index();
            $table->string('priority');
            $table->dateTime('published_at')->nullable();
            $table->boolean('is_pinned')->default(false);

            $table->index(['tenant_id', 'published_at']);
            $table->index(['tenant_id', 'priority']);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('replaces legacy announcement indexes and restores them exactly on rollback', function (): void {
    withHrAnnouncementApplicationIndexDatabase(function (): void {
        $migration = hrAnnouncementApplicationIndexMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_announcements', 'hr_announcements_status_published_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_announcements', 'hr_announcements_pinned_status_published_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_announcements', 'hr_announcements_priority_status_published_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_announcements', 'hr_announcements_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_announcements', 'hr_announcements_tenant_id_published_at_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_announcements', 'hr_announcements_tenant_id_priority_index'))->toBeFalse();

        $migration->down();

        expect(Schema::hasIndex('hr_announcements', 'hr_announcements_status_published_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_announcements', 'hr_announcements_pinned_status_published_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_announcements', 'hr_announcements_priority_status_published_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_announcements', 'hr_announcements_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_announcements', 'hr_announcements_tenant_id_published_at_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_announcements', 'hr_announcements_tenant_id_priority_index'))->toBeTrue();
    });
});
