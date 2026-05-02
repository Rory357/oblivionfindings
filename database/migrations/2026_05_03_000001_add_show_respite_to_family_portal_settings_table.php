<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_portal_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('family_portal_settings', 'show_respite')) {
                $table->boolean('show_respite')->default(true)->after('show_shift_schedule');
            }
        });
    }

    public function down(): void
    {
        Schema::table('family_portal_settings', function (Blueprint $table) {
            if (Schema::hasColumn('family_portal_settings', 'show_respite')) {
                $table->dropColumn('show_respite');
            }
        });
    }
};
