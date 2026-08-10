<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function feedApplicationIndexMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_27_000009_realign_hr_feed_application_indexes.php',
    );
}

function withFeedIndexDatabase(Closure $callback): void
{
    $connection = 'feed_index_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-feed-index-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary feed migration database.');
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
        Schema::create('hr_feed_posts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('post_type');
            $table->string('target_audience')->default('all');
            $table->string('target_value')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'post_type', 'created_at']);
        });
        Schema::create('hr_kudos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('from_user_id');
            $table->unsignedBigInteger('to_user_id');
            $table->timestamps();
            $table->index(['tenant_id', 'to_user_id']);
        });
        Schema::create('hr_kudos_reactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('kudos_id');
            $table->unsignedBigInteger('user_id');
            $table->string('emoji');
            $table->unique(['kudos_id', 'user_id', 'emoji']);
            $table->index(['tenant_id', 'kudos_id']);
        });
        foreach (['hr_kudos_replies', 'hr_feed_reactions', 'hr_feed_replies', 'hr_feed_attachments'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
            });
        }

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('replaces and exactly restores feed compatibility indexes', function (): void {
    withFeedIndexDatabase(function (): void {
        $migration = feedApplicationIndexMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_feed_posts', 'hr_feed_posts_type_created_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_feed_posts', 'hr_feed_posts_audience_created_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_kudos', 'hr_kudos_recipient_created_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_kudos', 'hr_kudos_sender_created_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_feed_posts', 'hr_feed_posts_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_kudos_reactions', 'hr_kudos_reactions_tenant_id_kudos_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_feed_attachments', 'hr_feed_attachments_tenant_id_index'))->toBeFalse();

        $migration->down();

        expect(Schema::hasIndex('hr_feed_posts', 'hr_feed_posts_type_created_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_kudos', 'hr_kudos_sender_created_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_feed_posts', 'hr_feed_posts_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_feed_posts', 'hr_feed_posts_tenant_id_post_type_created_at_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_kudos', 'hr_kudos_tenant_id_to_user_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_kudos_reactions', 'hr_kudos_reactions_tenant_id_kudos_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_feed_attachments', 'hr_feed_attachments_tenant_id_index'))->toBeTrue();
    });
});
