<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roster_template_shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('roster_template_shifts', 'shift_type')) {
                $table->string('shift_type')->default('standard')->after('end_time');
            }

            if (! Schema::hasColumn('roster_template_shifts', 'is_sleepover')) {
                $table->boolean('is_sleepover')->default(false)->after('shift_type');
            }

            if (! Schema::hasColumn('roster_template_shifts', 'is_on_call')) {
                $table->boolean('is_on_call')->default(false)->after('is_sleepover');
            }

            if (! Schema::hasColumn('roster_template_shifts', 'expected_break_minutes')) {
                $table->unsignedInteger('expected_break_minutes')->nullable()->after('is_on_call');
            }

            if (! Schema::hasColumn('roster_template_shifts', 'notes')) {
                $table->text('notes')->nullable()->after('location');
            }
        });
    }

    public function down(): void
    {
        Schema::table('roster_template_shifts', function (Blueprint $table) {
            foreach ([
                'notes',
                'expected_break_minutes',
                'is_on_call',
                'is_sleepover',
                'shift_type',
            ] as $column) {
                if (Schema::hasColumn('roster_template_shifts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
