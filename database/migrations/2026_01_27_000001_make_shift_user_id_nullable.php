<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // Drop FK first, then allow nulls
            try {
                $table->dropForeign(['user_id']);
            } catch (\Throwable $e) {
                // ignore if already dropped
            }

            $table->foreignId('user_id')->nullable()->change();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            try {
                $table->dropForeign(['user_id']);
            } catch (\Throwable $e) {
            }
            $table->foreignId('user_id')->nullable(false)->change();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
