<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_handovers', function (Blueprint $table) {
            // Optimistic-concurrency token: bumped on every draft save so a second
            // editor of the same shared handover is blocked (their stale version
            // no longer matches) rather than silently overwriting.
            $table->unsignedInteger('version')->default(0)->after('cd_verification');
        });
    }

    public function down(): void
    {
        Schema::table('shift_handovers', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
