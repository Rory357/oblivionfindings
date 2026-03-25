<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (!Schema::hasColumn('sites', 'total_capacity')) {
                $table->integer('total_capacity')->nullable()->after('respite_max_stay_days');
            }
            if (!Schema::hasColumn('sites', 'current_occupancy')) {
                $table->integer('current_occupancy')->default(0)->after('total_capacity');
            }
            if (!Schema::hasColumn('sites', 'waitlist_count')) {
                $table->integer('waitlist_count')->default(0)->after('current_occupancy');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $columns = ['total_capacity', 'current_occupancy', 'waitlist_count'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('sites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
