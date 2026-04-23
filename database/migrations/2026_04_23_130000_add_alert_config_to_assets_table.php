<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('assets', 'alert_config')) {
            return;
        }

        Schema::table('assets', function (Blueprint $table) {
            $table->json('alert_config')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('assets', 'alert_config')) {
            return;
        }

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('alert_config');
        });
    }
};
