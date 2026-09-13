<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('board_evaluations')) {
            Schema::table('board_evaluations', function (Blueprint $table) {
                if (!Schema::hasColumn('board_evaluations', 'period_start')) {
                    $table->date('period_start')->nullable()->after('year');
                }
                if (!Schema::hasColumn('board_evaluations', 'period_end')) {
                    $table->date('period_end')->nullable()->after('period_start');
                }
                if (!Schema::hasColumn('board_evaluations', 'due_date')) {
                    $table->date('due_date')->nullable()->after('period_end');
                }
                if (!Schema::hasColumn('board_evaluations', 'version_number')) {
                    $table->unsignedInteger('version_number')->default(1)->after('due_date');
                }
                if (!Schema::hasColumn('board_evaluations', 'audience')) {
                    $table->string('audience')->default('all_members')->after('version_number');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('board_evaluations')) {
            Schema::table('board_evaluations', function (Blueprint $table) {
                $columns = ['audience', 'version_number', 'due_date', 'period_end', 'period_start'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('board_evaluations', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
