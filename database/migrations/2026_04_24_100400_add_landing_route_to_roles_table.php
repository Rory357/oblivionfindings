<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (! Schema::hasColumn('roles', 'landing_route')) {
                // Key into config('landing_routes'). Null means "no opinion
                // from this role" — the LoginResponse falls through to the
                // next candidate or /dashboard.
                $table->string('landing_route', 40)->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'landing_route')) {
                $table->dropColumn('landing_route');
            }
        });
    }
};
