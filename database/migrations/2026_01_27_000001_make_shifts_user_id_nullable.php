<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Skip for SQLite (doesn't support MODIFY)
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        try {
            Schema::table('shifts', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        } catch (\Throwable $e) {
            // ignore
        }

        DB::statement("ALTER TABLE `shifts` MODIFY `user_id` BIGINT UNSIGNED NULL");

        try {
            DB::statement("ALTER TABLE `shifts` ADD CONSTRAINT `shifts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL");
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function down(): void
    {
        // Skip for SQLite (doesn't support MODIFY)
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        try {
            Schema::table('shifts', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        } catch (\Throwable $e) {
            // ignore
        }

        // Best-effort: keep nullable to avoid data loss.
        DB::statement("ALTER TABLE `shifts` MODIFY `user_id` BIGINT UNSIGNED NULL");

        try {
            DB::statement("ALTER TABLE `shifts` ADD CONSTRAINT `shifts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE");
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
