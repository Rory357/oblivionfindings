<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Theme: 'light' | 'dark' | 'system' (default system)
            if (! Schema::hasColumn('users', 'theme')) {
                $table->string('theme', 10)->default('system')->after('time_format');
            }

            // Accent colour — a hex string; null means "inherit brand default"
            if (! Schema::hasColumn('users', 'accent_colour')) {
                $table->string('accent_colour', 9)->nullable()->after('theme');
            }

            if (! Schema::hasColumn('users', 'font_size')) {
                $table->unsignedTinyInteger('font_size')->default(14)->after('accent_colour');
            }

            // 'comfortable' | 'compact'
            if (! Schema::hasColumn('users', 'sidebar_density')) {
                $table->string('sidebar_density', 12)->default('comfortable')->after('font_size');
            }

            if (! Schema::hasColumn('users', 'reduce_motion')) {
                $table->boolean('reduce_motion')->default(false)->after('sidebar_density');
            }

            // 'monday' | 'sunday'
            if (! Schema::hasColumn('users', 'first_day_of_week')) {
                $table->string('first_day_of_week', 10)->default('monday')->after('reduce_motion');
            }

            // Landing route preference (used in Phase 3 — nullable means "resolve
            // from highest-level role's landing_route", or fall through to dashboard).
            if (! Schema::hasColumn('users', 'landing_route_preference')) {
                $table->string('landing_route_preference', 40)->nullable()->after('first_day_of_week');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [];
            foreach ([
                'theme',
                'accent_colour',
                'font_size',
                'sidebar_density',
                'reduce_motion',
                'first_day_of_week',
                'landing_route_preference',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $columns[] = $column;
                }
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
